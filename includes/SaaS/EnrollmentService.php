<?php
/**
 * SaaS Enrollment Service
 *
 * Handles the onboarding flow for connecting WordPress site to SaaS Hub.
 *
 * @package Hubbee\SaaS
 */

namespace Hubbee\SaaS;

use WP_Error;

class EnrollmentService {

    /**
     * SaaS enrollment endpoint path
     */
    const ENROLL_ENDPOINT = '/functions/v1/enroll';

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
     * Enroll this WordPress site with the SaaS Hub
     *
     * @param string $onboarding_token The onboarding token from SaaS dashboard.
     * @return array|WP_Error Array with site_id on success, WP_Error on failure.
     */
    public function enroll( string $onboarding_token ): array|WP_Error {
        if ( empty( $onboarding_token ) ) {
            return new WP_Error(
                'bz_empty_token',
                __( 'Onboarding token is required.', 'hubbee' )
            );
        }

        // Gather site information
        $site_info = $this->get_site_info();

        // Get SaaS endpoint
        $saas_url = $this->connection->get_saas_endpoint();
        $enroll_url = trailingslashit( $saas_url ) . ltrim( self::ENROLL_ENDPOINT, '/' );

        // Idempotency-Key: cached per onboarding-token for 5 minutes so a
        // retry after a network glitch reuses the same key. The Edge Function
        // forwards it to check_rate_limit_atomic, which short-circuits the
        // second request and returns the original response — no double site.
        $idempotency_cache_key = 'hubbee_enroll_idem_' . md5( $onboarding_token );
        $idempotency_key = get_transient( $idempotency_cache_key );
        if ( ! $idempotency_key ) {
            $idempotency_key = wp_generate_uuid4();
            set_transient( $idempotency_cache_key, $idempotency_key, 5 * MINUTE_IN_SECONDS );
        }

        // Make enrollment request
        $response = wp_remote_post(
            $enroll_url,
            [
                'headers' => [
                    'Authorization'     => 'Bearer ' . $onboarding_token,
                    'Content-Type'      => 'application/json',
                    'Accept'            => 'application/json',
                    'X-Idempotency-Key' => $idempotency_key,
                ],
                'body'    => wp_json_encode( $site_info ),
                'timeout' => 30,
            ]
        );

        // Check for connection errors
        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'bz_connection_failed',
                sprintf(
                    /* translators: %s: error message */
                    __( 'Failed to connect to SaaS: %s', 'hubbee' ),
                    $response->get_error_message()
                )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        // Handle error responses
        if ( $status_code !== 200 && $status_code !== 201 ) {
            return $this->handle_error_response( $status_code, $data );
        }

        // Validate response structure
        if ( empty( $data['site_id'] ) || empty( $data['api_secret'] ) ) {
            return new WP_Error(
                'bz_invalid_response',
                __( 'Invalid response from SaaS. Missing site_id or api_secret.', 'hubbee' )
            );
        }

        // Store credentials
        $this->connection->store_credentials(
            $data['site_id'],
            $data['api_secret'],
            $data['site_name'] ?? ''
        );

        // Persist the M2M API endpoint when SaaS pins one in the enroll response.
        // ModeConfig::get_m2m_api_url() reads `bz_m2m_api_endpoint` to route
        // heartbeat / site-commands / command-result / event-receiver to the
        // Hetzner API Server, sparing the Supabase Edge Function quota at
        // AppSumo-scale. Existing sites stay on Supabase Edge Functions
        // because the option is only written on (re-)enroll.
        if ( ! empty( $data['m2m_api_endpoint'] ) && is_string( $data['m2m_api_endpoint'] ) ) {
            $endpoint = rtrim( $data['m2m_api_endpoint'], '/' );
            // SSRF guard: the endpoint comes from the (network-controlled) enroll
            // response, so it must resolve to allowed Hubbee infrastructure before
            // we route signed M2M traffic to it. An invalid value is ignored and
            // we fall back to the default Supabase endpoint.
            if ( ModeConfig::get_instance()->is_allowed_m2m_host( $endpoint ) ) {
                update_option( 'bz_m2m_api_endpoint', $endpoint );
            } else {
                delete_option( 'bz_m2m_api_endpoint' );
                error_log( sprintf(
                    '[Hubbee Enrollment] Ignored disallowed m2m_api_endpoint host: %s',
                    (string) ( wp_parse_url( $endpoint, PHP_URL_HOST ) ?: 'unparseable' )
                ) );
            }
        }

        // NOTE: initial_commands are NOT executed here anymore.
        // The SaaS frontend now uses snapshot-pull to fetch data directly,
        // which is faster and more reliable than waiting for WordPress to push.
        // This makes enrollment instantaneous (<1s).

        /**
         * Fires after successful SaaS enrollment
         *
         * @param string $site_id The SaaS site ID.
         * @param array  $data    Full response data from SaaS.
         */
        do_action( 'hubbee_enrolled', $data['site_id'], $data );

        return [
            'success'   => true,
            'site_id'   => $data['site_id'],
            'site_name' => $data['site_name'] ?? '',
            'message'   => __( 'Successfully connected to Hubbee!', 'hubbee' ),
        ];
    }

    /**
     * Disconnect from SaaS
     *
     * @return array
     */
    public function disconnect(): array {
        $this->connection->disconnect();

        return [
            'success' => true,
            'message' => __( 'Disconnected from Hubbee.', 'hubbee' ),
        ];
    }

    /**
     * Get site information for enrollment
     *
     * @return array
     */
    private function get_site_info(): array {
        return [
            'name'           => get_bloginfo( 'name' ),
            'url'            => home_url(),
            'rest_url'       => rest_url( 'bz/v1/' ),
            'admin_email'    => get_option( 'admin_email' ),
            'wp_version'     => get_bloginfo( 'version' ),
            'php_version'    => PHP_VERSION,
            'plugin_version' => BZ_VERSION,
            'locale'         => get_locale(),
            'timezone'       => wp_timezone_string(),
            'local_site_id'  => get_option( 'bz_site_id', '' ),
        ];
    }

    /**
     * Handle error response from SaaS
     *
     * @param int        $status_code HTTP status code.
     * @param array|null $data        Response data.
     * @return WP_Error
     */
    private function handle_error_response( int $status_code, ?array $data ): WP_Error {
        $error_code = $data['error'] ?? 'bz_unknown_error';
        $error_message = $data['message'] ?? '';

        switch ( $status_code ) {
            case 401:
                return new WP_Error(
                    'bz_invalid_token',
                    $error_message ?: __( 'Invalid or expired onboarding token.', 'hubbee' )
                );

            case 403:
                return new WP_Error(
                    'bz_forbidden',
                    $error_message ?: __( 'Access denied. Please check your subscription.', 'hubbee' )
                );

            case 404:
                return new WP_Error(
                    'bz_not_found',
                    $error_message ?: __( 'Enrollment endpoint not found. Please check the SaaS URL.', 'hubbee' )
                );

            case 409:
                return new WP_Error(
                    'bz_already_enrolled',
                    $error_message ?: __( 'This site is already enrolled.', 'hubbee' )
                );

            case 422:
                return new WP_Error(
                    'bz_validation_error',
                    $error_message ?: __( 'Invalid site information provided.', 'hubbee' )
                );

            case 429:
                return new WP_Error(
                    'bz_rate_limited',
                    $error_message ?: __( 'Too many requests. Please try again later.', 'hubbee' )
                );

            case 500:
            case 502:
            case 503:
                return new WP_Error(
                    'bz_server_error',
                    $error_message ?: __( 'SaaS server error. Please try again later.', 'hubbee' )
                );

            default:
                return new WP_Error(
                    $error_code,
                    $error_message ?: sprintf(
                        /* translators: %d: HTTP status code */
                        __( 'Enrollment failed with status code %d.', 'hubbee' ),
                        $status_code
                    )
                );
        }
    }

}
