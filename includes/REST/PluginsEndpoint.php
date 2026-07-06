<?php
/**
 * Plugins Endpoint - Plugin management via REST API
 *
 * Provides endpoints for listing, activating, and deactivating plugins.
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class PluginsEndpoint extends RestEndpoint {

    protected function get_routes(): array {
        $slug_arg = [
            'slug' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];

        return [
            [ 'route' => '/plugins',                                          'methods' => 'POST', 'callback' => 'list_plugins' ],
            [ 'route' => '/plugins/(?P<slug>[a-zA-Z0-9_-]+)/activate',        'methods' => 'POST', 'callback' => 'activate_plugin',   'args' => $slug_arg ],
            [ 'route' => '/plugins/(?P<slug>[a-zA-Z0-9_-]+)/deactivate',      'methods' => 'POST', 'callback' => 'deactivate_plugin', 'args' => $slug_arg ],
            [ 'route' => '/plugins/(?P<slug>[a-zA-Z0-9_-]+)',                 'methods' => 'POST', 'callback' => 'get_plugin',        'args' => $slug_arg ],
        ];
    }

    /**
     * List all plugins with detailed information
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function list_plugins( WP_REST_Request $request ): WP_REST_Response {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option( 'active_plugins', [] );
        $update_plugins = get_site_transient( 'update_plugins' );
        $auto_updates = get_option( 'auto_update_plugins', [] );

        // Get must-use plugins
        $mu_plugins = get_mu_plugins();

        // Get dropins
        $dropins = get_dropins();

        $plugins = [];

        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            $plugins[] = $this->format_plugin_data( $plugin_file, $plugin_data, $active_plugins, $update_plugins, $auto_updates );
        }

        // Sort: active first, then updates available, then by name
        usort( $plugins, function ( $a, $b ) {
            if ( $a['is_active'] !== $b['is_active'] ) {
                return $b['is_active'] ? 1 : -1;
            }
            if ( $a['update_available'] !== $b['update_available'] ) {
                return $b['update_available'] ? 1 : -1;
            }
            return strcasecmp( $a['name'], $b['name'] );
        } );

        // Count statistics
        $stats = [
            'total'         => count( $plugins ),
            'active'        => count( array_filter( $plugins, fn( $p ) => $p['is_active'] ) ),
            'inactive'      => count( array_filter( $plugins, fn( $p ) => ! $p['is_active'] ) ),
            'updates'       => count( array_filter( $plugins, fn( $p ) => $p['update_available'] ) ),
            'auto_updates'  => count( array_filter( $plugins, fn( $p ) => $p['auto_update'] ) ),
            'mu_plugins'    => count( $mu_plugins ),
            'dropins'       => count( $dropins ),
        ];

        return new WP_REST_Response(
            [
                'success'    => true,
                'timestamp'  => current_time( 'mysql' ),
                'plugins'    => $plugins,
                'mu_plugins' => $this->format_mu_plugins( $mu_plugins ),
                'dropins'    => $this->format_dropins( $dropins ),
                'stats'      => $stats,
            ],
            200
        );
    }

    /**
     * Get single plugin details
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_plugin( WP_REST_Request $request ) {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $slug = $request->get_param( 'slug' );
        $plugin_file = $this->find_plugin_file( $slug );

        if ( ! $plugin_file ) {
            return new WP_Error(
                'plugin_not_found',
                sprintf( 'Plugin with slug "%s" not found.', $slug ),
                [ 'status' => 404 ]
            );
        }

        $all_plugins = get_plugins();
        $plugin_data = $all_plugins[ $plugin_file ] ?? null;

        if ( ! $plugin_data ) {
            return new WP_Error(
                'plugin_not_found',
                sprintf( 'Plugin data for "%s" not found.', $slug ),
                [ 'status' => 404 ]
            );
        }

        $active_plugins = get_option( 'active_plugins', [] );
        $update_plugins = get_site_transient( 'update_plugins' );
        $auto_updates = get_option( 'auto_update_plugins', [] );

        return new WP_REST_Response(
            [
                'success'   => true,
                'timestamp' => current_time( 'mysql' ),
                'plugin'    => $this->format_plugin_data( $plugin_file, $plugin_data, $active_plugins, $update_plugins, $auto_updates ),
            ],
            200
        );
    }

    /**
     * Activate a plugin
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function activate_plugin( WP_REST_Request $request ) {
        if ( ! function_exists( 'activate_plugin' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $slug = $request->get_param( 'slug' );
        $plugin_file = $this->find_plugin_file( $slug );

        if ( ! $plugin_file ) {
            return new WP_Error(
                'plugin_not_found',
                sprintf( 'Plugin with slug "%s" not found.', $slug ),
                [ 'status' => 404 ]
            );
        }

        // Check if already active
        if ( is_plugin_active( $plugin_file ) ) {
            return new WP_REST_Response(
                [
                    'success'     => true,
                    'message'     => 'Plugin is already active.',
                    'plugin_file' => $plugin_file,
                    'was_active'  => true,
                ],
                200
            );
        }

        // Activate the plugin
        $result = activate_plugin( $plugin_file );

        if ( is_wp_error( $result ) ) {
            return new WP_Error(
                'activation_failed',
                $result->get_error_message(),
                [ 'status' => 500 ]
            );
        }

        // Get updated plugin info
        $all_plugins = get_plugins();
        $plugin_data = $all_plugins[ $plugin_file ] ?? [];
        $active_plugins = get_option( 'active_plugins', [] );
        $update_plugins = get_site_transient( 'update_plugins' );
        $auto_updates = get_option( 'auto_update_plugins', [] );

        return new WP_REST_Response(
            [
                'success'     => true,
                'message'     => 'Plugin activated successfully.',
                'plugin_file' => $plugin_file,
                'plugin'      => $this->format_plugin_data( $plugin_file, $plugin_data, $active_plugins, $update_plugins, $auto_updates ),
            ],
            200
        );
    }

    /**
     * Deactivate a plugin
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function deactivate_plugin( WP_REST_Request $request ) {
        if ( ! function_exists( 'deactivate_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $slug = $request->get_param( 'slug' );
        $plugin_file = $this->find_plugin_file( $slug );

        if ( ! $plugin_file ) {
            return new WP_Error(
                'plugin_not_found',
                sprintf( 'Plugin with slug "%s" not found.', $slug ),
                [ 'status' => 404 ]
            );
        }

        // Prevent deactivating Hubbee plugin itself
        if ( $this->is_hubbee_plugin( $plugin_file ) ) {
            return new WP_Error(
                'cannot_deactivate',
                'Cannot deactivate the Hubbee plugin via API.',
                [ 'status' => 403 ]
            );
        }

        // Check if already inactive
        if ( ! is_plugin_active( $plugin_file ) ) {
            return new WP_REST_Response(
                [
                    'success'     => true,
                    'message'     => 'Plugin is already inactive.',
                    'plugin_file' => $plugin_file,
                    'was_inactive'=> true,
                ],
                200
            );
        }

        // Deactivate the plugin
        deactivate_plugins( $plugin_file );

        // Get updated plugin info
        $all_plugins = get_plugins();
        $plugin_data = $all_plugins[ $plugin_file ] ?? [];
        $active_plugins = get_option( 'active_plugins', [] );
        $update_plugins = get_site_transient( 'update_plugins' );
        $auto_updates = get_option( 'auto_update_plugins', [] );

        return new WP_REST_Response(
            [
                'success'     => true,
                'message'     => 'Plugin deactivated successfully.',
                'plugin_file' => $plugin_file,
                'plugin'      => $this->format_plugin_data( $plugin_file, $plugin_data, $active_plugins, $update_plugins, $auto_updates ),
            ],
            200
        );
    }

    /**
     * Find plugin file by slug
     *
     * @param string $slug Plugin slug.
     * @return string|null Plugin file path or null if not found.
     */
    private function find_plugin_file( string $slug ): ?string {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();

        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            $plugin_slug = dirname( $plugin_file ) === '.'
                ? basename( $plugin_file, '.php' )
                : dirname( $plugin_file );

            if ( $plugin_slug === $slug ) {
                return $plugin_file;
            }
        }

        return null;
    }

    /**
     * Check if this is the Hubbee plugin
     *
     * @param string $plugin_file Plugin file path.
     * @return bool
     */
    private function is_hubbee_plugin( string $plugin_file ): bool {
        return strpos( $plugin_file, 'hubbee' ) !== false;
    }

    /**
     * Format plugin data for response
     *
     * @param string $plugin_file    Plugin file path.
     * @param array  $plugin_data    Plugin header data.
     * @param array  $active_plugins Active plugins list.
     * @param object $update_plugins Update plugins transient.
     * @param array  $auto_updates   Auto-update plugins list.
     * @return array Formatted plugin data.
     */
    private function format_plugin_data(
        string $plugin_file,
        array $plugin_data,
        array $active_plugins,
        $update_plugins,
        array $auto_updates
    ): array {
        $slug = dirname( $plugin_file ) === '.'
            ? basename( $plugin_file, '.php' )
            : dirname( $plugin_file );

        $is_active = in_array( $plugin_file, $active_plugins, true );
        $has_update = $update_plugins && isset( $update_plugins->response[ $plugin_file ] );

        $plugin_info = [
            'slug'            => $slug,
            'file'            => $plugin_file,
            'name'            => $plugin_data['Name'] ?? '',
            'version'         => $plugin_data['Version'] ?? '',
            'author'          => wp_strip_all_tags( $plugin_data['Author'] ?? '' ),
            'author_uri'      => $plugin_data['AuthorURI'] ?? '',
            'plugin_uri'      => $plugin_data['PluginURI'] ?? '',
            'description'     => wp_strip_all_tags( $plugin_data['Description'] ?? '' ),
            'text_domain'     => $plugin_data['TextDomain'] ?? '',
            'domain_path'     => $plugin_data['DomainPath'] ?? '',
            'network'         => ! empty( $plugin_data['Network'] ),
            'is_active'       => $is_active,
            'is_network_active' => is_multisite() && is_plugin_active_for_network( $plugin_file ),
            'auto_update'     => in_array( $plugin_file, $auto_updates, true ),
            'update_available'=> $has_update,
            'new_version'     => null,
            'update_uri'      => null,
            'requires_php'    => $plugin_data['RequiresPHP'] ?? null,
            'requires_wp'     => $plugin_data['RequiresWP'] ?? null,
            'tested_wp'       => null,
            'is_hubbee'       => $this->is_hubbee_plugin( $plugin_file ),
        ];

        // Add update information if available
        if ( $has_update ) {
            $update = $update_plugins->response[ $plugin_file ];
            $plugin_info['new_version'] = $update->new_version ?? null;
            $plugin_info['update_uri'] = $update->url ?? $update->package ?? null;
            $plugin_info['tested_wp'] = $update->tested ?? null;
            $plugin_info['requires_php'] = $update->requires_php ?? $plugin_info['requires_php'];
            $plugin_info['requires_wp'] = $update->requires ?? $plugin_info['requires_wp'];
        }

        return $plugin_info;
    }

    /**
     * Format must-use plugins for response
     *
     * @param array $mu_plugins Must-use plugins array.
     * @return array
     */
    private function format_mu_plugins( array $mu_plugins ): array {
        $formatted = [];

        foreach ( $mu_plugins as $file => $data ) {
            $formatted[] = [
                'file'        => $file,
                'name'        => $data['Name'] ?? $file,
                'version'     => $data['Version'] ?? '',
                'author'      => wp_strip_all_tags( $data['Author'] ?? '' ),
                'description' => wp_strip_all_tags( $data['Description'] ?? '' ),
            ];
        }

        return $formatted;
    }

    /**
     * Format dropins for response
     *
     * @param array $dropins Dropins array.
     * @return array
     */
    private function format_dropins( array $dropins ): array {
        $formatted = [];

        foreach ( $dropins as $file => $data ) {
            $formatted[] = [
                'file'        => $file,
                'name'        => $data['Name'] ?? $file,
                'description' => wp_strip_all_tags( $data['Description'] ?? '' ),
            ];
        }

        return $formatted;
    }
}
