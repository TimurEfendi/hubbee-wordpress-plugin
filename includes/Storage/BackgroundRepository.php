<?php
/**
 * Background Repository - Background storage for pushed backgrounds from SaaS
 *
 * @package Hubbee\Storage
 */

namespace Hubbee\Storage;

class BackgroundRepository {

    /**
     * Get table name
     *
     * @return string
     */
    private function get_table(): string {
        return Database::get_backgrounds_table();
    }

    /**
     * Get background by slug
     *
     * @param string $slug Background slug.
     * @return object|null Background object or null.
     */
    public function get_by_slug( string $slug ): ?object {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE background_slug = %s",
                $slug
            )
        );

        return $result;
    }

    /**
     * Get all backgrounds
     *
     * @return array Array of background objects.
     */
    public function get_all(): array {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY background_name ASC"
        );
    }

    /**
     * Insert or update a background
     *
     * @param array $data Background data.
     * @return int|false Background ID on success, false on failure.
     */
    public function upsert( array $data ) {
        global $wpdb;

        $table = $this->get_table();

        // Sanitize data
        $slug = sanitize_title( $data['slug'] ?? '' );

        if ( empty( $slug ) ) {
            return false;
        }

        // Ensure config is a JSON string
        $config = $data['config'] ?? '{}';
        if ( is_array( $config ) || is_object( $config ) ) {
            $config = wp_json_encode( $config );
        }

        $insert_data = [
            'background_slug' => $slug,
            'background_name' => sanitize_text_field( $data['name'] ?? $slug ),
            'bg_type'         => sanitize_text_field( $data['bg_type'] ?? '' ),
            'config'          => $config,
            'pushed_at'       => $data['pushed_at'] ?? current_time( 'mysql' ),
        ];

        // Check if background exists
        $existing = $this->get_by_slug( $slug );

        if ( $existing ) {
            // Update existing
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

        // Insert new
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            $insert_data,
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        return false !== $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Delete background by slug
     *
     * @param string $slug Background slug.
     * @return bool
     */
    public function delete_by_slug( string $slug ): bool {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table,
            [ 'background_slug' => $slug ],
            [ '%s' ]
        );

        return false !== $result;
    }

    /**
     * Get backgrounds for dropdown (slug => 'name (type)' pairs)
     *
     * @return array
     */
    public function get_for_dropdown(): array {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            "SELECT background_slug, background_name, bg_type FROM {$table} ORDER BY background_name ASC"
        );

        $dropdown = [];
        foreach ( $results as $background ) {
            $dropdown[ $background->background_slug ] = $background->background_name . ' (' . $background->bg_type . ')';
        }

        return $dropdown;
    }
}
