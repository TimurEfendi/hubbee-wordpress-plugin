<?php
/**
 * Content Endpoint - WordPress Content Management
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_Query;

class ContentEndpoint extends RestEndpoint {

    /**
     * Allowed orderby values for post queries (WP_Query columns).
     */
    private const POST_ORDERBY = [ 'date', 'modified', 'title', 'ID', 'author', 'comment_count', 'menu_order', 'rand', 'relevance' ];

    /**
     * Allowed orderby values for page queries (WP_Query columns).
     */
    private const PAGE_ORDERBY = [ 'menu_order', 'date', 'modified', 'title', 'ID', 'author', 'parent', 'rand', 'relevance' ];

    /**
     * Allowed orderby values for media queries (WP_Query columns).
     */
    private const MEDIA_ORDERBY = [ 'date', 'modified', 'title', 'ID', 'author', 'rand' ];

    /**
     * Allowed orderby values for comment queries (WP_Comment_Query columns).
     */
    private const COMMENT_ORDERBY = [ 'comment_date', 'comment_date_gmt', 'comment_ID', 'comment_post_ID', 'comment_author', 'comment_approved' ];

    protected function get_routes(): array {
        return [
            [ 'route' => '/content',            'methods' => 'POST', 'callback' => 'get_content_overview' ],
            [ 'route' => '/content/posts',      'methods' => 'POST', 'callback' => 'get_posts' ],
            [ 'route' => '/content/pages',      'methods' => 'POST', 'callback' => 'get_pages' ],
            [ 'route' => '/content/media',      'methods' => 'POST', 'callback' => 'get_media' ],
            [ 'route' => '/content/post-types', 'methods' => 'POST', 'callback' => 'get_post_types' ],
            [ 'route' => '/content/taxonomies', 'methods' => 'POST', 'callback' => 'get_taxonomies' ],
            [ 'route' => '/content/categories', 'methods' => 'POST', 'callback' => 'get_categories' ],
            [ 'route' => '/content/tags',       'methods' => 'POST', 'callback' => 'get_tags' ],
            [ 'route' => '/content/comments',   'methods' => 'POST', 'callback' => 'get_comments' ],
        ];
    }

    /**
     * Get content overview (stats)
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_content_overview( WP_REST_Request $request ): WP_REST_Response {
        // Count posts by status
        $post_counts = wp_count_posts( 'post' );
        $page_counts = wp_count_posts( 'page' );
        $media_counts = wp_count_posts( 'attachment' );

        // Comments
        $comment_count = wp_count_comments();

        // Categories and tags
        $category_count = wp_count_terms( 'category' );
        $tag_count = wp_count_terms( 'post_tag' );

        // Recent content
        $recent_posts = get_posts([
            'numberposts' => 5,
            'post_status' => 'any',
            'orderby'     => 'modified',
            'order'       => 'DESC',
        ]);

        $recent_pages = get_posts([
            'numberposts' => 5,
            'post_type'   => 'page',
            'post_status' => 'any',
            'orderby'     => 'modified',
            'order'       => 'DESC',
        ]);

        return new WP_REST_Response([
            'success' => true,
            'stats'   => [
                'posts' => [
                    'publish' => (int) $post_counts->publish,
                    'draft'   => (int) $post_counts->draft,
                    'pending' => (int) $post_counts->pending,
                    'private' => (int) $post_counts->private,
                    'trash'   => (int) $post_counts->trash,
                    'total'   => (int) $post_counts->publish + (int) $post_counts->draft + (int) $post_counts->pending + (int) $post_counts->private,
                ],
                'pages' => [
                    'publish' => (int) $page_counts->publish,
                    'draft'   => (int) $page_counts->draft,
                    'pending' => (int) $page_counts->pending,
                    'private' => (int) $page_counts->private,
                    'trash'   => (int) $page_counts->trash,
                    'total'   => (int) $page_counts->publish + (int) $page_counts->draft + (int) $page_counts->pending + (int) $page_counts->private,
                ],
                'media' => [
                    'total'   => (int) $media_counts->inherit,
                    'trash'   => (int) $media_counts->trash,
                ],
                'comments' => [
                    'approved' => (int) $comment_count->approved,
                    'pending'  => (int) $comment_count->moderated,
                    'spam'     => (int) $comment_count->spam,
                    'trash'    => (int) $comment_count->trash,
                    'total'    => (int) $comment_count->total_comments,
                ],
                'categories' => is_wp_error( $category_count ) ? 0 : (int) $category_count,
                'tags'       => is_wp_error( $tag_count ) ? 0 : (int) $tag_count,
            ],
            'recent' => [
                'posts' => array_map( [ $this, 'format_post_summary' ], $recent_posts ),
                'pages' => array_map( [ $this, 'format_post_summary' ], $recent_pages ),
            ],
        ], 200 );
    }

    /**
     * Get posts
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_posts( WP_REST_Request $request ): WP_REST_Response {
        $body = json_decode( $request->get_body(), true );

        $args = [
            'post_type'      => 'post',
            'posts_per_page' => min( $body['per_page'] ?? 20, 100 ),
            'paged'          => $body['page'] ?? 1,
            'post_status'    => $body['status'] ?? 'any',
            'orderby'        => $this->sanitize_orderby( $body['orderby'] ?? null, self::POST_ORDERBY, 'date' ),
            'order'          => $this->sanitize_order( $body['order'] ?? null, 'DESC' ),
        ];

        // Search
        if ( ! empty( $body['search'] ) ) {
            $args['s'] = sanitize_text_field( $body['search'] );
        }

        // Category filter
        if ( ! empty( $body['category'] ) ) {
            $args['cat'] = absint( $body['category'] );
        }

        // Tag filter
        if ( ! empty( $body['tag'] ) ) {
            $args['tag_id'] = absint( $body['tag'] );
        }

        // Author filter
        if ( ! empty( $body['author'] ) ) {
            $args['author'] = absint( $body['author'] );
        }

        $query = new WP_Query( $args );
        $this->prime_post_caches( $query->posts, [ 'category', 'post_tag' ] );
        $posts = array_map( [ $this, 'format_post' ], $query->posts );

        return new WP_REST_Response([
            'success'    => true,
            'posts'      => $posts,
            'total'      => $query->found_posts,
            'pages'      => $query->max_num_pages,
            'page'       => $args['paged'],
            'per_page'   => $args['posts_per_page'],
        ], 200 );
    }

    /**
     * Get pages
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_pages( WP_REST_Request $request ): WP_REST_Response {
        $body = json_decode( $request->get_body(), true );

        $args = [
            'post_type'      => 'page',
            'posts_per_page' => min( $body['per_page'] ?? 20, 100 ),
            'paged'          => $body['page'] ?? 1,
            'post_status'    => $body['status'] ?? 'any',
            'orderby'        => $this->sanitize_orderby( $body['orderby'] ?? null, self::PAGE_ORDERBY, 'menu_order' ),
            'order'          => $this->sanitize_order( $body['order'] ?? null, 'ASC' ),
        ];

        // Search
        if ( ! empty( $body['search'] ) ) {
            $args['s'] = sanitize_text_field( $body['search'] );
        }

        // Parent filter
        if ( isset( $body['parent'] ) ) {
            $args['post_parent'] = absint( $body['parent'] );
        }

        // Author filter
        if ( ! empty( $body['author'] ) ) {
            $args['author'] = absint( $body['author'] );
        }

        $query = new WP_Query( $args );
        $this->prime_post_caches( $query->posts, [] );
        $parent_ids = array_map(
            static function( $page ) {
                return (int) $page->post_parent;
            },
            $query->posts
        );
        $parent_titles = $this->build_title_map( $parent_ids );
        $pages = array_map(
            function( $page ) use ( $parent_titles ) {
                return $this->format_page( $page, $parent_titles );
            },
            $query->posts
        );

        return new WP_REST_Response([
            'success'    => true,
            'pages'      => $pages,
            'total'      => $query->found_posts,
            'pages_count' => $query->max_num_pages,
            'page'       => $args['paged'],
            'per_page'   => $args['posts_per_page'],
        ], 200 );
    }

    /**
     * Get media
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_media( WP_REST_Request $request ): WP_REST_Response {
        $body = json_decode( $request->get_body(), true );

        $args = [
            'post_type'      => 'attachment',
            'posts_per_page' => min( $body['per_page'] ?? 24, 100 ),
            'paged'          => $body['page'] ?? 1,
            'post_status'    => 'inherit',
            'orderby'        => $this->sanitize_orderby( $body['orderby'] ?? null, self::MEDIA_ORDERBY, 'date' ),
            'order'          => $this->sanitize_order( $body['order'] ?? null, 'DESC' ),
        ];

        // Search
        if ( ! empty( $body['search'] ) ) {
            $args['s'] = sanitize_text_field( $body['search'] );
        }

        // MIME type filter
        if ( ! empty( $body['mime_type'] ) ) {
            $args['post_mime_type'] = sanitize_text_field( $body['mime_type'] );
        }

        $query = new WP_Query( $args );
        $this->prime_post_caches( $query->posts, [] );
        $media = array_map( [ $this, 'format_media' ], $query->posts );

        // Get mime type stats
        $mime_stats = $this->get_mime_type_stats();

        return new WP_REST_Response([
            'success'    => true,
            'media'      => $media,
            'total'      => $query->found_posts,
            'pages'      => $query->max_num_pages,
            'page'       => $args['paged'],
            'per_page'   => $args['posts_per_page'],
            'mime_stats' => $mime_stats,
        ], 200 );
    }

    /**
     * Get post types
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_post_types( WP_REST_Request $request ): WP_REST_Response {
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        $result = [];

        foreach ( $post_types as $post_type ) {
            $counts = wp_count_posts( $post_type->name );
            $result[] = [
                'name'         => $post_type->name,
                'label'        => $post_type->label,
                'singular'     => $post_type->labels->singular_name,
                'description'  => $post_type->description,
                'public'       => $post_type->public,
                'hierarchical' => $post_type->hierarchical,
                'has_archive'  => $post_type->has_archive,
                'rest_base'    => $post_type->rest_base ?? $post_type->name,
                'count'        => array_sum( (array) $counts ),
            ];
        }

        return new WP_REST_Response([
            'success'    => true,
            'post_types' => $result,
            'total'      => count( $result ),
        ], 200 );
    }

    /**
     * Get taxonomies
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_taxonomies( WP_REST_Request $request ): WP_REST_Response {
        $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
        $result = [];

        foreach ( $taxonomies as $taxonomy ) {
            $count = wp_count_terms( $taxonomy->name );
            $result[] = [
                'name'         => $taxonomy->name,
                'label'        => $taxonomy->label,
                'singular'     => $taxonomy->labels->singular_name,
                'description'  => $taxonomy->description,
                'public'       => $taxonomy->public,
                'hierarchical' => $taxonomy->hierarchical,
                'object_types' => $taxonomy->object_type,
                'count'        => is_wp_error( $count ) ? 0 : (int) $count,
            ];
        }

        return new WP_REST_Response([
            'success'    => true,
            'taxonomies' => $result,
            'total'      => count( $result ),
        ], 200 );
    }

    /**
     * Get categories
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_categories( WP_REST_Request $request ): WP_REST_Response {
        $body = json_decode( $request->get_body(), true );

        $args = [
            'taxonomy'   => 'category',
            'number'     => min( $body['per_page'] ?? 100, 500 ),
            'offset'     => ( ( $body['page'] ?? 1 ) - 1 ) * ( $body['per_page'] ?? 100 ),
            'orderby'    => $body['orderby'] ?? 'name',
            'order'      => $body['order'] ?? 'ASC',
            'hide_empty' => $body['hide_empty'] ?? false,
        ];

        // Search
        if ( ! empty( $body['search'] ) ) {
            $args['search'] = sanitize_text_field( $body['search'] );
        }

        // Parent filter
        if ( isset( $body['parent'] ) ) {
            $args['parent'] = absint( $body['parent'] );
        }

        $categories = get_terms( $args );

        if ( is_wp_error( $categories ) ) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => $categories->get_error_message(),
            ], 400 );
        }

        $total = wp_count_terms( [ 'taxonomy' => 'category', 'hide_empty' => $body['hide_empty'] ?? false ] );

        $result = array_map( function( $term ) {
            return [
                'id'          => $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'parent'      => $term->parent,
                'count'       => $term->count,
                'link'        => get_term_link( $term ),
            ];
        }, $categories );

        return new WP_REST_Response([
            'success'    => true,
            'categories' => $result,
            'total'      => is_wp_error( $total ) ? count( $result ) : (int) $total,
        ], 200 );
    }

    /**
     * Get tags
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_tags( WP_REST_Request $request ): WP_REST_Response {
        $body = json_decode( $request->get_body(), true );

        $args = [
            'taxonomy'   => 'post_tag',
            'number'     => min( $body['per_page'] ?? 100, 500 ),
            'offset'     => ( ( $body['page'] ?? 1 ) - 1 ) * ( $body['per_page'] ?? 100 ),
            'orderby'    => $body['orderby'] ?? 'count',
            'order'      => $body['order'] ?? 'DESC',
            'hide_empty' => $body['hide_empty'] ?? false,
        ];

        // Search
        if ( ! empty( $body['search'] ) ) {
            $args['search'] = sanitize_text_field( $body['search'] );
        }

        $tags = get_terms( $args );

        if ( is_wp_error( $tags ) ) {
            return new WP_REST_Response([
                'success' => false,
                'error'   => $tags->get_error_message(),
            ], 400 );
        }

        $total = wp_count_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => $body['hide_empty'] ?? false ] );

        $result = array_map( function( $term ) {
            return [
                'id'          => $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'count'       => $term->count,
                'link'        => get_term_link( $term ),
            ];
        }, $tags );

        return new WP_REST_Response([
            'success' => true,
            'tags'    => $result,
            'total'   => is_wp_error( $total ) ? count( $result ) : (int) $total,
        ], 200 );
    }

    /**
     * Get comments
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response
     */
    public function get_comments( WP_REST_Request $request ): WP_REST_Response {
        $body = json_decode( $request->get_body(), true );

        $args = [
            'number'  => min( $body['per_page'] ?? 20, 100 ),
            'offset'  => ( ( $body['page'] ?? 1 ) - 1 ) * ( $body['per_page'] ?? 20 ),
            'orderby' => $this->sanitize_orderby( $body['orderby'] ?? null, self::COMMENT_ORDERBY, 'comment_date' ),
            'order'   => $this->sanitize_order( $body['order'] ?? null, 'DESC' ),
            'status'  => $body['status'] ?? 'all',
        ];

        // Post filter
        if ( ! empty( $body['post_id'] ) ) {
            $args['post_id'] = absint( $body['post_id'] );
        }

        // Search
        if ( ! empty( $body['search'] ) ) {
            $args['search'] = sanitize_text_field( $body['search'] );
        }

        $comments_query = new \WP_Comment_Query();
        $comments = $comments_query->query( $args );

        // Get total count
        $count_args = $args;
        unset( $count_args['number'], $count_args['offset'] );
        $count_args['count'] = true;
        $total = $comments_query->query( $count_args );

        $post_titles = $this->build_title_map(
            array_map(
                static function( $comment ) {
                    return (int) $comment->comment_post_ID;
                },
                $comments
            )
        );

        $result = array_map( function( $comment ) use ( $post_titles ) {
            $post_id = (int) $comment->comment_post_ID;
            return [
                'id'           => (int) $comment->comment_ID,
                'post_id'      => $post_id,
                'post_title'   => $post_titles[ $post_id ] ?? '',
                'author'       => $comment->comment_author,
                'author_email' => $comment->comment_author_email,
                'author_url'   => $comment->comment_author_url,
                'content'      => wp_trim_words( $comment->comment_content, 30 ),
                'date'         => $comment->comment_date,
                'status'       => wp_get_comment_status( $comment ),
                'parent'       => (int) $comment->comment_parent,
                'type'         => $comment->comment_type ?: 'comment',
            ];
        }, $comments );

        return new WP_REST_Response([
            'success'  => true,
            'comments' => $result,
            'total'    => (int) $total,
            'page'     => $body['page'] ?? 1,
            'per_page' => $args['number'],
        ], 200 );
    }

    // =========================================================================
    // PUBLIC DATA METHODS (for CommandExecutor)
    // =========================================================================

    /**
     * Get content overview data
     *
     * @return array
     */
    public function get_content_overview_data(): array {
        // Count posts by status
        $post_counts = wp_count_posts( 'post' );
        $page_counts = wp_count_posts( 'page' );
        $media_counts = wp_count_posts( 'attachment' );

        // Comments
        $comment_count = wp_count_comments();

        // Categories and tags
        $category_count = wp_count_terms( 'category' );
        $tag_count = wp_count_terms( 'post_tag' );

        // Recent content
        $recent_posts = get_posts([
            'numberposts' => 5,
            'post_status' => 'any',
            'orderby'     => 'modified',
            'order'       => 'DESC',
        ]);

        $recent_pages = get_posts([
            'numberposts' => 5,
            'post_type'   => 'page',
            'post_status' => 'any',
            'orderby'     => 'modified',
            'order'       => 'DESC',
        ]);

        return [
            'success' => true,
            'stats'   => [
                'posts' => [
                    'publish' => (int) $post_counts->publish,
                    'draft'   => (int) $post_counts->draft,
                    'pending' => (int) $post_counts->pending,
                    'private' => (int) $post_counts->private,
                    'trash'   => (int) $post_counts->trash,
                    'total'   => (int) $post_counts->publish + (int) $post_counts->draft + (int) $post_counts->pending + (int) $post_counts->private,
                ],
                'pages' => [
                    'publish' => (int) $page_counts->publish,
                    'draft'   => (int) $page_counts->draft,
                    'pending' => (int) $page_counts->pending,
                    'private' => (int) $page_counts->private,
                    'trash'   => (int) $page_counts->trash,
                    'total'   => (int) $page_counts->publish + (int) $page_counts->draft + (int) $page_counts->pending + (int) $page_counts->private,
                ],
                'media' => [
                    'total'   => (int) $media_counts->inherit,
                    'trash'   => (int) $media_counts->trash,
                ],
                'comments' => [
                    'approved' => (int) $comment_count->approved,
                    'pending'  => (int) $comment_count->moderated,
                    'spam'     => (int) $comment_count->spam,
                    'trash'    => (int) $comment_count->trash,
                    'total'    => (int) $comment_count->total_comments,
                ],
                'categories' => is_wp_error( $category_count ) ? 0 : (int) $category_count,
                'tags'       => is_wp_error( $tag_count ) ? 0 : (int) $tag_count,
            ],
            'recent' => [
                'posts' => array_map( [ $this, 'format_post_summary' ], $recent_posts ),
                'pages' => array_map( [ $this, 'format_post_summary' ], $recent_pages ),
            ],
        ];
    }

    /**
     * Get posts data
     *
     * @param array $params Query parameters.
     * @return array
     */
    public function get_posts_data( array $params ): array {
        $args = [
            'post_type'      => 'post',
            'posts_per_page' => min( $params['per_page'] ?? 20, 100 ),
            'paged'          => $params['page'] ?? 1,
            'post_status'    => $params['status'] ?? 'any',
            'orderby'        => $this->sanitize_orderby( $params['orderby'] ?? null, self::POST_ORDERBY, 'date' ),
            'order'          => $this->sanitize_order( $params['order'] ?? null, 'DESC' ),
        ];

        // Search
        if ( ! empty( $params['search'] ) ) {
            $args['s'] = sanitize_text_field( $params['search'] );
        }

        // Category filter
        if ( ! empty( $params['category'] ) ) {
            $args['cat'] = absint( $params['category'] );
        }

        // Tag filter
        if ( ! empty( $params['tag'] ) ) {
            $args['tag_id'] = absint( $params['tag'] );
        }

        // Author filter
        if ( ! empty( $params['author'] ) ) {
            $args['author'] = absint( $params['author'] );
        }

        $query = new WP_Query( $args );
        $this->prime_post_caches( $query->posts, [ 'category', 'post_tag' ] );
        $posts = array_map( [ $this, 'format_post' ], $query->posts );

        return [
            'success'    => true,
            'posts'      => $posts,
            'total'      => $query->found_posts,
            'pages'      => $query->max_num_pages,
            'page'       => $args['paged'],
            'per_page'   => $args['posts_per_page'],
        ];
    }

    /**
     * Get pages data
     *
     * @param array $params Query parameters.
     * @return array
     */
    public function get_pages_data( array $params ): array {
        $args = [
            'post_type'      => 'page',
            'posts_per_page' => min( $params['per_page'] ?? 20, 100 ),
            'paged'          => $params['page'] ?? 1,
            'post_status'    => $params['status'] ?? 'any',
            'orderby'        => $this->sanitize_orderby( $params['orderby'] ?? null, self::PAGE_ORDERBY, 'menu_order' ),
            'order'          => $this->sanitize_order( $params['order'] ?? null, 'ASC' ),
        ];

        // Search
        if ( ! empty( $params['search'] ) ) {
            $args['s'] = sanitize_text_field( $params['search'] );
        }

        // Parent filter
        if ( isset( $params['parent'] ) ) {
            $args['post_parent'] = absint( $params['parent'] );
        }

        // Author filter
        if ( ! empty( $params['author'] ) ) {
            $args['author'] = absint( $params['author'] );
        }

        $query = new WP_Query( $args );
        $this->prime_post_caches( $query->posts, [] );
        $parent_ids = array_map(
            static function( $page ) {
                return (int) $page->post_parent;
            },
            $query->posts
        );
        $parent_titles = $this->build_title_map( $parent_ids );
        $pages = array_map(
            function( $page ) use ( $parent_titles ) {
                return $this->format_page( $page, $parent_titles );
            },
            $query->posts
        );

        return [
            'success'     => true,
            'pages'       => $pages,
            'total'       => $query->found_posts,
            'pages_count' => $query->max_num_pages,
            'page'        => $args['paged'],
            'per_page'    => $args['posts_per_page'],
        ];
    }

    /**
     * Get media data
     *
     * @param array $params Query parameters.
     * @return array
     */
    public function get_media_data( array $params ): array {
        $args = [
            'post_type'      => 'attachment',
            'posts_per_page' => min( $params['per_page'] ?? 24, 100 ),
            'paged'          => $params['page'] ?? 1,
            'post_status'    => 'inherit',
            'orderby'        => $this->sanitize_orderby( $params['orderby'] ?? null, self::MEDIA_ORDERBY, 'date' ),
            'order'          => $this->sanitize_order( $params['order'] ?? null, 'DESC' ),
        ];

        // Search
        if ( ! empty( $params['search'] ) ) {
            $args['s'] = sanitize_text_field( $params['search'] );
        }

        // MIME type filter
        if ( ! empty( $params['mime_type'] ) ) {
            $args['post_mime_type'] = sanitize_text_field( $params['mime_type'] );
        }

        $query = new WP_Query( $args );
        $this->prime_post_caches( $query->posts, [] );
        $media = array_map( [ $this, 'format_media' ], $query->posts );

        // Get mime type stats
        $mime_stats = $this->get_mime_type_stats();

        return [
            'success'    => true,
            'media'      => $media,
            'total'      => $query->found_posts,
            'pages'      => $query->max_num_pages,
            'page'       => $args['paged'],
            'per_page'   => $args['posts_per_page'],
            'mime_stats' => $mime_stats,
        ];
    }

    /**
     * Get post types data
     *
     * @return array
     */
    public function get_post_types_data(): array {
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        $result = [];

        foreach ( $post_types as $post_type ) {
            $counts = wp_count_posts( $post_type->name );
            $result[] = [
                'name'         => $post_type->name,
                'label'        => $post_type->label,
                'singular'     => $post_type->labels->singular_name,
                'description'  => $post_type->description,
                'public'       => $post_type->public,
                'hierarchical' => $post_type->hierarchical,
                'has_archive'  => $post_type->has_archive,
                'rest_base'    => $post_type->rest_base ?? $post_type->name,
                'count'        => array_sum( (array) $counts ),
            ];
        }

        return [
            'success'    => true,
            'post_types' => $result,
            'total'      => count( $result ),
        ];
    }

    /**
     * Get taxonomies data
     *
     * @return array
     */
    public function get_taxonomies_data(): array {
        $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
        $result = [];

        foreach ( $taxonomies as $taxonomy ) {
            $count = wp_count_terms( $taxonomy->name );
            $result[] = [
                'name'         => $taxonomy->name,
                'label'        => $taxonomy->label,
                'singular'     => $taxonomy->labels->singular_name,
                'description'  => $taxonomy->description,
                'public'       => $taxonomy->public,
                'hierarchical' => $taxonomy->hierarchical,
                'object_types' => $taxonomy->object_type,
                'count'        => is_wp_error( $count ) ? 0 : (int) $count,
            ];
        }

        return [
            'success'    => true,
            'taxonomies' => $result,
            'total'      => count( $result ),
        ];
    }

    /**
     * Get categories data
     *
     * @param array $params Query parameters.
     * @return array
     */
    public function get_categories_data( array $params ): array {
        $args = [
            'taxonomy'   => 'category',
            'number'     => min( $params['per_page'] ?? 100, 500 ),
            'offset'     => ( ( $params['page'] ?? 1 ) - 1 ) * ( $params['per_page'] ?? 100 ),
            'orderby'    => $params['orderby'] ?? 'name',
            'order'      => $params['order'] ?? 'ASC',
            'hide_empty' => $params['hide_empty'] ?? false,
        ];

        // Search
        if ( ! empty( $params['search'] ) ) {
            $args['search'] = sanitize_text_field( $params['search'] );
        }

        // Parent filter
        if ( isset( $params['parent'] ) ) {
            $args['parent'] = absint( $params['parent'] );
        }

        $categories = get_terms( $args );

        if ( is_wp_error( $categories ) ) {
            return [
                'success' => false,
                'error'   => $categories->get_error_message(),
            ];
        }

        $total = wp_count_terms( [ 'taxonomy' => 'category', 'hide_empty' => $params['hide_empty'] ?? false ] );

        $result = array_map( function( $term ) {
            return [
                'id'          => $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'parent'      => $term->parent,
                'count'       => $term->count,
                'link'        => get_term_link( $term ),
            ];
        }, $categories );

        return [
            'success'    => true,
            'categories' => $result,
            'total'      => is_wp_error( $total ) ? count( $result ) : (int) $total,
        ];
    }

    /**
     * Get tags data
     *
     * @param array $params Query parameters.
     * @return array
     */
    public function get_tags_data( array $params ): array {
        $args = [
            'taxonomy'   => 'post_tag',
            'number'     => min( $params['per_page'] ?? 100, 500 ),
            'offset'     => ( ( $params['page'] ?? 1 ) - 1 ) * ( $params['per_page'] ?? 100 ),
            'orderby'    => $params['orderby'] ?? 'count',
            'order'      => $params['order'] ?? 'DESC',
            'hide_empty' => $params['hide_empty'] ?? false,
        ];

        // Search
        if ( ! empty( $params['search'] ) ) {
            $args['search'] = sanitize_text_field( $params['search'] );
        }

        $tags = get_terms( $args );

        if ( is_wp_error( $tags ) ) {
            return [
                'success' => false,
                'error'   => $tags->get_error_message(),
            ];
        }

        $total = wp_count_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => $params['hide_empty'] ?? false ] );

        $result = array_map( function( $term ) {
            return [
                'id'          => $term->term_id,
                'name'        => $term->name,
                'slug'        => $term->slug,
                'description' => $term->description,
                'count'       => $term->count,
                'link'        => get_term_link( $term ),
            ];
        }, $tags );

        return [
            'success' => true,
            'tags'    => $result,
            'total'   => is_wp_error( $total ) ? count( $result ) : (int) $total,
        ];
    }

    /**
     * Get comments data
     *
     * @param array $params Query parameters.
     * @return array
     */
    public function get_comments_data( array $params ): array {
        $args = [
            'number'  => min( $params['per_page'] ?? 20, 100 ),
            'offset'  => ( ( $params['page'] ?? 1 ) - 1 ) * ( $params['per_page'] ?? 20 ),
            'orderby' => $this->sanitize_orderby( $params['orderby'] ?? null, self::COMMENT_ORDERBY, 'comment_date' ),
            'order'   => $this->sanitize_order( $params['order'] ?? null, 'DESC' ),
            'status'  => $params['status'] ?? 'all',
        ];

        // Post filter
        if ( ! empty( $params['post_id'] ) ) {
            $args['post_id'] = absint( $params['post_id'] );
        }

        // Search
        if ( ! empty( $params['search'] ) ) {
            $args['search'] = sanitize_text_field( $params['search'] );
        }

        $comments_query = new \WP_Comment_Query();
        $comments = $comments_query->query( $args );

        // Get total count
        $count_args = $args;
        unset( $count_args['number'], $count_args['offset'] );
        $count_args['count'] = true;
        $total = $comments_query->query( $count_args );

        $post_titles = $this->build_title_map(
            array_map(
                static function( $comment ) {
                    return (int) $comment->comment_post_ID;
                },
                $comments
            )
        );

        $result = array_map( function( $comment ) use ( $post_titles ) {
            $post_id = (int) $comment->comment_post_ID;
            return [
                'id'           => (int) $comment->comment_ID,
                'post_id'      => $post_id,
                'post_title'   => $post_titles[ $post_id ] ?? '',
                'author'       => $comment->comment_author,
                'author_email' => $comment->comment_author_email,
                'author_url'   => $comment->comment_author_url,
                'content'      => wp_trim_words( $comment->comment_content, 30 ),
                'date'         => $comment->comment_date,
                'status'       => wp_get_comment_status( $comment ),
                'parent'       => (int) $comment->comment_parent,
                'type'         => $comment->comment_type ?: 'comment',
            ];
        }, $comments );

        return [
            'success'  => true,
            'comments' => $result,
            'total'    => (int) $total,
            'page'     => $params['page'] ?? 1,
            'per_page' => $args['number'],
        ];
    }

    // =========================================================================
    // PRIVATE QUERY HELPERS
    // =========================================================================

    /**
     * Prime term and meta caches for a result set so per-post formatting
     * (categories, tags, featured image, page templates) reads from cache
     * instead of issuing one query per post.
     *
     * @param \WP_Post[] $posts      Post objects from the query.
     * @param string[]   $taxonomies Taxonomies to prime term caches for.
     * @return void
     */
    private function prime_post_caches( array $posts, array $taxonomies ): void {
        $ids = array_map(
            static function( $post ) {
                return (int) $post->ID;
            },
            $posts
        );

        if ( empty( $ids ) ) {
            return;
        }

        if ( ! empty( $taxonomies ) ) {
            update_object_term_cache( $ids, $taxonomies );
        }
        update_postmeta_cache( $ids );
    }

    /**
     * Build an [ id => title ] map for a set of post IDs in a single query,
     * avoiding repeated get_the_title() lookups inside formatting loops.
     *
     * @param int[] $ids Post IDs (may contain duplicates / zero values).
     * @return array<int, string> Map of post ID to title.
     */
    private function build_title_map( array $ids ): array {
        $unique_ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

        if ( empty( $unique_ids ) ) {
            return [];
        }

        $found = get_posts([
            'post__in'       => $unique_ids,
            'post_type'      => 'any',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'post__in',
        ]);

        $map = [];
        foreach ( $found as $post ) {
            $map[ (int) $post->ID ] = get_the_title( $post );
        }

        return $map;
    }

    /**
     * Sanitize an orderby value against an explicit allowlist, falling back to
     * the supplied default when the value is missing or unsupported.
     *
     * @param mixed    $value   Raw orderby value from the request body.
     * @param string[] $allowed Allowed orderby values.
     * @param string   $default Default orderby when invalid.
     * @return string
     */
    private function sanitize_orderby( $value, array $allowed, string $default ): string {
        if ( is_string( $value ) && in_array( $value, $allowed, true ) ) {
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

    // =========================================================================
    // PRIVATE FORMAT METHODS
    // =========================================================================

    /**
     * Format post for response
     *
     * @param \WP_Post $post Post object.
     * @return array
     */
    private function format_post( $post ): array {
        $author = get_userdata( $post->post_author );
        $categories = wp_get_post_categories( $post->ID, [ 'fields' => 'all' ] );
        $tags = wp_get_post_tags( $post->ID, [ 'fields' => 'all' ] );
        $thumbnail_id = get_post_thumbnail_id( $post->ID );

        return [
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'slug'           => $post->post_name,
            'excerpt'        => get_the_excerpt( $post ),
            'status'         => $post->post_status,
            'date'           => $post->post_date,
            'modified'       => $post->post_modified,
            'author'         => [
                'id'   => (int) $post->post_author,
                'name' => $author ? $author->display_name : null,
            ],
            'categories'     => array_map( function( $cat ) {
                return [
                    'id'   => $cat->term_id,
                    'name' => $cat->name,
                    'slug' => $cat->slug,
                ];
            }, $categories ),
            'tags'           => array_map( function( $tag ) {
                return [
                    'id'   => $tag->term_id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                ];
            }, $tags ),
            'featured_image' => $thumbnail_id ? [
                'id'  => $thumbnail_id,
                'url' => wp_get_attachment_image_url( $thumbnail_id, 'medium' ),
            ] : null,
            'comment_status' => $post->comment_status,
            'comment_count'  => (int) $post->comment_count,
            'permalink'      => get_permalink( $post ),
            'edit_link'      => get_edit_post_link( $post->ID, 'raw' ),
        ];
    }

    /**
     * Format page for response
     *
     * @param \WP_Post           $page          Page object.
     * @param array<int, string> $parent_titles Optional pre-resolved [ id => title ] map for parents.
     * @return array
     */
    private function format_page( $page, array $parent_titles = [] ): array {
        $author = get_userdata( $page->post_author );
        $thumbnail_id = get_post_thumbnail_id( $page->ID );
        $template = get_page_template_slug( $page->ID );

        $parent_id = (int) $page->post_parent;
        if ( $parent_id ) {
            $parent_title = $parent_titles[ $parent_id ] ?? get_the_title( $parent_id );
        } else {
            $parent_title = null;
        }

        return [
            'id'             => $page->ID,
            'title'          => $page->post_title,
            'slug'           => $page->post_name,
            'excerpt'        => get_the_excerpt( $page ),
            'status'         => $page->post_status,
            'date'           => $page->post_date,
            'modified'       => $page->post_modified,
            'author'         => [
                'id'   => (int) $page->post_author,
                'name' => $author ? $author->display_name : null,
            ],
            'parent'         => $page->post_parent,
            'parent_title'   => $parent_title,
            'menu_order'     => $page->menu_order,
            'template'       => $template ?: 'default',
            'featured_image' => $thumbnail_id ? [
                'id'  => $thumbnail_id,
                'url' => wp_get_attachment_image_url( $thumbnail_id, 'medium' ),
            ] : null,
            'comment_status' => $page->comment_status,
            'comment_count'  => (int) $page->comment_count,
            'permalink'      => get_permalink( $page ),
            'edit_link'      => get_edit_post_link( $page->ID, 'raw' ),
        ];
    }

    /**
     * Format media for response
     *
     * @param \WP_Post $media Media object.
     * @return array
     */
    private function format_media( $media ): array {
        $metadata = wp_get_attachment_metadata( $media->ID );
        $file_path = get_attached_file( $media->ID );

        return [
            'id'          => $media->ID,
            'title'       => $media->post_title,
            'caption'     => $media->post_excerpt,
            'alt_text'    => get_post_meta( $media->ID, '_wp_attachment_image_alt', true ),
            'description' => $media->post_content,
            'date'        => $media->post_date,
            'modified'    => $media->post_modified,
            'mime_type'   => $media->post_mime_type,
            'url'         => wp_get_attachment_url( $media->ID ),
            'thumbnail'   => wp_get_attachment_image_url( $media->ID, 'thumbnail' ),
            'medium'      => wp_get_attachment_image_url( $media->ID, 'medium' ),
            'filename'    => basename( $file_path ),
            'filesize'    => $file_path && file_exists( $file_path ) ? size_format( filesize( $file_path ) ) : null,
            'dimensions'  => isset( $metadata['width'], $metadata['height'] ) ? [
                'width'  => $metadata['width'],
                'height' => $metadata['height'],
            ] : null,
            'edit_link'   => get_edit_post_link( $media->ID, 'raw' ),
        ];
    }

    /**
     * Format post summary for recent content
     *
     * @param \WP_Post $post Post object.
     * @return array
     */
    private function format_post_summary( $post ): array {
        return [
            'id'       => $post->ID,
            'title'    => $post->post_title,
            'status'   => $post->post_status,
            'modified' => $post->post_modified,
        ];
    }

    /**
     * Get MIME type statistics
     *
     * @return array
     */
    private function get_mime_type_stats(): array {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT post_mime_type, COUNT(*) as count
             FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_status = 'inherit'
             GROUP BY post_mime_type
             ORDER BY count DESC"
        );

        $stats = [];
        foreach ( $results as $row ) {
            $type = explode( '/', $row->post_mime_type )[0];
            if ( ! isset( $stats[ $type ] ) ) {
                $stats[ $type ] = 0;
            }
            $stats[ $type ] += (int) $row->count;
        }

        return $stats;
    }
}
