<?php
/**
 * Theme-related commands.
 *
 * Owns:
 *   - theme.action       (legacy: switch active theme)
 *   - theme.activate
 *   - theme.auto_update
 *   - theme.install
 *   - theme.update
 *   - theme.delete
 *   - themes.sync        (full theme-list event push)
 *
 * Behaviour mirrors the legacy CommandExecutor::execute_theme_* methods 1:1.
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

use Hubbee\Agent\EventPusher;
use WP_Error;

class ThemeCommandHandler implements CommandHandler {

    public static function command_types(): array {
        return [
            'theme.action',
            'theme.activate',
            'theme.auto_update',
            'theme.install',
            'theme.update',
            'theme.delete',
            'themes.sync',
        ];
    }

    public function execute( string $type, array $payload ) {
        switch ( $type ) {
            case 'theme.action':
                return $this->theme_action( $payload );
            case 'theme.activate':
                return $this->theme_activate( $payload );
            case 'theme.auto_update':
                return $this->theme_auto_update( $payload );
            case 'theme.install':
                return $this->theme_install( $payload );
            case 'theme.update':
                return $this->theme_update( $payload );
            case 'theme.delete':
                return $this->theme_delete( $payload );
            case 'themes.sync':
                return $this->themes_sync( $payload );
            default:
                return new WP_Error( 'bz_unknown_theme_command', sprintf( 'Unknown theme command: %s', $type ) );
        }
    }

    private function theme_action( array $payload ) {
        $theme = $payload['theme'] ?? '';

        if ( empty( $theme ) ) {
            return new WP_Error( 'bz_invalid_theme', __( 'No theme specified.', 'hubbee' ) );
        }

        $theme_obj = wp_get_theme( $theme );
        if ( ! $theme_obj->exists() ) {
            return new WP_Error(
                'bz_theme_not_found',
                /* translators: %s: theme slug */
                sprintf( __( 'Theme not found: %s', 'hubbee' ), $theme )
            );
        }

        switch_theme( $theme );

        return [ 'success' => true, 'action' => 'switched', 'theme' => $theme ];
    }

    private function theme_activate( array $payload ) {
        $slug = $payload['slug'] ?? '';
        if ( empty( $slug ) ) {
            return new WP_Error( 'bz_missing_theme_slug', __( 'Theme slug is missing.', 'hubbee' ) );
        }
        return $this->theme_action( [ 'theme' => $slug ] );
    }

    private function theme_auto_update( array $payload ) {
        $slug    = $payload['slug'] ?? '';
        $enabled = $payload['enabled'] ?? false;

        if ( empty( $slug ) ) {
            return new WP_Error( 'bz_missing_theme_slug', __( 'Theme slug is missing.', 'hubbee' ) );
        }

        $theme = wp_get_theme( $slug );
        if ( ! $theme->exists() ) {
            return new WP_Error(
                'bz_theme_not_found',
                /* translators: %s: theme slug */
                sprintf( __( 'Theme not found: %s', 'hubbee' ), $slug )
            );
        }

        $auto_updates = get_option( 'auto_update_themes', [] );
        if ( $enabled ) {
            if ( ! in_array( $slug, $auto_updates, true ) ) {
                $auto_updates[] = $slug;
            }
        } else {
            $auto_updates = array_filter( $auto_updates, static fn( $t ) => $t !== $slug );
        }
        update_option( 'auto_update_themes', array_values( $auto_updates ) );

        return [
            'success'     => true,
            'theme'       => $slug,
            'auto_update' => $enabled,
            'theme_name'  => $theme->get( 'Name' ),
        ];
    }

    private function theme_install( array $payload ) {
        $slug     = $payload['slug'] ?? '';
        $activate = $payload['activate'] ?? false;

        if ( empty( $slug ) ) {
            return new WP_Error( 'bz_missing_theme_slug', __( 'Theme slug is missing.', 'hubbee' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $installed_themes = wp_get_themes();
        if ( isset( $installed_themes[ $slug ] ) ) {
            $theme     = $installed_themes[ $slug ];
            $is_active = ( get_stylesheet() === $slug );
            if ( $activate && ! $is_active ) {
                switch_theme( $slug );
                $is_active = true;
            }
            return [
                'success'           => true,
                'slug'              => $slug,
                'theme_name'        => $theme->get( 'Name' ),
                'version'           => $theme->get( 'Version' ),
                'description'       => $theme->get( 'Description' ),
                'author'            => $theme->get( 'Author' ),
                'screenshot_url'    => $theme->get_screenshot(),
                'is_active'         => $is_active,
                'activated'         => $activate && $is_active,
                'already_installed' => true,
            ];
        }

        $api = themes_api( 'theme_information', [
            'slug'   => $slug,
            'fields' => [ 'sections' => false, 'versions' => false ],
        ] );

        if ( is_wp_error( $api ) ) {
            return new WP_Error(
                'bz_theme_api_error',
                /* translators: %s: theme slug */
                sprintf( __( 'Theme "%s" not found on WordPress.org.', 'hubbee' ), $slug )
            );
        }

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Theme_Upgrader( $skin );
        $result   = $upgrader->install( $api->download_link );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( ! $result ) {
            return new WP_Error( 'bz_theme_install_failed', __( 'Theme installation failed.', 'hubbee' ) );
        }

        $theme = wp_get_theme( $slug );
        if ( ! $theme->exists() ) {
            return new WP_Error( 'bz_theme_not_found', __( 'Theme installed, but not found.', 'hubbee' ) );
        }

        $is_active = false;
        if ( $activate ) {
            switch_theme( $slug );
            $is_active = true;
        }

        return [
            'success'        => true,
            'slug'           => $slug,
            'theme_name'     => $theme->get( 'Name' ),
            'version'        => $theme->get( 'Version' ),
            'description'   => $theme->get( 'Description' ),
            'author'         => $theme->get( 'Author' ),
            'screenshot_url' => $theme->get_screenshot(),
            'is_active'      => $is_active,
            'activated'      => $activate && $is_active,
        ];
    }

    private function theme_update( array $payload ) {
        $slug = $payload['slug'] ?? '';

        if ( empty( $slug ) ) {
            return new WP_Error( 'bz_missing_theme_slug', __( 'Theme slug is missing.', 'hubbee' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $theme = wp_get_theme( $slug );
        if ( ! $theme->exists() ) {
            return new WP_Error(
                'bz_theme_not_found',
                /* translators: %s: theme slug */
                sprintf( __( 'Theme not found: %s', 'hubbee' ), $slug )
            );
        }

        $current_version = $theme->get( 'Version' );
        $theme_name      = $theme->get( 'Name' );

        delete_site_transient( 'update_themes' );
        wp_update_themes();

        $update_themes = get_site_transient( 'update_themes' );
        if ( ! isset( $update_themes->response[ $slug ] ) ) {
            return [
                'success'         => true,
                'slug'            => $slug,
                'theme_name'      => $theme_name,
                'current_version' => $current_version,
                'message'         => __( 'Theme is already up to date.', 'hubbee' ),
                'already_current' => true,
            ];
        }

        $skin     = new \Automatic_Upgrader_Skin();
        $upgrader = new \Theme_Upgrader( $skin );
        $result   = $upgrader->upgrade( $slug );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( false === $result ) {
            return new WP_Error(
                'bz_theme_update_failed',
                /* translators: %s: theme name */
                sprintf( __( 'Theme update failed: %s', 'hubbee' ), $theme_name )
            );
        }

        $updated_theme     = wp_get_theme( $slug );
        $installed_version = $updated_theme->get( 'Version' );

        EventPusher::get_instance()->push( 'theme_updated', [
            'theme_slug'   => $slug,
            'theme_name'   => $theme_name,
            'from_version' => $current_version,
            'to_version'   => $installed_version,
            'timestamp'    => current_time( 'mysql' ),
        ] );

        return [
            'success'          => true,
            'slug'             => $slug,
            'theme_name'       => $theme_name,
            'previous_version' => $current_version,
            'new_version'      => $installed_version,
        ];
    }

    private function theme_delete( array $payload ) {
        $slug = $payload['slug'] ?? '';

        if ( empty( $slug ) ) {
            return new WP_Error( 'bz_missing_theme_slug', __( 'Theme slug is missing.', 'hubbee' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $theme = wp_get_theme( $slug );
        if ( ! $theme->exists() ) {
            return new WP_Error(
                'bz_theme_not_found',
                /* translators: %s: theme slug */
                sprintf( __( 'Theme not found: %s', 'hubbee' ), $slug )
            );
        }

        // Active theme + parent-of-active are protected — same as legacy.
        if ( get_stylesheet() === $slug || get_template() === $slug ) {
            return new WP_Error(
                'bz_cannot_delete_active_theme',
                __( 'The active theme cannot be deleted.', 'hubbee' )
            );
        }
        $active_theme = wp_get_theme();
        if ( $active_theme->parent() && $active_theme->parent()->get_stylesheet() === $slug ) {
            return new WP_Error(
                'bz_cannot_delete_parent_theme',
                __( 'The parent theme of the active theme cannot be deleted.', 'hubbee' )
            );
        }

        $theme_name = $theme->get( 'Name' );
        $deleted    = delete_theme( $slug );

        if ( is_wp_error( $deleted ) ) {
            return new WP_Error(
                'bz_theme_delete_failed',
                /* translators: %s: error message */
                sprintf( __( 'Theme deletion failed: %s', 'hubbee' ), $deleted->get_error_message() )
            );
        }
        if ( ! $deleted ) {
            return new WP_Error( 'bz_theme_delete_failed', __( 'Theme deletion failed.', 'hubbee' ) );
        }

        EventPusher::get_instance()->push( 'theme_deleted', [
            'theme_slug' => $slug,
            'timestamp'  => current_time( 'mysql' ),
        ] );

        return [
            'success'    => true,
            'slug'       => $slug,
            'theme_name' => $theme_name,
        ];
    }

    private function themes_sync( array $payload ): array {
        unset( $payload );
        $result = EventPusher::get_instance()->push_themes_list();

        if ( is_wp_error( $result ) ) {
            return [ 'success' => false, 'error' => $result->get_error_message() ];
        }
        return [ 'success' => true, 'message' => 'Themes synced successfully' ];
    }
}
