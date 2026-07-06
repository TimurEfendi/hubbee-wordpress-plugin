<?php
/**
 * Options Endpoint - WordPress Options Management
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class OptionsEndpoint extends RestEndpoint {

    /**
     * Whitelisted options that can be read/written
     */
    private array $readable_options = [
        // General Settings
        'blogname',
        'blogdescription',
        'siteurl',
        'home',
        'admin_email',
        'users_can_register',
        'default_role',
        'timezone_string',
        'date_format',
        'time_format',
        'start_of_week',
        'WPLANG',

        // Reading Settings
        'posts_per_page',
        'posts_per_rss',
        'rss_use_excerpt',
        'show_on_front',
        'page_on_front',
        'page_for_posts',
        'blog_public',

        // Discussion Settings
        'default_pingback_flag',
        'default_ping_status',
        'default_comment_status',
        'require_name_email',
        'comment_registration',
        'close_comments_for_old_posts',
        'close_comments_days_old',
        'thread_comments',
        'thread_comments_depth',
        'page_comments',
        'comments_per_page',
        'default_comments_page',
        'comment_order',
        'comment_moderation',
        'comment_previously_approved',
        'moderation_keys',
        'disallowed_keys',
        'show_avatars',
        'avatar_rating',
        'avatar_default',

        // Media Settings
        'thumbnail_size_w',
        'thumbnail_size_h',
        'thumbnail_crop',
        'medium_size_w',
        'medium_size_h',
        'large_size_w',
        'large_size_h',
        'uploads_use_yearmonth_folders',

        // Permalink Settings (read-only)
        'permalink_structure',
        'category_base',
        'tag_base',

        // Privacy
        'wp_page_for_privacy_policy',
    ];

    /**
     * Options that can be written (subset of readable)
     */
    private array $writable_options = [
        'blogname',
        'blogdescription',
        'timezone_string',
        'date_format',
        'time_format',
        'start_of_week',
        'posts_per_page',
        'posts_per_rss',
        'rss_use_excerpt',
        'default_pingback_flag',
        'default_ping_status',
        'default_comment_status',
        'require_name_email',
        'comment_registration',
        'close_comments_for_old_posts',
        'close_comments_days_old',
        'thread_comments',
        'thread_comments_depth',
        'page_comments',
        'comments_per_page',
        'default_comments_page',
        'comment_order',
        'comment_moderation',
        'comment_previously_approved',
        'show_avatars',
        'avatar_rating',
        'avatar_default',
        'thumbnail_size_w',
        'thumbnail_size_h',
        'thumbnail_crop',
        'medium_size_w',
        'medium_size_h',
        'large_size_w',
        'large_size_h',
        'uploads_use_yearmonth_folders',
    ];

    protected function get_routes(): array {
        return [
            [ 'route' => '/options',           'methods' => 'POST', 'callback' => 'get_options' ],
            [ 'route' => '/options/get',       'methods' => 'POST', 'callback' => 'get_specific_options' ],
            [ 'route' => '/options/update',    'methods' => 'POST', 'callback' => 'update_options' ],
            [ 'route' => '/options/whitelist', 'methods' => 'POST', 'callback' => 'get_whitelist' ],
        ];
    }

    /**
     * Get all whitelisted options
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_options( WP_REST_Request $request ): WP_REST_Response {
        $options = [];

        foreach ( $this->readable_options as $option_name ) {
            $value = get_option( $option_name );
            $options[ $option_name ] = [
                'value'    => $value,
                'writable' => in_array( $option_name, $this->writable_options, true ),
            ];
        }

        // Group options by category
        $grouped = [
            'general'    => $this->filter_options_by_keys( $options, [
                'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email',
                'users_can_register', 'default_role', 'timezone_string',
                'date_format', 'time_format', 'start_of_week', 'WPLANG',
            ]),
            'reading'    => $this->filter_options_by_keys( $options, [
                'posts_per_page', 'posts_per_rss', 'rss_use_excerpt',
                'show_on_front', 'page_on_front', 'page_for_posts', 'blog_public',
            ]),
            'discussion' => $this->filter_options_by_keys( $options, [
                'default_pingback_flag', 'default_ping_status', 'default_comment_status',
                'require_name_email', 'comment_registration', 'close_comments_for_old_posts',
                'close_comments_days_old', 'thread_comments', 'thread_comments_depth',
                'page_comments', 'comments_per_page', 'default_comments_page',
                'comment_order', 'comment_moderation', 'comment_previously_approved',
                'moderation_keys', 'disallowed_keys', 'show_avatars', 'avatar_rating',
                'avatar_default',
            ]),
            'media'      => $this->filter_options_by_keys( $options, [
                'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop',
                'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h',
                'uploads_use_yearmonth_folders',
            ]),
            'permalinks' => $this->filter_options_by_keys( $options, [
                'permalink_structure', 'category_base', 'tag_base',
            ]),
            'privacy'    => $this->filter_options_by_keys( $options, [
                'wp_page_for_privacy_policy',
            ]),
        ];

        return new WP_REST_Response([
            'success' => true,
            'options' => $options,
            'grouped' => $grouped,
            'total'   => count( $options ),
        ], 200 );
    }

    /**
     * Get specific options by keys
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_specific_options( WP_REST_Request $request ): WP_REST_Response {
        $body = json_decode( $request->get_body(), true );
        $keys = $body['keys'] ?? [];

        if ( empty( $keys ) || ! is_array( $keys ) ) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'No option keys provided',
            ], 400 );
        }

        $options = [];
        $invalid_keys = [];

        foreach ( $keys as $key ) {
            if ( in_array( $key, $this->readable_options, true ) ) {
                $options[ $key ] = [
                    'value'    => get_option( $key ),
                    'writable' => in_array( $key, $this->writable_options, true ),
                ];
            } else {
                $invalid_keys[] = $key;
            }
        }

        return new WP_REST_Response([
            'success'      => true,
            'options'      => $options,
            'invalid_keys' => $invalid_keys,
        ], 200 );
    }

    /**
     * Update options
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function update_options( WP_REST_Request $request ): WP_REST_Response {
        $body = json_decode( $request->get_body(), true );
        $updates = $body['options'] ?? [];

        if ( empty( $updates ) || ! is_array( $updates ) ) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'No options to update',
            ], 400 );
        }

        $results = [
            'updated'  => [],
            'skipped'  => [],
            'denied'   => [],
            'errors'   => [],
        ];

        foreach ( $updates as $key => $value ) {
            // Check if option is writable
            if ( ! in_array( $key, $this->writable_options, true ) ) {
                if ( in_array( $key, $this->readable_options, true ) ) {
                    $results['denied'][] = [
                        'key'    => $key,
                        'reason' => 'Option is read-only',
                    ];
                } else {
                    $results['denied'][] = [
                        'key'    => $key,
                        'reason' => 'Option not in whitelist',
                    ];
                }
                continue;
            }

            // Get current value
            $current_value = get_option( $key );

            // Skip if value hasn't changed
            if ( $current_value === $value ) {
                $results['skipped'][] = $key;
                continue;
            }

            // Sanitize value based on option type
            $sanitized_value = $this->sanitize_option_value( $key, $value );

            // Update option
            $updated = update_option( $key, $sanitized_value );

            if ( $updated ) {
                $results['updated'][] = [
                    'key'       => $key,
                    'old_value' => $current_value,
                    'new_value' => $sanitized_value,
                ];
            } else {
                $results['errors'][] = [
                    'key'    => $key,
                    'reason' => 'Failed to update option',
                ];
            }
        }

        return new WP_REST_Response([
            'success' => count( $results['errors'] ) === 0,
            'results' => $results,
            'summary' => [
                'updated' => count( $results['updated'] ),
                'skipped' => count( $results['skipped'] ),
                'denied'  => count( $results['denied'] ),
                'errors'  => count( $results['errors'] ),
            ],
        ], 200 );
    }

    /**
     * Get whitelist information
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_whitelist( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response([
            'success'  => true,
            'readable' => $this->readable_options,
            'writable' => $this->writable_options,
        ], 200 );
    }

    /**
     * Filter options by keys
     *
     * @param array $options All options.
     * @param array $keys Keys to filter.
     * @return array
     */
    private function filter_options_by_keys( array $options, array $keys ): array {
        return array_intersect_key( $options, array_flip( $keys ) );
    }

    /**
     * Sanitize option value based on option type
     *
     * @param string $key Option key.
     * @param mixed $value Option value.
     * @return mixed
     */
    private function sanitize_option_value( string $key, $value ) {
        // Integer options
        $integer_options = [
            'posts_per_page', 'posts_per_rss', 'start_of_week',
            'close_comments_days_old', 'thread_comments_depth',
            'comments_per_page', 'thumbnail_size_w', 'thumbnail_size_h',
            'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h',
        ];

        // Boolean options (stored as 0/1 or open/closed)
        $boolean_options = [
            'rss_use_excerpt', 'default_pingback_flag', 'require_name_email',
            'comment_registration', 'close_comments_for_old_posts',
            'thread_comments', 'page_comments', 'comment_moderation',
            'comment_previously_approved', 'show_avatars', 'thumbnail_crop',
            'uploads_use_yearmonth_folders',
        ];

        // Status options (open/closed)
        $status_options = [
            'default_ping_status', 'default_comment_status',
        ];

        if ( in_array( $key, $integer_options, true ) ) {
            return absint( $value );
        }

        if ( in_array( $key, $boolean_options, true ) ) {
            return $value ? 1 : 0;
        }

        if ( in_array( $key, $status_options, true ) ) {
            return in_array( $value, [ 'open', 'closed' ], true ) ? $value : 'closed';
        }

        // Text options
        if ( in_array( $key, [ 'blogname', 'blogdescription' ], true ) ) {
            return sanitize_text_field( $value );
        }

        // Timezone
        if ( $key === 'timezone_string' ) {
            $timezones = timezone_identifiers_list();
            return in_array( $value, $timezones, true ) ? $value : '';
        }

        // Date/time formats
        if ( in_array( $key, [ 'date_format', 'time_format' ], true ) ) {
            return sanitize_text_field( $value );
        }

        // Comment order
        if ( $key === 'comment_order' ) {
            return in_array( $value, [ 'asc', 'desc' ], true ) ? $value : 'asc';
        }

        // Default comments page
        if ( $key === 'default_comments_page' ) {
            return in_array( $value, [ 'newest', 'oldest' ], true ) ? $value : 'newest';
        }

        // Avatar rating
        if ( $key === 'avatar_rating' ) {
            return in_array( $value, [ 'G', 'PG', 'R', 'X' ], true ) ? $value : 'G';
        }

        // Default sanitization
        return sanitize_text_field( $value );
    }
}
