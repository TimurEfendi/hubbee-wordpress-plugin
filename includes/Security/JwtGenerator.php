<?php
/**
 * JWT Generator for SaaS API Authentication
 *
 * Generates short-lived JWTs for secure communication between WordPress and SaaS.
 * Each request gets a fresh JWT with ~120 second TTL.
 *
 * JWT Structure:
 * - Header: { alg: "HS256", typ: "JWT" }
 * - Payload: { site_id, iat, exp, jti }
 * - Signature: HMAC-SHA256(header.payload, api_secret)
 *
 * Security:
 * - Short TTL (120s) minimizes exposure if token is intercepted
 * - jti (JWT ID) provides replay protection
 * - api_secret never transmitted (only used for signing)
 *
 * @package Hubbee\Security
 */

namespace Hubbee\Security;

use Hubbee\SaaS\ConnectionManager;

class JwtGenerator {

    /**
     * JWT expiry time in seconds (120s = 2 minutes)
     */
    const JWT_TTL_SECONDS = 120;

    /**
     * Singleton instance
     *
     * @var JwtGenerator|null
     */
    private static ?JwtGenerator $instance = null;

    /**
     * Connection manager
     *
     * @var ConnectionManager
     */
    private ConnectionManager $connection;

    /**
     * Get singleton instance
     *
     * @return JwtGenerator
     */
    public static function get_instance(): JwtGenerator {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton
     */
    private function __construct() {
        $this->connection = new ConnectionManager();
    }

    /**
     * Generate a JWT for API requests
     *
     * Creates a short-lived JWT signed with the site's api_secret.
     *
     * @return string|null JWT string or null if credentials not available.
     */
    public function generate(): ?string {
        $site_id    = $this->connection->get_saas_site_id();
        $api_secret = $this->connection->get_api_secret();

        if ( empty( $site_id ) || empty( $api_secret ) ) {
            return null;
        }

        return $this->create_jwt( $site_id, $api_secret );
    }

    /**
     * Generate a JWT with explicit credentials
     *
     * Useful when credentials are already available (e.g., during enrollment).
     *
     * @param string $site_id    The site UUID.
     * @param string $api_secret The API secret for signing.
     * @return string The generated JWT.
     */
    public function generate_with_credentials( string $site_id, string $api_secret ): string {
        return $this->create_jwt( $site_id, $api_secret );
    }

    /**
     * Create the actual JWT
     *
     * @param string $site_id    The site UUID.
     * @param string $api_secret The API secret for signing.
     * @return string The generated JWT.
     */
    private function create_jwt( string $site_id, string $api_secret ): string {
        // Header
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        // Payload
        $now = time();
        $payload = [
            'site_id' => $site_id,
            'iat'     => $now,                           // Issued at
            'exp'     => $now + self::JWT_TTL_SECONDS,   // Expiry (120s from now)
            'jti'     => $this->generate_jti(),          // Unique token ID for replay protection
        ];

        // Encode header and payload
        $header_encoded  = $this->base64url_encode( wp_json_encode( $header ) );
        $payload_encoded = $this->base64url_encode( wp_json_encode( $payload ) );

        // Create signature
        $signature_input = $header_encoded . '.' . $payload_encoded;
        $signature       = hash_hmac( 'sha256', $signature_input, $api_secret, true );
        $signature_encoded = $this->base64url_encode( $signature );

        // Combine into JWT
        return $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;
    }

    /**
     * Generate a unique JWT ID for replay protection
     *
     * @return string UUID v4 string.
     */
    private function generate_jti(): string {
        return wp_generate_uuid4();
    }

    /**
     * Base64URL encode (RFC 7515)
     *
     * Standard base64 with URL-safe characters and no padding.
     *
     * @param string $data Data to encode.
     * @return string Base64URL encoded string.
     */
    private function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url encoding for JWT, not obfuscation.
    }

    /**
     * Get the Authorization header value for API requests
     *
     * Convenience method that generates JWT and formats as Bearer token.
     *
     * @return string|null "Bearer {jwt}" or null if not connected.
     */
    public function get_auth_header(): ?string {
        $jwt = $this->generate();

        if ( null === $jwt ) {
            return null;
        }

        return 'Bearer ' . $jwt;
    }

    /**
     * Validate that we can generate JWTs
     *
     * @return bool True if credentials are available.
     */
    public function can_generate(): bool {
        return $this->connection->is_connected();
    }
}
