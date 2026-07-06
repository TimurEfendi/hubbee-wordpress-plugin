<?php
/**
 * Users Endpoint - WordPress User Management
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_User_Query;

class UsersEndpoint extends RestEndpoint {

    /**
     * Allowed orderby values for user queries (WP_User_Query columns).
     */
    private const USER_ORDERBY = [ 'registered', 'display_name', 'login', 'nicename', 'email', 'url', 'ID', 'post_count' ];

    /**
     * Transient key + TTL for cached role-count aggregation. count_users()
     * triggers a full users/usermeta scan, so the result is cached briefly to
     * spare repeated list requests from re-running it.
     */
    private const ROLE_COUNTS_TRANSIENT = 'bz_role_counts';
    private const ROLE_COUNTS_TTL       = 300;

    protected function get_routes(): array {
        return [
            [ 'route' => '/users',         'methods' => 'POST', 'callback' => 'get_users' ],
            [ 'route' => '/users/roles',   'methods' => 'POST', 'callback' => 'get_roles' ],
            [ 'route' => '/users/by-role', 'methods' => 'POST', 'callback' => 'get_users_by_role' ],
            [ 'route' => '/users/stats',   'methods' => 'POST', 'callback' => 'get_user_stats' ],
        ];
    }

    /**
     * Get users list
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_users( WP_REST_Request $request ): WP_REST_Response {
        $body = json_decode( $request->get_body(), true );

        $args = [
            'number'  => min( $body['per_page'] ?? 20, 100 ),
            'paged'   => $body['page'] ?? 1,
            'orderby' => $this->sanitize_orderby( $body['orderby'] ?? null, 'registered' ),
            'order'   => $this->sanitize_order( $body['order'] ?? null, 'DESC' ),
        ];

        // Search
        if ( ! empty( $body['search'] ) ) {
            $args['search'] = '*' . sanitize_text_field( $body['search'] ) . '*';
            $args['search_columns'] = [ 'user_login', 'user_email', 'user_nicename', 'display_name' ];
        }

        // Role filter
        if ( ! empty( $body['role'] ) ) {
            $args['role'] = sanitize_text_field( $body['role'] );
        }

        $user_query = new WP_User_Query( $args );
        $users = $user_query->get_results();
        $total = $user_query->get_total();

        $this->prime_user_caches( $users );
        $formatted_users = array_map( [ $this, 'format_user' ], $users );

        // Get role counts for stats
        $role_counts = $this->get_role_counts();

        return new WP_REST_Response([
            'success'     => true,
            'users'       => $formatted_users,
            'total'       => $total,
            'pages'       => ceil( $total / $args['number'] ),
            'page'        => $args['paged'],
            'per_page'    => $args['number'],
            'role_counts' => $role_counts,
        ], 200 );
    }

    /**
     * Get available roles
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_roles( WP_REST_Request $request ): WP_REST_Response {
        global $wp_roles;

        $role_counts = $this->get_role_counts();

        $roles = [];
        foreach ( $wp_roles->roles as $role_slug => $role_data ) {
            $roles[] = [
                'slug'         => $role_slug,
                'name'         => translate_user_role( $role_data['name'] ),
                'capabilities' => array_keys( array_filter( $role_data['capabilities'] ) ),
                'user_count'   => $role_counts[ $role_slug ] ?? 0,
            ];
        }

        // Sort by user count (descending)
        usort( $roles, function( $a, $b ) {
            return $b['user_count'] - $a['user_count'];
        });

        return new WP_REST_Response([
            'success' => true,
            'roles'   => $roles,
            'total'   => count( $roles ),
        ], 200 );
    }

    /**
     * Get users by specific role
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_users_by_role( WP_REST_Request $request ): WP_REST_Response {
        $body = json_decode( $request->get_body(), true );
        $role = $body['role'] ?? '';

        if ( empty( $role ) ) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => 'Role parameter is required',
            ], 400 );
        }

        $args = [
            'role'    => sanitize_text_field( $role ),
            'number'  => min( $body['per_page'] ?? 50, 100 ),
            'paged'   => $body['page'] ?? 1,
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ];

        $user_query = new WP_User_Query( $args );
        $users = $user_query->get_results();
        $total = $user_query->get_total();

        $this->prime_user_caches( $users );
        $formatted_users = array_map( [ $this, 'format_user' ], $users );

        return new WP_REST_Response([
            'success'  => true,
            'role'     => $role,
            'users'    => $formatted_users,
            'total'    => $total,
            'pages'    => ceil( $total / $args['number'] ),
            'page'     => $args['paged'],
            'per_page' => $args['number'],
        ], 200 );
    }

    /**
     * Get user statistics
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_user_stats( WP_REST_Request $request ): WP_REST_Response {
        $role_counts = $this->get_role_counts();
        $total_users = array_sum( $role_counts );

        // Recent registrations
        $recent_query = new WP_User_Query([
            'number'  => 10,
            'orderby' => 'registered',
            'order'   => 'DESC',
        ]);
        $recent_users = array_map( function( $user ) {
            return [
                'id'         => $user->ID,
                'username'   => $user->user_login,
                'email'      => $user->user_email,
                'display_name' => $user->display_name,
                'registered' => $user->user_registered,
                'roles'      => $user->roles,
            ];
        }, $recent_query->get_results() );

        // Users registered in last 30 days
        $thirty_days_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
        $new_users_query = new WP_User_Query([
            'date_query' => [
                [
                    'after'     => $thirty_days_ago,
                    'inclusive' => true,
                ],
            ],
            'count_total' => true,
        ]);
        $new_users_count = $new_users_query->get_total();

        // Users with posts
        global $wpdb;
        $users_with_posts = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_author) FROM {$wpdb->posts} WHERE post_status = 'publish'"
        );

        return new WP_REST_Response([
            'success' => true,
            'stats'   => [
                'total_users'       => $total_users,
                'role_distribution' => $role_counts,
                'new_last_30_days'  => $new_users_count,
                'users_with_posts'  => $users_with_posts,
            ],
            'recent_registrations' => $recent_users,
        ], 200 );
    }

    // =========================================================================
    // PUBLIC DATA METHODS (for CommandExecutor)
    // =========================================================================

    /**
     * Get users data
     *
     * @param array $params Query parameters.
     * @return array
     */
    public function get_users_data( array $params ): array {
        $args = [
            'number'  => min( $params['per_page'] ?? 20, 100 ),
            'paged'   => $params['page'] ?? 1,
            'orderby' => $this->sanitize_orderby( $params['orderby'] ?? null, 'registered' ),
            'order'   => $this->sanitize_order( $params['order'] ?? null, 'DESC' ),
        ];

        // Search
        if ( ! empty( $params['search'] ) ) {
            $args['search'] = '*' . sanitize_text_field( $params['search'] ) . '*';
            $args['search_columns'] = [ 'user_login', 'user_email', 'user_nicename', 'display_name' ];
        }

        // Role filter
        if ( ! empty( $params['role'] ) ) {
            $args['role'] = sanitize_text_field( $params['role'] );
        }

        $user_query = new WP_User_Query( $args );
        $users = $user_query->get_results();
        $total = $user_query->get_total();

        $this->prime_user_caches( $users );
        $formatted_users = array_map( [ $this, 'format_user' ], $users );

        // Get role counts for stats
        $role_counts = $this->get_role_counts();

        return [
            'success'     => true,
            'users'       => $formatted_users,
            'total'       => $total,
            'pages'       => ceil( $total / $args['number'] ),
            'page'        => $args['paged'],
            'per_page'    => $args['number'],
            'role_counts' => $role_counts,
        ];
    }

    /**
     * Get roles data
     *
     * @return array
     */
    public function get_roles_data(): array {
        global $wp_roles;

        $role_counts = $this->get_role_counts();

        $roles = [];
        foreach ( $wp_roles->roles as $role_slug => $role_data ) {
            $roles[] = [
                'slug'         => $role_slug,
                'name'         => translate_user_role( $role_data['name'] ),
                'capabilities' => array_keys( array_filter( $role_data['capabilities'] ) ),
                'user_count'   => $role_counts[ $role_slug ] ?? 0,
            ];
        }

        // Sort by user count (descending)
        usort( $roles, function( $a, $b ) {
            return $b['user_count'] - $a['user_count'];
        });

        return [
            'success' => true,
            'roles'   => $roles,
            'total'   => count( $roles ),
        ];
    }

    /**
     * Get users by role data
     *
     * @param array $params Query parameters.
     * @return array
     */
    public function get_users_by_role_data( array $params ): array {
        $role = $params['role'] ?? '';

        if ( empty( $role ) ) {
            return [
                'success' => false,
                'error'   => 'Role parameter is required',
            ];
        }

        $args = [
            'role'    => sanitize_text_field( $role ),
            'number'  => min( $params['per_page'] ?? 50, 100 ),
            'paged'   => $params['page'] ?? 1,
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ];

        $user_query = new WP_User_Query( $args );
        $users = $user_query->get_results();
        $total = $user_query->get_total();

        $this->prime_user_caches( $users );
        $formatted_users = array_map( [ $this, 'format_user' ], $users );

        return [
            'success'  => true,
            'role'     => $role,
            'users'    => $formatted_users,
            'total'    => $total,
            'pages'    => ceil( $total / $args['number'] ),
            'page'     => $args['paged'],
            'per_page' => $args['number'],
        ];
    }

    /**
     * Get user stats data
     *
     * @return array
     */
    public function get_user_stats_data(): array {
        $role_counts = $this->get_role_counts();
        $total_users = array_sum( $role_counts );

        // Recent registrations
        $recent_query = new WP_User_Query([
            'number'  => 10,
            'orderby' => 'registered',
            'order'   => 'DESC',
        ]);
        $recent_users = array_map( function( $user ) {
            return [
                'id'           => $user->ID,
                'username'     => $user->user_login,
                'email'        => $user->user_email,
                'display_name' => $user->display_name,
                'registered'   => $user->user_registered,
                'roles'        => $user->roles,
            ];
        }, $recent_query->get_results() );

        // Users registered in last 30 days
        $thirty_days_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
        $new_users_query = new WP_User_Query([
            'date_query' => [
                [
                    'after'     => $thirty_days_ago,
                    'inclusive' => true,
                ],
            ],
            'count_total' => true,
        ]);
        $new_users_count = $new_users_query->get_total();

        // Users with posts
        global $wpdb;
        $users_with_posts = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_author) FROM {$wpdb->posts} WHERE post_status = 'publish'"
        );

        return [
            'success' => true,
            'stats'   => [
                'total_users'       => $total_users,
                'role_distribution' => $role_counts,
                'new_last_30_days'  => $new_users_count,
                'users_with_posts'  => $users_with_posts,
            ],
            'recent_registrations' => $recent_users,
        ];
    }

    // =========================================================================
    // PRIVATE FORMAT METHODS
    // =========================================================================

    /**
     * Format user for response (no sensitive data)
     *
     * @param \WP_User $user User object.
     * @return array
     */
    private function format_user( $user ): array {
        // Get user's post count
        $post_count = count_user_posts( $user->ID );

        // Get last login if available (from popular plugins)
        $last_login = get_user_meta( $user->ID, 'last_login', true );
        if ( ! $last_login ) {
            $last_login = get_user_meta( $user->ID, 'wfls-last-login', true ); // Wordfence
        }
        if ( ! $last_login ) {
            $last_login = get_user_meta( $user->ID, '_last_login', true );
        }

        return [
            'id'           => $user->ID,
            'username'     => $user->user_login,
            'email'        => $user->user_email,
            'display_name' => $user->display_name,
            'first_name'   => $user->first_name,
            'last_name'    => $user->last_name,
            'nickname'     => $user->nickname,
            'url'          => $user->user_url,
            'registered'   => $user->user_registered,
            'roles'        => $user->roles,
            'role_display' => $this->get_role_display_names( $user->roles ),
            'avatar_url'   => get_avatar_url( $user->ID, [ 'size' => 96 ] ),
            'post_count'   => $post_count,
            'last_login'   => $last_login ?: null,
            'capabilities' => $this->get_user_capability_summary( $user ),
            'edit_link'    => get_edit_user_link( $user->ID ),
        ];
    }

    /**
     * Get role counts
     *
     * Cached in a short-lived transient because count_users() performs a full
     * users/usermeta scan; without the cache every list request re-runs it.
     *
     * @return array
     */
    private function get_role_counts(): array {
        $cached = get_transient( self::ROLE_COUNTS_TRANSIENT );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        global $wp_roles;

        $users = count_users();
        $avail_roles = $users['avail_roles'] ?? [];

        $counts = [];
        foreach ( array_keys( $wp_roles->roles ) as $role ) {
            $counts[ $role ] = (int) ( $avail_roles[ $role ] ?? 0 );
        }

        // Sort by count descending
        arsort( $counts );

        set_transient( self::ROLE_COUNTS_TRANSIENT, $counts, self::ROLE_COUNTS_TTL );

        return $counts;
    }

    /**
     * Prime the WP user cache (user objects + usermeta) for a result set so
     * per-user formatting reads meta/capabilities from cache rather than
     * issuing a query per user.
     *
     * @param \WP_User[] $users User objects from the query.
     * @return void
     */
    private function prime_user_caches( array $users ): void {
        $ids = array_map(
            static function( $user ) {
                return (int) $user->ID;
            },
            $users
        );

        if ( empty( $ids ) ) {
            return;
        }

        cache_users( $ids );
        update_meta_cache( 'user', $ids );
    }

    /**
     * Sanitize an orderby value against the WP_User_Query allowlist, falling
     * back to the supplied default when the value is missing or unsupported.
     *
     * @param mixed  $value   Raw orderby value from the request body.
     * @param string $default Default orderby when invalid.
     * @return string
     */
    private function sanitize_orderby( $value, string $default ): string {
        if ( is_string( $value ) && in_array( $value, self::USER_ORDERBY, true ) ) {
            return $value;
        }
        return $default;
    }

    /**
     * Sanitize an order value to ASC/DESC, falling back to the supplied
     * default when the value is missing or unsupported.
     *
     * @param mixed  $value   Raw order value from the request body.
     * @param string $default Default order when invalid.
     * @return string
     */
    private function sanitize_order( $value, string $default ): string {
        if ( is_string( $value ) ) {
            $upper = strtoupper( $value );
            if ( 'ASC' === $upper || 'DESC' === $upper ) {
                return $upper;
            }
        }
        return $default;
    }

    /**
     * Get display names for roles
     *
     * @param array $roles Role slugs.
     * @return array
     */
    private function get_role_display_names( array $roles ): array {
        global $wp_roles;

        $display_names = [];
        foreach ( $roles as $role ) {
            if ( isset( $wp_roles->roles[ $role ] ) ) {
                $display_names[] = translate_user_role( $wp_roles->roles[ $role ]['name'] );
            } else {
                $display_names[] = ucfirst( $role );
            }
        }

        return $display_names;
    }

    /**
     * Get user capability summary
     *
     * @param \WP_User $user User object.
     * @return array
     */
    private function get_user_capability_summary( $user ): array {
        $key_capabilities = [
            'manage_options'     => 'Site Admin',
            'edit_others_posts'  => 'Edit Others Posts',
            'publish_posts'      => 'Publish Posts',
            'edit_posts'         => 'Edit Posts',
            'upload_files'       => 'Upload Files',
            'moderate_comments'  => 'Moderate Comments',
            'manage_categories'  => 'Manage Categories',
            'edit_themes'        => 'Edit Themes',
            'edit_plugins'       => 'Edit Plugins',
            'install_plugins'    => 'Install Plugins',
            'activate_plugins'   => 'Activate Plugins',
        ];

        $has_capabilities = [];
        foreach ( $key_capabilities as $cap => $label ) {
            if ( $user->has_cap( $cap ) ) {
                $has_capabilities[] = $cap;
            }
        }

        return $has_capabilities;
    }
}
