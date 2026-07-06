<?php
/**
 * Plugin-related commands.
 *
 * Owns:
 *   - plugin.action       (legacy: activate|deactivate router)
 *   - plugin.activate
 *   - plugin.deactivate
 *   - plugin.auto_update
 *   - plugin.install
 *   - plugin.update
 *   - plugin.delete
 *
 * Behaviour mirrors the legacy CommandExecutor::execute_plugin_* methods 1:1
 * — same WP-admin includes, same return shapes, same EventPusher events.
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

use Hubbee\Agent\EventPusher;
use WP_Error;

class PluginCommandHandler implements CommandHandler {

    public static function command_types(): array {
        return [
            'plugin.action',
            'plugin.activate',
            'plugin.deactivate',
            'plugin.auto_update',
            'plugin.install',
            'plugin.update',
            'plugin.delete',
        ];
    }

    public function execute( string $type, array $payload ) {
        switch ( $type ) {
            case 'plugin.action':
                return $this->plugin_action( $payload );
            case 'plugin.activate':
                return $this->plugin_activate( $payload );
            case 'plugin.deactivate':
                return $this->plugin_deactivate( $payload );
            case 'plugin.auto_update':
                return $this->plugin_auto_update( $payload );
            case 'plugin.install':
                return $this->plugin_install( $payload );
            case 'plugin.update':
                return $this->plugin_update( $payload );
            case 'plugin.delete':
                return $this->plugin_delete( $payload );
            default:
                return new WP_Error( 'bz_unknown_plugin_command', sprintf( 'Unknown plugin command: %s', $type ) );
        }
    }

    private function plugin_action( array $payload ) {
        $plugin = str_replace( '\\', '/', $payload['plugin'] ?? '' );
        $action = $payload['action'] ?? '';

        if ( empty( $plugin ) || ! in_array( $action, [ 'activate', 'deactivate' ], true ) ) {
            return new WP_Error( 'bz_invalid_plugin_action', __( 'Invalid plugin action.', 'hubbee' ) );
        }

        if ( ! function_exists( 'activate_plugin' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        wp_cache_delete( 'plugins', 'plugins' );

        $all_plugins = get_plugins();
        if ( ! isset( $all_plugins[ $plugin ] ) ) {
            error_log( sprintf(
                '[Hubbee] Plugin not found: "%s". Available: %s',
                $plugin,
                implode( ', ', array_keys( $all_plugins ) )
            ) );
            return new WP_Error(
                'bz_plugin_not_found',
                sprintf( __( 'Plugin not found: %s', 'hubbee' ), $plugin )
            );
        }

        if ( 'activate' === $action ) {
            $result = activate_plugin( $plugin );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return [ 'success' => true, 'action' => 'activated', 'plugin' => $plugin ];
        }

        if ( 'deactivate' === $action ) {
            deactivate_plugins( $plugin );
            return [ 'success' => true, 'action' => 'deactivated', 'plugin' => $plugin ];
        }

        return new WP_Error( 'bz_action_failed', __( 'Action failed.', 'hubbee' ) );
    }

    private function plugin_activate( array $payload ) {
        $plugin_file = $payload['plugin_file'] ?? '';
        if ( empty( $plugin_file ) ) {
            return new WP_Error( 'bz_missing_plugin_file', __( 'Plugin file is missing.', 'hubbee' ) );
        }
        return $this->plugin_action( [ 'plugin' => $plugin_file, 'action' => 'activate' ] );
    }

    private function plugin_deactivate( array $payload ) {
        $plugin_file = $payload['plugin_file'] ?? '';
        if ( empty( $plugin_file ) ) {
            return new WP_Error( 'bz_missing_plugin_file', __( 'Plugin file is missing.', 'hubbee' ) );
        }
        return $this->plugin_action( [ 'plugin' => $plugin_file, 'action' => 'deactivate' ] );
    }

    private function plugin_auto_update( array $payload ) {
        $plugin_file = $payload['plugin_file'] ?? '';
        $enabled     = $payload['enabled'] ?? false;

        if ( empty( $plugin_file ) ) {
            return new WP_Error( 'bz_missing_plugin_file', __( 'Plugin file is missing.', 'hubbee' ) );
        }

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
            return new WP_Error(
                'bz_plugin_not_found',
                sprintf( __( 'Plugin not found: %s', 'hubbee' ), $plugin_file )
            );
        }

        $auto_updates = get_option( 'auto_update_plugins', [] );
        if ( $enabled ) {
            if ( ! in_array( $plugin_file, $auto_updates, true ) ) {
                $auto_updates[] = $plugin_file;
            }
        } else {
            $auto_updates = array_filter( $auto_updates, static fn( $p ) => $p !== $plugin_file );
        }
        update_option( 'auto_update_plugins', array_values( $auto_updates ) );

        return [
            'success'     => true,
            'plugin_file' => $plugin_file,
            'auto_update' => $enabled,
            'plugin_name' => $all_plugins[ $plugin_file ]['Name'] ?? $plugin_file,
        ];
    }

    private function plugin_install( array $payload ) {
        $slug     = $payload['slug'] ?? '';
        $activate = $payload['activate'] ?? false;

        if ( empty( $slug ) ) {
            return new WP_Error( 'bz_missing_plugin_slug', __( 'Plugin slug is missing.', 'hubbee' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // Plugin already installed?
        $all_plugins = get_plugins();
        foreach ( $all_plugins as $file => $data ) {
            $installed_slug = strpos( $file, '/' ) !== false
                ? dirname( $file )
                : str_replace( '.php', '', $file );
            if ( $installed_slug === $slug ) {
                $is_active = is_plugin_active( $file );
                if ( $activate && ! $is_active ) {
                    $activate_result = activate_plugin( $file );
                    if ( ! is_wp_error( $activate_result ) ) {
                        $is_active = true;
                    }
                }
                return [
                    'success'           => true,
                    'slug'              => $slug,
                    'plugin_file'       => $file,
                    'plugin_name'       => $data['Name'] ?? $slug,
                    'version'           => $data['Version'] ?? '',
                    'description'       => $data['Description'] ?? '',
                    'author'            => $data['Author'] ?? '',
                    'plugin_uri'        => $data['PluginURI'] ?? '',
                    'is_active'         => $is_active,
                    'activated'         => $activate && $is_active,
                    'already_installed' => true,
                ];
            }
        }

        $api = plugins_api( 'plugin_information', [
            'slug'   => $slug,
            'fields' => [
                'short_description' => true,
                'sections'          => false,
                'versions'          => false,
                'download_link'     => true,
            ],
        ] );

        if ( is_wp_error( $api ) ) {
            return new WP_Error(
                'bz_plugin_api_error',
                sprintf( __( 'Plugin "%s" not found on WordPress.org.', 'hubbee' ), $slug )
            );
        }

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader( $skin );
        $result   = $upgrader->install( $api->download_link );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( ! $result ) {
            return new WP_Error( 'bz_plugin_install_failed', __( 'Plugin installation failed.', 'hubbee' ) );
        }

        $plugin_file = null;
        $all_plugins = get_plugins();
        foreach ( $all_plugins as $file => $data ) {
            $installed_slug = strpos( $file, '/' ) !== false
                ? dirname( $file )
                : str_replace( '.php', '', $file );
            if ( $installed_slug === $slug ) {
                $plugin_file = $file;
                break;
            }
        }

        if ( ! $plugin_file ) {
            return new WP_Error(
                'bz_plugin_file_not_found',
                __( 'Plugin installed, but file not found.', 'hubbee' )
            );
        }

        $plugin_data = $all_plugins[ $plugin_file ];

        $is_active = false;
        if ( $activate ) {
            $activate_result = activate_plugin( $plugin_file );
            if ( ! is_wp_error( $activate_result ) ) {
                $is_active = true;
            }
        }

        return [
            'success'     => true,
            'slug'        => $slug,
            'plugin_file' => $plugin_file,
            'plugin_name' => $plugin_data['Name'] ?? $slug,
            'version'     => $plugin_data['Version'] ?? '',
            'description' => $plugin_data['Description'] ?? '',
            'author'      => $plugin_data['Author'] ?? '',
            'plugin_uri'  => $plugin_data['PluginURI'] ?? '',
            'is_active'   => $is_active,
            'activated'   => $activate && $is_active,
        ];
    }

    private function plugin_update( array $payload ) {
        $slug = $payload['slug'] ?? '';

        if ( empty( $slug ) ) {
            return new WP_Error( 'bz_missing_plugin_slug', __( 'Plugin slug is missing.', 'hubbee' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $plugin_file = $this->find_plugin_file_by_slug( $slug );
        if ( ! $plugin_file ) {
            return new WP_Error(
                'bz_plugin_not_found',
                sprintf( __( 'Plugin not found: %s', 'hubbee' ), $slug )
            );
        }

        $all_plugins     = get_plugins();
        $plugin_data     = $all_plugins[ $plugin_file ] ?? [];
        $current_version = $plugin_data['Version'] ?? '';
        $plugin_name     = $plugin_data['Name'] ?? $slug;

        wp_clean_plugins_cache();
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();

        $update_plugins = get_site_transient( 'update_plugins' );
        if ( ! isset( $update_plugins->response[ $plugin_file ] ) ) {
            return [
                'success'         => true,
                'slug'            => $slug,
                'plugin_file'     => $plugin_file,
                'plugin_name'     => $plugin_name,
                'current_version' => $current_version,
                'message'         => __( 'Plugin is already up to date.', 'hubbee' ),
                'already_current' => true,
            ];
        }

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader( $skin );
        $result   = $upgrader->upgrade( $plugin_file );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( false === $result ) {
            return new WP_Error(
                'bz_plugin_update_failed',
                sprintf( __( 'Plugin update failed: %s', 'hubbee' ), $plugin_name )
            );
        }

        wp_clean_plugins_cache();
        $all_plugins       = get_plugins();
        $updated_data      = $all_plugins[ $plugin_file ] ?? [];
        $installed_version = $updated_data['Version'] ?? '';

        EventPusher::get_instance()->push( 'plugin_updated', [
            'plugin_file'  => $plugin_file,
            'plugin_slug'  => $slug,
            'plugin_name'  => $plugin_name,
            'from_version' => $current_version,
            'to_version'   => $installed_version,
            'timestamp'    => current_time( 'mysql' ),
        ] );

        return [
            'success'          => true,
            'slug'             => $slug,
            'plugin_file'      => $plugin_file,
            'plugin_name'      => $plugin_name,
            'previous_version' => $current_version,
            'new_version'      => $installed_version,
        ];
    }

    private function plugin_delete( array $payload ) {
        $plugin_file = $payload['plugin_file'] ?? '';

        if ( empty( $plugin_file ) ) {
            return new WP_Error( 'bz_missing_plugin_file', __( 'Plugin file is missing.', 'hubbee' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $all_plugins = get_plugins();
        if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
            return new WP_Error(
                'bz_plugin_not_found',
                sprintf( __( 'Plugin not found: %s', 'hubbee' ), $plugin_file )
            );
        }

        // Self-protection: never let a remote command uninstall the Hubbee plugin.
        // Exact basename match — a substring 'hubbee' test also blocked unrelated
        // third-party plugins that merely contain "hubbee" in their folder name.
        $own_basename = defined( 'BZ_PLUGIN_BASENAME' ) ? BZ_PLUGIN_BASENAME : plugin_basename( __FILE__ );
        $own_dir      = strpos( $own_basename, '/' ) !== false ? dirname( $own_basename ) : $own_basename;
        $target_dir   = strpos( $plugin_file, '/' ) !== false ? dirname( $plugin_file ) : $plugin_file;
        if ( $plugin_file === $own_basename || $target_dir === $own_dir ) {
            return new WP_Error(
                'bz_cannot_delete_self',
                __( 'The Hubbee plugin cannot be uninstalled.', 'hubbee' )
            );
        }

        $plugin_data = $all_plugins[ $plugin_file ];
        $plugin_name = $plugin_data['Name'] ?? $plugin_file;
        $plugin_slug = strpos( $plugin_file, '/' ) !== false
            ? dirname( $plugin_file )
            : str_replace( '.php', '', $plugin_file );

        if ( is_plugin_active( $plugin_file ) ) {
            deactivate_plugins( $plugin_file );
        }

        $deleted = delete_plugins( [ $plugin_file ] );
        if ( is_wp_error( $deleted ) ) {
            return new WP_Error(
                'bz_plugin_delete_failed',
                sprintf( __( 'Plugin deletion failed: %s', 'hubbee' ), $deleted->get_error_message() )
            );
        }

        EventPusher::get_instance()->push( 'plugin_deleted', [
            'plugin_file' => $plugin_file,
            'plugin_slug' => $plugin_slug,
            'timestamp'   => current_time( 'mysql' ),
        ] );

        return [
            'success'     => true,
            'plugin_file' => $plugin_file,
            'plugin_name' => $plugin_name,
            'plugin_slug' => $plugin_slug,
        ];
    }

    /**
     * Resolve a plugin's directory-slug to its actual entry-point file.
     * "elementor" → "elementor/elementor.php".
     */
    private function find_plugin_file_by_slug( string $slug ): ?string {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        foreach ( get_plugins() as $file => $data ) {
            if ( strpos( $file, $slug . '/' ) === 0 || $file === $slug . '.php' ) {
                return $file;
            }
        }
        return null;
    }
}
