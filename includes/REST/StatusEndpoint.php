<?php
/**
 * Status Endpoint - Return detailed site status
 *
 * IMPORTANT: This endpoint requires authentication (Bearer token).
 * For public connectivity checks, use /ping instead.
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use Hubbee\Agent\AgentManager;
use Hubbee\SaaS\ConnectionManager;
use Hubbee\Security\Authenticator;

class StatusEndpoint extends RestEndpoint {

    protected function get_route(): string {
        return '/status';
    }

    protected function get_methods(): string {
        return 'GET';
    }

    /** Bearer-token (api_secret) auth for the detailed status payload. */
    protected function get_permission_callback(): callable {
        return [ Authenticator::class, 'authenticate_bearer' ];
    }

    public function handle_request( WP_REST_Request $request ) {
        $agent = new AgentManager();
        $connection = new ConnectionManager();
        $status = $agent->get_status();

        // Full status info (authenticated request)
        $full_status = [
            'site_id'        => get_option( 'bz_site_id', '' ),
            'plugin_version' => BZ_VERSION,
            'rest_url'       => RestController::get_rest_url(),
            'connected'      => $connection->is_connected(),
            'token_count'    => $status['token_count'] ?? 0,
            'capabilities'   => $this->get_capabilities(),
            'wordpress'      => $this->get_wordpress_info(),
            'php'            => $this->get_php_info(),
            'health'         => $this->get_health_summary(),
            'cron'           => $this->get_cron_summary(),
        ];

        return new WP_REST_Response( $full_status, 200 );
    }

    /**
     * Get cron health summary so the SaaS can detect stuck/zombie scheduling.
     *
     * @return array
     */
    private function get_cron_summary(): array {
        return [
            'wp_cron_disabled' => \Hubbee\Health\CronResilience::wp_cron_disabled(),
            'has_overdue'      => \Hubbee\Health\CronResilience::has_overdue_hook(),
            'hooks'            => \Hubbee\Health\CronResilience::cron_health(),
        ];
    }

    /**
     * Get supported capabilities
     *
     * @return array
     */
    private function get_capabilities(): array {
        return [
            'push',
            'test-connection',
            'versioning',
            'idempotency',
            'multi-locale',
        ];
    }

    /**
     * Get WordPress information
     *
     * @return array
     */
    private function get_wordpress_info(): array {
        global $wp_version;

        return [
            'version'   => $wp_version,
            'multisite' => is_multisite(),
            'locale'    => get_locale(),
            'timezone'  => wp_timezone_string(),
        ];
    }

    /**
     * Get PHP information
     *
     * @return array
     */
    private function get_php_info(): array {
        return [
            'version'      => PHP_VERSION,
            'memory_limit' => ini_get( 'memory_limit' ),
            'sapi'         => php_sapi_name(),
        ];
    }

    /**
     * Get health summary
     *
     * @return array
     */
    private function get_health_summary(): array {
        // Memory usage
        $memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        $memory_used = memory_get_usage( true );

        // Plugin/theme updates
        $update_plugins = get_site_transient( 'update_plugins' );
        $update_themes = get_site_transient( 'update_themes' );

        $plugin_updates = 0;
        if ( $update_plugins && ! empty( $update_plugins->response ) ) {
            $plugin_updates = count( $update_plugins->response );
        }

        $theme_updates = 0;
        if ( $update_themes && ! empty( $update_themes->response ) ) {
            $theme_updates = count( $update_themes->response );
        }

        return [
            'memory_percent'  => $memory_limit > 0 ? round( ( $memory_used / $memory_limit ) * 100, 1 ) : 0,
            'plugin_updates'  => $plugin_updates,
            'theme_updates'   => $theme_updates,
            'debug_enabled'   => defined( 'WP_DEBUG' ) && WP_DEBUG,
        ];
    }
}
