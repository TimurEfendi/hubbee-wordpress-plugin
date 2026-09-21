<?php
/**
 * Analytics Endpoint
 *
 * Proxy endpoint that receives analytics from frontend JavaScript
 * and forwards to SaaS with HMAC signature.
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Hubbee\SaaS\ConnectionManager;
use Hubbee\SaaS\SignatureValidator;

class AnalyticsEndpoint extends RestEndpoint {

    /**
     * Rate limit: requests per minute per IP.
     *
     * Mirrors PingEndpoint::RATE_LIMIT (60/min). The analytics pixel fires once
     * per pageview, so 60/min per IP comfortably covers legitimate browsing
     * (including SPA navigation) while still capping flood/forwarding abuse of
     * this unauthenticated, fire-and-forward proxy.
     */
    const RATE_LIMIT = 60;

    /**
     * Transient prefix for rate limiting (mirrors PingEndpoint key style).
     */
    const RATE_LIMIT_PREFIX = 'bz_analytics_rl_';

    /**
     * Maximum accepted request body size in bytes (~512 KB).
     *
     * Analytics batches are small JSON blobs; anything larger is abuse or a
     * misbehaving client. Rejecting up front protects the JSON decode and the
     * async forward from oversized payloads.
     */
    const MAX_BODY_BYTES = 512 * 1024;

    private ConnectionManager $connection;
    private SignatureValidator $validator;

    public function __construct() {
        $this->connection = new ConnectionManager();
        $this->validator  = new SignatureValidator();
    }

    protected function get_route(): string {
        return '/analytics';
    }

    protected function get_methods(): string {
        return 'POST';
    }

    /**
     * Public — frontend tracking pixel calls this without HMAC. Authenticity
     * is enforced downstream by the SaaS-side analytics-collect signature.
     * Per-IP rate limiting guards the unauthenticated proxy from abuse.
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

    /**
     * Handle analytics request
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        // Check if connected to SaaS
        if ( ! $this->connection->is_connected() ) {
            return new WP_Error(
                'bz_not_connected',
                __( 'Not connected to Hubbee SaaS.', 'hubbee' ),
                [ 'status' => 503 ]
            );
        }

        // Reject oversized payloads before any decode/forward work. Trust the
        // declared Content-Length first (cheap, lets us bail without reading
        // the full body), then verify against the actual body length so a
        // spoofed/absent header can't slip a large body through.
        $content_length = (int) $request->get_header( 'content_length' );
        if ( $content_length > self::MAX_BODY_BYTES ) {
            return new WP_Error(
                'bz_payload_too_large',
                __( 'Request body exceeds the allowed size.', 'hubbee' ),
                [ 'status' => 413 ]
            );
        }

        // Get the body from frontend
        $body = $request->get_body();

        if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
            return new WP_Error(
                'bz_payload_too_large',
                __( 'Request body exceeds the allowed size.', 'hubbee' ),
                [ 'status' => 413 ]
            );
        }

        if ( empty( $body ) ) {
            return new WP_Error(
                'bz_empty_body',
                __( 'Empty request body.', 'hubbee' ),
                [ 'status' => 400 ]
            );
        }

        // Validate JSON
        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error(
                'bz_invalid_json',
                __( 'Invalid JSON payload.', 'hubbee' ),
                [ 'status' => 400 ]
            );
        }

        // Add batch_id for idempotency
        if ( empty( $data['batch_id'] ) ) {
            $data['batch_id'] = wp_generate_uuid4();
        }

        // Re-encode for forwarding
        $forward_body = wp_json_encode( $data );

        // Get auth headers with HMAC signature
        $headers = $this->validator->get_auth_headers( $forward_body );

        // Build SaaS endpoint URL (use base URL, not stored endpoint which may contain paths)
        $base_url = rtrim( preg_replace( '#/functions/v1/.*$#', '', $this->connection->get_saas_endpoint() ), '/' );
        $saas_url = $base_url . '/functions/v1/analytics-collect';

        // Forward to SaaS without blocking the public visitor's pageview.
        // Analytics is fire-and-forget by design — the browser shouldn't wait
        // up to 10 s if Supabase is slow, and a one-off failure must not
        // surface as a 500 to the frontend tracking pixel.
        // `blocking => false` returns as soon as the TCP connection is open;
        // delivery success is best-effort.
        wp_remote_post(
            $saas_url,
            [
                'headers'   => $headers,
                'body'      => $forward_body,
                'timeout'   => 1,
                'blocking'  => false,
                'sslverify' => true,
            ]
        );

        // Always 202 — the analytics pixel doesn't care about SaaS-side success.
        return new WP_REST_Response(
            [
                'success'  => true,
                'batch_id' => $data['batch_id'],
                'queued'   => true,
            ],
            202
        );
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
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

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
