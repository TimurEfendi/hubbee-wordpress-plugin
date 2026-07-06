<?php
/**
 * Heartbeat Scheduler
 *
 * Lightweight "I'm alive" ping to the SaaS.
 * Sends GET /heartbeat with JWT auth — no body, single DB update.
 * Separated from HealthScheduler which collects wp_version, php_version, etc.
 *
 * @package Hubbee\Health
 */

namespace Hubbee\Health;

use Hubbee\SaaS\ConnectionManager;
use Hubbee\SaaS\ModeConfig;
use Hubbee\Security\JwtGenerator;

class HeartbeatScheduler {

    /**
     * WP-Cron hook name
     */
    const HOOK = 'hubbee_heartbeat';

    /**
     * Custom cron schedule name
     */
    const SCHEDULE = 'hubbee_heartbeat_interval';

    /**
     * Default interval in seconds (5 minutes)
     */
    const DEFAULT_INTERVAL = 300;

    /**
     * Max consecutive failures before extended backoff
     */
    const MAX_FAILURES = 5;

    /**
     * Initialize the scheduler
     *
     * Skips scheduling entirely when the `hubbee_heartbeat_enabled` filter
     * returns false — gives site owners a single, documented opt-out for the
     * outbound telemetry without disconnecting from Hubbee Cloud.
     */
    public function init(): void {
        /**
         * Filter: hubbee_heartbeat_enabled
         *
         * Return false to fully disable the heartbeat (no scheduling, no calls).
         * Default: true. Documented in readme.txt FAQ.
         *
         * @param bool $enabled Whether heartbeat should run.
         */
        if ( ! apply_filters( 'hubbee_heartbeat_enabled', true ) ) {
            // Also clear an existing cron entry from prior plugin versions so
            // the opt-out is immediate, not delayed to the next cron tick.
            self::unschedule();
            return;
        }

        add_filter( 'cron_schedules', [ $this, 'register_interval' ] );
        add_action( self::HOOK, [ $this, 'send_heartbeat' ] );

        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 30, self::SCHEDULE, self::HOOK );
        }
    }

    /**
     * Register custom cron interval
     *
     * @param array $schedules Existing schedules.
     * @return array Modified schedules.
     */
    public function register_interval( array $schedules ): array {
        $interval = self::get_interval();

        $schedules[ self::SCHEDULE ] = [
            'interval' => $interval,
            'display'  => sprintf( 'Every %d seconds (Hubbee Heartbeat)', $interval ),
        ];

        return $schedules;
    }

    /**
     * Get the configured heartbeat interval
     *
     * @return int Interval in seconds.
     */
    public static function get_interval(): int {
        $interval = (int) get_option( 'bz_heartbeat_interval', self::DEFAULT_INTERVAL );
        return max( 60, $interval ); // Minimum 60 seconds
    }

    /**
     * Send heartbeat ping
     */
    public function send_heartbeat(): void {
        // Late opt-out: even if the cron event was scheduled before the filter
        // was added (e.g. via mu-plugin loaded after this scheduler), bail here.
        if ( ! apply_filters( 'hubbee_heartbeat_enabled', true ) ) {
            return;
        }

        // Check backoff from consecutive failures
        if ( get_transient( 'bz_heartbeat_backoff' ) ) {
            return;
        }

        $connection = new ConnectionManager();
        if ( ! $connection->is_connected() ) {
            return;
        }

        $jwt = JwtGenerator::get_instance();
        $auth_header = $jwt->get_auth_header();
        if ( empty( $auth_header ) ) {
            $this->record_failure();
            return;
        }

        $config = ModeConfig::get_instance();
        $endpoint = $config->get_m2m_api_url() . '/heartbeat';

        $response = wp_remote_get(
            $endpoint,
            [
                'headers' => [
                    'Authorization' => $auth_header,
                ],
                'timeout' => 10,
            ]
        );

        if ( is_wp_error( $response ) ) {
            $this->record_failure();
            return;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status_code ) {
            $this->record_failure();
            return;
        }

        // Success — reset failure counter
        $failures = (int) get_option( 'bz_heartbeat_failures', 0 );
        if ( $failures > 0 ) {
            delete_option( 'bz_heartbeat_failures' );
            delete_transient( 'bz_heartbeat_backoff' );
        }
    }

    /**
     * Record a failure and apply exponential backoff
     */
    private function record_failure(): void {
        $failures = (int) get_option( 'bz_heartbeat_failures', 0 );
        $failures++;
        update_option( 'bz_heartbeat_failures', $failures );

        if ( $failures >= self::MAX_FAILURES ) {
            // 4x interval backoff, max 30 minutes
            $backoff = min( self::get_interval() * 4, 1800 );
            set_transient( 'bz_heartbeat_backoff', true, $backoff );
        } elseif ( $failures >= 3 ) {
            // 2x interval backoff
            $backoff = min( self::get_interval() * 2, 1800 );
            set_transient( 'bz_heartbeat_backoff', true, $backoff );
        }
    }

    /**
     * Reschedule with updated interval
     *
     * Called when CommandPoller detects a new heartbeat_interval_seconds from SaaS.
     */
    public static function reschedule(): void {
        wp_clear_scheduled_hook( self::HOOK );
        wp_schedule_event( time() + 30, self::SCHEDULE, self::HOOK );
    }

    /**
     * Schedule on activation
     */
    public static function schedule(): void {
        add_filter( 'cron_schedules', [ __CLASS__, 'register_interval_static' ] );
        self::unschedule();
        wp_schedule_event( time() + 30, self::SCHEDULE, self::HOOK );
    }

    /**
     * Static version of register_interval for activation context
     *
     * @param array $schedules Existing schedules.
     * @return array Modified schedules.
     */
    public static function register_interval_static( array $schedules ): array {
        $interval = self::get_interval();

        $schedules[ self::SCHEDULE ] = [
            'interval' => $interval,
            'display'  => sprintf( 'Every %d seconds (Hubbee Heartbeat)', $interval ),
        ];

        return $schedules;
    }

    /**
     * Unschedule on deactivation
     */
    public static function unschedule(): void {
        wp_clear_scheduled_hook( self::HOOK );
    }

    /**
     * Get status info
     *
     * @return array Status information.
     */
    public static function get_status(): array {
        return [
            'hook'     => self::HOOK,
            'interval' => self::get_interval(),
            'next'     => wp_next_scheduled( self::HOOK ),
            'failures' => (int) get_option( 'bz_heartbeat_failures', 0 ),
        ];
    }
}
