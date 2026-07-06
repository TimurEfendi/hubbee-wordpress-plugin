<?php
/**
 * Site lifecycle commands.
 *
 * Owns: site.disconnect, reconcile.
 *
 * `site.disconnect` is fired by SaaS when a site is removed from the
 * dashboard — credentials cleared, heartbeat + command-poller cron jobs
 * unscheduled. `reconcile` runs during enrollment to delete local
 * tokens/components that aren't in the SaaS-supplied valid lists, then
 * triggers a cache-purge hook for plugins like WP Rocket.
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

use Hubbee\Agent\ComponentService;
use Hubbee\SaaS\ConnectionManager;
use Hubbee\Storage\ComponentRepository;
use Hubbee\Storage\TokenRepository;
use Hubbee\Token\TokenService;
use WP_Error;

class LifecycleCommandHandler implements CommandHandler {

    public static function command_types(): array {
        return [ 'site.disconnect', 'reconcile' ];
    }

    public function execute( string $type, array $payload ) {
        switch ( $type ) {
            case 'site.disconnect':
                return $this->site_disconnect( $payload );
            case 'reconcile':
                return $this->reconcile( $payload );
            default:
                return new WP_Error( 'bz_unknown_lifecycle_command', sprintf( 'Unknown lifecycle command: %s', $type ) );
        }
    }

    private function site_disconnect( array $payload ): array {
        unset( $payload );

        $connection    = new ConnectionManager();
        $was_connected = $connection->is_connected();

        if ( $was_connected ) {
            // disconnect() clears credentials, unschedules cron jobs,
            // resets failure counters and fires the hubbee_disconnected hook.
            $connection->disconnect();
        }

        return [
            'success'       => true,
            'was_connected' => $was_connected,
        ];
    }

    private function reconcile( array $payload ): array {
        $valid_token_keys      = $payload['valid_token_keys'] ?? [];
        $valid_component_slugs = $payload['valid_component_slugs'] ?? [];

        $results = [
            'tokens_deleted'     => 0,
            'components_deleted' => 0,
        ];

        if ( is_array( $valid_token_keys ) ) {
            $token_repo    = new TokenRepository();
            $token_service = new TokenService();
            foreach ( $token_repo->get_all() as $token ) {
                if ( ! in_array( $token->token_key, $valid_token_keys, true ) ) {
                    $token_service->invalidate_cache( $token->token_key );
                    $token_repo->delete_by_key( $token->token_key );
                    $results['tokens_deleted']++;
                }
            }
        }

        if ( is_array( $valid_component_slugs ) ) {
            $component_repo    = new ComponentRepository();
            $component_service = new ComponentService();
            foreach ( $component_repo->get_all() as $component ) {
                if ( ! in_array( $component->component_slug, $valid_component_slugs, true ) ) {
                    $component_repo->delete_by_slug( $component->component_slug );
                    $component_service->invalidate_cache( $component->component_slug );
                    $results['components_deleted']++;
                }
            }

            delete_transient( 'bz_components_list' );
            delete_transient( 'bz_components_dropdown' );
        }

        do_action( 'hubbee_reconcile_completed', $results );

        if ( $results['tokens_deleted'] > 0 || $results['components_deleted'] > 0 ) {
            do_action( 'hubbee_cache_purge_needed' );
        }

        return $results;
    }
}
