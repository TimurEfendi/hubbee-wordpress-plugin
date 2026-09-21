<?php
/**
 * CORS Handler - Cross-Origin Resource Sharing support
 *
 * Enables cross-origin requests from the SaaS Hub to WordPress REST API.
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

class CorsHandler {

    /**
     * Allowed headers for CORS requests
     */
    const ALLOWED_HEADERS = 'Content-Type, X-Hubbee-Signature, X-Hubbee-Timestamp, X-Hubbee-Site-Id, X-Hubbee-Request-Id';

    /**
     * Allowed methods for CORS requests
     */
    const ALLOWED_METHODS = 'POST, GET, OPTIONS';

    /**
     * Max age for preflight cache (24 hours)
     */
    const MAX_AGE = 86400;

    /**
     * Default allowlist of trusted SaaS / VPS origins. Sites can extend this
     * via the `hubbee_cors_allowed_origins` filter (e.g. for staging).
     * Public endpoints (AnalyticsEndpoint) are wide-open by design and bypass
     * the allowlist — see `is_public_endpoint()`.
     */
    const DEFAULT_ALLOWED_ORIGINS = [
        'https://hubbee.io',
        'https://www.hubbee.io',
        'https://app.hubbee.io',
        'https://api.hubbee.io',
    ];

    /**
     * Initialize CORS support
     */
    public function init(): void {
        // Handle preflight OPTIONS requests early
        add_action( 'init', [ $this, 'handle_preflight' ] );

        // Add CORS headers to REST API responses
        add_action( 'rest_api_init', [ $this, 'add_cors_support' ], 15 );
    }

    /**
     * Add CORS support to REST API
     */
    public function add_cors_support(): void {
        // Remove default WordPress CORS headers
        remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

        // Add our custom CORS headers
        add_filter( 'rest_pre_serve_request', [ $this, 'send_cors_headers' ] );
    }

    /**
     * Send CORS headers for REST API responses
     *
     * @param mixed $value The response value.
     * @return mixed
     */
    public function send_cors_headers( $value ) {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $origin      = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';

        if ( $this->is_public_endpoint( $request_uri ) ) {
            $this->set_cors_headers( '*' );
        } else {
            $resolved = $this->resolve_origin( $origin );
            // Browser-less callers (server-to-server) won't send an Origin header
            // at all — they don't need CORS headers, so emit nothing in that case.
            if ( null !== $resolved ) {
                $this->set_cors_headers( $resolved );
            }
        }
        return $value;
    }

    /**
     * Handle preflight OPTIONS requests.
     *
     * Runs early on `init` so OPTIONS responses don't go through WordPress's
     * full bootstrap (REST router etc.). Allowlist-based: requests from
     * trusted Hubbee origins or public endpoints get 204, everything else
     * gets 403 instead of an open `*`.
     */
    public function handle_preflight(): void {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'OPTIONS' !== $_SERVER['REQUEST_METHOD'] ) {
            return;
        }

        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $origin      = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';

        // Public endpoints (analytics tracking) intentionally accept any origin.
        if ( $this->is_public_endpoint( $request_uri ) ) {
            $this->set_cors_headers( '*' );
            status_header( 204 );
            exit( 0 );
        }

        // Anything else must come from a trusted origin.
        $resolved = $this->resolve_origin( $origin );
        if ( null === $resolved ) {
            status_header( 403 );
            exit( 0 );
        }

        $this->set_cors_headers( $resolved );
        status_header( 204 );
        exit( 0 );
    }

    /**
     * Send CORS headers on REST responses (non-OPTIONS). Same allowlist logic.
     */
    private function set_cors_headers( string $origin = '*' ): void {
        header( 'Access-Control-Allow-Origin: ' . $origin );
        if ( '*' !== $origin ) {
            header( 'Vary: Origin' );
        }
        header( 'Access-Control-Allow-Methods: ' . self::ALLOWED_METHODS );
        header( 'Access-Control-Allow-Headers: ' . self::ALLOWED_HEADERS );
        header( 'Access-Control-Max-Age: ' . self::MAX_AGE );
    }

    /**
     * Resolve the requesting origin against the allowlist. Returns the
     * matching origin (echoed back) or null if rejected.
     */
    private function resolve_origin( string $origin ): ?string {
        if ( '' === $origin ) {
            return null;
        }
        $allowed = (array) apply_filters( 'hubbee_cors_allowed_origins', self::DEFAULT_ALLOWED_ORIGINS );
        if ( in_array( $origin, $allowed, true ) ) {
            return $origin;
        }
        return null;
    }

    /**
     * Path patterns for endpoints that legitimately accept any browser origin
     * (public tracking pixel etc.). Keep this list small — every entry is
     * effectively `Access-Control-Allow-Origin: *`.
     */
    private function is_public_endpoint( string $request_uri ): bool {
        $public_paths = [
            '/wp-json/bz/v1/analytics',
        ];
        foreach ( $public_paths as $path ) {
            if ( false !== strpos( $request_uri, $path ) ) {
                return true;
            }
        }
        return false;
    }
}
