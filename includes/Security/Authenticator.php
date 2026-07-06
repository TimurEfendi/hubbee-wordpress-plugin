<?php
/**
 * REST API Authentication handler
 *
 * Supports multiple authentication methods:
 * - HMAC signature (for push requests)
 * - Bearer token (site_api_key for pull requests)
 *
 * @package Hubbee\Security
 */

namespace Hubbee\Security;

use WP_REST_Request;
use WP_Error;
use Hubbee\SaaS\SignatureValidator;
use Hubbee\SaaS\ConnectionManager;

class Authenticator {

    /**
     * Authenticate a REST API request using HMAC signature
     *
     * @param WP_REST_Request $request The REST request.
     * @return true|WP_Error True if authenticated, WP_Error otherwise.
     */
    public static function authenticate( WP_REST_Request $request ) {
        $connection = new ConnectionManager();

        // Check if site is connected
        if ( ! $connection->is_connected() ) {
            return new WP_Error(
                'bz_not_connected',
                __( 'Site is not connected to Hubbee. Please connect your site first.', 'hubbee' ),
                [ 'status' => 401 ]
            );
        }

        // Validate HMAC signature
        $validator = new SignatureValidator();
        return $validator->validate( $request );
    }

    /**
     * Authenticate using Bearer token (site_api_key)
     *
     * Used for SaaS -> WordPress pull requests where SaaS
     * sends the site's api_secret as a Bearer token.
     *
     * @param WP_REST_Request $request The REST request.
     * @return true|WP_Error True if authenticated, WP_Error otherwise.
     */
    public static function authenticate_bearer( WP_REST_Request $request ) {
        $connection = new ConnectionManager();

        // Check if site is connected
        if ( ! $connection->is_connected() ) {
            return new WP_Error(
                'bz_not_connected',
                __( 'Site is not connected to Hubbee. Please connect your site first.', 'hubbee' ),
                [ 'status' => 401 ]
            );
        }

        // Get Authorization header
        $auth_header = $request->get_header( 'Authorization' );

        if ( empty( $auth_header ) || ! preg_match( '/^Bearer\s+(.+)$/i', $auth_header, $matches ) ) {
            return new WP_Error(
                'bz_missing_auth',
                __( 'Missing or invalid Authorization header. Expected: Bearer <token>', 'hubbee' ),
                [ 'status' => 401 ]
            );
        }

        $provided_token = trim( $matches[1] );

        // Get stored api_secret via ConnectionManager (handles decryption)
        $stored_secret = $connection->get_api_secret();

        if ( empty( $stored_secret ) ) {
            return new WP_Error(
                'bz_no_secret',
                __( 'Site is not properly configured. Missing API secret.', 'hubbee' ),
                [ 'status' => 500 ]
            );
        }

        // Constant-time comparison to prevent timing attacks
        if ( ! hash_equals( $stored_secret, $provided_token ) ) {
            return new WP_Error(
                'bz_invalid_token',
                __( 'Invalid authorization token.', 'hubbee' ),
                [ 'status' => 401 ]
            );
        }

        return true;
    }

    /**
     * Authenticate using either HMAC signature OR Bearer token
     *
     * Tries Bearer token first (faster), falls back to HMAC.
     *
     * @param WP_REST_Request $request The REST request.
     * @return true|WP_Error True if authenticated, WP_Error otherwise.
     */
    public static function authenticate_any( WP_REST_Request $request ) {
        // Try Bearer token first
        $auth_header = $request->get_header( 'Authorization' );

        if ( ! empty( $auth_header ) && preg_match( '/^Bearer\s+/i', $auth_header ) ) {
            return self::authenticate_bearer( $request );
        }

        // Fall back to HMAC signature
        return self::authenticate( $request );
    }

    /**
     * Check if site is connected to SaaS
     *
     * @return bool
     */
    public static function is_connected(): bool {
        $connection = new ConnectionManager();
        return $connection->is_connected();
    }

    /**
     * Get connection status for display
     *
     * @return array
     */
    public static function get_connection_status(): array {
        $connection = new ConnectionManager();
        return $connection->get_connection_status();
    }
}
