<?php
/**
 * SaaS Signature Validator
 *
 * Validates HMAC signatures for requests from the SaaS Hub.
 *
 * @package Hubbee\SaaS
 */

namespace Hubbee\SaaS;

use WP_Error;
use WP_REST_Request;

class SignatureValidator {

    /**
     * HTTP header names
     */
    const SIGNATURE_HEADER = 'X-Hubbee-Signature';
    const TIMESTAMP_HEADER = 'X-Hubbee-Timestamp';
    const SITE_ID_HEADER   = 'X-Hubbee-Site-Id';

    /**
     * Timestamp tolerance in seconds. Tightened from 300s → 60s for audit P1-3
     * to shrink the replay window. Must match _shared/hmac.ts on the Supabase
     * side. Requires NTP-synchronised clocks (±60s).
     */
    const TIMESTAMP_TOLERANCE = 60;

    /**
     * Connection manager instance
     *
     * @var ConnectionManager
     */
    private ConnectionManager $connection;

    /**
     * Constructor
     */
    public function __construct() {
        $this->connection = new ConnectionManager();
    }

    /**
     * Validate request signature
     *
     * @param WP_REST_Request $request The REST request.
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    public function validate( WP_REST_Request $request ): bool|WP_Error {
        // Check if site is connected
        if ( ! $this->connection->is_connected() ) {
            return new WP_Error(
                'bz_not_connected',
                __( 'Site is not connected to Hubbee.', 'hubbee' ),
                [ 'status' => 401 ]
            );
        }

        // Get headers (handle case-insensitivity)
        $signature = $request->get_header( 'x-hubbee-signature' );
        $timestamp = $request->get_header( 'x-hubbee-timestamp' );
        $site_id = $request->get_header( 'x-hubbee-site-id' );

        // Check required headers
        if ( empty( $signature ) ) {
            return new WP_Error(
                'bz_missing_signature',
                __( 'Missing signature header.', 'hubbee' ),
                [ 'status' => 401 ]
            );
        }

        if ( empty( $timestamp ) ) {
            return new WP_Error(
                'bz_missing_timestamp',
                __( 'Missing timestamp header.', 'hubbee' ),
                [ 'status' => 401 ]
            );
        }

        // Validate timestamp format
        if ( ! is_numeric( $timestamp ) ) {
            return new WP_Error(
                'bz_invalid_timestamp',
                __( 'Invalid timestamp format.', 'hubbee' ),
                [ 'status' => 401 ]
            );
        }

        // Check timestamp freshness
        $current_time = time();
        $request_time = (int) $timestamp;

        if ( ! self::is_timestamp_fresh( $request_time, $current_time ) ) {
            $time_diff = abs( $current_time - $request_time );
            return new WP_Error(
                'bz_stale_request',
                sprintf(
                    /* translators: %d: seconds difference */
                    __( 'Request timestamp is too old (%d seconds difference).', 'hubbee' ),
                    $time_diff
                ),
                [ 'status' => 401 ]
            );
        }

        // Optionally validate site ID matches
        if ( ! empty( $site_id ) ) {
            $expected_site_id = $this->connection->get_saas_site_id();
            if ( $site_id !== $expected_site_id ) {
                return new WP_Error(
                    'bz_site_id_mismatch',
                    __( 'Site ID does not match.', 'hubbee' ),
                    [ 'status' => 401 ]
                );
            }
        }

        // Get request body
        $body = $request->get_body();

        // Compute expected signature
        $expected_signature = $this->compute_signature( $body, $timestamp );

        // Compare signatures using timing-safe comparison
        if ( ! hash_equals( $expected_signature, $signature ) ) {
            return new WP_Error(
                'bz_invalid_signature',
                __( 'Invalid signature.', 'hubbee' ),
                [ 'status' => 401 ]
            );
        }

        return true;
    }

    /**
     * Pure freshness check — testable without WordPress.
     * Symmetric ±TIMESTAMP_TOLERANCE window so clock skew on either side is
     * tolerated equally. Reject (return false) if the absolute drift exceeds
     * the window — that's the replay-protection signal.
     *
     * @param int $request_time Unix timestamp from X-Hubbee-Timestamp header.
     * @param int $current_time Unix timestamp at validation time (defaults to time()).
     * @return bool True if within tolerance, false if stale or too-future.
     */
    public static function is_timestamp_fresh( int $request_time, int $current_time = 0 ): bool {
        if ( 0 === $current_time ) {
            $current_time = time();
        }
        return abs( $current_time - $request_time ) <= self::TIMESTAMP_TOLERANCE;
    }

    /**
     * Pure HMAC computation — testable without WordPress / ConnectionManager.
     * Format: HMAC-SHA256(secret, "{timestamp}.{body}"). MUST match
     * `_shared/hmac.ts` on the Supabase side; any drift breaks pushes.
     *
     * @param string     $body      Request body (raw, before any decoding).
     * @param string|int $timestamp Unix timestamp (as sent in X-Hubbee-Timestamp).
     * @param string     $secret    Shared secret (api_secret on this site).
     * @return string Lowercase hex HMAC.
     */
    public static function compute_hmac( string $body, $timestamp, string $secret ): string {
        return hash_hmac( 'sha256', ( (string) $timestamp ) . '.' . $body, $secret );
    }

    /**
     * Compute HMAC signature for payload
     *
     * @param string $body      Request body.
     * @param string $timestamp Request timestamp.
     * @return string HMAC-SHA256 signature.
     */
    private function compute_signature( string $body, string $timestamp ): string {
        return self::compute_hmac( $body, $timestamp, $this->connection->get_api_secret() );
    }

    /**
     * Create signature for outgoing requests
     *
     * @param string $body      Request body.
     * @param int    $timestamp Unix timestamp.
     * @return string HMAC-SHA256 signature.
     */
    public function create_signature( string $body, int $timestamp ): string {
        return self::compute_hmac( $body, $timestamp, $this->connection->get_api_secret() );
    }

    /**
     * Get headers for authenticated requests to SaaS
     *
     * @param string $body Request body (for signature).
     * @return array Headers array.
     */
    public function get_auth_headers( string $body = '' ): array {
        $timestamp = time();
        $signature = $this->create_signature( $body, $timestamp );
        $site_id = $this->connection->get_saas_site_id();

        return [
            self::SIGNATURE_HEADER => $signature,
            self::TIMESTAMP_HEADER => (string) $timestamp,
            self::SITE_ID_HEADER   => $site_id,
            'Content-Type'         => 'application/json',
            'Accept'               => 'application/json',
        ];
    }

    /**
     * Validate a raw signature (for testing/debugging)
     *
     * @param string $signature Signature to validate.
     * @param string $body      Request body.
     * @param string $timestamp Request timestamp.
     * @return bool
     */
    public function validate_raw( string $signature, string $body, string $timestamp ): bool {
        $expected = $this->compute_signature( $body, $timestamp );
        return hash_equals( $expected, $signature );
    }
}
