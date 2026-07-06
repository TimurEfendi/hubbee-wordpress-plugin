<?php
/**
 * Test Connection Endpoint - Verify SaaS connection
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use Hubbee\SaaS\ConnectionManager;

class TestConnectionEndpoint extends RestEndpoint {

    protected function get_route(): string {
        return '/test-connection';
    }

    protected function get_methods(): string {
        return 'POST';
    }

    public function handle_request( WP_REST_Request $request ) {
        $connection = new ConnectionManager();

        return new WP_REST_Response(
            [
                'success'        => true,
                'message'        => __( 'Connection successful!', 'hubbee' ),
                'site_id'        => get_option( 'bz_site_id', '' ),
                'saas_site_id'   => $connection->get_saas_site_id(),
                'site_name'      => get_bloginfo( 'name' ),
                'site_url'       => home_url(),
                'plugin_version' => BZ_VERSION,
                'wp_version'     => get_bloginfo( 'version' ),
                'php_version'    => PHP_VERSION,
                'timestamp'      => current_time( 'mysql' ),
            ],
            200
        );
    }
}
