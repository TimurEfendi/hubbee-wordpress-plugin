<?php
/**
 * Cron Endpoint - WordPress cron job management
 *
 * Provides endpoints to list, run, and manage WordPress scheduled tasks.
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class CronEndpoint extends RestEndpoint {

    protected function get_routes(): array {
        return [
            [ 'route' => '/cron-jobs',           'methods' => 'POST', 'callback' => 'list_cron_jobs' ],
            [ 'route' => '/cron-jobs/schedules', 'methods' => 'POST', 'callback' => 'get_schedules' ],
            [ 'route' => '/cron-jobs/run',       'methods' => 'POST', 'callback' => 'run_cron_job' ],
            [ 'route' => '/cron-jobs/delete',    'methods' => 'POST', 'callback' => 'delete_cron_job' ],
        ];
    }

    /**
     * List all cron jobs
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function list_cron_jobs( WP_REST_Request $request ): WP_REST_Response {
        $cron_array = _get_cron_array();
        $schedules = wp_get_schedules();

        $jobs = [];
        $stats = [
            'total'          => 0,
            'overdue'        => 0,
            'recurring'      => 0,
            'single'         => 0,
            'hubbee'         => 0,
            'core'           => 0,
            'plugin'         => 0,
        ];

        $now = time();

        // Known WordPress core hooks
        $core_hooks = [
            'wp_scheduled_delete',
            'wp_scheduled_auto_draft_delete',
            'delete_expired_transients',
            'wp_version_check',
            'wp_update_plugins',
            'wp_update_themes',
            'wp_site_health_scheduled_check',
            'recovery_mode_clean_expired_keys',
            'wp_https_detection',
            'wp_privacy_delete_old_export_files',
        ];

        if ( $cron_array ) {
            foreach ( $cron_array as $timestamp => $cron ) {
                foreach ( $cron as $hook => $events ) {
                    foreach ( $events as $key => $event ) {
                        $is_overdue = $timestamp < $now;
                        $schedule = $event['schedule'] ?? false;
                        $is_recurring = $schedule !== false;
                        $is_hubbee = strpos( $hook, 'bz_' ) === 0 || strpos( $hook, 'hubbee' ) === 0;
                        $is_core = in_array( $hook, $core_hooks, true ) || strpos( $hook, 'wp_' ) === 0;

                        $schedule_info = null;
                        if ( $schedule && isset( $schedules[ $schedule ] ) ) {
                            $schedule_info = [
                                'name'     => $schedule,
                                'display'  => $schedules[ $schedule ]['display'],
                                'interval' => $schedules[ $schedule ]['interval'],
                            ];
                        }

                        $jobs[] = [
                            'hook'           => $hook,
                            'key'            => $key,
                            'timestamp'      => $timestamp,
                            'datetime'       => wp_date( 'Y-m-d H:i:s', $timestamp ),
                            'datetime_utc'   => gmdate( 'Y-m-d H:i:s', $timestamp ),
                            'relative'       => human_time_diff( $timestamp, $now ) . ( $is_overdue ? ' ago' : '' ),
                            'is_overdue'     => $is_overdue,
                            'is_recurring'   => $is_recurring,
                            'schedule'       => $schedule_info,
                            'args'           => $event['args'] ?? [],
                            'interval'       => $event['interval'] ?? null,
                            'is_hubbee'      => $is_hubbee,
                            'is_core'        => $is_core,
                            'category'       => $is_hubbee ? 'hubbee' : ( $is_core ? 'core' : 'plugin' ),
                        ];

                        // Update stats
                        $stats['total']++;
                        if ( $is_overdue ) $stats['overdue']++;
                        if ( $is_recurring ) $stats['recurring']++; else $stats['single']++;
                        if ( $is_hubbee ) $stats['hubbee']++;
                        if ( $is_core ) $stats['core']++;
                        if ( ! $is_hubbee && ! $is_core ) $stats['plugin']++;
                    }
                }
            }
        }

        // Sort by timestamp
        usort( $jobs, function ( $a, $b ) {
            return $a['timestamp'] <=> $b['timestamp'];
        } );

        // Get next scheduled event
        $next_event = ! empty( $jobs ) ? $jobs[0] : null;

        return new WP_REST_Response(
            [
                'success'       => true,
                'timestamp'     => current_time( 'mysql' ),
                'jobs'          => $jobs,
                'stats'         => $stats,
                'next_event'    => $next_event,
                'cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
                'alternate_cron'=> defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
                'timezone'      => wp_timezone_string(),
            ],
            200
        );
    }

    /**
     * Get available cron schedules
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function get_schedules( WP_REST_Request $request ): WP_REST_Response {
        $schedules = wp_get_schedules();

        $formatted = [];
        foreach ( $schedules as $name => $schedule ) {
            $formatted[] = [
                'name'              => $name,
                'display'           => $schedule['display'],
                'interval'          => $schedule['interval'],
                'interval_formatted'=> $this->format_interval( $schedule['interval'] ),
            ];
        }

        // Sort by interval
        usort( $formatted, function ( $a, $b ) {
            return $a['interval'] <=> $b['interval'];
        } );

        return new WP_REST_Response(
            [
                'success'   => true,
                'timestamp' => current_time( 'mysql' ),
                'schedules' => $formatted,
            ],
            200
        );
    }

    /**
     * Run a specific cron job
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function run_cron_job( WP_REST_Request $request ) {
        $body = json_decode( $request->get_body(), true );

        if ( empty( $body['hook'] ) ) {
            return new WP_Error(
                'missing_hook',
                'Hook name is required.',
                [ 'status' => 400 ]
            );
        }

        $hook = sanitize_text_field( $body['hook'] );
        $args = $body['args'] ?? [];

        // Verify the hook exists
        $cron_array = _get_cron_array();
        $hook_exists = false;
        $original_timestamp = null;

        if ( $cron_array ) {
            foreach ( $cron_array as $timestamp => $cron ) {
                if ( isset( $cron[ $hook ] ) ) {
                    $hook_exists = true;
                    $original_timestamp = $timestamp;
                    break;
                }
            }
        }

        if ( ! $hook_exists ) {
            return new WP_Error(
                'hook_not_found',
                sprintf( 'Cron hook "%s" not found.', $hook ),
                [ 'status' => 404 ]
            );
        }

        // Run the hook
        $start_time = microtime( true );

        try {
            do_action_ref_array( $hook, $args );
            $success = true;
            $error_message = null;
        } catch ( \Exception $e ) {
            $success = false;
            $error_message = $e->getMessage();
        } catch ( \Error $e ) {
            $success = false;
            $error_message = $e->getMessage();
        }

        $execution_time = microtime( true ) - $start_time;

        return new WP_REST_Response(
            [
                'success'        => $success,
                'hook'           => $hook,
                'args'           => $args,
                'executed_at'    => current_time( 'mysql' ),
                'execution_time' => round( $execution_time, 4 ),
                'error'          => $error_message,
                'message'        => $success
                    ? sprintf( 'Cron job "%s" executed successfully.', $hook )
                    : sprintf( 'Cron job "%s" failed: %s', $hook, $error_message ),
            ],
            200
        );
    }

    /**
     * Delete a specific cron job
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function delete_cron_job( WP_REST_Request $request ) {
        $body = json_decode( $request->get_body(), true );

        if ( empty( $body['hook'] ) ) {
            return new WP_Error(
                'missing_hook',
                'Hook name is required.',
                [ 'status' => 400 ]
            );
        }

        $hook = sanitize_text_field( $body['hook'] );
        $timestamp = isset( $body['timestamp'] ) ? (int) $body['timestamp'] : null;

        // Prevent deleting core WordPress hooks
        $protected_hooks = [
            'wp_scheduled_delete',
            'wp_version_check',
            'wp_update_plugins',
            'wp_update_themes',
        ];

        if ( in_array( $hook, $protected_hooks, true ) ) {
            return new WP_Error(
                'protected_hook',
                sprintf( 'The hook "%s" is a protected WordPress core hook and cannot be deleted.', $hook ),
                [ 'status' => 403 ]
            );
        }

        // Get current cron array
        $cron_array = _get_cron_array();
        $deleted_count = 0;

        if ( $cron_array ) {
            foreach ( $cron_array as $ts => $cron ) {
                if ( isset( $cron[ $hook ] ) ) {
                    // If timestamp specified, only delete that specific event
                    if ( $timestamp !== null && $ts !== $timestamp ) {
                        continue;
                    }

                    foreach ( $cron[ $hook ] as $key => $event ) {
                        wp_unschedule_event( $ts, $hook, $event['args'] );
                        $deleted_count++;
                    }
                }
            }
        }

        if ( $deleted_count === 0 ) {
            return new WP_Error(
                'hook_not_found',
                sprintf( 'Cron hook "%s" not found.', $hook ),
                [ 'status' => 404 ]
            );
        }

        return new WP_REST_Response(
            [
                'success'       => true,
                'hook'          => $hook,
                'deleted_count' => $deleted_count,
                'deleted_at'    => current_time( 'mysql' ),
                'message'       => sprintf( 'Deleted %d scheduled event(s) for hook "%s".', $deleted_count, $hook ),
            ],
            200
        );
    }

    /**
     * Format interval in human readable format
     *
     * @param int $seconds Interval in seconds.
     * @return string
     */
    private function format_interval( int $seconds ): string {
        if ( $seconds < 60 ) {
            return sprintf( '%d seconds', $seconds );
        } elseif ( $seconds < 3600 ) {
            $minutes = round( $seconds / 60 );
            return sprintf( '%d minute%s', $minutes, $minutes === 1 ? '' : 's' );
        } elseif ( $seconds < 86400 ) {
            $hours = round( $seconds / 3600, 1 );
            return sprintf( '%s hour%s', $hours, $hours == 1 ? '' : 's' );
        } else {
            $days = round( $seconds / 86400, 1 );
            return sprintf( '%s day%s', $days, $days == 1 ? '' : 's' );
        }
    }
}
