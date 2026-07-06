<?php
/**
 * Ping Endpoint - Minimal public connectivity check
 *
 * Returns only essential connectivity information.
 * This is the ONLY truly public endpoint.
 * Rate-limited to prevent abuse.
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class PingEndpoint extends RestEndpoint {

    /**
     * Rate limit: requests per minute per IP
     */
    const RATE_LIMIT = 60;

    /**
     * Transient prefix for rate limiting
     */
    const RATE_LIMIT_PREFIX = 'bz_ping_rl_';

    protected function get_route(): string {
        return '/ping';
    }

    protected function get_methods(): string {
        return 'GET';
    }

    /**
     * Public endpoint with per-IP rate limiting (60 req/min) instead of HMAC.
     */
    protected function get_permission_callback(): callable {
        return [ $this, 'check_rate_limit' ];
    }

    /**
     * Check rate limit for the request
     *
     * @param WP_REST_Request $request REST request.
     * @return bool|WP_Error
     */
    public function check_rate_limit( WP_REST_Request $request ) {
        $ip = $this->get_client_ip();
        $key = self::RATE_LIMIT_PREFIX . md5( $ip );

        $count = (int) get_transient( $key );

        if ( $count >= self::RATE_LIMIT ) {
            return new WP_Error(
                'bz_rate_limited',
                __( 'Too many requests. Please try again later.', 'hubbee' ),
                [ 'status' => 429 ]
            );
        }

        // Increment counter (expires in 60 seconds)
        set_transient( $key, $count + 1, 60 );

        return true;
    }

    /**
     * Handle the ping request
     *
     * Returns minimal response for connectivity check.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function handle_request( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response(
            [
                'ok' => true,
                'ts' => time(),
            ],
            200
        );
    }

    /**
     * Get client IP address
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
