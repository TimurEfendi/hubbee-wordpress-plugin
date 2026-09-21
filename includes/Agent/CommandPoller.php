<?php
/**
 * Command Poller
 *
 * Polls pending commands from SaaS via the site-commands Edge Function.
 * This is MANDATORY for SaaS → WordPress communication in DEV mode
 * (because SaaS cannot reach localhost).
 *
 * Workflow:
 * 1. WordPress polls /site-commands (via site_api_key Bearer token)
 * 2. SaaS returns pending commands for this site
 * 3. WordPress processes each command via CommandExecutor
 * 4. WordPress reports results back via /command-result
 *
 * @package Hubbee\Agent
 */

namespace Hubbee\Agent;

use Hubbee\SaaS\ConnectionManager;
use Hubbee\SaaS\ModeConfig;
use Hubbee\Security\JwtGenerator;

class CommandPoller {

    /**
     * WP-Cron hook name
     */
    const CRON_HOOK = 'hubbee_poll_commands';

    /**
     * Custom cron schedule name
     */
    const CRON_SCHEDULE = 'hubbee_poll_interval';

    /**
     * Singleton instance
     *
     * @var CommandPoller|null
     */
    private static ?CommandPoller $instance = null;

    /**
     * Connection manager
     *
     * @var ConnectionManager
     */
    private ConnectionManager $connection;

    /**
     * Mode config
     *
     * @var ModeConfig
     */
    private ModeConfig $config;

    /**
     * Command executor
     *
     * @var CommandExecutor
     */
    private CommandExecutor $executor;

    /**
     * JWT generator for API auth
     *
     * @var JwtGenerator
     */
    private JwtGenerator $jwt;

    /**
     * Get singleton instance
     *
     * @return CommandPoller
     */
    public static function get_instance(): CommandPoller {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton
     */
    private function __construct() {
        $this->connection = new ConnectionManager();
        $this->config     = ModeConfig::get_instance();
        $this->executor   = CommandExecutor::get_instance();
        $this->jwt        = JwtGenerator::get_instance();
    }

    /**
     * Initialize polling system
     *
     * Call this in Plugin::run() or during init.
     */
    public function init(): void {
        // Only init if connected and command poll is enabled
        if ( ! $this->connection->is_connected() || ! $this->config->is_command_poll_enabled() ) {
            return;
        }

        // If suspended after repeated 401s, skip scheduling (transient auto-expires after 24h)
        if ( get_transient( 'bz_poll_suspended' ) ) {
            return;
        }

        // Register custom cron schedule
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedule' ] );

        // Register the cron hook
        add_action( self::CRON_HOOK, [ $this, 'poll_and_execute' ] );

        // Schedule cron if not already scheduled
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::CRON_SCHEDULE, self::CRON_HOOK );
        }
    }

    /**
     * Add custom cron schedule
     *
     * @param array $schedules Existing cron schedules.
     * @return array Modified schedules.
     */
    public function add_cron_schedule( array $schedules ): array {
        $interval = $this->config->get_poll_interval();

        $schedules[ self::CRON_SCHEDULE ] = [
            'interval' => $interval,
            'display'  => sprintf(
                /* translators: %d: number of seconds */
                __( 'Every %d seconds (Hubbee Poll)', 'hubbee' ),
                $interval
            ),
        ];

        return $schedules;
    }

    /**
     * Poll commands from SaaS
     *
     * @return array|WP_Error Array of commands or WP_Error on failure.
     */
    public function poll() {
        // Check if connected
        if ( ! $this->connection->is_connected() ) {
            return new \WP_Error( 'bz_not_connected', __( 'Not connected to SaaS.', 'hubbee' ) );
        }

        // Generate JWT for this request
        $auth_header = $this->jwt->get_auth_header();
        if ( empty( $auth_header ) ) {
            return new \WP_Error( 'bz_jwt_failed', __( 'JWT generation failed.', 'hubbee' ) );
        }

        // Build request
        $endpoint = $this->config->get_m2m_api_url() . '/site-commands';

        $response = wp_remote_get(
            $endpoint,
            [
                'headers' => [
                    'Authorization' => $auth_header,
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'Poll failed', [
                'error' => $response->get_error_message(),
            ] );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $status_code ) {
            if ( 401 === $status_code ) {
                $auth_failures = (int) get_option( 'bz_poll_auth_failures', 0 ) + 1;
                update_option( 'bz_poll_auth_failures', $auth_failures, 'no' );

                // Do NOT log the response body — it can contain JWT/validation
                // details that leak into debug.log on shared hosting.
                $this->log_error( 'Poll 401 response', [
                    'endpoint' => wp_parse_url( $endpoint, PHP_URL_PATH ),
                    'attempt'  => $auth_failures,
                ] );

                if ( $auth_failures >= 3 ) {
                    // Suspend polling but PRESERVE credentials
                    wp_clear_scheduled_hook( self::CRON_HOOK );
                    wp_clear_scheduled_hook( \Hubbee\Health\HeartbeatScheduler::HOOK );
                    set_transient( 'bz_poll_suspended', true, DAY_IN_SECONDS );
                    delete_option( 'bz_poll_auth_failures' );
                    $this->log_error( 'Polling suspended after 3x 401. Credentials preserved.', [
                        'endpoint' => $endpoint,
                    ] );
                    return new \WP_Error( 'bz_poll_suspended', __( 'Polling paused after 3x 401.', 'hubbee' ) );
                }
            }

            $error_message = $body['error'] ?? sprintf(
                /* translators: %d: HTTP status code */
                __( 'HTTP %d', 'hubbee' ),
                $status_code
            );

            $this->log_error( 'Poll rejected', [
                'status_code' => $status_code,
                'error'       => $error_message,
            ] );

            return new \WP_Error( 'bz_poll_failed', $error_message );
        }

        $commands = $body['commands'] ?? [];

        // Update poll interval hint if provided and reschedule cron if changed
        if ( ! empty( $body['poll_interval_hint'] ) ) {
            $new_hint = (int) $body['poll_interval_hint'];
            $old_hint = (int) get_option( 'bz_poll_interval_hint', 0 );

            if ( $new_hint !== $old_hint ) {
                update_option( 'bz_poll_interval_hint', $new_hint, 'no' );
                $this->reschedule_cron();
            }
        }

        // Sync plan-driven monitoring intervals from SaaS (shared logic with
        // the heartbeat-response ingest; explicit 0 = unschedule).
        \Hubbee\Health\IntervalSync::apply( $body );

        // Version-skew awareness. If the SaaS signals a minimum supported plugin
        // version, flag an admin upgrade notice (AdminController renders it).
        // Forward-compatible: a no-op until the SaaS includes min_plugin_version.
        if ( ! empty( $body['min_plugin_version'] ) && is_string( $body['min_plugin_version'] ) ) {
            if ( version_compare( BZ_VERSION, $body['min_plugin_version'], '<' ) ) {
                update_option( 'bz_min_plugin_version', $body['min_plugin_version'], 'no' );
            } else {
                delete_option( 'bz_min_plugin_version' );
            }
        }

        // Trigger immediate health report if SaaS flags it as stale
        if ( ! empty( $body['trigger_health_report'] ) ) {
            $scheduler = new \Hubbee\Health\HealthScheduler();
            $scheduler->trigger_immediate( \Hubbee\Health\HealthReporter::LEVEL_SNAPSHOT );
        }

        return $commands;
    }

    /**
     * Report command result back to SaaS
     *
     * @param string $job_id Job/Command ID.
     * @param string $status 'completed' or 'failed'.
     * @param array  $result Result data.
     * @param string $error_message Optional error message.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function report_result( string $job_id, string $status, array $result = [], string $error_message = '' ) {
        // Generate JWT for this request
        $auth_header = $this->jwt->get_auth_header();
        if ( empty( $auth_header ) ) {
            return new \WP_Error( 'bz_jwt_failed', __( 'JWT generation failed.', 'hubbee' ) );
        }

        // Build request
        $endpoint = $this->config->get_m2m_api_url() . '/command-result';

        $payload = [
            'job_id' => $job_id,
            'status' => $status,
        ];

        if ( ! empty( $result ) ) {
            $payload['result'] = $result;
        }

        if ( ! empty( $error_message ) ) {
            $payload['error_message'] = $error_message;
        }

        $response = wp_remote_post(
            $endpoint,
            [
                'headers' => [
                    'Authorization' => $auth_header,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( $payload ),
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'Result report failed', [
                'job_id' => $job_id,
                'error'  => $response->get_error_message(),
            ] );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 === $status_code && ! empty( $body['acknowledged'] ) ) {
            $this->log_success( 'Result reported', [
                'job_id' => $job_id,
                'status' => $status,
            ] );
            return true;
        }

        return new \WP_Error(
            'bz_report_failed',
            $body['error'] ?? __( 'Result report failed.', 'hubbee' )
        );
    }

    /**
     * Poll commands and execute them
     *
     * This is the main entry point called by WP-Cron.
     * Includes exponential backoff on consecutive failures.
     */
    public function poll_and_execute(): void {
        // Lock to prevent concurrent execution
        if ( get_transient( 'bz_poll_lock' ) ) {
            return;
        }
        set_transient( 'bz_poll_lock', true, 60 ); // 60 second lock

        try {
            // Check failure backoff - skip poll if in backoff period
            if ( get_transient( 'bz_poll_backoff' ) ) {
                $this->log_success( 'Skipping poll due to failure backoff', [] );
                return;
            }

            // Poll for commands
            $commands = $this->poll();

            if ( is_wp_error( $commands ) ) {
                $this->log_error( 'Poll and execute failed', [
                    'error' => $commands->get_error_message(),
                ] );
                $this->record_poll_failure();
                return;
            }

            // Reset failure counter on success
            $this->reset_poll_failures();

            if ( empty( $commands ) ) {
                $this->log_success( 'No commands to execute', [] );
                return;
            }

            $this->log_success( 'Commands received', [
                'count' => count( $commands ),
            ] );

            // Command types owned by the VPS Job Runner — skip if received
            $job_runner_types = [ 'site_ping', 'retention_cleanup', 'external_source_sync', 'event_plugin_list', 'event_theme_list', 'airtable_webhook_refresh', 'google_sheets_watch_refresh', 'background_push', 'element_push', 'text_effect_push' ];

            // Execute each command
            foreach ( $commands as $command ) {
                $type = $command['type'] ?? $command['command_type'] ?? '';
                if ( in_array( $type, $job_runner_types, true ) ) {
                    continue; // Handled by VPS Job Runner, not WordPress
                }
                $this->execute_command( $command );
            }

            // Push resource lists ONCE after entire batch (not per-command)
            $this->push_final_resource_lists( $commands );

        } finally {
            delete_transient( 'bz_poll_lock' );
        }
    }

    /**
     * Record a poll failure and set backoff transient if threshold exceeded
     */
    private function record_poll_failure(): void {
        $failures = (int) get_option( 'bz_poll_consecutive_failures', 0 );
        $failures++;
        update_option( 'bz_poll_consecutive_failures', $failures, 'no' );

        $interval = $this->config->get_poll_interval();

        if ( $failures >= 5 ) {
            // 4x interval backoff, max 30 minutes
            $backoff = min( $interval * 4, 1800 );
            set_transient( 'bz_poll_backoff', true, $backoff );
        } elseif ( $failures >= 3 ) {
            // 2x interval backoff, max 30 minutes
            $backoff = min( $interval * 2, 1800 );
            set_transient( 'bz_poll_backoff', true, $backoff );
        }
    }

    /**
     * Reset poll failure counter on successful poll
     */
    private function reset_poll_failures(): void {
        $failures = (int) get_option( 'bz_poll_consecutive_failures', 0 );
        if ( $failures > 0 ) {
            delete_option( 'bz_poll_consecutive_failures' );
            delete_transient( 'bz_poll_backoff' );
        }
        // Reset 401 auth failure counter on successful poll
        if ( get_option( 'bz_poll_auth_failures' ) !== false ) {
            delete_option( 'bz_poll_auth_failures' );
        }
    }

    /**
     * Reschedule cron with the current poll interval
     *
     * Called when poll_interval_hint changes from SaaS.
     */
    private function reschedule_cron(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        wp_schedule_event( time(), self::CRON_SCHEDULE, self::CRON_HOOK );

        $this->log_success( 'Cron rescheduled with new interval', [
            'interval' => $this->config->get_poll_interval(),
        ] );
    }

    /**
     * Execute a single command and report result
     *
     * @param array $command Command data from SaaS.
     */
    private function execute_command( array $command ): void {
        $job_id = $command['id'] ?? '';
        $type   = $command['type'] ?? '';
        $payload = $command['payload'] ?? [];

        if ( empty( $job_id ) || empty( $type ) ) {
            $this->log_error( 'Invalid command', [ 'command' => $command ] );
            return;
        }

        // Idempotency guard: if this job already executed (e.g. the SaaS re-sent
        // it because a prior result-report was lost to a network error),
        // re-report the stored outcome instead of running it again. Prevents
        // duplicate destructive actions (post.delete, plugin.install, ...).
        $result_key = 'bz_cmd_result_' . $job_id;
        $cached      = get_transient( $result_key );
        if ( is_array( $cached ) && isset( $cached['status'] ) ) {
            $this->log_success( 'Command already processed — re-reporting cached result', [
                'job_id' => $job_id,
                'type'   => $type,
            ] );
            $this->report_result(
                $job_id,
                $cached['status'],
                $cached['result'] ?? [],
                $cached['error'] ?? ''
            );
            return;
        }

        // Inject command_id into payload for commands that need it (e.g., snapshot_collect)
        $payload['command_id'] = $job_id;

        $this->log_success( 'Executing command', [
            'job_id' => $job_id,
            'type'   => $type,
        ] );

        try {
            // Execute via CommandExecutor
            $result = $this->executor->execute( $type, $payload );

            if ( is_wp_error( $result ) ) {
                $outcome = [ 'status' => 'failed', 'error' => $result->get_error_message() ];
            } else {
                $outcome = [ 'status' => 'completed', 'result' => is_array( $result ) ? $result : [ 'success' => true ] ];
            }
        } catch ( \Exception $e ) {
            $this->log_error( 'Command execution failed', [
                'job_id' => $job_id,
                'error'  => $e->getMessage(),
            ] );
            $outcome = [ 'status' => 'failed', 'error' => $e->getMessage() ];
        }

        // Persist the outcome BEFORE reporting so a failed report still leaves a
        // record that prevents re-execution on the next poll cycle.
        set_transient( $result_key, $outcome, DAY_IN_SECONDS );

        $this->report_result(
            $job_id,
            $outcome['status'],
            $outcome['result'] ?? [],
            $outcome['error'] ?? ''
        );
    }

    /**
     * Manually trigger poll (for testing or manual sync)
     *
     * @return array Results of poll and execute.
     */
    public function trigger_manual_poll(): array {
        $commands = $this->poll();

        if ( is_wp_error( $commands ) ) {
            return [
                'success' => false,
                'error'   => $commands->get_error_message(),
            ];
        }

        if ( empty( $commands ) ) {
            return [
                'success' => true,
                'message' => __( 'No pending commands.', 'hubbee' ),
                'count'   => 0,
            ];
        }

        // Execute commands
        $results = [];
        foreach ( $commands as $command ) {
            $this->execute_command( $command );
            $results[] = [
                'id'   => $command['id'] ?? '',
                'type' => $command['type'] ?? '',
            ];
        }

        // Push resource lists ONCE after entire batch (not per-command)
        $this->push_final_resource_lists( $commands );

        return [
            'success'  => true,
            'message'  => sprintf(
                /* translators: %d: number of commands */
                __( '%d commands executed.', 'hubbee' ),
                count( $results )
            ),
            'count'    => count( $results ),
            'commands' => $results,
        ];
    }

    /**
     * Clear scheduled cron
     *
     * Call this on plugin deactivation.
     */
    public static function clear_scheduled_cron(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Push resource lists ONCE after an entire command batch completes.
     *
     * Instead of pushing after each individual command (which causes incomplete lists
     * to overwrite correct state during batch installs), this checks which resource
     * types were affected and pushes each list exactly once at the end.
     *
     * NOTE: plugin.delete/theme.delete excluded — event handles DB delete directly.
     * NOTE: plugin.install/theme.install excluded — dispatcher postProcessCommand
     * handles immediate DB state. Pushing intermediate lists during batch installs
     * causes stale reconciliation to delete newly installed plugins.
     *
     * @param array $commands Array of executed commands.
     */
    private function push_final_resource_lists( array $commands ): void {
        $needs_plugins = false;
        $needs_themes  = false;

        $plugin_types = [ 'plugin.activate', 'plugin.deactivate', 'plugin.auto_update' ];
        $theme_types  = [ 'theme.activate', 'theme.auto_update' ];

        foreach ( $commands as $command ) {
            $type = $command['type'] ?? '';
            if ( in_array( $type, $plugin_types, true ) ) {
                $needs_plugins = true;
            }
            if ( in_array( $type, $theme_types, true ) ) {
                $needs_themes = true;
            }
        }

        $pusher = EventPusher::get_instance();

        if ( $needs_plugins ) {
            $pusher->push_plugins_list_async();
        }
        if ( $needs_themes ) {
            $pusher->push_themes_list_async();
        }
    }

    /**
     * Log success (for debugging)
     *
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    private function log_success( string $message, array $context = [] ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            hubbee_debug_log( sprintf( '[Hubbee CommandPoller] %s: %s', $message, wp_json_encode( $context ) ) );
        }
    }

    /**
     * Log error
     *
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    private function log_error( string $message, array $context = [] ): void {
        hubbee_debug_log( sprintf( '[Hubbee CommandPoller ERROR] %s: %s', $message, wp_json_encode( $context ) ) );
        ErrorReporter::get_instance()->record( 'CommandPoller', $message, $context );
    }
}
