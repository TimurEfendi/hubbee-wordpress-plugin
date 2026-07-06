<?php
/**
 * Agent Manager - Initialize Agent functionality
 *
 * @package Hubbee\Agent
 */

namespace Hubbee\Agent;

use Hubbee\Agent\CommandPoller;
use Hubbee\Agent\EventPusher;
use Hubbee\SaaS\ConnectionManager;

class AgentManager {

    /**
     * Token service instance
     *
     * @var TokenService
     */
    private TokenService $token_service;

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
        $this->token_service = new TokenService();
        $this->connection = new ConnectionManager();
    }

    /**
     * Initialize Agent functionality
     */
    public function init(): void {
        // Register cache purge hooks
        $this->register_cache_hooks();

        // Add template functions
        $this->register_template_functions();

        // Initialize CommandPoller for SaaS command polling (only if connected)
        if ( $this->connection->is_connected() ) {
            CommandPoller::get_instance()->init();

            // Initialize EventPusher for realtime events (plugin/theme changes)
            EventPusher::get_instance()->init();
        }
    }

    /**
     * Register cache purge hooks for popular caching plugins
     */
    private function register_cache_hooks(): void {
        add_action( 'hubbee_cache_purge_needed', [ $this, 'trigger_cache_purge' ] );
    }

    /**
     * Trigger cache purge for various caching plugins
     */
    public function trigger_cache_purge(): void {
        // WP Super Cache
        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            wp_cache_clear_cache();
        }

        // W3 Total Cache
        if ( function_exists( 'w3tc_flush_all' ) ) {
            w3tc_flush_all();
        }

        // WP Fastest Cache
        if ( function_exists( 'wpfc_clear_all_cache' ) ) {
            wpfc_clear_all_cache();
        }

        // LiteSpeed Cache — action hook + direct API for max compatibility
        do_action( 'litespeed_purge_all' );
        if ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'purge_all' ) ) {
            \LiteSpeed_Cache_API::purge_all();
        }

        // Cloudflare
        if ( class_exists( 'CF\WordPress\Hooks' ) ) {
            do_action( 'cloudflare_purge_everything' );
        }

        // WP Rocket
        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }

        // Autoptimize
        if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
            \autoptimizeCache::clearall();
        }

        // Elementor CSS cache
        if ( did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) ) {
            \Elementor\Plugin::instance()->files_manager->clear_cache();
        }

        // Generic object cache flush
        wp_cache_flush();

        /**
         * Fires after cache purge is triggered
         *
         * Hook into this action to add support for additional caching plugins.
         */
        do_action( 'hubbee_after_cache_purge' );
    }

    /**
     * Register template functions
     */
    private function register_template_functions(): void {
        // Template functions are available via the TokenService
        // This is placeholder for any future template function registration
    }

    /**
     * Get token service
     *
     * @return TokenService
     */
    public function get_token_service(): TokenService {
        return $this->token_service;
    }

    /**
     * Get connection manager
     *
     * @return ConnectionManager
     */
    public function get_connection_manager(): ConnectionManager {
        return $this->connection;
    }

    /**
     * Get agent status information
     *
     * @return array
     */
    public function get_status(): array {
        $connection_status = $this->connection->get_connection_status();
        $token_count = $this->token_service->get_token_count();

        return [
            'site_id'        => get_option( 'bz_site_id', '' ),
            'saas_site_id'   => $connection_status['site_id'],
            'site_name'      => get_bloginfo( 'name' ),
            'site_url'       => home_url(),
            'rest_url'       => rest_url( 'bz/v1/' ),
            'connected'      => $connection_status['connected'],
            'token_count'    => $token_count,
            'plugin_version' => BZ_VERSION,
            'last_push'      => get_option( 'bz_last_push_received', '' ),
            'enrolled_at'    => $connection_status['enrolled_at'],
        ];
    }

    /**
     * Check if agent is properly configured
     *
     * @return array Status with issues.
     */
    public function check_configuration(): array {
        $issues = [];

        // Check if connected to SaaS
        if ( ! $this->connection->is_connected() ) {
            $issues[] = [
                'type'    => 'warning',
                'message' => __( 'Not connected to Hubbee. Connect your site to receive token updates.', 'hubbee' ),
            ];
        }

        // Check REST API availability
        if ( ! $this->is_rest_api_available() ) {
            $issues[] = [
                'type'    => 'error',
                'message' => __( 'REST API is not available. Hubbee requires the WordPress REST API.', 'hubbee' ),
            ];
        }

        // Check permalinks
        if ( ! get_option( 'permalink_structure' ) ) {
            $issues[] = [
                'type'    => 'warning',
                'message' => __( 'Pretty permalinks are not enabled. REST API will use query string format.', 'hubbee' ),
            ];
        }

        // Check for SSL
        if ( ! is_ssl() ) {
            $issues[] = [
                'type'    => 'warning',
                'message' => __( 'HTTPS is recommended for secure communication with Hubbee.', 'hubbee' ),
            ];
        }

        return [
            'is_ok'  => empty( array_filter( $issues, fn( $i ) => 'error' === $i['type'] ) ),
            'issues' => $issues,
        ];
    }

    /**
     * Check if REST API is available
     *
     * @return bool
     */
    private function is_rest_api_available(): bool {
        return ! ( defined( 'REST_API_DISABLED' ) && REST_API_DISABLED );
    }
}
