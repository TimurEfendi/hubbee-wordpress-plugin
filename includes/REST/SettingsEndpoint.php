<?php
/**
 * Settings Endpoint - WordPress Site Settings
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class SettingsEndpoint extends RestEndpoint {

    protected function get_routes(): array {
        return [
            [ 'route' => '/settings',            'methods' => 'POST', 'callback' => 'get_settings' ],
            [ 'route' => '/settings/general',    'methods' => 'POST', 'callback' => 'get_general_settings' ],
            [ 'route' => '/settings/reading',    'methods' => 'POST', 'callback' => 'get_reading_settings' ],
            [ 'route' => '/settings/discussion', 'methods' => 'POST', 'callback' => 'get_discussion_settings' ],
            [ 'route' => '/settings/media',      'methods' => 'POST', 'callback' => 'get_media_settings' ],
            [ 'route' => '/settings/permalinks', 'methods' => 'POST', 'callback' => 'get_permalink_settings' ],
            [ 'route' => '/settings/menus',      'methods' => 'POST', 'callback' => 'get_menus' ],
            [ 'route' => '/settings/widgets',    'methods' => 'POST', 'callback' => 'get_widgets' ],
            [ 'route' => '/settings/ssl',        'methods' => 'POST', 'callback' => 'get_ssl_info' ],
        ];
    }

    /**
     * Get all settings overview
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_settings( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response([
            'success'  => true,
            'general'  => $this->build_general_settings(),
            'reading'  => $this->build_reading_settings(),
            'discussion' => $this->build_discussion_settings(),
            'media'    => $this->build_media_settings(),
            'permalinks' => $this->build_permalink_settings(),
            'ssl'      => $this->build_ssl_info(),
        ], 200 );
    }

    /**
     * Get general settings
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_general_settings( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response([
            'success'  => true,
            'settings' => $this->build_general_settings(),
        ], 200 );
    }

    /**
     * Get reading settings
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_reading_settings( WP_REST_Request $request ): WP_REST_Response {
        $settings = $this->build_reading_settings();

        // Add page titles if front page/posts page are set
        if ( $settings['page_on_front'] ) {
            $page = get_post( $settings['page_on_front'] );
            $settings['page_on_front_title'] = $page ? $page->post_title : null;
        }
        if ( $settings['page_for_posts'] ) {
            $page = get_post( $settings['page_for_posts'] );
            $settings['page_for_posts_title'] = $page ? $page->post_title : null;
        }

        // Get available pages for selection
        $pages = get_pages( [ 'post_status' => 'publish', 'number' => 100 ] );
        $available_pages = array_map( function( $page ) {
            return [
                'id'    => $page->ID,
                'title' => $page->post_title,
            ];
        }, $pages );

        return new WP_REST_Response([
            'success'         => true,
            'settings'        => $settings,
            'available_pages' => $available_pages,
        ], 200 );
    }

    /**
     * Get discussion settings
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_discussion_settings( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response([
            'success'  => true,
            'settings' => $this->build_discussion_settings(),
        ], 200 );
    }

    /**
     * Get media settings
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_media_settings( WP_REST_Request $request ): WP_REST_Response {
        $settings = $this->build_media_settings();

        // Add upload directory info
        $upload_dir = wp_upload_dir();
        $settings['upload_path'] = $upload_dir['basedir'];
        $settings['upload_url'] = $upload_dir['baseurl'];

        return new WP_REST_Response([
            'success'  => true,
            'settings' => $settings,
        ], 200 );
    }

    /**
     * Get permalink settings
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_permalink_settings( WP_REST_Request $request ): WP_REST_Response {
        $settings = $this->build_permalink_settings();

        // Add common permalink structures
        $settings['common_structures'] = [
            'plain'      => '',
            'day_name'   => '/%year%/%monthnum%/%day%/%postname%/',
            'month_name' => '/%year%/%monthnum%/%postname%/',
            'numeric'    => '/archives/%post_id%',
            'post_name'  => '/%postname%/',
        ];

        // Determine current structure type
        $current = $settings['permalink_structure'];
        $settings['structure_type'] = 'custom';
        foreach ( $settings['common_structures'] as $type => $structure ) {
            if ( $current === $structure ) {
                $settings['structure_type'] = $type;
                break;
            }
        }

        return new WP_REST_Response([
            'success'  => true,
            'settings' => $settings,
        ], 200 );
    }

    /**
     * Get navigation menus
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_menus( WP_REST_Request $request ): WP_REST_Response {
        $menus = wp_get_nav_menus();
        $locations = get_registered_nav_menus();
        $assigned_locations = get_nav_menu_locations();

        $menu_data = [];
        foreach ( $menus as $menu ) {
            $items = wp_get_nav_menu_items( $menu->term_id );
            $item_count = $items ? count( $items ) : 0;

            // Find assigned locations for this menu
            $menu_locations = [];
            foreach ( $assigned_locations as $location => $menu_id ) {
                if ( $menu_id === $menu->term_id ) {
                    $menu_locations[] = [
                        'slug'  => $location,
                        'label' => $locations[ $location ] ?? $location,
                    ];
                }
            }

            $menu_data[] = [
                'id'          => $menu->term_id,
                'name'        => $menu->name,
                'slug'        => $menu->slug,
                'description' => $menu->description,
                'item_count'  => $item_count,
                'locations'   => $menu_locations,
            ];
        }

        // Available locations
        $location_data = [];
        foreach ( $locations as $slug => $label ) {
            $location_data[] = [
                'slug'        => $slug,
                'label'       => $label,
                'assigned_to' => $assigned_locations[ $slug ] ?? null,
            ];
        }

        return new WP_REST_Response([
            'success'   => true,
            'menus'     => $menu_data,
            'locations' => $location_data,
            'total'     => count( $menu_data ),
        ], 200 );
    }

    /**
     * Get widgets
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_widgets( WP_REST_Request $request ): WP_REST_Response {
        global $wp_registered_sidebars, $wp_registered_widgets;

        // Get sidebars (widget areas)
        $sidebars = [];
        $sidebars_widgets = wp_get_sidebars_widgets();

        foreach ( $wp_registered_sidebars as $sidebar_id => $sidebar ) {
            $widgets_in_sidebar = $sidebars_widgets[ $sidebar_id ] ?? [];
            $widget_data = [];

            foreach ( $widgets_in_sidebar as $widget_id ) {
                if ( isset( $wp_registered_widgets[ $widget_id ] ) ) {
                    $widget = $wp_registered_widgets[ $widget_id ];
                    $widget_data[] = [
                        'id'   => $widget_id,
                        'name' => $widget['name'] ?? $widget_id,
                    ];
                }
            }

            $sidebars[] = [
                'id'           => $sidebar_id,
                'name'         => $sidebar['name'],
                'description'  => $sidebar['description'] ?? '',
                'class'        => $sidebar['class'] ?? '',
                'widget_count' => count( $widget_data ),
                'widgets'      => $widget_data,
            ];
        }

        // Get available widgets
        global $wp_widget_factory;
        $available_widgets = [];

        if ( isset( $wp_widget_factory->widgets ) ) {
            foreach ( $wp_widget_factory->widgets as $widget_class => $widget_obj ) {
                $available_widgets[] = [
                    'id_base'     => $widget_obj->id_base,
                    'name'        => $widget_obj->name,
                    'description' => $widget_obj->widget_options['description'] ?? '',
                ];
            }
        }

        return new WP_REST_Response([
            'success'           => true,
            'sidebars'          => $sidebars,
            'available_widgets' => $available_widgets,
            'total_sidebars'    => count( $sidebars ),
        ], 200 );
    }

    /**
     * Get SSL info
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_ssl_info( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response([
            'success' => true,
            'ssl'     => $this->build_ssl_info(),
        ], 200 );
    }

    // =========================================================================
    // PUBLIC DATA METHODS (for CommandExecutor)
    // =========================================================================

    /**
     * Get general settings data
     *
     * @return array
     */
    public function get_general_settings_data(): array {
        return $this->build_general_settings();
    }

    /**
     * Get reading settings data
     *
     * @return array
     */
    public function get_reading_settings_data(): array {
        $settings = $this->build_reading_settings();

        // Add page titles if front page/posts page are set
        if ( $settings['page_on_front'] ) {
            $page = get_post( $settings['page_on_front'] );
            $settings['page_on_front_title'] = $page ? $page->post_title : null;
        }
        if ( $settings['page_for_posts'] ) {
            $page = get_post( $settings['page_for_posts'] );
            $settings['page_for_posts_title'] = $page ? $page->post_title : null;
        }

        return $settings;
    }

    /**
     * Get available pages data (for reading settings)
     *
     * @return array
     */
    public function get_available_pages_data(): array {
        $pages = get_pages( [ 'post_status' => 'publish', 'number' => 100 ] );
        return array_map( function( $page ) {
            return [
                'id'    => $page->ID,
                'title' => $page->post_title,
            ];
        }, $pages );
    }

    /**
     * Get discussion settings data
     *
     * @return array
     */
    public function get_discussion_settings_data(): array {
        return $this->build_discussion_settings();
    }

    /**
     * Get media settings data
     *
     * @return array
     */
    public function get_media_settings_data(): array {
        $settings = $this->build_media_settings();

        // Add upload directory info
        $upload_dir = wp_upload_dir();
        $settings['upload_path'] = $upload_dir['basedir'];
        $settings['upload_url'] = $upload_dir['baseurl'];

        return $settings;
    }

    /**
     * Get permalink settings data
     *
     * @return array
     */
    public function get_permalink_settings_data(): array {
        $settings = $this->build_permalink_settings();

        // Add common permalink structures
        $settings['common_structures'] = [
            'plain'      => '',
            'day_name'   => '/%year%/%monthnum%/%day%/%postname%/',
            'month_name' => '/%year%/%monthnum%/%postname%/',
            'numeric'    => '/archives/%post_id%',
            'post_name'  => '/%postname%/',
        ];

        // Determine current structure type
        $current = $settings['permalink_structure'];
        $settings['structure_type'] = 'custom';
        foreach ( $settings['common_structures'] as $type => $structure ) {
            if ( $current === $structure ) {
                $settings['structure_type'] = $type;
                break;
            }
        }

        return $settings;
    }

    /**
     * Get SSL info data
     *
     * @return array
     */
    public function get_ssl_info_data(): array {
        return $this->build_ssl_info();
    }

    /**
     * Get menus data
     *
     * @return array
     */
    public function get_menus_data(): array {
        $menus = wp_get_nav_menus();
        $locations = get_registered_nav_menus();
        $assigned_locations = get_nav_menu_locations();

        $menu_data = [];
        foreach ( $menus as $menu ) {
            $items = wp_get_nav_menu_items( $menu->term_id );
            $item_count = $items ? count( $items ) : 0;

            // Find assigned locations for this menu
            $menu_locations = [];
            foreach ( $assigned_locations as $location => $menu_id ) {
                if ( $menu_id === $menu->term_id ) {
                    $menu_locations[] = [
                        'slug'  => $location,
                        'label' => $locations[ $location ] ?? $location,
                    ];
                }
            }

            $menu_data[] = [
                'id'          => $menu->term_id,
                'name'        => $menu->name,
                'slug'        => $menu->slug,
                'description' => $menu->description,
                'item_count'  => $item_count,
                'locations'   => $menu_locations,
            ];
        }

        // Available locations
        $location_data = [];
        foreach ( $locations as $slug => $label ) {
            $location_data[] = [
                'slug'        => $slug,
                'label'       => $label,
                'assigned_to' => $assigned_locations[ $slug ] ?? null,
            ];
        }

        return [
            'success'   => true,
            'menus'     => $menu_data,
            'locations' => $location_data,
            'total'     => count( $menu_data ),
        ];
    }

    /**
     * Get widgets data
     *
     * @return array
     */
    public function get_widgets_data(): array {
        global $wp_registered_sidebars, $wp_registered_widgets;

        // Get sidebars (widget areas)
        $sidebars = [];
        $sidebars_widgets = wp_get_sidebars_widgets();

        foreach ( $wp_registered_sidebars as $sidebar_id => $sidebar ) {
            $widgets_in_sidebar = $sidebars_widgets[ $sidebar_id ] ?? [];
            $widget_data = [];

            foreach ( $widgets_in_sidebar as $widget_id ) {
                if ( isset( $wp_registered_widgets[ $widget_id ] ) ) {
                    $widget = $wp_registered_widgets[ $widget_id ];
                    $widget_data[] = [
                        'id'   => $widget_id,
                        'name' => $widget['name'] ?? $widget_id,
                    ];
                }
            }

            $sidebars[] = [
                'id'           => $sidebar_id,
                'name'         => $sidebar['name'],
                'description'  => $sidebar['description'] ?? '',
                'class'        => $sidebar['class'] ?? '',
                'widget_count' => count( $widget_data ),
                'widgets'      => $widget_data,
            ];
        }

        // Get available widgets
        global $wp_widget_factory;
        $available_widgets = [];

        if ( isset( $wp_widget_factory->widgets ) ) {
            foreach ( $wp_widget_factory->widgets as $widget_class => $widget_obj ) {
                $available_widgets[] = [
                    'id_base'     => $widget_obj->id_base,
                    'name'        => $widget_obj->name,
                    'description' => $widget_obj->widget_options['description'] ?? '',
                ];
            }
        }

        return [
            'success'           => true,
            'sidebars'          => $sidebars,
            'available_widgets' => $available_widgets,
            'total_sidebars'    => count( $sidebars ),
        ];
    }

    // =========================================================================
    // PRIVATE BUILD METHODS
    // =========================================================================

    /**
     * Build general settings array
     *
     * @return array
     */
    private function build_general_settings(): array {
        return [
            'blogname'        => get_option( 'blogname' ),
            'blogdescription' => get_option( 'blogdescription' ),
            'siteurl'         => get_option( 'siteurl' ),
            'home'            => get_option( 'home' ),
            'admin_email'     => get_option( 'admin_email' ),
            'timezone_string' => get_option( 'timezone_string' ),
            'gmt_offset'      => get_option( 'gmt_offset' ),
            'date_format'     => get_option( 'date_format' ),
            'time_format'     => get_option( 'time_format' ),
            'start_of_week'   => get_option( 'start_of_week' ),
            'language'        => get_locale(),
            'site_language'   => get_option( 'WPLANG' ),
        ];
    }

    /**
     * Build reading settings array
     *
     * @return array
     */
    private function build_reading_settings(): array {
        return [
            'posts_per_page'   => (int) get_option( 'posts_per_page' ),
            'posts_per_rss'    => (int) get_option( 'posts_per_rss' ),
            'rss_use_excerpt'  => (bool) get_option( 'rss_use_excerpt' ),
            'show_on_front'    => get_option( 'show_on_front' ),
            'page_on_front'    => (int) get_option( 'page_on_front' ),
            'page_for_posts'   => (int) get_option( 'page_for_posts' ),
            'blog_public'      => (bool) get_option( 'blog_public' ),
        ];
    }

    /**
     * Build discussion settings array
     *
     * @return array
     */
    private function build_discussion_settings(): array {
        return [
            'default_pingback_flag'       => (bool) get_option( 'default_pingback_flag' ),
            'default_ping_status'         => get_option( 'default_ping_status' ),
            'default_comment_status'      => get_option( 'default_comment_status' ),
            'require_name_email'          => (bool) get_option( 'require_name_email' ),
            'comment_registration'        => (bool) get_option( 'comment_registration' ),
            'close_comments_for_old_posts' => (bool) get_option( 'close_comments_for_old_posts' ),
            'close_comments_days_old'     => (int) get_option( 'close_comments_days_old' ),
            'thread_comments'             => (bool) get_option( 'thread_comments' ),
            'thread_comments_depth'       => (int) get_option( 'thread_comments_depth' ),
            'page_comments'               => (bool) get_option( 'page_comments' ),
            'comments_per_page'           => (int) get_option( 'comments_per_page' ),
            'default_comments_page'       => get_option( 'default_comments_page' ),
            'comment_order'               => get_option( 'comment_order' ),
            'comment_moderation'          => (bool) get_option( 'comment_moderation' ),
            'comment_previously_approved' => (bool) get_option( 'comment_previously_approved' ),
            'show_avatars'                => (bool) get_option( 'show_avatars' ),
            'avatar_rating'               => get_option( 'avatar_rating' ),
            'avatar_default'              => get_option( 'avatar_default' ),
        ];
    }

    /**
     * Build media settings array
     *
     * @return array
     */
    private function build_media_settings(): array {
        return [
            'thumbnail_size_w'            => (int) get_option( 'thumbnail_size_w' ),
            'thumbnail_size_h'            => (int) get_option( 'thumbnail_size_h' ),
            'thumbnail_crop'              => (bool) get_option( 'thumbnail_crop' ),
            'medium_size_w'               => (int) get_option( 'medium_size_w' ),
            'medium_size_h'               => (int) get_option( 'medium_size_h' ),
            'large_size_w'                => (int) get_option( 'large_size_w' ),
            'large_size_h'                => (int) get_option( 'large_size_h' ),
            'uploads_use_yearmonth_folders' => (bool) get_option( 'uploads_use_yearmonth_folders' ),
        ];
    }

    /**
     * Build permalink settings array
     *
     * @return array
     */
    private function build_permalink_settings(): array {
        global $wp_rewrite;

        return [
            'permalink_structure' => get_option( 'permalink_structure' ),
            'category_base'       => get_option( 'category_base' ),
            'tag_base'            => get_option( 'tag_base' ),
            'using_permalinks'    => $wp_rewrite->using_permalinks(),
            'using_index_permalinks' => $wp_rewrite->using_index_permalinks(),
        ];
    }

    /**
     * Build SSL info array
     *
     * @return array
     */
    private function build_ssl_info(): array {
        $ssl_info = [
            'is_ssl'     => is_ssl(),
            'site_uses_ssl' => strpos( get_option( 'siteurl' ), 'https://' ) === 0,
            'home_uses_ssl' => strpos( get_option( 'home' ), 'https://' ) === 0,
        ];

        // Try to get certificate info if available
        if ( is_ssl() && ! empty( $_SERVER['SSL_SERVER_CERT'] ) ) {
            $cert = openssl_x509_parse( $_SERVER['SSL_SERVER_CERT'] );
            if ( $cert ) {
                $ssl_info['certificate'] = [
                    'subject'     => $cert['subject']['CN'] ?? null,
                    'issuer'      => $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? null,
                    'valid_from'  => $cert['validFrom_time_t'] ? gmdate( 'Y-m-d H:i:s', $cert['validFrom_time_t'] ) : null,
                    'valid_to'    => $cert['validTo_time_t'] ? gmdate( 'Y-m-d H:i:s', $cert['validTo_time_t'] ) : null,
                    'days_until_expiry' => $cert['validTo_time_t'] ? floor( ( $cert['validTo_time_t'] - time() ) / 86400 ) : null,
                ];
            }
        }

        return $ssl_info;
    }
}
