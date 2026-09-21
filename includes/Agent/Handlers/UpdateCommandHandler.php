<?php
/**
 * Update-related commands.
 *
 * Owns:
 *   - core.update      (upgrade WordPress core)
 *
 * Same WP-admin includes and return shape as the legacy
 * CommandExecutor::_core_update, and the same `core_updated` EventPusher emit.
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

use Hubbee\Agent\EventPusher;
use WP_Error;

class UpdateCommandHandler implements CommandHandler {

    public static function command_types(): array {
        return [ 'core.update' ];
    }

    public function execute( string $type, array $payload ) {
        switch ( $type ) {
            case 'core.update':
                return $this->core_update( $payload );
            default:
                return new WP_Error( 'bz_unknown_update_command', sprintf( 'Unknown update command: %s', $type ) );
        }
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
