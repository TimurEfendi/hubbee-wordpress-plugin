<?php
/**
 * Updates Endpoint - Available updates information
 *
 * Returns comprehensive information about available updates
 * for WordPress core, plugins, and themes.
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class UpdatesEndpoint extends RestEndpoint {

    protected function get_route(): string {
        return '/updates';
    }

    protected function get_methods(): string {
        return 'POST';
    }

    /**
     * Handle the updates request
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function handle_request( WP_REST_Request $request ): WP_REST_Response {
        // Force update check if requested
        $force_check = $request->get_param( 'force_check' ) === true;

        if ( $force_check ) {
            $this->force_update_check();
        }

        return new WP_REST_Response(
            [
                'success'     => true,
                'timestamp'   => current_time( 'mysql' ),
                'core'        => $this->get_core_updates(),
                'plugins'     => $this->get_plugin_updates(),
                'themes'      => $this->get_theme_updates(),
                'translations'=> $this->get_translation_updates(),
                'summary'     => $this->get_update_summary(),
                'last_checked'=> $this->get_last_checked(),
            ],
            200
        );
    }

    /**
     * Force WordPress to check for updates
     */
    private function force_update_check(): void {
        // Include update functions
        require_once ABSPATH . 'wp-admin/includes/update.php';

        // Delete cached update data to force fresh check
        delete_site_transient( 'update_core' );
        delete_site_transient( 'update_plugins' );
        delete_site_transient( 'update_themes' );

        // Trigger update checks
        wp_version_check();
        wp_update_plugins();
        wp_update_themes();
    }

    /**
     * Get WordPress core update information
     *
     * @return array
     */
    private function get_core_updates(): array {
        global $wp_version;

        $update = get_site_transient( 'update_core' );
        $result = [
            'current_version'  => $wp_version,
            'update_available' => false,
            'latest_version'   => $wp_version,
            'update_type'      => null,
            'download_url'     => null,
            'release_date'     => null,
            'php_version'      => null,
            'mysql_version'    => null,
            'locale'           => get_locale(),
        ];

        if ( ! $update || empty( $update->updates ) ) {
            return $result;
        }

        // Find the latest update
        foreach ( $update->updates as $update_info ) {
            if ( isset( $update_info->response ) && $update_info->response === 'upgrade' ) {
                $result['update_available'] = true;
                $result['latest_version'] = $update_info->current ?? $update_info->version ?? $wp_version;
                $result['download_url'] = $update_info->download ?? null;
                $result['php_version'] = $update_info->php_version ?? null;
                $result['mysql_version'] = $update_info->mysql_version ?? null;
                $result['locale'] = $update_info->locale ?? get_locale();

                // Determine update type
                if ( isset( $update_info->current ) ) {
                    $current_parts = explode( '.', $wp_version );
                    $new_parts = explode( '.', $update_info->current );

                    if ( $new_parts[0] > $current_parts[0] ) {
                        $result['update_type'] = 'major';
                    } elseif ( isset( $new_parts[1], $current_parts[1] ) && $new_parts[1] > $current_parts[1] ) {
                        $result['update_type'] = 'minor';
                    } else {
                        $result['update_type'] = 'security';
                    }
                }

                break;
            }
        }

        // Check for auto-update settings
        $result['auto_update_enabled'] = $this->is_core_auto_update_enabled();

        return $result;
    }

    /**
     * Check if core auto-updates are enabled
     *
     * @return bool
     */
    private function is_core_auto_update_enabled(): bool {
        if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
            return false;
        }

        if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
            if ( WP_AUTO_UPDATE_CORE === false ) {
                return false;
            }
            if ( WP_AUTO_UPDATE_CORE === true || WP_AUTO_UPDATE_CORE === 'minor' ) {
                return true;
            }
        }

        return true; // Default is enabled for minor updates
    }

    /**
     * Get plugin updates information
     *
     * @return array
     */
    private function get_plugin_updates(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option( 'active_plugins', [] );
        $update_plugins = get_site_transient( 'update_plugins' );
        $auto_updates = get_option( 'auto_update_plugins', [] );

        $plugins = [];

        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            $plugin_info = [
                'slug'            => dirname( $plugin_file ) === '.' ? basename( $plugin_file, '.php' ) : dirname( $plugin_file ),
                'file'            => $plugin_file,
                'name'            => $plugin_data['Name'],
                'version'         => $plugin_data['Version'],
                'author'          => wp_strip_all_tags( $plugin_data['Author'] ),
                'author_uri'      => $plugin_data['AuthorURI'],
                'plugin_uri'      => $plugin_data['PluginURI'],
                'description'     => wp_trim_words( wp_strip_all_tags( $plugin_data['Description'] ), 20 ),
                'is_active'       => in_array( $plugin_file, $active_plugins, true ),
                'auto_update'     => in_array( $plugin_file, $auto_updates, true ),
                'update_available'=> false,
                'new_version'     => null,
                'update_uri'      => null,
                'requires_php'    => $plugin_data['RequiresPHP'] ?? null,
                'requires_wp'     => $plugin_data['RequiresWP'] ?? null,
                'tested_wp'       => null,
                'compatibility'   => null,
            ];

            // Check for available update
            if ( $update_plugins && isset( $update_plugins->response[ $plugin_file ] ) ) {
                $update = $update_plugins->response[ $plugin_file ];
                $plugin_info['update_available'] = true;
                $plugin_info['new_version'] = $update->new_version ?? null;
                $plugin_info['update_uri'] = $update->url ?? $update->package ?? null;
                $plugin_info['tested_wp'] = $update->tested ?? null;
                $plugin_info['requires_php'] = $update->requires_php ?? $plugin_info['requires_php'];
                $plugin_info['requires_wp'] = $update->requires ?? $plugin_info['requires_wp'];

                // Check compatibility
                $plugin_info['compatibility'] = $this->check_plugin_compatibility( $update );
            }

            $plugins[] = $plugin_info;
        }

        // Sort: updates first, then by name
        usort( $plugins, function ( $a, $b ) {
            if ( $a['update_available'] !== $b['update_available'] ) {
                return $b['update_available'] ? 1 : -1;
            }
            return strcasecmp( $a['name'], $b['name'] );
        } );

        return $plugins;
    }

    /**
     * Check plugin compatibility with current WordPress and PHP
     *
     * @param object $update Update info object.
     * @return array
     */
    private function check_plugin_compatibility( $update ): array {
        global $wp_version;

        $compatibility = [
            'php_compatible' => true,
            'wp_compatible'  => true,
            'issues'         => [],
        ];

        // Check PHP version requirement
        if ( ! empty( $update->requires_php ) ) {
            if ( version_compare( PHP_VERSION, $update->requires_php, '<' ) ) {
                $compatibility['php_compatible'] = false;
                $compatibility['issues'][] = sprintf(
                    'Requires PHP %s (current: %s)',
                    $update->requires_php,
                    PHP_VERSION
                );
            }
        }

        // Check WordPress version requirement
        if ( ! empty( $update->requires ) ) {
            if ( version_compare( $wp_version, $update->requires, '<' ) ) {
                $compatibility['wp_compatible'] = false;
                $compatibility['issues'][] = sprintf(
                    'Requires WordPress %s (current: %s)',
                    $update->requires,
                    $wp_version
                );
            }
        }

        return $compatibility;
    }

    /**
     * Get theme updates information
     *
     * @return array
     */
    private function get_theme_updates(): array {
        $all_themes = wp_get_themes();
        $active_theme = wp_get_theme();
        $update_themes = get_site_transient( 'update_themes' );
        $auto_updates = get_option( 'auto_update_themes', [] );

        $themes = [];

        foreach ( $all_themes as $stylesheet => $theme ) {
            $theme_info = [
                'slug'            => $stylesheet,
                'name'            => $theme->get( 'Name' ),
                'version'         => $theme->get( 'Version' ),
                'author'          => $theme->get( 'Author' ),
                'author_uri'      => $theme->get( 'AuthorURI' ),
                'theme_uri'       => $theme->get( 'ThemeURI' ),
                'description'     => wp_trim_words( $theme->get( 'Description' ), 20 ),
                'is_active'       => $stylesheet === $active_theme->get_stylesheet(),
                'is_parent'       => $active_theme->parent() && $stylesheet === $active_theme->get_template(),
                'is_child'        => (bool) $theme->parent(),
                'parent_theme'    => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
                'auto_update'     => in_array( $stylesheet, $auto_updates, true ),
                'update_available'=> false,
                'new_version'     => null,
                'update_uri'      => null,
                'requires_php'    => $theme->get( 'RequiresPHP' ),
                'requires_wp'     => $theme->get( 'RequiresWP' ),
                'screenshot'      => $theme->get_screenshot(),
            ];

            // Check for available update
            if ( $update_themes && isset( $update_themes->response[ $stylesheet ] ) ) {
                $update = $update_themes->response[ $stylesheet ];
                $theme_info['update_available'] = true;
                $theme_info['new_version'] = $update['new_version'] ?? null;
                $theme_info['update_uri'] = $update['url'] ?? $update['package'] ?? null;
                $theme_info['requires_php'] = $update['requires_php'] ?? $theme_info['requires_php'];
                $theme_info['requires_wp'] = $update['requires'] ?? $theme_info['requires_wp'];
            }

            $themes[] = $theme_info;
        }

        // Sort: active first, then updates, then by name
        usort( $themes, function ( $a, $b ) {
            if ( $a['is_active'] !== $b['is_active'] ) {
                return $b['is_active'] ? 1 : -1;
            }
            if ( $a['update_available'] !== $b['update_available'] ) {
                return $b['update_available'] ? 1 : -1;
            }
            return strcasecmp( $a['name'], $b['name'] );
        } );

        return $themes;
    }

    /**
     * Get translation updates information
     *
     * @return array
     */
    private function get_translation_updates(): array {
        $update_translations = wp_get_translation_updates();

        if ( empty( $update_translations ) ) {
            return [
                'available' => false,
                'count'     => 0,
                'items'     => [],
            ];
        }

        $items = [];
        foreach ( $update_translations as $translation ) {
            $items[] = [
                'type'     => $translation->type,
                'slug'     => $translation->slug,
                'language' => $translation->language,
                'version'  => $translation->version,
            ];
        }

        return [
            'available' => true,
            'count'     => count( $items ),
            'items'     => $items,
        ];
    }

    /**
     * Get update summary counts
     *
     * @return array
     */
    private function get_update_summary(): array {
        $core_update = get_site_transient( 'update_core' );
        $plugin_updates = get_site_transient( 'update_plugins' );
        $theme_updates = get_site_transient( 'update_themes' );
        $translation_updates = wp_get_translation_updates();

        $core_count = 0;
        if ( $core_update && ! empty( $core_update->updates ) ) {
            foreach ( $core_update->updates as $update ) {
                if ( isset( $update->response ) && $update->response === 'upgrade' ) {
                    $core_count = 1;
                    break;
                }
            }
        }

        $plugin_count = $plugin_updates && isset( $plugin_updates->response )
            ? count( $plugin_updates->response )
            : 0;

        $theme_count = $theme_updates && isset( $theme_updates->response )
            ? count( $theme_updates->response )
            : 0;

        $translation_count = is_array( $translation_updates )
            ? count( $translation_updates )
            : 0;

        $total = $core_count + $plugin_count + $theme_count + $translation_count;

        // Determine security update count (simplified check)
        $security_count = 0;
        if ( $core_count > 0 && isset( $core_update->updates[0]->response ) ) {
            // Minor version updates are often security updates
            global $wp_version;
            $current_parts = explode( '.', $wp_version );
            $new_parts = explode( '.', $core_update->updates[0]->current ?? '' );
            if (
                isset( $current_parts[0], $new_parts[0], $current_parts[1], $new_parts[1] ) &&
                $current_parts[0] === $new_parts[0] &&
                $current_parts[1] === $new_parts[1]
            ) {
                $security_count++;
            }
        }

        return [
            'total'        => $total,
            'core'         => $core_count,
            'plugins'      => $plugin_count,
            'themes'       => $theme_count,
            'translations' => $translation_count,
            'security'     => $security_count,
            'has_updates'  => $total > 0,
            'has_critical' => $core_count > 0 || $security_count > 0,
        ];
    }

    /**
     * Get last update check timestamps
     *
     * @return array
     */
    private function get_last_checked(): array {
        $core = get_site_transient( 'update_core' );
        $plugins = get_site_transient( 'update_plugins' );
        $themes = get_site_transient( 'update_themes' );

        return [
            'core'    => isset( $core->last_checked )
                ? wp_date( 'Y-m-d H:i:s', $core->last_checked )
                : null,
            'plugins' => isset( $plugins->last_checked )
                ? wp_date( 'Y-m-d H:i:s', $plugins->last_checked )
                : null,
            'themes'  => isset( $themes->last_checked )
                ? wp_date( 'Y-m-d H:i:s', $themes->last_checked )
                : null,
        ];
    }
}
