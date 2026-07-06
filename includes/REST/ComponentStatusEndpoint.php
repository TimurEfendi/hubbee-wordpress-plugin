<?php
/**
 * Component Status Endpoint
 *
 * Returns the timestamp of the last component push.
 * Used by Elementor preview polling to detect new components.
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ComponentStatusEndpoint extends RestEndpoint {

    /**
     * Rate limit: requests per minute per IP.
     *
     * Mirrors PingEndpoint::RATE_LIMIT (60/min). The Elementor preview polls
     * this roughly every 10 s (~6/min), so 60/min per IP leaves ample headroom
     * for legitimate editors while capping abusive polling of this
     * unauthenticated read endpoint.
     */
    const RATE_LIMIT = 60;

    /**
     * Transient prefix for rate limiting (mirrors PingEndpoint key style).
     */
    const RATE_LIMIT_PREFIX = 'bz_compstatus_rl_';

    /**
     * Response cache lifetime in seconds, aligned to the client poll interval.
     */
    const CACHE_MAX_AGE = 10;

    protected function get_route(): string {
        return '/component-status';
    }

    protected function get_methods(): string {
        return 'GET';
    }

    /**
     * Public read — no HMAC. Editor preview polls this to detect new pushes.
     * Per-IP rate limiting guards the unauthenticated endpoint from abuse.
     */
    protected function get_permission_callback(): callable {
        return [ $this, 'check_rate_limit' ];
    }

    /**
     * Check per-IP rate limit for the request.
     *
     * Mirrors PingEndpoint::check_rate_limit — same transient-key style and
     * 429 behavior — adding a Retry-After header so well-behaved clients back
     * off for the rate-limit window.
     *
     * @param WP_REST_Request $request REST request.
     * @return bool|WP_Error
     */
    public function check_rate_limit( WP_REST_Request $request ) {
        unset( $request );

        $ip  = $this->get_client_ip();
        $key = self::RATE_LIMIT_PREFIX . md5( $ip );

        $count = (int) get_transient( $key );

        if ( $count >= self::RATE_LIMIT ) {
            if ( ! headers_sent() ) {
                header( 'Retry-After: 60' );
            }

            return new WP_Error(
                'bz_rate_limited',
                __( 'Too many requests. Please try again later.', 'hubbee' ),
                [ 'status' => 429 ]
            );
        }

        // Increment counter (expires in 60 seconds).
        set_transient( $key, $count + 1, 60 );

        return true;
    }

    public function handle_request( WP_REST_Request $request ): WP_REST_Response {
        unset( $request );

        $response = new WP_REST_Response(
            [
                'last_push' => get_option( 'bz_last_component_push_received', '' ),
            ],
            200
        );

        // Align caching with the editor's ~10 s poll interval so intermediaries
        // and the browser can serve a fresh-enough cached value between polls.
        $response->header( 'Cache-Control', 'max-age=' . self::CACHE_MAX_AGE );

        return $response;
    }

    /**
     * Get client IP address.
     *
     * Mirrors PingEndpoint::get_client_ip — honors common proxy/CDN forwarding
     * headers and falls back to a sentinel when none validate.
     *
     * @return string
     */
    private function get_client_ip(): string {
        // Check for forwarded IP (behind proxy/load balancer)
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = $_SERVER[ $header ];

                // X-Forwarded-For can contain multiple IPs, take the first
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }

                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
