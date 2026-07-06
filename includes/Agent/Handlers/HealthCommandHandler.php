<?php
/**
 * Health-related commands (`health.check`, `sync.request`).
 *
 * Both commands collect a full deep-health snapshot — `sync.request` was a
 * legacy alias kept for older SaaS clients. Implementation is identical to
 * the legacy CommandExecutor::execute_health_check / _sync_request bodies.
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

use Hubbee\Health\HealthReporter;

class HealthCommandHandler implements CommandHandler {

    public static function command_types(): array {
        return [ 'health.check', 'sync.request' ];
    }

    public function execute( string $type, array $payload ) {
        unset( $type, $payload ); // both types collect the same snapshot.

        $reporter    = new HealthReporter();
        $health_data = $reporter->collect( HealthReporter::LEVEL_DEEP );

        $health_data['locale']       = get_locale();
        $health_data['timezone']     = wp_timezone_string();
        $health_data['is_multisite'] = is_multisite();
        $health_data['plugin_count'] = count( get_option( 'active_plugins', [] ) );
        $health_data['active_theme'] = get_stylesheet();
        $health_data['collected_at'] = current_time( 'mysql' );

        $plugin_updates = get_site_transient( 'update_plugins' );
        $theme_updates  = get_site_transient( 'update_themes' );

        $health_data['pending_plugin_updates'] = $plugin_updates && ! empty( $plugin_updates->response )
            ? count( $plugin_updates->response )
            : 0;
        $health_data['pending_theme_updates'] = $theme_updates && ! empty( $theme_updates->response )
            ? count( $theme_updates->response )
            : 0;

        return $health_data;
    }
}
