<?php
/**
 * Interval Sync
 *
 * Single place that applies SaaS-provided monitoring intervals, no matter
 * which response carried them (heartbeat response, command poll, manual
 * poll). The SaaS derives the values from the workspace's plan.
 *
 * Semantics:
 * - key absent      → no-op (older servers don't send intervals)
 * - explicit 0      → plan disables the scheduler: store 0 + unschedule
 * - positive value  → store + reschedule when changed (scheduler floors apply)
 *
 * @package Hubbee\Health
 */

namespace Hubbee\Health;

class IntervalSync {

    /**
     * Option names (shared with the schedulers).
     */
    const OPTION_HEARTBEAT = 'bz_heartbeat_interval';
    const OPTION_HEALTH    = 'bz_health_check_interval';

    /**
     * Apply monitoring intervals from a decoded SaaS response body.
     *
     * @param array $body Decoded JSON response body.
     */
    public static function apply( array $body ): void {
        self::apply_heartbeat( $body );
        self::apply_health( $body );
    }

    /**
     * @param array $body Decoded JSON response body.
     */
    private static function apply_heartbeat( array $body ): void {
        if ( ! array_key_exists( 'heartbeat_interval_seconds', $body ) ) {
            return;
        }

        $new = (int) $body['heartbeat_interval_seconds'];
        $old = (int) get_option( self::OPTION_HEARTBEAT, HeartbeatScheduler::DEFAULT_INTERVAL );

        if ( 0 === $new ) {
            if ( 0 !== $old || wp_next_scheduled( HeartbeatScheduler::HOOK ) ) {
                update_option( self::OPTION_HEARTBEAT, 0, 'no' );
                HeartbeatScheduler::unschedule();
            }
            return;
        }

        if ( $new > 0 && $new !== $old ) {
            update_option( self::OPTION_HEARTBEAT, $new, 'no' );
            HeartbeatScheduler::reschedule();
        }
    }

    /**
     * @param array $body Decoded JSON response body.
     */
    private static function apply_health( array $body ): void {
        if ( ! array_key_exists( 'health_check_interval_minutes', $body ) ) {
            return;
        }

        $new_sec = (int) $body['health_check_interval_minutes'] * 60;
        $old_sec = (int) get_option( self::OPTION_HEALTH, HealthScheduler::DEFAULT_INTERVAL_SNAPSHOT );

        if ( 0 === $new_sec ) {
            if ( 0 !== $old_sec || wp_next_scheduled( HealthScheduler::HOOK_SNAPSHOT ) ) {
                update_option( self::OPTION_HEALTH, 0, 'no' );
                HealthScheduler::unschedule_snapshot();
            }
            return;
        }

        if ( $new_sec > 0 && $new_sec !== $old_sec ) {
            update_option( self::OPTION_HEALTH, $new_sec, 'no' );
            HealthScheduler::reschedule();
        }
    }
}
