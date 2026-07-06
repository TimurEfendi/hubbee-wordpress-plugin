<?php
/**
 * Page-related commands.
 *
 * Owns: page.status, page.create, page.update, page.delete.
 *
 * Mirrors PostCommandHandler structure but for `page` post-type with
 * page-specific fields (parent, menu_order, _wp_page_template).
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

use Hubbee\Agent\BatchContext;
use Hubbee\Agent\EventPusher;
use WP_Error;

class PageCommandHandler implements CommandHandler {

    public static function command_types(): array {
        return [ 'page.status', 'page.create', 'page.update', 'page.delete' ];
    }

    public function execute( string $type, array $payload ) {
        switch ( $type ) {
            case 'page.status':
                return $this->page_status( $payload );
            case 'page.create':
                return $this->page_create( $payload );
            case 'page.update':
                return $this->page_update( $payload );
            case 'page.delete':
                return $this->page_delete( $payload );
            default:
                return new WP_Error( 'bz_unknown_page_command', sprintf( 'Unknown page command: %s', $type ) );
        }
    }

    private function page_status( array $payload ) {
        $page_id = absint( $payload['page_id'] ?? $payload['post_id'] ?? 0 );
        $status  = sanitize_text_field( $payload['status'] ?? '' );

        if ( ! $page_id ) {
            return new WP_Error( 'bz_missing_page_id', __( 'Page ID is missing.', 'hubbee' ) );
        }

        $valid_statuses = [ 'publish', 'draft', 'pending', 'private', 'trash' ];
        if ( ! in_array( $status, $valid_statuses, true ) ) {
            return new WP_Error( 'bz_invalid_status', __( 'Invalid status.', 'hubbee' ) );
        }

        $page = get_post( $page_id );
        if ( ! $page || 'page' !== $page->post_type ) {
            return new WP_Error( 'bz_page_not_found', __( 'Page not found.', 'hubbee' ) );
        }

        $result = wp_update_post( [
            'ID'          => $page_id,
            'post_status' => $status,
        ], true );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( ! BatchContext::is_active() ) {
            EventPusher::get_instance()->push( 'content_changed', [
                'type'       => 'page',
                'action'     => 'status_changed',
                'page_id'    => $page_id,
                'new_status' => $status,
                'page'       => $this->format_page_for_event( $page_id ),
                'timestamp'  => current_time( 'mysql' ),
            ] );
        }

        return [ 'success' => true, 'page_id' => $page_id, 'status' => $status ];
    }

    private function page_create( array $payload ) {
        $title      = sanitize_text_field( $payload['title'] ?? '' );
        $content    = wp_kses_post( $payload['content'] ?? '' );
        $status     = sanitize_text_field( $payload['status'] ?? 'draft' );
        $excerpt    = wp_kses_post( $payload['excerpt'] ?? '' );
        $parent_id  = absint( $payload['parent_id'] ?? 0 );
        $menu_order = absint( $payload['menu_order'] ?? 0 );
        $template   = sanitize_text_field( $payload['template'] ?? '' );

        if ( empty( $title ) ) {
            return new WP_Error( 'bz_missing_title', __( 'Title is required.', 'hubbee' ) );
        }

        $valid_statuses = [ 'publish', 'draft', 'pending', 'private' ];
        if ( ! in_array( $status, $valid_statuses, true ) ) {
            $status = 'draft';
        }

        $page_id = wp_insert_post( [
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $status,
            'post_excerpt' => $excerpt,
            'post_type'    => 'page',
            'post_parent'  => $parent_id,
            'menu_order'   => $menu_order,
            'post_author'  => get_current_user_id() ?: 1,
        ], true );

        if ( is_wp_error( $page_id ) ) {
            return $page_id;
        }

        if ( ! empty( $template ) ) {
            update_post_meta( $page_id, '_wp_page_template', $template );
        }
        if ( ! empty( $payload['featured_image_id'] ) ) {
            set_post_thumbnail( $page_id, absint( $payload['featured_image_id'] ) );
        }

        EventPusher::get_instance()->push( 'content_changed', [
            'type'      => 'page',
            'action'    => 'created',
            'page_id'   => $page_id,
            'page'      => $this->format_page_for_event( $page_id ),
            'timestamp' => current_time( 'mysql' ),
        ] );

        return [ 'success' => true, 'page_id' => $page_id, 'status' => $status ];
    }

    private function page_update( array $payload ) {
        $page_id = absint( $payload['page_id'] ?? $payload['post_id'] ?? 0 );

        if ( ! $page_id ) {
            return new WP_Error( 'bz_missing_page_id', __( 'Page ID is missing.', 'hubbee' ) );
        }

        $page = get_post( $page_id );
        if ( ! $page || 'page' !== $page->post_type ) {
            return new WP_Error( 'bz_page_not_found', __( 'Page not found.', 'hubbee' ) );
        }

        $page_data = [ 'ID' => $page_id ];
        if ( isset( $payload['title'] ) ) {
            $page_data['post_title'] = sanitize_text_field( $payload['title'] );
        }
        if ( isset( $payload['content'] ) ) {
            $page_data['post_content'] = wp_kses_post( $payload['content'] );
        }
        if ( isset( $payload['status'] ) ) {
            $valid_statuses = [ 'publish', 'draft', 'pending', 'private', 'trash' ];
            if ( in_array( $payload['status'], $valid_statuses, true ) ) {
                $page_data['post_status'] = $payload['status'];
            }
        }
        if ( isset( $payload['excerpt'] ) ) {
            $page_data['post_excerpt'] = wp_kses_post( $payload['excerpt'] );
        }
        if ( isset( $payload['parent_id'] ) ) {
            $page_data['post_parent'] = absint( $payload['parent_id'] );
        }
        if ( isset( $payload['menu_order'] ) ) {
            $page_data['menu_order'] = absint( $payload['menu_order'] );
        }

        $result = wp_update_post( $page_data, true );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( isset( $payload['template'] ) ) {
            update_post_meta( $page_id, '_wp_page_template', sanitize_text_field( $payload['template'] ) );
        }
        if ( array_key_exists( 'featured_image_id', $payload ) ) {
            if ( $payload['featured_image_id'] ) {
                set_post_thumbnail( $page_id, absint( $payload['featured_image_id'] ) );
            } else {
                delete_post_thumbnail( $page_id );
            }
        }

        EventPusher::get_instance()->push( 'content_changed', [
            'type'      => 'page',
            'action'    => 'updated',
            'page_id'   => $page_id,
            'page'      => $this->format_page_for_event( $page_id ),
            'timestamp' => current_time( 'mysql' ),
        ] );

        return [ 'success' => true, 'page_id' => $page_id ];
    }

    private function page_delete( array $payload ) {
        $page_id = absint( $payload['page_id'] ?? $payload['post_id'] ?? 0 );
        $force   = (bool) ( $payload['force'] ?? false );

        if ( ! $page_id ) {
            return new WP_Error( 'bz_missing_page_id', __( 'Page ID is missing.', 'hubbee' ) );
        }

        $page = get_post( $page_id );
        if ( ! $page || 'page' !== $page->post_type ) {
            return new WP_Error( 'bz_page_not_found', __( 'Page not found.', 'hubbee' ) );
        }

        $result = wp_delete_post( $page_id, $force );
        if ( ! $result ) {
            return new WP_Error( 'bz_delete_failed', __( 'Page could not be deleted.', 'hubbee' ) );
        }

        if ( ! BatchContext::is_active() ) {
            EventPusher::get_instance()->push( 'content_changed', [
                'type'      => 'page',
                'action'    => $force ? 'deleted' : 'trashed',
                'page_id'   => $page_id,
                'timestamp' => current_time( 'mysql' ),
            ] );
        }

        return [
            'success'             => true,
            'page_id'             => $page_id,
            'permanently_deleted' => $force,
        ];
    }

    private function format_page_for_event( int $page_id ): array {
        $page = get_post( $page_id );
        if ( ! $page ) {
            return [];
        }

        $parent_title = null;
        if ( $page->post_parent ) {
            $parent       = get_post( $page->post_parent );
            $parent_title = $parent ? $parent->post_title : null;
        }

        $featured_image = null;
        $thumbnail_id   = get_post_thumbnail_id( $page_id );
        if ( $thumbnail_id ) {
            $featured_image = [ 'id' => $thumbnail_id, 'url' => wp_get_attachment_url( $thumbnail_id ) ];
        }

        $template = get_post_meta( $page_id, '_wp_page_template', true );

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
                'name' => get_the_author_meta( 'display_name', $page->post_author ),
            ],
            'parent'         => (int) $page->post_parent,
            'parent_title'   => $parent_title,
            'menu_order'     => (int) $page->menu_order,
            'template'       => $template ?: 'default',
            'featured_image' => $featured_image,
            'comment_status' => $page->comment_status,
            'comment_count'  => (int) $page->comment_count,
            'permalink'      => get_permalink( $page ),
            'edit_link'      => get_edit_post_link( $page, 'raw' ),
        ];
    }
}
