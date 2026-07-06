<?php
/**
 * Cron Resilience
 *
 * The plugin's connectivity (heartbeat, command poll, health snapshot) rides on
 * WP-Cron, which only fires on traffic. On sites with `DISABLE_WP_CRON` set and
 * no real server cron, those hooks silently stop firing and the SaaS marks the
 * site offline even though it is healthy.
 *
 * This class provides a safety net WITHOUT changing the deterministic-server-cron
 * recommendation (CLAUDE.md §9):
 *   1. An admin notice when WP-Cron is disabled, pointing to a real cron setup.
 *   2. A throttled loopback that re-triggers wp-cron.php — but ONLY when a Hubbee
 *      hook is genuinely overdue (so it self-disables when a real server cron is
 *      keeping the site current) and only when `DISABLE_WP_CRON` is set.
 *   3. A `cron_health()` snapshot the StatusEndpoint / health report can surface.
 *
 * @package Hubbee\Health
 */

namespace Hubbee\Health;

use Hubbee\Agent\CommandPoller;

class CronResilience {

    /**
     * Throttle key — at most one loopback spawn per minute regardless of traffic.
     */
    const LOOPBACK_THROTTLE = 'bz_cron_loopback_throttle';

    /**
     * Seconds a hook may run late before it is considered stuck/overdue.
     */
    const OVERDUE_MARGIN = 300;

    /**
     * Critical hooks whose health reflects SaaS connectivity.
     *
     * @return string[]
     */
    public static function critical_hooks(): array {
        return [
            HeartbeatScheduler::HOOK,
            HealthScheduler::HOOK_SNAPSHOT,
            CommandPoller::CRON_HOOK,
        ];
    }

    /**
     * Register the safety-net hooks. Call only when the site is connected.
     */
    public function init(): void {
        add_action( 'shutdown', [ $this, 'maybe_spawn_loopback' ], 999 );

        if ( is_admin() ) {
            add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
        }
    }

    /**
     * Whether WP-Cron's own loopback spawn is disabled in wp-config.php.
     *
     * @return bool
     */
    public static function wp_cron_disabled(): bool {
        return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
    }

    /**
     * Snapshot of the scheduled critical hooks and whether each is overdue.
     *
     * @return array<string, array{next_run:int, overdue:bool, overdue_by:int}>
     */
    public static function cron_health(): array {
        $now    = time();
        $report = [];

        foreach ( self::critical_hooks() as $hook ) {
            $next = wp_next_scheduled( $hook );
            if ( false === $next ) {
                continue; // Not scheduled (e.g. command poll is off in PROD).
            }
            $report[ $hook ] = [
                'next_run'   => (int) $next,
                'overdue'    => $next < ( $now - self::OVERDUE_MARGIN ),
                'overdue_by' => max( 0, $now - (int) $next ),
            ];
        }

        return $report;
    }

    /**
     * True if any scheduled critical hook is past its overdue margin.
     *
     * @return bool
     */
    public static function has_overdue_hook(): bool {
        foreach ( self::cron_health() as $entry ) {
            if ( ! empty( $entry['overdue'] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * On shutdown, re-trigger wp-cron.php when our hooks are overdue and WP-Cron
     * is disabled. Self-disables when a real server cron keeps hooks current
     * (nothing overdue) and is throttled to at most once per minute.
     */
    public function maybe_spawn_loopback(): void {
        /**
         * Filter: hubbee_enable_cron_loopback
         *
         * Return false to fully disable the loopback safety net (e.g. operators
         * who manage cron exclusively via their own server scheduler).
         *
         * @param bool $enabled Default true.
         */
        if ( ! apply_filters( 'hubbee_enable_cron_loopback', true ) ) {
            return;
        }

        // When WP-Cron is enabled, WordPress already spawns its own loopback.
        if ( ! self::wp_cron_disabled() ) {
            return;
        }

        // Never re-enter from within a cron request.
        if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
            return;
        }

        if ( get_transient( self::LOOPBACK_THROTTLE ) ) {
            return;
        }

        // A real server cron keeps hooks current → nothing overdue → no-op.
        if ( ! self::has_overdue_hook() ) {
            return;
        }

        set_transient( self::LOOPBACK_THROTTLE, 1, MINUTE_IN_SECONDS );

        $cron_url = site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) );

        // Fire-and-forget. wp-cron.php's own `doing_cron` lock dedupes concurrent
        // spawns, so this is safe under traffic bursts.
        wp_remote_post(
            $cron_url,
            [
                'timeout'   => 0.01,
                'blocking'  => false,
                'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
                'headers'   => [ 'Cache-Control' => 'no-cache' ],
            ]
        );
    }

    /**
     * Admin notice (Hubbee screens + Dashboard) when WP-Cron is disabled.
     */
    public function maybe_render_notice(): void {
        if ( ! self::wp_cron_disabled() ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $relevant = ( 'dashboard' === $screen->id ) || ( false !== strpos( $screen->id, 'hubbee' ) );
        if ( ! $relevant ) {
            return;
        }

        $cron_url = esc_url( site_url( 'wp-cron.php?doing_wp_cron' ) );
        ?>
        <div class="notice notice-warning">
            <p>
                <strong><?php esc_html_e( 'Hubbee: WP-Cron is disabled on this site.', 'hubbee' ); ?></strong>
                <?php esc_html_e( 'Heartbeats, health reports and command polling rely on scheduled tasks. With WP-Cron disabled they only run when a visitor loads a page, which can leave this site marked offline during quiet periods.', 'hubbee' ); ?>
            </p>
            <p>
                <?php
                printf(
                    /* translators: %s: wp-cron.php URL on the current site */
                    esc_html__( 'Recommended: add a real server cron that requests %s every few minutes. Hubbee also runs a lightweight fallback automatically, but a server cron is more reliable.', 'hubbee' ),
                    '<code>' . $cron_url . '</code>'
                );
                ?>
            </p>
        </div>
        <?php
    }
}
