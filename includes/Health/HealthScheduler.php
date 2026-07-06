<?php
/**
 * Health Scheduler
 *
 * Manages WP Cron schedules for tiered health reporting.
 * Collects wp_version, php_version, memory, disk, etc. and pushes to SaaS.
 *
 * Heartbeat (lightweight "I'm alive" ping) is handled separately by HeartbeatScheduler.
 *
 * Intervals are dynamic: synced from SaaS via CommandPoller (stored in bz_health_check_interval option).
 *
 * @package Hubbee\Health
 */

namespace Hubbee\Health;

use Hubbee\Agent\EventPusher;

class HealthScheduler {

    /**
     * Cron hook names
     */
    const HOOK_PING = 'hubbee_health_ping';       // Deprecated, kept for cleanup
    const HOOK_SNAPSHOT = 'hubbee_health_snapshot';
    const HOOK_DEEP = 'hubbee_health_deep';

    /**
     * Default intervals in seconds
     */
    const DEFAULT_INTERVAL_SNAPSHOT = 900;   // 15 minutes
    const INTERVAL_DEEP = 86400;             // 24 hours (daily diagnostics)

    /**
     * Health reporter instance
     *
     * @var HealthReporter
     */
    private HealthReporter $reporter;

    /**
     * Constructor
     */
    public function __construct() {
        $this->reporter = new HealthReporter();
    }

    /**
     * Initialize the scheduler
     */
    public function init(): void {
        // Register custom cron intervals
        add_filter( 'cron_schedules', [ $this, 'register_intervals' ] );

        // Register event handlers (ping removed in v2.1.0)
        add_action( self::HOOK_SNAPSHOT, [ $this, 'handle_snapshot' ] );
        add_action( self::HOOK_DEEP, [ $this, 'handle_deep' ] );

        // Ensure events are scheduled
        $this->ensure_scheduled();

        // One-time cleanup: remove deprecated ping schedule if exists
        if ( wp_next_scheduled( self::HOOK_PING ) ) {
            wp_clear_scheduled_hook( self::HOOK_PING );
        }
    }

    /**
     * Get the configured snapshot interval
     *
     * @return int Interval in seconds.
     */
    public static function get_snapshot_interval(): int {
        $interval = (int) get_option( 'bz_health_check_interval', self::DEFAULT_INTERVAL_SNAPSHOT );
        return max( 300, $interval ); // Minimum 5 minutes
    }

    /**
     * Register custom cron intervals
     *
     * @param array $schedules Existing schedules.
     * @return array Modified schedules.
     */
    public function register_intervals( array $schedules ): array {
        $interval = self::get_snapshot_interval();

        $schedules['hubbee_health_snapshot_interval'] = [
            'interval' => $interval,
            'display'  => sprintf( 'Every %d minutes (Hubbee Health)', $interval / 60 ),
        ];

        // Note: Deep uses built-in 'daily' schedule

        return $schedules;
    }

    /**
     * Ensure all health check events are scheduled
     */
    private function ensure_scheduled(): void {
        // Snapshot at dynamic interval — health data collection
        if ( ! wp_next_scheduled( self::HOOK_SNAPSHOT ) ) {
            wp_schedule_event( time() + 60, 'hubbee_health_snapshot_interval', self::HOOK_SNAPSHOT );
        }

        // Deep daily - full diagnostics (memory, disk, plugin updates)
        if ( ! wp_next_scheduled( self::HOOK_DEEP ) ) {
            wp_schedule_event( time() + 120, 'daily', self::HOOK_DEEP );
        }

        // Cleanup: remove legacy 'hubbee_15min' schedule if snapshot is using it
        // (after upgrade, existing sites may have the old schedule name)
    }

    /**
     * Schedule all health check events
     *
     * Called on plugin activation.
     */
    public static function schedule_all(): void {
        // Register custom intervals first (needed for activation context)
        add_filter( 'cron_schedules', [ __CLASS__, 'register_intervals_static' ] );

        // Clear any existing schedules first (includes deprecated ping)
        self::unschedule_all();

        // Schedule with slight offsets to prevent all running at once
        wp_schedule_event( time() + 70, 'hubbee_health_snapshot_interval', self::HOOK_SNAPSHOT );
        wp_schedule_event( time() + 130, 'daily', self::HOOK_DEEP );
    }

    /**
     * Static version of register_intervals for use during activation
     *
     * @param array $schedules Existing schedules.
     * @return array Modified schedules.
     */
    public static function register_intervals_static( array $schedules ): array {
        $interval = self::get_snapshot_interval();

        $schedules['hubbee_health_snapshot_interval'] = [
            'interval' => $interval,
            'display'  => sprintf( 'Every %d minutes (Hubbee Health)', $interval / 60 ),
        ];

        return $schedules;
    }

    /**
     * Reschedule with updated interval
     *
     * Called when CommandPoller detects a new health_check_interval_minutes from SaaS.
     */
    public static function reschedule(): void {
        wp_clear_scheduled_hook( self::HOOK_SNAPSHOT );
        add_filter( 'cron_schedules', [ __CLASS__, 'register_intervals_static' ] );
        wp_schedule_event( time() + 60, 'hubbee_health_snapshot_interval', self::HOOK_SNAPSHOT );
    }

    /**
     * Unschedule all health check events
     *
     * Called on plugin deactivation.
     */
    public static function unschedule_all(): void {
        wp_clear_scheduled_hook( self::HOOK_PING );
        wp_clear_scheduled_hook( self::HOOK_SNAPSHOT );
        wp_clear_scheduled_hook( self::HOOK_DEEP );
    }

    /**
     * Handle snapshot event (at configured interval)
     *
     * Collects health data (wp_version, php_version, etc.) and pushes to SaaS.
     * Heartbeat (lightweight ping) is handled separately by HeartbeatScheduler.
     * Pushes plugin/theme inventory async with 1h debounce.
     */
    public function handle_snapshot(): void {
        // Check if we should throttle due to repeated failures
        if ( $this->reporter->should_throttle() ) {
            return;
        }

        $result = $this->reporter->send_report( HealthReporter::LEVEL_SNAPSHOT );

        // Push plugin/theme lists async with 1h debounce (max 1x/hour instead of 4x)
        if ( true === $result ) {
            $this->push_inventory_debounced();
        }
    }

    /**
     * Handle deep event (daily)
     *
     * Full diagnostics: memory, disk, plugin updates, etc.
     * Deep reports always run, even during throttling.
     * Always pushes inventory (bypasses debounce for daily freshness).
     */
    public function handle_deep(): void {
        $result = $this->reporter->send_report( HealthReporter::LEVEL_DEEP );

        // Deep always pushes inventory (bypass debounce for daily accuracy)
        if ( true === $result ) {
            $pusher = EventPusher::get_instance();
            $pusher->push_plugins_list_async();
            $pusher->push_themes_list_async();
            set_transient( 'bz_inventory_pushed', true, 15 * MINUTE_IN_SECONDS );
        }
    }

    /**
     * Push plugin/theme inventory async with 1h debounce
     *
     * Limits inventory pushes to max 1x/hour (was 4x/hour at every 15min snapshot).
     */
    private function push_inventory_debounced(): void {
        if ( get_transient( 'bz_inventory_pushed' ) ) {
            return;
        }

        $pusher = EventPusher::get_instance();
        $pusher->push_plugins_list_async();
        $pusher->push_themes_list_async();

        set_transient( 'bz_inventory_pushed', true, 15 * MINUTE_IN_SECONDS );
    }

    /**
     * Get next scheduled time for a hook
     *
     * @param string $hook Hook name.
     * @return int|false Unix timestamp or false if not scheduled.
     */
    public static function get_next_scheduled( string $hook ): int|false {
        return wp_next_scheduled( $hook );
    }

    /**
     * Get status of all scheduled health checks
     *
     * @return array Status information.
     */
    public static function get_status(): array {
        return [
            'snapshot' => [
                'hook'     => self::HOOK_SNAPSHOT,
                'interval' => self::get_snapshot_interval(),
                'next'     => self::get_next_scheduled( self::HOOK_SNAPSHOT ),
            ],
            'deep' => [
                'hook'     => self::HOOK_DEEP,
                'interval' => self::INTERVAL_DEEP,
                'next'     => self::get_next_scheduled( self::HOOK_DEEP ),
            ],
        ];
    }

    /**
     * Trigger an immediate health report
     *
     * Manual triggers always push inventory (bypass debounce).
     *
     * @param string $level Report level (snapshot, deep).
     * @return bool|WP_Error
     */
    public function trigger_immediate( string $level = HealthReporter::LEVEL_SNAPSHOT ): bool|\WP_Error {
        $result = $this->reporter->send_report( $level );

        // Manual trigger always pushes inventory (bypass debounce)
        if ( true === $result ) {
            $pusher = EventPusher::get_instance();
            $pusher->push_plugins_list_async();
            $pusher->push_themes_list_async();
            set_transient( 'bz_inventory_pushed', true, 15 * MINUTE_IN_SECONDS );
        }

        return $result;
    }
}
