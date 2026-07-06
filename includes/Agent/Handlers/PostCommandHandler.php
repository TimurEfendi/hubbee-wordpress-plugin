<?php
/**
 * Post-related commands.
 *
 * Owns: post.status, post.create, post.update, post.delete.
 *
 * Behaviour mirrors the legacy CommandExecutor::execute_post_* methods 1:1.
 * `$this->in_batch` checks have been replaced with `BatchContext::is_active()`
 * — the BatchContext service is set/cleared by CommandExecutor::execute_batch.
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

use Hubbee\Agent\BatchContext;
use Hubbee\Agent\EventPusher;
use WP_Error;

class PostCommandHandler implements CommandHandler {

    public static function command_types(): array {
        return [ 'post.status', 'post.create', 'post.update', 'post.delete' ];
    }

    public function execute( string $type, array $payload ) {
        switch ( $type ) {
            case 'post.status':
                return $this->post_status( $payload );
            case 'post.create':
                return $this->post_create( $payload );
            case 'post.update':
                return $this->post_update( $payload );
            case 'post.delete':
                return $this->post_delete( $payload );
            default:
                return new WP_Error( 'bz_unknown_post_command', sprintf( 'Unknown post command: %s', $type ) );
        }
    }

    private function post_status( array $payload ) {
        $post_id = absint( $payload['post_id'] ?? 0 );
        $status  = sanitize_text_field( $payload['status'] ?? '' );

        if ( ! $post_id ) {
            return new WP_Error( 'bz_missing_post_id', __( 'Post ID is missing.', 'hubbee' ) );
        }

        $valid_statuses = [ 'publish', 'draft', 'pending', 'private', 'trash' ];
        if ( ! in_array( $status, $valid_statuses, true ) ) {
            return new WP_Error( 'bz_invalid_status', __( 'Invalid status.', 'hubbee' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'post' !== $post->post_type ) {
            return new WP_Error( 'bz_post_not_found', __( 'Post not found.', 'hubbee' ) );
        }

        $result = wp_update_post( [
            'ID'          => $post_id,
            'post_status' => $status,
        ], true );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( ! BatchContext::is_active() ) {
            EventPusher::get_instance()->push( 'content_changed', [
                'type'       => 'post',
                'action'     => 'status_changed',
                'post_id'    => $post_id,
                'new_status' => $status,
                'post'       => $this->format_post_for_event( $post_id ),
                'timestamp'  => current_time( 'mysql' ),
            ] );
        }

        return [ 'success' => true, 'post_id' => $post_id, 'status' => $status ];
    }

    private function post_create( array $payload ) {
        $title   = sanitize_text_field( $payload['title'] ?? '' );
        $content = wp_kses_post( $payload['content'] ?? '' );
        $status  = sanitize_text_field( $payload['status'] ?? 'draft' );
        $excerpt = wp_kses_post( $payload['excerpt'] ?? '' );

        if ( empty( $title ) ) {
            return new WP_Error( 'bz_missing_title', __( 'Title is required.', 'hubbee' ) );
        }

        $valid_statuses = [ 'publish', 'draft', 'pending', 'private' ];
        if ( ! in_array( $status, $valid_statuses, true ) ) {
            $status = 'draft';
        }

        $post_id = wp_insert_post( [
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $status,
            'post_excerpt' => $excerpt,
            'post_type'    => 'post',
            'post_author'  => get_current_user_id() ?: 1,
        ], true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        if ( ! empty( $payload['categories'] ) && is_array( $payload['categories'] ) ) {
            wp_set_post_categories( $post_id, array_map( 'absint', $payload['categories'] ) );
        }
        if ( ! empty( $payload['tags'] ) && is_array( $payload['tags'] ) ) {
            wp_set_post_tags( $post_id, array_map( 'absint', $payload['tags'] ) );
        }
        if ( ! empty( $payload['featured_image_id'] ) ) {
            set_post_thumbnail( $post_id, absint( $payload['featured_image_id'] ) );
        }

        EventPusher::get_instance()->push( 'content_changed', [
            'type'      => 'post',
            'action'    => 'created',
            'post_id'   => $post_id,
            'post'      => $this->format_post_for_event( $post_id ),
            'timestamp' => current_time( 'mysql' ),
        ] );

        return [ 'success' => true, 'post_id' => $post_id, 'status' => $status ];
    }

    private function post_update( array $payload ) {
        $post_id = absint( $payload['post_id'] ?? 0 );

        if ( ! $post_id ) {
            return new WP_Error( 'bz_missing_post_id', __( 'Post ID is missing.', 'hubbee' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'post' !== $post->post_type ) {
            return new WP_Error( 'bz_post_not_found', __( 'Post not found.', 'hubbee' ) );
        }

        $post_data = [ 'ID' => $post_id ];
        if ( isset( $payload['title'] ) ) {
            $post_data['post_title'] = sanitize_text_field( $payload['title'] );
        }
        if ( isset( $payload['content'] ) ) {
            $post_data['post_content'] = wp_kses_post( $payload['content'] );
        }
        if ( isset( $payload['status'] ) ) {
            $valid_statuses = [ 'publish', 'draft', 'pending', 'private', 'trash' ];
            if ( in_array( $payload['status'], $valid_statuses, true ) ) {
                $post_data['post_status'] = $payload['status'];
            }
        }
        if ( isset( $payload['excerpt'] ) ) {
            $post_data['post_excerpt'] = wp_kses_post( $payload['excerpt'] );
        }

        $result = wp_update_post( $post_data, true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( isset( $payload['categories'] ) && is_array( $payload['categories'] ) ) {
            wp_set_post_categories( $post_id, array_map( 'absint', $payload['categories'] ) );
        }
        if ( isset( $payload['tags'] ) && is_array( $payload['tags'] ) ) {
            wp_set_post_tags( $post_id, array_map( 'absint', $payload['tags'] ) );
        }
        if ( array_key_exists( 'featured_image_id', $payload ) ) {
            if ( $payload['featured_image_id'] ) {
                set_post_thumbnail( $post_id, absint( $payload['featured_image_id'] ) );
            } else {
                delete_post_thumbnail( $post_id );
            }
        }

        EventPusher::get_instance()->push( 'content_changed', [
            'type'      => 'post',
            'action'    => 'updated',
            'post_id'   => $post_id,
            'post'      => $this->format_post_for_event( $post_id ),
            'timestamp' => current_time( 'mysql' ),
        ] );

        return [ 'success' => true, 'post_id' => $post_id ];
    }

    private function post_delete( array $payload ) {
        $post_id = absint( $payload['post_id'] ?? 0 );
        $force   = (bool) ( $payload['force'] ?? false );

        if ( ! $post_id ) {
            return new WP_Error( 'bz_missing_post_id', __( 'Post ID is missing.', 'hubbee' ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || 'post' !== $post->post_type ) {
            return new WP_Error( 'bz_post_not_found', __( 'Post not found.', 'hubbee' ) );
        }

        $result = wp_delete_post( $post_id, $force );
        if ( ! $result ) {
            return new WP_Error( 'bz_delete_failed', __( 'Post could not be deleted.', 'hubbee' ) );
        }

        if ( ! BatchContext::is_active() ) {
            EventPusher::get_instance()->push( 'content_changed', [
                'type'      => 'post',
                'action'    => $force ? 'deleted' : 'trashed',
                'post_id'   => $post_id,
                'timestamp' => current_time( 'mysql' ),
            ] );
        }

        return [
            'success'             => true,
            'post_id'             => $post_id,
            'permanently_deleted' => $force,
        ];
    }

    /**
     * Build the rich post payload that ships with `content_changed` events.
     */
    private function format_post_for_event( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return [];
        }

        $categories = [];
        $terms      = get_the_terms( $post_id, 'category' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $categories[] = [ 'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug ];
            }
        }

        $tags      = [];
        $tag_terms = get_the_terms( $post_id, 'post_tag' );
        if ( $tag_terms && ! is_wp_error( $tag_terms ) ) {
            foreach ( $tag_terms as $term ) {
                $tags[] = [ 'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug ];
            }
        }

        $featured_image = null;
        $thumbnail_id   = get_post_thumbnail_id( $post_id );
        if ( $thumbnail_id ) {
            $featured_image = [ 'id' => $thumbnail_id, 'url' => wp_get_attachment_url( $thumbnail_id ) ];
        }

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
                'name' => get_the_author_meta( 'display_name', $post->post_author ),
            ],
            'categories'     => $categories,
            'tags'           => $tags,
            'featured_image' => $featured_image,
            'comment_status' => $post->comment_status,
            'comment_count'  => (int) $post->comment_count,
            'permalink'      => get_permalink( $post ),
            'edit_link'      => get_edit_post_link( $post, 'raw' ),
        ];
    }
}
