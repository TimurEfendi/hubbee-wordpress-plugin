<?php
/**
 * Update-related commands.
 *
 * Owns:
 *   - update.check     (force WP to refresh plugin/theme update transients)
 *   - run.update       (apply queued plugin/theme updates)
 *   - core.update      (upgrade WordPress core)
 *
 * Identical behaviour to the legacy CommandExecutor::execute_update_check /
 * _run_update / _core_update — same WP-admin includes, same return shapes,
 * same `core_updated` EventPusher emit.
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

use Hubbee\Agent\EventPusher;
use WP_Error;

class UpdateCommandHandler implements CommandHandler {

    public static function command_types(): array {
        return [ 'update.check', 'run.update', 'core.update' ];
    }

    public function execute( string $type, array $payload ) {
        switch ( $type ) {
            case 'update.check':
                return $this->update_check( $payload );
            case 'run.update':
                return $this->run_update( $payload );
            case 'core.update':
                return $this->core_update( $payload );
            default:
                return new WP_Error( 'bz_unknown_update_command', sprintf( 'Unknown update command: %s', $type ) );
        }
    }

    private function update_check( array $payload ): array {
        unset( $payload );

        wp_clean_plugins_cache();
        wp_update_plugins();
        wp_update_themes();

        $plugin_updates = get_site_transient( 'update_plugins' );
        $theme_updates  = get_site_transient( 'update_themes' );

        return [
            'success'        => true,
            'plugin_updates' => $plugin_updates && ! empty( $plugin_updates->response )
                ? count( $plugin_updates->response )
                : 0,
            'theme_updates'  => $theme_updates && ! empty( $theme_updates->response )
                ? count( $theme_updates->response )
                : 0,
        ];
    }

    private function run_update( array $payload ) {
        $type  = $payload['type'] ?? '';
        $items = $payload['items'] ?? [];

        if ( empty( $type ) || empty( $items ) ) {
            return new WP_Error( 'bz_invalid_update_request', __( 'Invalid update request.', 'hubbee' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

        $results = [];

        if ( 'plugin' === $type ) {
            $upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
            foreach ( $items as $plugin ) {
                $result             = $upgrader->upgrade( $plugin );
                $results[ $plugin ] = $result ? 'success' : 'failed';
            }
        } elseif ( 'theme' === $type ) {
            $upgrader = new \Theme_Upgrader( new \Automatic_Upgrader_Skin() );
            foreach ( $items as $theme ) {
                $result            = $upgrader->upgrade( $theme );
                $results[ $theme ] = $result ? 'success' : 'failed';
            }
        }

        return [
            'success' => true,
            'type'    => $type,
            'results' => $results,
        ];
    }

    private function core_update( array $payload ) {
        unset( $payload );

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';

        global $wp_version;
        $current_version = $wp_version;

        delete_site_transient( 'update_core' );
        wp_version_check();

        $update_core = get_site_transient( 'update_core' );
        if ( ! $update_core || empty( $update_core->updates ) ) {
            return [
                'success'         => true,
                'current_version' => $current_version,
                'message'         => __( 'WordPress is already up to date.', 'hubbee' ),
                'already_current' => true,
            ];
        }

        $update = null;
        foreach ( $update_core->updates as $available_update ) {
            if ( 'upgrade' === $available_update->response ) {
                $update = $available_update;
                break;
            }
        }

        if ( ! $update ) {
            return [
                'success'         => true,
                'current_version' => $current_version,
                'message'         => __( 'WordPress is already up to date.', 'hubbee' ),
                'already_current' => true,
            ];
        }

        $new_version = $update->version ?? '';

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Core_Upgrader( $skin );
        $result   = $upgrader->upgrade( $update );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( false === $result ) {
            return new WP_Error( 'bz_core_update_failed', __( 'WordPress update failed.', 'hubbee' ) );
        }

        EventPusher::get_instance()->push( 'core_updated', [
            'from_version' => $current_version,
            'to_version'   => $new_version,
            'timestamp'    => current_time( 'mysql' ),
        ] );

        return [
            'success'          => true,
            'previous_version' => $current_version,
            'new_version'      => $new_version,
        ];
    }
}
