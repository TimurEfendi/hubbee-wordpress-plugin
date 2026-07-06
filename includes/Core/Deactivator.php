<?php
/**
 * Plugin Deactivator
 *
 * @package Hubbee\Core
 */

namespace Hubbee\Core;

class Deactivator {

    /**
     * Run deactivation tasks
     *
     * Note: We don't remove database tables or options on deactivation.
     * This is done in uninstall.php if the user truly wants to remove the plugin.
     */
    public static function deactivate(): void {
        // Clear scheduled events if any
        self::clear_scheduled_events();

        // Clear transients
        self::clear_transients();

        // Remove CORS rules from uploads .htaccess
        UploadsCors::remove();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Clear any scheduled cron events
     */
    private static function clear_scheduled_events(): void {
        // Legacy hooks (v1.x) - cleanup for older installations
        wp_clear_scheduled_hook( 'bz_token_sync' );
        wp_clear_scheduled_hook( 'bz_cleanup_logs' );

        // Deprecated hook (v2.1.0) - 5-min ping removed
        wp_clear_scheduled_hook( 'hubbee_health_ping' );

        // Clear command poll schedule (otherwise hubbee_poll_commands leaks)
        \Hubbee\Agent\CommandPoller::clear_scheduled_cron();

        // Clear heartbeat schedule
        \Hubbee\Health\HeartbeatScheduler::unschedule();

        // Clear active health check schedules (snapshot, deep)
        \Hubbee\Health\HealthScheduler::unschedule_all();
    }

    /**
     * Clear plugin transients
     */
    private static function clear_transients(): void {
        global $wpdb;

        // Delete all transients with our prefix
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bz_%' OR option_name LIKE '_transient_timeout_bz_%'"
        );
        // phpcs:enable
    }
}
