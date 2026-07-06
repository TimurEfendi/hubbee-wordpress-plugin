<?php
/**
 * Text Effect Repository - Storage for pushed text effects from SaaS
 *
 * @package Hubbee\Storage
 */

namespace Hubbee\Storage;

class TextEffectRepository {

    /**
     * Get table name
     *
     * @return string
     */
    private function get_table(): string {
        return Database::get_text_effects_table();
    }

    /**
     * Get text effect by slug
     *
     * @param string $slug Text effect slug.
     * @return object|null Text effect object or null.
     */
    public function get_by_slug( string $slug ): ?object {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE text_effect_slug = %s",
                $slug
            )
        );

        return $result;
    }

    /**
     * Get all text effects
     *
     * @return array Array of text effect objects.
     */
    public function get_all(): array {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY text_effect_name ASC"
        );
    }

    /**
     * Insert or update a text effect
     *
     * @param array $data Text effect data.
     * @return int|false Text effect ID on success, false on failure.
     */
    public function upsert( array $data ) {
        global $wpdb;

        $table = $this->get_table();

        $slug = sanitize_title( $data['slug'] ?? '' );

        if ( empty( $slug ) ) {
            return false;
        }

        $config = $data['config'] ?? '{}';
        if ( is_array( $config ) || is_object( $config ) ) {
            $config = wp_json_encode( $config );
        }

        $insert_data = [
            'text_effect_slug' => $slug,
            'text_effect_name' => sanitize_text_field( $data['name'] ?? $slug ),
            'effect_type'      => sanitize_text_field( $data['effect_type'] ?? '' ),
            'config'           => $config,
            'pushed_at'        => $data['pushed_at'] ?? current_time( 'mysql' ),
        ];

        $existing = $this->get_by_slug( $slug );

        if ( $existing ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->update(
                $table,
                $insert_data,
                [ 'id' => $existing->id ],
                [ '%s', '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );

            return false !== $result ? (int) $existing->id : false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            $insert_data,
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        return false !== $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Delete text effect by slug
     *
     * @param string $slug Text effect slug.
     * @return bool
     */
    public function delete_by_slug( string $slug ): bool {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table,
            [ 'text_effect_slug' => $slug ],
            [ '%s' ]
        );

        return false !== $result;
    }

    /**
     * Delete all text effects by effect type
     *
     * @param string $type Effect type (e.g. 'text-pressure').
     * @return int Number of deleted rows.
     */
    public function delete_by_type( string $type ): int {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table,
            [ 'effect_type' => $type ],
            [ '%s' ]
        );

        return false !== $result ? (int) $result : 0;
    }

    /**
     * Get text effects for dropdown (slug => 'name (type)' pairs)
     *
     * @return array
     */
    public function get_for_dropdown(): array {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            "SELECT text_effect_slug, text_effect_name, effect_type FROM {$table} ORDER BY text_effect_name ASC"
        );

        $dropdown = [];
        foreach ( $results as $effect ) {
            $dropdown[ $effect->text_effect_slug ] = $effect->text_effect_name . ' (' . $effect->effect_type . ')';
        }

        return $dropdown;
    }
}
