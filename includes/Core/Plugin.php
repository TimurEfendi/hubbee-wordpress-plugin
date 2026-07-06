<?php
/**
 * Main Plugin Class
 *
 * @package Hubbee\Core
 */

namespace Hubbee\Core;

use Hubbee\Admin\AdminController;
use Hubbee\REST\RestController;
use Hubbee\REST\CorsHandler;
use Hubbee\Security\Capabilities;
use Hubbee\Agent\AgentManager;
use Hubbee\Analytics\Tracker;

class Plugin {

    /**
     * Singleton instance
     *
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;

    /**
     * Get singleton instance
     *
     * @return Plugin
     */
    public static function get_instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton
     */
    private function __construct() {
        // No mode switching - always agent mode
    }

    /**
     * Run the plugin
     */
    public function run(): void {
        // Auto-upgrade: Ensure database tables exist
        // This handles the case where the plugin was updated via FTP without re-activation
        if ( \Hubbee\Storage\Database::needs_upgrade() ) {
            \Hubbee\Storage\Database::create_tables();
        }

        // Ensure CORS headers for uploads (retroactive for existing installs)
        if ( is_admin() ) {
            UploadsCors::ensure();
            UploadsCors::maybe_show_nginx_notice();
        }

        // Initialize capabilities
        $capabilities = new Capabilities();
        $capabilities->init();

        // Initialize CORS support (must be before REST API)
        $cors = new CorsHandler();
        $cors->init();

        // Initialize REST API
        $rest = new RestController();
        $rest->init();

        // Initialize Admin
        if ( is_admin() ) {
            $admin = new AdminController();
            $admin->init();
        }

        // Initialize Agent functionality (always active)
        $agent = new AgentManager();
        $agent->init();

        // Content-token frontend rendering ([bz_text] shortcode + hubbee/token
        // block) — works without Elementor Pro. Always active.
        $token_shortcode = new \Hubbee\Frontend\TokenShortcode();
        $token_shortcode->init();

        // Initialize Health Monitoring and Analytics Tracking (only if connected to SaaS)
        $connection = new \Hubbee\SaaS\ConnectionManager();
        if ( $connection->is_connected() ) {
            $heartbeat_scheduler = new \Hubbee\Health\HeartbeatScheduler();
            $heartbeat_scheduler->init();

            $health_scheduler = new \Hubbee\Health\HealthScheduler();
            $health_scheduler->init();

            $health_events = new \Hubbee\Health\HealthEventHandler();
            $health_events->init();

            // WP-Cron safety net: admin notice + throttled loopback when
            // DISABLE_WP_CRON is set and our hooks are overdue. Self-disables
            // when a real server cron keeps hooks current.
            $cron_resilience = new \Hubbee\Health\CronResilience();
            $cron_resilience->init();

            // Flush batched agent errors to the SaaS on shutdown (sampled,
            // non-blocking) for fleet-wide error visibility.
            add_action( 'shutdown', [ \Hubbee\Agent\ErrorReporter::get_instance(), 'flush' ], 1000 );

            // Initialize Analytics Tracking on frontend
            $tracker = new Tracker();
            $tracker->init();
        }

        // Initialize Elementor integration if Elementor is active
        add_action( 'elementor/init', [ $this, 'init_elementor' ] );
    }

    /**
     * Initialize Elementor integration
     */
    public function init_elementor(): void {
        if ( did_action( 'elementor/loaded' ) ) {
            $elementor = new \Hubbee\Elementor\ElementorManager();
            $elementor->init();
        }
    }

    /**
     * Get plugin version
     *
     * @return string
     */
    public function get_version(): string {
        return BZ_VERSION;
    }
}
