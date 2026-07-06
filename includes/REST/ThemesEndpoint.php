<?php
/**
 * Themes Endpoint - Theme management via REST API
 *
 * Provides endpoints for listing and activating themes.
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ThemesEndpoint extends RestEndpoint {

    protected function get_routes(): array {
        $slug_arg = [
            'slug' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];

        return [
            [ 'route' => '/themes',                                       'methods' => 'POST', 'callback' => 'list_themes' ],
            [ 'route' => '/themes/(?P<slug>[a-zA-Z0-9_-]+)',              'methods' => 'POST', 'callback' => 'get_theme',     'args' => $slug_arg ],
            [ 'route' => '/themes/(?P<slug>[a-zA-Z0-9_-]+)/activate',     'methods' => 'POST', 'callback' => 'activate_theme', 'args' => $slug_arg ],
        ];
    }

    /**
     * List all themes with detailed information
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function list_themes( WP_REST_Request $request ): WP_REST_Response {
        $all_themes = wp_get_themes();
        $active_theme = wp_get_theme();
        $update_themes = get_site_transient( 'update_themes' );
        $auto_updates = get_option( 'auto_update_themes', [] );

        $themes = [];

        foreach ( $all_themes as $stylesheet => $theme ) {
            $themes[] = $this->format_theme_data( $stylesheet, $theme, $active_theme, $update_themes, $auto_updates );
        }

        // Sort: active first, then parent theme, then updates available, then by name
        usort( $themes, function ( $a, $b ) {
            if ( $a['is_active'] !== $b['is_active'] ) {
                return $b['is_active'] ? 1 : -1;
            }
            if ( $a['is_parent'] !== $b['is_parent'] ) {
                return $b['is_parent'] ? 1 : -1;
            }
            if ( $a['update_available'] !== $b['update_available'] ) {
                return $b['update_available'] ? 1 : -1;
            }
            return strcasecmp( $a['name'], $b['name'] );
        } );

        // Count statistics
        $stats = [
            'total'        => count( $themes ),
            'updates'      => count( array_filter( $themes, fn( $t ) => $t['update_available'] ) ),
            'auto_updates' => count( array_filter( $themes, fn( $t ) => $t['auto_update'] ) ),
            'child_themes' => count( array_filter( $themes, fn( $t ) => $t['is_child'] ) ),
        ];

        return new WP_REST_Response(
            [
                'success'      => true,
                'timestamp'    => current_time( 'mysql' ),
                'themes'       => $themes,
                'active_theme' => $this->get_active_theme_info( $active_theme ),
                'stats'        => $stats,
            ],
            200
        );
    }

    /**
     * Get single theme details
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function get_theme( WP_REST_Request $request ) {
        $slug = $request->get_param( 'slug' );
        $theme = wp_get_theme( $slug );

        if ( ! $theme->exists() ) {
            return new WP_Error(
                'theme_not_found',
                sprintf( 'Theme with slug "%s" not found.', $slug ),
                [ 'status' => 404 ]
            );
        }

        $active_theme = wp_get_theme();
        $update_themes = get_site_transient( 'update_themes' );
        $auto_updates = get_option( 'auto_update_themes', [] );

        return new WP_REST_Response(
            [
                'success'   => true,
                'timestamp' => current_time( 'mysql' ),
                'theme'     => $this->format_theme_data( $slug, $theme, $active_theme, $update_themes, $auto_updates ),
            ],
            200
        );
    }

    /**
     * Activate a theme
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function activate_theme( WP_REST_Request $request ) {
        $slug = $request->get_param( 'slug' );
        $theme = wp_get_theme( $slug );

        if ( ! $theme->exists() ) {
            return new WP_Error(
                'theme_not_found',
                sprintf( 'Theme with slug "%s" not found.', $slug ),
                [ 'status' => 404 ]
            );
        }

        // Check if theme is allowed
        if ( ! $theme->is_allowed() ) {
            return new WP_Error(
                'theme_not_allowed',
                'This theme is not allowed to be activated.',
                [ 'status' => 403 ]
            );
        }

        $active_theme = wp_get_theme();

        // Check if already active
        if ( $slug === $active_theme->get_stylesheet() ) {
            $update_themes = get_site_transient( 'update_themes' );
            $auto_updates = get_option( 'auto_update_themes', [] );

            return new WP_REST_Response(
                [
                    'success'      => true,
                    'message'      => 'Theme is already active.',
                    'was_active'   => true,
                    'theme'        => $this->format_theme_data( $slug, $theme, $active_theme, $update_themes, $auto_updates ),
                ],
                200
            );
        }

        // Store previous theme info for response
        $previous_theme = [
            'slug' => $active_theme->get_stylesheet(),
            'name' => $active_theme->get( 'Name' ),
        ];

        // Switch theme
        switch_theme( $slug );

        // Clear theme caches
        wp_clean_themes_cache();

        // Get updated info
        $new_active_theme = wp_get_theme();
        $update_themes = get_site_transient( 'update_themes' );
        $auto_updates = get_option( 'auto_update_themes', [] );

        return new WP_REST_Response(
            [
                'success'        => true,
                'message'        => 'Theme activated successfully.',
                'theme'          => $this->format_theme_data( $slug, $new_active_theme, $new_active_theme, $update_themes, $auto_updates ),
                'previous_theme' => $previous_theme,
            ],
            200
        );
    }

    /**
     * Format theme data for response
     *
     * @param string    $stylesheet    Theme stylesheet (slug).
     * @param \WP_Theme $theme         Theme object.
     * @param \WP_Theme $active_theme  Active theme object.
     * @param object    $update_themes Update themes transient.
     * @param array     $auto_updates  Auto-update themes list.
     * @return array Formatted theme data.
     */
    private function format_theme_data(
        string $stylesheet,
        \WP_Theme $theme,
        \WP_Theme $active_theme,
        $update_themes,
        array $auto_updates
    ): array {
        $is_active = $stylesheet === $active_theme->get_stylesheet();
        $is_parent = ! $is_active && $active_theme->parent() && $stylesheet === $active_theme->get_template();
        $has_update = $update_themes && isset( $update_themes->response[ $stylesheet ] );

        $theme_info = [
            'slug'            => $stylesheet,
            'name'            => $theme->get( 'Name' ),
            'version'         => $theme->get( 'Version' ),
            'author'          => $theme->get( 'Author' ),
            'author_uri'      => $theme->get( 'AuthorURI' ),
            'theme_uri'       => $theme->get( 'ThemeURI' ),
            'description'     => $theme->get( 'Description' ),
            'tags'            => $theme->get( 'Tags' ) ?: [],
            'text_domain'     => $theme->get( 'TextDomain' ),
            'domain_path'     => $theme->get( 'DomainPath' ),
            'is_active'       => $is_active,
            'is_parent'       => $is_parent,
            'is_child'        => (bool) $theme->parent(),
            'parent_theme'    => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
            'parent_slug'     => $theme->get_template() !== $stylesheet ? $theme->get_template() : null,
            'auto_update'     => in_array( $stylesheet, $auto_updates, true ),
            'update_available'=> $has_update,
            'new_version'     => null,
            'update_uri'      => null,
            'requires_php'    => $theme->get( 'RequiresPHP' ),
            'requires_wp'     => $theme->get( 'RequiresWP' ),
            'screenshot'      => $theme->get_screenshot() ?: null,
            'template'        => $theme->get_template(),
            'stylesheet'      => $theme->get_stylesheet(),
            'stylesheet_dir'  => $theme->get_stylesheet_directory(),
            'status'          => $theme->get( 'Status' ),
        ];

        // Add update information if available
        if ( $has_update ) {
            $update = $update_themes->response[ $stylesheet ];
            $theme_info['new_version'] = $update['new_version'] ?? null;
            $theme_info['update_uri'] = $update['url'] ?? $update['package'] ?? null;
            $theme_info['requires_php'] = $update['requires_php'] ?? $theme_info['requires_php'];
            $theme_info['requires_wp'] = $update['requires'] ?? $theme_info['requires_wp'];
        }

        return $theme_info;
    }

    /**
     * Get detailed active theme information
     *
     * @param \WP_Theme $theme Active theme object.
     * @return array
     */
    private function get_active_theme_info( \WP_Theme $theme ): array {
        return [
            'name'           => $theme->get( 'Name' ),
            'slug'           => $theme->get_stylesheet(),
            'version'        => $theme->get( 'Version' ),
            'is_child'       => (bool) $theme->parent(),
            'parent_name'    => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
            'parent_slug'    => $theme->parent() ? $theme->get_template() : null,
            'screenshot'     => $theme->get_screenshot() ?: null,
            'template_dir'   => $theme->get_template_directory(),
            'stylesheet_dir' => $theme->get_stylesheet_directory(),
        ];
    }
}
