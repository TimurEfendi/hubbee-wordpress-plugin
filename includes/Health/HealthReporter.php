<?php
/**
 * Health Reporter
 *
 * Collects system metrics and sends health reports to the Hubbee SaaS.
 *
 * @package Hubbee\Health
 */

namespace Hubbee\Health;

use Hubbee\Agent\EventPusher;
use Hubbee\SaaS\ConnectionManager;
use WP_Error;

class HealthReporter {

    /**
     * Report level constants
     */
    const LEVEL_PING = 'ping';
    const LEVEL_SNAPSHOT = 'snapshot';
    const LEVEL_DEEP = 'deep';

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
        $this->connection = new ConnectionManager();
    }

    /**
     * Collect metrics based on report level
     *
     * @param string $level Report level (ping, snapshot, deep).
     * @return array Collected metrics.
     */
    public function collect( string $level ): array {
        $data = [
            'online' => true,
            'status' => 'ok',
        ];

        // Ping level: minimal data
        if ( $level === self::LEVEL_PING ) {
            return $data;
        }

        // Snapshot level: add version info
        $data['wp_version'] = get_bloginfo( 'version' );
        $data['php_version'] = PHP_VERSION;
        $data['plugin_version'] = defined( 'BZ_VERSION' ) ? BZ_VERSION : '2.0.0';
        $data['token_count'] = $this->get_token_count();

        if ( $level === self::LEVEL_SNAPSHOT ) {
            return $data;
        }

        // Deep level: add system metrics (field names match event-receiver expectation)
        $data['memory_usage_percent'] = $this->get_memory_usage();
        $data['disk_usage_percent'] = $this->get_disk_usage();
        $data['last_error'] = $this->get_last_error();
        $data['hints'] = $this->generate_hints( $data );
        $data['status'] = $this->calculate_status( $data );

        return $data;
    }

    /**
     * Send health report to SaaS via event-receiver
     *
     * Routes through EventPusher::push_health_report() to consolidate
     * all WP→SaaS communication through the event-receiver Edge Function.
     *
     * @param string $level Report level.
     * @param array  $extra Extra data to include.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function send_report( string $level, array $extra = [] ): bool|WP_Error {
        /**
         * Filter: hubbee_health_report_enabled
         *
         * Return false to suppress all outbound health reports (ping, snapshot,
         * deep). Default: true. Documented in readme.txt FAQ alongside the
         * `hubbee_heartbeat_enabled` filter — together they form the complete
         * telemetry opt-out for site owners with stricter privacy policies.
         *
         * @param bool   $enabled Whether health reports should be sent.
         * @param string $level   The requested report level (ping/snapshot/deep).
         */
        if ( ! apply_filters( 'hubbee_health_report_enabled', true, $level ) ) {
            return new WP_Error(
                'bz_health_report_disabled',
                __( 'Health reporting is disabled via filter.', 'hubbee' )
            );
        }

        if ( ! $this->connection->is_connected() ) {
            return new WP_Error(
                'bz_not_connected',
                __( 'Not connected to Hubbee SaaS.', 'hubbee' )
            );
        }

        // Collect metrics and merge with extra data
        $data = array_merge( $this->collect( $level ), $extra );
        $data['level'] = $level;

        // Route through EventPusher (uses event-receiver Edge Function)
        $result = EventPusher::get_instance()->push_health_report( $data );

        if ( is_wp_error( $result ) ) {
            $this->log_failure( $level, $result->get_error_message() );
            return $result;
        }

        // Success: update last report timestamp
        update_option( 'bz_last_health_report', current_time( 'mysql' ) );
        update_option( 'bz_last_health_level', $level );

        // Reset failure counter on success
        delete_option( 'bz_health_report_failures' );

        // Clear any previous error on successful report
        if ( $level === self::LEVEL_DEEP ) {
            delete_option( 'bz_last_error' );
        }

        return true;
    }

    /**
     * Get memory usage percentage
     *
     * @return int Memory usage percentage (0-100).
     */
    private function get_memory_usage(): int {
        $limit = ini_get( 'memory_limit' );
        $used = memory_get_usage( true );

        // Convert limit to bytes
        $limit_bytes = wp_convert_hr_to_bytes( $limit );

        if ( $limit_bytes <= 0 ) {
            return 0;
        }

        return (int) round( ( $used / $limit_bytes ) * 100 );
    }

    /**
     * Get disk usage percentage
     *
     * @return int Disk usage percentage (0-100).
     */
    private function get_disk_usage(): int {
        $upload_dir = wp_upload_dir();
        $path = $upload_dir['basedir'];

        // Suppress errors for systems that don't support these functions
        $total = @disk_total_space( $path );
        $free = @disk_free_space( $path );

        if ( ! $total || ! $free ) {
            return 0;
        }

        $used = $total - $free;
        return (int) round( ( $used / $total ) * 100 );
    }

    /**
     * Get token count from database
     *
     * Uses a 1h transient cache for the table existence check to avoid
     * running SHOW TABLES every 15 minutes.
     *
     * @return int Number of tokens.
     */
    private function get_token_count(): int {
        global $wpdb;

        $table = $wpdb->prefix . 'bz_tokens';

        // Cache table existence check (1h TTL)
        $exists = get_transient( 'bz_tokens_table_exists' );
        if ( false === $exists ) {
            $table_exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SHOW TABLES LIKE %s",
                    $table
                )
            );
            $exists = $table_exists ? 'yes' : 'no';
            set_transient( 'bz_tokens_table_exists', $exists, HOUR_IN_SECONDS );
        }

        if ( 'yes' !== $exists ) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
    }

    /**
     * Get last recorded error
     *
     * @return string|null Last error message or null.
     */
    private function get_last_error(): ?string {
        $error = get_option( 'bz_last_error', '' );
        return ! empty( $error ) ? $error : null;
    }

    /**
     * Calculate overall health status based on metrics
     *
     * @param array $data Collected metrics.
     * @return string Health status (ok, warning, critical).
     */
    private function calculate_status( array $data ): string {
        // Critical conditions
        if ( ( $data['memory_usage_percent'] ?? 0 ) > 90 ) {
            return 'critical';
        }
        if ( ( $data['disk_usage_percent'] ?? 0 ) > 95 ) {
            return 'critical';
        }

        // Warning conditions
        if ( ( $data['memory_usage_percent'] ?? 0 ) > 80 ) {
            return 'warning';
        }
        if ( ( $data['disk_usage_percent'] ?? 0 ) > 85 ) {
            return 'warning';
        }
        if ( ! empty( $data['last_error'] ) ) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * Generate hints/recommendations based on metrics
     *
     * @param array $data Collected metrics.
     * @return array Array of hint objects.
     */
    private function generate_hints( array $data ): array {
        $hints = [];

        // Memory warning
        if ( ( $data['memory_usage_percent'] ?? 0 ) > 80 ) {
            $hints[] = [
                'type'    => 'memory',
                'message' => __( 'High memory usage', 'hubbee' ),
            ];
        }

        // Disk warning
        if ( ( $data['disk_usage_percent'] ?? 0 ) > 85 ) {
            $hints[] = [
                'type'    => 'disk',
                'message' => __( 'Disk almost full', 'hubbee' ),
            ];
        }

        // WordPress core update check
        $update = get_site_transient( 'update_core' );
        if ( $update && ! empty( $update->updates ) ) {
            $latest = $update->updates[0];
            if ( isset( $latest->response ) && $latest->response === 'upgrade' ) {
                $hints[] = [
                    'type'    => 'update',
                    'message' => sprintf(
                        /* translators: %s: WordPress version number */
                        __( 'WordPress update available: %s', 'hubbee' ),
                        $latest->current
                    ),
                ];
            }
        }

        // PHP version check (recommend 8.0+)
        if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
            $hints[] = [
                'type'    => 'php',
                'message' => sprintf(
                    /* translators: %s: PHP version number */
                    __( 'PHP %s - update to 8.0+ recommended', 'hubbee' ),
                    PHP_VERSION
                ),
            ];
        }

        // Plugin update check with details
        $plugin_updates = get_site_transient( 'update_plugins' );
        if ( $plugin_updates && ! empty( $plugin_updates->response ) ) {
            $plugins_data = [];

            // Load plugin.php if not already loaded (required for Cron/REST contexts)
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $all_plugins = get_plugins();

            foreach ( $plugin_updates->response as $plugin_file => $plugin_info ) {
                $plugin_name = isset( $all_plugins[ $plugin_file ] )
                    ? $all_plugins[ $plugin_file ]['Name']
                    : basename( dirname( $plugin_file ) );
                $current_version = isset( $all_plugins[ $plugin_file ] )
                    ? $all_plugins[ $plugin_file ]['Version']
                    : 'unknown';

                $plugins_data[] = [
                    'name'            => $plugin_name,
                    'slug'            => $plugin_file,
                    'current_version' => $current_version,
                    'new_version'     => $plugin_info->new_version,
                ];
            }

            $plugin_count = count( $plugins_data );
            $hints[] = [
                'type'    => 'plugins',
                'message' => sprintf(
                    /* translators: %d: Number of plugin updates */
                    _n(
                        '%d plugin update available',
                        '%d plugin updates available',
                        $plugin_count,
                        'hubbee'
                    ),
                    $plugin_count
                ),
                'data'    => $plugins_data,
            ];
        }

        // Theme update check with details
        $theme_updates = get_site_transient( 'update_themes' );
        if ( $theme_updates && ! empty( $theme_updates->response ) ) {
            $themes_data = [];
            foreach ( $theme_updates->response as $theme_slug => $theme_info ) {
                $current_theme = wp_get_theme( $theme_slug );
                if ( $current_theme->exists() ) {
                    $themes_data[] = [
                        'name'            => $current_theme->get( 'Name' ),
                        'slug'            => $theme_slug,
                        'current_version' => $current_theme->get( 'Version' ),
                        'new_version'     => $theme_info['new_version'],
                    ];
                }
            }
            if ( ! empty( $themes_data ) ) {
                $hints[] = [
                    'type'    => 'themes',
                    'message' => sprintf(
                        /* translators: %d: Number of theme updates */
                        _n(
                            '%d theme update available',
                            '%d theme updates available',
                            count( $themes_data ),
                            'hubbee'
                        ),
                        count( $themes_data )
                    ),
                    'data'    => $themes_data,
                ];
            }
        }

        return $hints;
    }

    /**
     * Log a health report failure
     *
     * @param string $level Report level.
     * @param string $error Error message.
     */
    private function log_failure( string $level, string $error ): void {
        // Log to error log
        error_log( sprintf(
            'Hubbee Health Report (%s) failed: %s',
            $level,
            $error
        ) );

        // Store as last error (but don't overwrite more important errors)
        $current_error = get_option( 'bz_last_error', '' );
        if ( empty( $current_error ) || strpos( $current_error, 'Health Report' ) !== false ) {
            update_option( 'bz_last_error', sprintf(
                /* translators: 1: Report level, 2: Error message */
                __( 'Health Report (%1$s) failed: %2$s', 'hubbee' ),
                $level,
                $error
            ) );
        }

        // Track failure count for potential backoff
        $failures = get_option( 'bz_health_report_failures', 0 );
        update_option( 'bz_health_report_failures', $failures + 1 );
    }

    /**
     * Check if health reports should be throttled due to repeated failures
     *
     * @return bool True if should throttle, false otherwise.
     */
    public function should_throttle(): bool {
        $failures = get_option( 'bz_health_report_failures', 0 );

        // After 5 consecutive failures, throttle
        if ( $failures >= 5 ) {
            // Check if we've waited long enough (30 minutes)
            $last_report = get_option( 'bz_last_health_report', '' );
            if ( ! empty( $last_report ) ) {
                $last_time = strtotime( $last_report );
                $throttle_period = 30 * MINUTE_IN_SECONDS;

                if ( ( time() - $last_time ) < $throttle_period ) {
                    return true;
                }
            }

            // Reset failure count after throttle period
            update_option( 'bz_health_report_failures', 0 );
        }

        return false;
    }

    /**
     * Reset failure tracking (call on successful connection)
     */
    public function reset_failures(): void {
        delete_option( 'bz_health_report_failures' );
        delete_option( 'bz_last_error' );
    }
}
