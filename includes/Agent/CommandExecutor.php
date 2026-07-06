<?php
/**
 * Command Executor — dispatcher for SaaS-issued commands.
 *
 * Concrete commands live in `\Hubbee\Agent\Handlers\*` and are looked up
 * through HandlerRegistry. This class owns only the dispatch path
 * (`execute`) and the `batch` super-command, which validates an allowlist of
 * sub-types and re-enters `execute()` for each one inside a BatchContext so
 * the constituent pushes can be coalesced.
 *
 * @package Hubbee\Agent
 */

namespace Hubbee\Agent;

use Hubbee\Agent\EventPusher;
use Hubbee\Agent\Handlers\HandlerRegistry;
use WP_Error;

class CommandExecutor {

    /**
     * Singleton instance
     *
     * @var CommandExecutor|null
     */
    private static ?CommandExecutor $instance = null;

    /**
     * Get singleton instance
     *
     * @return CommandExecutor
     */
    public static function get_instance(): CommandExecutor {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton.
     */
    private function __construct() {
        // No initialization required — extracted handlers manage their own dependencies.
    }

    /**
     * Execute a command
     *
     * @param string $type    Command type.
     * @param array  $payload Command payload.
     * @return array|WP_Error Result data or WP_Error on failure.
     */
    public function execute( string $type, array $payload = [] ) {
        // Flag to prevent EventPusher hooks from re-pushing command-triggered
        // changes (infinite loop protection). Set BEFORE dispatch so both the
        // registry and legacy paths see it.
        if ( ! defined( 'HUBBEE_COMMAND_EXECUTION' ) ) {
            define( 'HUBBEE_COMMAND_EXECUTION', true );
        }

        // Every concrete command lives in a HandlerRegistry-registered handler.
        // The legacy in-class `execute_*` fallback only remains for `batch` —
        // the dispatcher itself stays here because batch needs access to the
        // registry to delegate sub-commands.
        $registry = HandlerRegistry::get_instance();
        if ( $registry->has( $type ) ) {
            $handler = $registry->resolve( $type );
            try {
                return $handler->execute( $type, $payload );
            } catch ( \Throwable $e ) {
                return new WP_Error( 'bz_execution_error', $e->getMessage() );
            }
        }

        $method = 'execute_' . str_replace( [ '.', '-' ], '_', $type );
        if ( ! method_exists( $this, $method ) ) {
            return new WP_Error(
                'bz_unknown_command',
                sprintf(
                    /* translators: %s: command type */
                    __( 'Unknown command type: %s', 'hubbee' ),
                    $type
                )
            );
        }

        try {
            return $this->$method( $payload );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'bz_execution_error', $e->getMessage() );
        }
    }

    /**
     * Execute: batch
     *
     * Executes multiple sub-commands in a single request.
     * Suppresses individual push() calls and sends one consolidated event at the end.
     *
     * @param array $payload { commands: [ { type: string, payload: array }, ... ] }
     * @return array { success: true, results: [ { index, type, success, result/error }, ... ] }
     */
    protected function execute_batch( array $payload ) {
        $commands = $payload['commands'] ?? [];

        if ( empty( $commands ) || ! is_array( $commands ) ) {
            return new WP_Error( 'bz_batch_empty', __( 'No commands in batch.', 'hubbee' ) );
        }

        if ( count( $commands ) > 50 ) {
            return new WP_Error( 'bz_batch_too_large', __( 'Maximum 50 commands per batch.', 'hubbee' ) );
        }

        $allowed_types = [
            'post.delete',
            'post.status',
            'page.delete',
            'page.status',
            'media.delete',
            'comment.moderate',
        ];

        // Validate all commands before executing any
        foreach ( $commands as $index => $cmd ) {
            $type = $cmd['type'] ?? '';
            if ( ! in_array( $type, $allowed_types, true ) ) {
                return new WP_Error(
                    'bz_batch_invalid_type',
                    sprintf( __( 'Invalid batch command type at index %d: %s', 'hubbee' ), $index, $type )
                );
            }
        }

        // Suppress individual push() calls in extracted handlers via BatchContext.
        BatchContext::enter();

        $results    = [];
        $successful = 0;
        $failed     = 0;

        foreach ( $commands as $index => $cmd ) {
            $type    = $cmd['type'];
            $sub_payload = $cmd['payload'] ?? [];

            try {
                // Route through the same dispatcher as execute() so migrated
                // handlers (HandlerRegistry) and legacy execute_* methods both
                // work inside a batch. Allowed types are validated above so we
                // never reach an unknown command here.
                $result = $this->execute( $type, $sub_payload );

                if ( is_wp_error( $result ) ) {
                    $failed++;
                    $results[] = [
                        'index'   => $index,
                        'type'    => $type,
                        'success' => false,
                        'error'   => $result->get_error_message(),
                    ];
                } else {
                    $successful++;
                    $results[] = [
                        'index'   => $index,
                        'type'    => $type,
                        'success' => true,
                        'result'  => $result,
                    ];
                }
            } catch ( \Throwable $e ) {
                $failed++;
                $results[] = [
                    'index'   => $index,
                    'type'    => $type,
                    'success' => false,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        // Re-enable individual pushes
        BatchContext::leave();

        // Send one consolidated event
        EventPusher::get_instance()->push( 'content_changed', [
            'action'     => 'batch_completed',
            'total'      => count( $commands ),
            'successful' => $successful,
            'failed'     => $failed,
            'timestamp'  => current_time( 'mysql' ),
        ] );

        return [
            'success'    => true,
            'total'      => count( $commands ),
            'successful' => $successful,
            'failed'     => $failed,
            'results'    => $results,
        ];
    }

    // health.check and sync.request are now served by
    // \Hubbee\Agent\Handlers\HealthCommandHandler — see HandlerRegistry boot
    // in execute(). Behaviour is identical (deep health snapshot).

    // cache.clear and cache.flush are now served by
    // \Hubbee\Agent\Handlers\CacheCommandHandler — see HandlerRegistry boot
    // in execute(). Behaviour is identical.

    // plugin.action / plugin.activate / plugin.deactivate / plugin.auto_update /
    // plugin.install / plugin.update / plugin.delete are now served by
    // \Hubbee\Agent\Handlers\PluginCommandHandler — see HandlerRegistry boot.

}
