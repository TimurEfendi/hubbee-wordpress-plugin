<?php
/**
 * SaaS Connection Manager
 *
 * Manages the connection state and credentials for SaaS communication.
 *
 * @package Hubbee\SaaS
 */

namespace Hubbee\SaaS;

class ConnectionManager {

    /**
     * Option keys
     */
    const OPTION_SITE_ID          = 'bz_saas_site_id';
    const OPTION_API_SECRET       = 'bz_saas_api_secret';
    const OPTION_ENROLLED_AT      = 'bz_saas_enrolled_at';
    const OPTION_ENDPOINT         = 'bz_saas_endpoint';
    const OPTION_SITE_NAME        = 'bz_saas_site_name';
    const OPTION_DOMAIN_FINGERPRINT = 'bz_domain_fingerprint';
    const OPTION_LOCAL_SITE_ID    = 'bz_site_id';

    /**
     * Flag to prevent multiple resets in same request
     */
    private bool $reset_performed = false;

    /**
     * Fixed SaaS endpoint (IMMUTABLE - do not change)
     *
     * This endpoint is hardcoded and cannot be modified by users.
     * The endpoint URL is not a security boundary - all requests are HMAC-signed.
     */
    const SAAS_ENDPOINT = 'https://api-db.hubbee.io';

    /**
     * Default M2M (machine-to-machine) API endpoint — the Hubbee API lane.
     *
     * heartbeat / site-commands / command-result / event-receiver live ONLY on
     * this lane (api.hubbee.io), NOT as Edge Functions on SAAS_ENDPOINT. Used as
     * the fallback in ModeConfig::get_m2m_api_url() when neither the
     * HUBBEE_API_ENDPOINT define nor the bz_m2m_api_endpoint option is set, so a
     * site that never (re)enrolled still reaches a live M2M endpoint.
     * Not a security boundary — all requests are HMAC/JWT-signed.
     */
    const M2M_ENDPOINT = 'https://api.hubbee.io';

    /**
     * Check if site is connected to SaaS
     *
     * Includes auto-detection for copied installations.
     *
     * @return bool
     */
    public function is_connected(): bool {
        $secret = get_option( self::OPTION_API_SECRET, '' );
        $site_id = get_option( self::OPTION_SITE_ID, '' );

        if ( empty( $secret ) || empty( $site_id ) ) {
            return false;
        }

        // Copy detection: warn but do NOT auto-reset credentials
        if ( $this->needs_reset() ) {
            error_log( '[Hubbee] needs_reset() triggered — credentials intact, skipping auto_reset. Site ID: ' . $site_id );
        }

        return true;
    }

    /**
     * Check if plugin needs to be reset (copied to new installation)
     *
     * @return bool True if reset is needed
     */
    public function needs_reset(): bool {
        // Skip if no credentials stored
        $secret = get_option( self::OPTION_API_SECRET, '' );
        if ( empty( $secret ) ) {
            return false;
        }

        // Check domain fingerprint
        $stored_fingerprint = get_option( self::OPTION_DOMAIN_FINGERPRINT, '' );
        $current_fingerprint = $this->generate_domain_fingerprint();

        if ( ! empty( $stored_fingerprint ) && $stored_fingerprint !== $current_fingerprint ) {
            return true;
        }

        // Check if decryption works (different WordPress salts = decryption fails)
        $decrypted = $this->decrypt( $secret );
        if ( empty( $decrypted ) || strlen( $decrypted ) < 32 ) {
            return true;
        }

        return false;
    }

    /**
     * Generate domain fingerprint for copy detection
     *
     * @return string SHA256 hash of domain URLs
     */
    public function generate_domain_fingerprint(): string {
        return hash( 'sha256', home_url() . '|' . get_option( 'siteurl' ) );
    }

    /**
     * Store domain fingerprint (call during enrollment)
     */
    public function store_domain_fingerprint(): void {
        update_option( self::OPTION_DOMAIN_FINGERPRINT, $this->generate_domain_fingerprint() );
    }

    /**
     * Auto-reset credentials when plugin was copied to new installation
     */
    private function auto_reset(): void {
        if ( $this->reset_performed ) {
            return;
        }

        $this->reset_performed = true;

        // Clear all SaaS credentials
        delete_option( self::OPTION_SITE_ID );
        delete_option( self::OPTION_API_SECRET );
        delete_option( self::OPTION_ENROLLED_AT );
        delete_option( self::OPTION_SITE_NAME );
        delete_option( self::OPTION_DOMAIN_FINGERPRINT );

        // Generate new local site ID
        if ( get_option( self::OPTION_LOCAL_SITE_ID ) ) {
            delete_option( self::OPTION_LOCAL_SITE_ID );
        }
        add_option( self::OPTION_LOCAL_SITE_ID, wp_generate_uuid4() );

        // Set transient for admin notice
        set_transient( 'bz_connection_reset', true, DAY_IN_SECONDS );

        /**
         * Fires after auto-reset due to copied plugin detection
         */
        do_action( 'hubbee_auto_reset' );
    }

    /**
     * Check if connection was auto-reset (for admin notice)
     *
     * @return bool
     */
    public function was_auto_reset(): bool {
        return (bool) get_transient( 'bz_connection_reset' );
    }

    /**
     * Clear the auto-reset notice
     */
    public function clear_reset_notice(): void {
        delete_transient( 'bz_connection_reset' );
    }

    /**
     * Get connection status details
     *
     * @return array
     */
    public function get_connection_status(): array {
        return [
            'connected'   => $this->is_connected(),
            'site_id'     => get_option( self::OPTION_SITE_ID, '' ),
            'site_name'   => get_option( self::OPTION_SITE_NAME, '' ),
            'enrolled_at' => get_option( self::OPTION_ENROLLED_AT, '' ),
            'endpoint'    => $this->get_saas_endpoint(),
            'last_sync'   => get_option( 'bz_last_push_received', '' ),
        ];
    }

    /**
     * Store SaaS credentials after successful enrollment
     *
     * @param string $site_id    Site ID from SaaS.
     * @param string $api_secret API secret for authentication.
     * @param string $site_name  Optional site name from SaaS.
     */
    public function store_credentials( string $site_id, string $api_secret, string $site_name = '' ): void {
        update_option( self::OPTION_SITE_ID, sanitize_text_field( $site_id ) );
        update_option( self::OPTION_API_SECRET, $this->encrypt( $api_secret ) );
        update_option( self::OPTION_ENROLLED_AT, current_time( 'mysql' ) );

        if ( ! empty( $site_name ) ) {
            update_option( self::OPTION_SITE_NAME, sanitize_text_field( $site_name ) );
        }

        // Store domain fingerprint for copy detection
        $this->store_domain_fingerprint();

        // Clear any previous reset notice
        $this->clear_reset_notice();
    }

    /**
     * Get the API secret (decrypted)
     *
     * @return string
     */
    public function get_api_secret(): string {
        $encrypted = get_option( self::OPTION_API_SECRET, '' );

        if ( empty( $encrypted ) ) {
            return '';
        }

        return $this->decrypt( $encrypted );
    }

    /**
     * Get the SaaS site ID
     *
     * @return string
     */
    public function get_saas_site_id(): string {
        return get_option( self::OPTION_SITE_ID, '' );
    }

    /**
     * Get the SaaS endpoint URL
     *
     * The endpoint is fixed and immutable. This method always returns
     * the hardcoded constant, ignoring any stored option values.
     *
     * @return string
     */
    public function get_saas_endpoint(): string {
        return self::SAAS_ENDPOINT;
    }

    /**
     * Clear all connection credentials (disconnect)
     *
     * Single source of truth: also stops background workers (heartbeat +
     * command poller) and resets transient failure counters. All disconnect
     * paths (AJAX button, SaaS-issued site.disconnect command) share this
     * cleanup — callers must not duplicate it.
     */
    public function disconnect(): void {
        // Notify SaaS BEFORE clearing credentials — the HMAC signature needs
        // the api_secret. Best-effort: a failure here does not block local
        // disconnect; the dashboard will still mark the site offline via the
        // heartbeat timeout fallback.
        $this->notify_saas_disconnect();

        delete_option( self::OPTION_SITE_ID );
        delete_option( self::OPTION_API_SECRET );
        delete_option( self::OPTION_ENROLLED_AT );
        delete_option( self::OPTION_SITE_NAME );
        delete_option( self::OPTION_DOMAIN_FINGERPRINT );

        // Stop background workers — they have no credentials to authenticate with anymore.
        \Hubbee\Health\HeartbeatScheduler::unschedule();
        wp_clear_scheduled_hook( \Hubbee\Agent\CommandPoller::CRON_HOOK );

        // Reset failure counters so a future re-enrollment starts fresh.
        delete_option( 'bz_heartbeat_failures' );
        delete_option( 'bz_poll_auth_failures' );
        delete_option( 'bz_heartbeat_backoff' );

        /**
         * Fires after disconnecting from SaaS
         */
        do_action( 'hubbee_disconnected' );
    }

    /**
     * Send a signed disconnect notification to the SaaS dashboard.
     *
     * Lets Hubbee write an audit_log entry, mark the site as `offline`
     * immediately, and cancel any queued site_commands — without having to
     * wait for the heartbeat timeout to detect the silence.
     *
     * Best-effort. Errors are logged but never thrown.
     */
    private function notify_saas_disconnect(): void {
        if ( ! $this->is_connected() ) {
            return;
        }

        $body = wp_json_encode( [
            'site_id'        => $this->get_saas_site_id(),
            'site_url'       => home_url(),
            'reason'         => 'plugin_user_disconnect',
            'plugin_version' => defined( 'BZ_VERSION' ) ? BZ_VERSION : '',
        ] );

        if ( false === $body ) {
            return;
        }

        $validator = new SignatureValidator();
        $headers   = $validator->get_auth_headers( $body );

        $response = wp_remote_post(
            self::SAAS_ENDPOINT . '/functions/v1/notify-saas-disconnect',
            [
                'headers'  => $headers,
                'body'     => $body,
                'timeout'  => 5,
                'blocking' => true,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[Hubbee] notify_saas_disconnect failed: ' . $response->get_error_message() );
        }
    }

    /**
     * Encrypt sensitive data
     *
     * Uses WordPress salts for encryption key derivation.
     *
     * @param string $data Data to encrypt.
     * @return string Encrypted data (base64 encoded).
     */
    private function encrypt( string $data ): string {
        if ( empty( $data ) ) {
            return '';
        }

        $key = $this->get_encryption_key();

        // Use OpenSSL if available
        if ( function_exists( 'openssl_encrypt' ) ) {
            $iv_length = openssl_cipher_iv_length( 'AES-256-CBC' );
            $iv = openssl_random_pseudo_bytes( $iv_length );
            $encrypted = openssl_encrypt( $data, 'AES-256-CBC', $key, 0, $iv );

            if ( false === $encrypted ) {
                // Fallback to base64 encoding with key XOR
                return $this->simple_encrypt( $data, $key );
            }

            return base64_encode( $iv . $encrypted );
        }

        // Fallback for systems without OpenSSL
        return $this->simple_encrypt( $data, $key );
    }

    /**
     * Decrypt sensitive data
     *
     * @param string $data Encrypted data (base64 encoded).
     * @return string Decrypted data.
     */
    private function decrypt( string $data ): string {
        if ( empty( $data ) ) {
            return '';
        }

        $key = $this->get_encryption_key();

        // Try OpenSSL decryption first
        if ( function_exists( 'openssl_decrypt' ) ) {
            $decoded = base64_decode( $data );

            if ( false === $decoded ) {
                return '';
            }

            $iv_length = openssl_cipher_iv_length( 'AES-256-CBC' );

            // Check if data is long enough to contain IV
            if ( strlen( $decoded ) <= $iv_length ) {
                // Fallback to simple decryption
                return $this->simple_decrypt( $data, $key );
            }

            $iv = substr( $decoded, 0, $iv_length );
            $encrypted = substr( $decoded, $iv_length );

            $decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, 0, $iv );

            if ( false !== $decrypted ) {
                return $decrypted;
            }
        }

        // Fallback to simple decryption
        return $this->simple_decrypt( $data, $key );
    }

    /**
     * Simple XOR-based encryption fallback
     *
     * @param string $data Data to encrypt.
     * @param string $key  Encryption key.
     * @return string
     */
    private function simple_encrypt( string $data, string $key ): string {
        $result = '';
        $key_length = strlen( $key );

        for ( $i = 0; $i < strlen( $data ); $i++ ) {
            $result .= $data[ $i ] ^ $key[ $i % $key_length ];
        }

        return base64_encode( 'simple:' . $result );
    }

    /**
     * Simple XOR-based decryption fallback
     *
     * @param string $data Data to decrypt.
     * @param string $key  Encryption key.
     * @return string
     */
    private function simple_decrypt( string $data, string $key ): string {
        $decoded = base64_decode( $data );

        if ( false === $decoded || strpos( $decoded, 'simple:' ) !== 0 ) {
            return '';
        }

        $encrypted = substr( $decoded, 7 );
        $result = '';
        $key_length = strlen( $key );

        for ( $i = 0; $i < strlen( $encrypted ); $i++ ) {
            $result .= $encrypted[ $i ] ^ $key[ $i % $key_length ];
        }

        return $result;
    }

    /**
     * Get encryption key from WordPress salts
     *
     * @return string
     */
    private function get_encryption_key(): string {
        $key_parts = [
            defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bz_default_key_1',
            defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'bz_default_key_2',
        ];

        return hash( 'sha256', implode( '', $key_parts ), true );
    }

    /**
     * Get masked API secret for display
     *
     * @return string
     */
    public function get_masked_secret(): string {
        $secret = $this->get_api_secret();

        if ( empty( $secret ) || strlen( $secret ) < 8 ) {
            return '********';
        }

        return substr( $secret, 0, 4 ) . '...' . substr( $secret, -4 );
    }

    /**
     * Test the current connection
     *
     * Sends a simple health report to verify credentials work.
     *
     * @return array|WP_Error Result array or WP_Error on failure.
     */
    public function test_connection() {
        if ( ! $this->is_connected() ) {
            return new \WP_Error( 'bz_not_connected', __( 'No connection configured.', 'hubbee' ) );
        }

        $site_id    = $this->get_saas_site_id();
        $api_secret = $this->get_api_secret();
        $endpoint   = $this->get_saas_endpoint() . '/functions/v1/health-report';

        // Build minimal health payload
        $body = wp_json_encode( [
            'status'         => 'ok',
            'online'         => true,
            'plugin_version' => defined( 'BZ_VERSION' ) ? BZ_VERSION : '2.0.0',
        ] );

        $timestamp = time();
        $signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $api_secret );

        $response = wp_remote_post(
            $endpoint,
            [
                'headers' => [
                    'Content-Type'        => 'application/json',
                    'X-Hubbee-Signature'  => $signature,
                    'X-Hubbee-Timestamp'  => (string) $timestamp,
                    'X-Hubbee-Site-Id'    => $site_id,
                ],
                'body'    => $body,
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return new \WP_Error(
                'bz_connection_error',
                sprintf(
                    /* translators: %s: error message */
                    __( 'Connection error: %s', 'hubbee' ),
                    $response->get_error_message()
                )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body_response = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code === 200 && ! empty( $body_response['success'] ) ) {
            return [
                'success' => true,
                'status'  => $body_response['connection_status'] ?? 'online',
                'message' => __( 'Connection is working.', 'hubbee' ),
            ];
        }

        if ( $status_code === 401 ) {
            return new \WP_Error( 'bz_auth_failed', __( 'Authentication failed. Invalid API key.', 'hubbee' ) );
        }

        return new \WP_Error(
            'bz_test_failed',
            sprintf(
                /* translators: %d: HTTP status code */
                __( 'Connection test failed (HTTP %d).', 'hubbee' ),
                $status_code
            )
        );
    }
}
