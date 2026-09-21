<?php
/**
 * Enroll Endpoint - Initiate SaaS enrollment from admin
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Hubbee\SaaS\EnrollmentService;
use Hubbee\Security\Capabilities;

class EnrollEndpoint extends RestEndpoint {

    protected function get_routes(): array {
        return [
            [ 'route' => '/enroll',     'methods' => 'POST', 'callback' => 'handle_request',    'args' => $this->get_args() ],
            [ 'route' => '/disconnect', 'methods' => 'POST', 'callback' => 'handle_disconnect' ],
        ];
    }

    /**
     * Admin-only — both enroll and disconnect mutate plugin-wide credentials,
     * so HMAC isn't appropriate here (no SaaS in the loop yet).
     */
    protected function get_permission_callback(): callable {
        return [ $this, 'check_admin_permission' ];
    }

    public function check_admin_permission( WP_REST_Request $request ) {
        unset( $request );
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'bz_forbidden',
                __( 'You do not have permission to perform this action.', 'hubbee' ),
                [ 'status' => 403 ]
            );
        }
        return true;
    }

    /**
     * Handle the enrollment request
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_request( WP_REST_Request $request ) {
        $onboarding_token = $request->get_param( 'onboarding_token' );

        if ( empty( $onboarding_token ) ) {
            return new WP_Error(
                'bz_missing_token',
                __( 'Onboarding token is required.', 'hubbee' ),
                [ 'status' => 400 ]
            );
        }

        // Same visitor-analytics decision the admin connect screen records, so
        // this route cannot enrol a site into a different state than the UI
        // would. The arg schema supplies the documented default when omitted.
        $analytics_consent = (bool) $request->get_param( 'analytics_consent' );

        $enrollment = new EnrollmentService();
        $result = $enrollment->enroll( $onboarding_token, $analytics_consent );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * Handle the disconnect request
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function handle_disconnect( WP_REST_Request $request ): WP_REST_Response {
        $enrollment = new EnrollmentService();
        $result = $enrollment->disconnect();

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * Get endpoint arguments schema
     *
     * @return array
     */
    protected function get_args(): array {
        return [
            'onboarding_token' => [
                'description'       => __( 'The onboarding token from the SaaS dashboard.', 'hubbee' ),
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'analytics_consent' => [
                'description' => __( 'Whether this site should send visitor analytics to Hubbee. Defaults to enabled.', 'hubbee' ),
                'type'        => 'boolean',
                'required'    => false,
                'default'     => true,
            ],
        ];
    }
}
