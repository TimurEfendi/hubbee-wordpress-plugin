<?php
/**
 * Taxonomy commands — categories and tags share the same WP terms API
 * (`wp_insert_term` / `wp_update_term` / `wp_delete_term` against `category`
 * vs `post_tag`), so a single handler avoids near-duplicate classes.
 *
 * Owns: category.create/update/delete + tag.create/update/delete.
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

use Hubbee\Agent\EventPusher;
use WP_Error;

class TaxonomyCommandHandler implements CommandHandler {

    public static function command_types(): array {
        return [
            'category.create', 'category.update', 'category.delete',
            'tag.create',      'tag.update',      'tag.delete',
        ];
    }

    public function execute( string $type, array $payload ) {
        switch ( $type ) {
            case 'category.create':
                return $this->category_create( $payload );
            case 'category.update':
                return $this->category_update( $payload );
            case 'category.delete':
                return $this->category_delete( $payload );
            case 'tag.create':
                return $this->tag_create( $payload );
            case 'tag.update':
                return $this->tag_update( $payload );
            case 'tag.delete':
                return $this->tag_delete( $payload );
            default:
                return new WP_Error( 'bz_unknown_taxonomy_command', sprintf( 'Unknown taxonomy command: %s', $type ) );
        }
    }

    // ── category ────────────────────────────────────────────────────────

    private function category_create( array $payload ) {
        $name = sanitize_text_field( $payload['name'] ?? '' );
        if ( empty( $name ) ) {
            return new WP_Error( 'bz_missing_name', __( 'Category name is missing.', 'hubbee' ) );
        }

        $term_data = [
            'description' => sanitize_textarea_field( $payload['description'] ?? '' ),
            'parent'      => absint( $payload['parent'] ?? 0 ),
        ];
        $slug = sanitize_title( $payload['slug'] ?? '' );
        if ( ! empty( $slug ) ) {
            $term_data['slug'] = $slug;
        }

        $result = wp_insert_term( $name, 'category', $term_data );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $term_id = $result['term_id'];
        EventPusher::get_instance()->push( 'content_changed', [
            'type'        => 'category',
            'action'      => 'created',
            'category_id' => $term_id,
            'category'    => $this->format_term_for_event( $term_id, 'category' ),
            'timestamp'   => current_time( 'mysql' ),
        ] );

        return [ 'success' => true, 'category_id' => $term_id ];
    }

    private function category_update( array $payload ) {
        $category_id = absint( $payload['category_id'] ?? $payload['term_id'] ?? 0 );
        if ( ! $category_id ) {
            return new WP_Error( 'bz_missing_category_id', __( 'Category ID is missing.', 'hubbee' ) );
        }

        $term = get_term( $category_id, 'category' );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'bz_category_not_found', __( 'Category not found.', 'hubbee' ) );
        }

        $args = [];
        if ( isset( $payload['name'] ) ) {
            $args['name'] = sanitize_text_field( $payload['name'] );
        }
        if ( isset( $payload['slug'] ) ) {
            $args['slug'] = sanitize_title( $payload['slug'] );
        }
        if ( isset( $payload['description'] ) ) {
            $args['description'] = sanitize_textarea_field( $payload['description'] );
        }
        if ( isset( $payload['parent'] ) ) {
            $args['parent'] = absint( $payload['parent'] );
        }

        if ( empty( $args ) ) {
            return new WP_Error( 'bz_nothing_to_update', __( 'No fields to update.', 'hubbee' ) );
        }

        $result = wp_update_term( $category_id, 'category', $args );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        EventPusher::get_instance()->push( 'content_changed', [
            'type'        => 'category',
            'action'      => 'updated',
            'category_id' => $category_id,
            'category'    => $this->format_term_for_event( $category_id, 'category' ),
            'timestamp'   => current_time( 'mysql' ),
        ] );

        return [ 'success' => true, 'category_id' => $category_id ];
    }

    private function category_delete( array $payload ) {
        $category_id = absint( $payload['category_id'] ?? $payload['term_id'] ?? 0 );
        if ( ! $category_id ) {
            return new WP_Error( 'bz_missing_category_id', __( 'Category ID is missing.', 'hubbee' ) );
        }

        // Hard guard: never delete the default category — same as legacy.
        $default_category = (int) get_option( 'default_category' );
        if ( $category_id === $default_category ) {
            return new WP_Error( 'bz_cannot_delete_default', __( 'The default category cannot be deleted.', 'hubbee' ) );
        }

        $term = get_term( $category_id, 'category' );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'bz_category_not_found', __( 'Category not found.', 'hubbee' ) );
        }

        $result = wp_delete_term( $category_id, 'category' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( ! $result ) {
            return new WP_Error( 'bz_delete_failed', __( 'Category could not be deleted.', 'hubbee' ) );
        }

        EventPusher::get_instance()->push( 'content_changed', [
            'type'        => 'category',
            'action'      => 'deleted',
            'category_id' => $category_id,
            'timestamp'   => current_time( 'mysql' ),
        ] );

        return [ 'success' => true, 'category_id' => $category_id ];
    }

    // ── tag ─────────────────────────────────────────────────────────────

    private function tag_create( array $payload ) {
        $name = sanitize_text_field( $payload['name'] ?? '' );
        if ( empty( $name ) ) {
            return new WP_Error( 'bz_missing_name', __( 'Tag name is missing.', 'hubbee' ) );
        }

        $term_data = [ 'description' => sanitize_textarea_field( $payload['description'] ?? '' ) ];
        $slug      = sanitize_title( $payload['slug'] ?? '' );
        if ( ! empty( $slug ) ) {
            $term_data['slug'] = $slug;
        }

        $result = wp_insert_term( $name, 'post_tag', $term_data );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $term_id = $result['term_id'];
        EventPusher::get_instance()->push( 'content_changed', [
            'type'      => 'tag',
            'action'    => 'created',
            'tag_id'    => $term_id,
            'tag'       => $this->format_term_for_event( $term_id, 'post_tag' ),
            'timestamp' => current_time( 'mysql' ),
        ] );

        return [ 'success' => true, 'tag_id' => $term_id ];
    }

    private function tag_update( array $payload ) {
        $tag_id = absint( $payload['tag_id'] ?? $payload['term_id'] ?? 0 );
        if ( ! $tag_id ) {
            return new WP_Error( 'bz_missing_tag_id', __( 'Tag ID is missing.', 'hubbee' ) );
        }

        $term = get_term( $tag_id, 'post_tag' );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'bz_tag_not_found', __( 'Tag not found.', 'hubbee' ) );
        }

        $args = [];
        if ( isset( $payload['name'] ) ) {
            $args['name'] = sanitize_text_field( $payload['name'] );
        }
        if ( isset( $payload['slug'] ) ) {
            $args['slug'] = sanitize_title( $payload['slug'] );
        }
        if ( isset( $payload['description'] ) ) {
            $args['description'] = sanitize_textarea_field( $payload['description'] );
        }

        if ( empty( $args ) ) {
            return new WP_Error( 'bz_nothing_to_update', __( 'No fields to update.', 'hubbee' ) );
        }

        $result = wp_update_term( $tag_id, 'post_tag', $args );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        EventPusher::get_instance()->push( 'content_changed', [
            'type'      => 'tag',
            'action'    => 'updated',
            'tag_id'    => $tag_id,
            'tag'       => $this->format_term_for_event( $tag_id, 'post_tag' ),
            'timestamp' => current_time( 'mysql' ),
        ] );

        return [ 'success' => true, 'tag_id' => $tag_id ];
    }

    private function tag_delete( array $payload ) {
        $tag_id = absint( $payload['tag_id'] ?? $payload['term_id'] ?? 0 );
        if ( ! $tag_id ) {
            return new WP_Error( 'bz_missing_tag_id', __( 'Tag ID is missing.', 'hubbee' ) );
        }

        $term = get_term( $tag_id, 'post_tag' );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'bz_tag_not_found', __( 'Tag not found.', 'hubbee' ) );
        }

        $result = wp_delete_term( $tag_id, 'post_tag' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( ! $result ) {
            return new WP_Error( 'bz_delete_failed', __( 'Tag could not be deleted.', 'hubbee' ) );
        }

        EventPusher::get_instance()->push( 'content_changed', [
            'type'      => 'tag',
            'action'    => 'deleted',
            'tag_id'    => $tag_id,
            'timestamp' => current_time( 'mysql' ),
        ] );

        return [ 'success' => true, 'tag_id' => $tag_id ];
    }

    // ── shared event payload builder ────────────────────────────────────

    private function format_term_for_event( int $term_id, string $taxonomy ): array {
        $term = get_term( $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return [];
        }

        return [
            'id'          => $term->term_id,
            'name'        => $term->name,
            'slug'        => $term->slug,
            'description' => $term->description,
            'parent'      => $term->parent,
            'count'       => $term->count,
            'link'        => get_term_link( $term ),
        ];
    }
}
