<?php
/**
 * Command Endpoint - Receive direct push commands from SaaS
 *
 * This endpoint allows the SaaS to push commands directly to WordPress
 * for immediate execution, bypassing the polling mechanism.
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Hubbee\Agent\CommandExecutor;
use Hubbee\Storage\Database;

class CommandEndpoint extends RestEndpoint {

    protected function get_route(): string {
        return '/command';
    }

    protected function get_methods(): string {
        return 'POST';
    }

    public function handle_request( WP_REST_Request $request ) {
        $command_id   = $request->get_param( 'command_id' );
        $command_type = $request->get_param( 'command_type' );
        $payload      = $request->get_param( 'payload' ) ?: [];

        // Validate required fields
        if ( empty( $command_type ) ) {
            return new WP_Error(
                'bz_missing_command_type',
                __( 'Command type is required.', 'hubbee' ),
                [ 'status' => 400 ]
            );
        }

        // Tag every log line emitted during this command with the command_id
        // (or a generated uuid). Without this, debugging a single VPS-triggered
        // command across CommandEndpoint → CommandExecutor → handler is grep
        // archaeology against interleaved error_log lines from concurrent calls.
        \Hubbee\Debug\HubbeeDebug::set_request_id( $command_id ?: wp_generate_uuid4() );

        // Inject command_id into payload for result reporting
        if ( ! empty( $command_id ) ) {
            $payload['command_id'] = $command_id;
        }

        // Idempotency guard: if this exact command_id already executed
        // successfully, do NOT run it again. A re-delivery — the dispatcher's
        // network-error retry, a stale re-dispatch, or a lost result POST that
        // left completed_at NULL — must not double-execute a destructive action
        // (plugin/theme install/delete). Mirrors PushEndpoint's request_id
        // dedupe; command_id is a UUID and fits the shared bz_processed_requests
        // key. Only successful commands are marked (see below), so a genuinely
        // failed command is still retryable.
        if ( ! empty( $command_id ) && Database::is_request_processed( $command_id ) ) {
            return new WP_REST_Response(
                [
                    'success'      => true,
                    'idempotent'   => true,
                    'result'       => [ 'success' => true, 'idempotent' => true ],
                    'command_id'   => $command_id,
                    'command_type' => $command_type,
                ],
                200
            );
        }

        // Log the incoming command
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[Hubbee CommandEndpoint] Received direct push: type=%s, id=%s',
                $command_type,
                $command_id ?: 'none'
            ) );
        }

        // Execute the command
        $executor = CommandExecutor::get_instance();
        $result   = $executor->execute( $command_type, $payload );

        if ( is_wp_error( $result ) ) {
            // Log error
            error_log( sprintf(
                '[Hubbee CommandEndpoint ERROR] Command failed: type=%s, error=%s',
                $command_type,
                $result->get_error_message()
            ) );

            return new WP_REST_Response(
                [
                    'success'      => false,
                    'error'        => $result->get_error_message(),
                    'error_code'   => $result->get_error_code(),
                    'command_id'   => $command_id,
                    'command_type' => $command_type,
                ],
                400
            );
        }

        // Log success
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[Hubbee CommandEndpoint] Command executed successfully: type=%s, id=%s',
                $command_type,
                $command_id ?: 'none'
            ) );
        }

        // Mark this command_id processed so a re-delivery becomes the idempotent
        // no-op above. Only reached on success — failed commands stay retryable.
        if ( ! empty( $command_id ) ) {
            Database::mark_request_processed( $command_id );
        }

        // Full list push for reconciliation (push path)
        $this->push_resource_list_if_needed( $command_type );

        return new WP_REST_Response(
            [
                'success'      => true,
                'result'       => is_array( $result ) ? $result : [ 'success' => true ],
                'command_id'   => $command_id,
                'command_type' => $command_type,
            ],
            200
        );
    }

    /**
     * Push full plugin/theme list after command execution for reconciliation.
     *
     * This triggers the event-receiver's batch UPSERT + DELETE reconciliation,
     * ensuring phantom entries are cleaned up and drift is corrected.
     *
     * @param string $command_type The command type that was executed.
     */
    private function push_resource_list_if_needed( string $command_type ): void {
        // NOTE: plugin.delete/theme.delete excluded — event handles DB delete directly.
        // NOTE: plugin.install/theme.install excluded — dispatcher postProcessCommand handles
        // immediate DB state. Pushing intermediate lists during batch installs causes stale
        // reconciliation to delete newly installed plugins (same issue as delete).
        $plugin_commands = [ 'plugin.activate', 'plugin.deactivate', 'plugin.auto_update' ];
        $theme_commands  = [ 'theme.activate', 'theme.auto_update' ];

        $pusher = \Hubbee\Agent\EventPusher::get_instance();

        if ( in_array( $command_type, $plugin_commands, true ) ) {
            $pusher->push_plugins_list_async();
        }
        if ( in_array( $command_type, $theme_commands, true ) ) {
            $pusher->push_themes_list_async();
        }
    }

    /**
     * Get endpoint arguments schema
     *
     * @return array
     */
    protected function get_args(): array {
        return [
            'command_id'   => [
                'description' => __( 'Unique command identifier for tracking.', 'hubbee' ),
                'type'        => 'string',
                'required'    => false,
            ],
            'command_type' => [
                'description' => __( 'Type of command to execute (e.g., plugin.activate).', 'hubbee' ),
                'type'        => 'string',
                'required'    => true,
            ],
            'payload'      => [
                'description' => __( 'Command-specific payload data.', 'hubbee' ),
                'type'        => 'object',
                'required'    => false,
                'default'     => [],
            ],
        ];
    }
}
