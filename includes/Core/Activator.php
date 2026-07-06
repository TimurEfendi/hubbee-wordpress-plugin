<?php
/**
 * Plugin Activator
 *
 * @package Hubbee\Core
 */

namespace Hubbee\Core;

use Hubbee\Storage\Database;
use Hubbee\Security\Capabilities;

class Activator {

    /**
     * Run activation tasks
     */
    public static function activate(): void {
        // Create database tables
        Database::create_tables();

        // Set up capabilities
        Capabilities::add_capabilities();

        // Set default options
        self::set_default_options();

        // Generate unique site ID if not exists
        self::generate_site_id();

        // Migrate from HubText if it exists
        Migrator::migrate_from_hubtext();

        // Write CORS headers for uploads directory (.htaccess)
        UploadsCors::ensure();

        // Invalidate OPcache for all plugin PHP files (ensures new code is active after update)
        if ( function_exists( 'opcache_invalidate' ) ) {
            $plugin_dir = defined( 'BZ_PLUGIN_DIR' ) ? BZ_PLUGIN_DIR : plugin_dir_path( __DIR__ . '/../../hubbee.php' );
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $plugin_dir, \FilesystemIterator::SKIP_DOTS )
            );
            foreach ( $iterator as $file ) {
                if ( $file->getExtension() === 'php' ) {
                    opcache_invalidate( $file->getPathname(), true );
                }
            }
        }

        // Clear any cached data
        wp_cache_flush();

        // Schedule heartbeat + health check cron jobs
        \Hubbee\Health\HeartbeatScheduler::schedule();
        \Hubbee\Health\HealthScheduler::schedule_all();

        // Set activation flag for welcome notice
        set_transient( 'bz_activated', true, 30 );
    }

    /**
     * Set default plugin options
     *
     * Note: The SaaS endpoint is now an immutable constant in ConnectionManager.
     * Any existing bz_saas_endpoint option is ignored (kept for rollback compatibility).
     */
    private static function set_default_options(): void {
        // Endpoint handling removed - now hardcoded in ConnectionManager::SAAS_ENDPOINT
        // No other default options needed at this time.
    }

    /**
     * Generate unique site identifier
     */
    private static function generate_site_id(): void {
        if ( false === get_option( 'bz_site_id' ) ) {
            $site_id = wp_generate_uuid4();
            add_option( 'bz_site_id', $site_id );
        }
    }
}
