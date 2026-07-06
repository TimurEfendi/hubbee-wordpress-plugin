<?php
/**
 * Component Repository - Component storage for pushed components from SaaS
 *
 * @package Hubbee\Storage
 */

namespace Hubbee\Storage;

class ComponentRepository {

    /**
     * Get table name
     *
     * @return string
     */
    private function get_table(): string {
        return Database::get_components_table();
    }

    /**
     * Get component by slug
     *
     * @param string $slug Component slug.
     * @return object|null Component object or null.
     */
    public function get_by_slug( string $slug ): ?object {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE component_slug = %s",
                $slug
            )
        );

        return $result;
    }

    /**
     * Get component by ID
     *
     * @param int $id Component ID.
     * @return object|null Component object or null.
     */
    public function get_by_id( int $id ): ?object {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $id
            )
        );
    }

    /**
     * Get all components
     *
     * @param array $args Query arguments.
     * @return array Array of component objects.
     */
    public function get_all( array $args = [] ): array {
        global $wpdb;

        $table = $this->get_table();

        $defaults = [
            'orderby' => 'component_name',
            'order'   => 'ASC',
            'limit'   => 0,
            'offset'  => 0,
        ];

        $args = wp_parse_args( $args, $defaults );

        $orderby = in_array( $args['orderby'], [ 'component_slug', 'component_name', 'version', 'pushed_at' ], true )
            ? $args['orderby']
            : 'component_name';

        $order = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

        $sql = "SELECT * FROM {$table} ORDER BY {$orderby} {$order}";

        if ( $args['limit'] > 0 ) {
            $sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $sql );
    }

    /**
     * Count components
     *
     * @return int
     */
    public function count(): int {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    /**
     * Insert or update a component
     *
     * @param array $data Component data.
     * @return int|false Component ID on success, false on failure.
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
            'component_slug' => $slug,
            'component_name' => sanitize_text_field( $data['name'] ?? $slug ),
            'version'        => absint( $data['version'] ?? 1 ),
            'config'         => $config,
            'pushed_at'      => $data['pushed_at'] ?? current_time( 'mysql' ),
        ];

        // Check if component exists
        $existing = $this->get_by_slug( $slug );

        if ( $existing ) {
            // Update existing
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->update(
                $table,
                $insert_data,
                [ 'id' => $existing->id ],
                [ '%s', '%s', '%d', '%s', '%s' ],
                [ '%d' ]
            );

            return false !== $result ? (int) $existing->id : false;
        }

        // Insert new
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            $insert_data,
            [ '%s', '%s', '%d', '%s', '%s' ]
        );

        return false !== $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Delete component
     *
     * @param int $id Component ID.
     * @return bool
     */
    public function delete( int $id ): bool {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table,
            [ 'id' => $id ],
            [ '%d' ]
        );

        return false !== $result;
    }

    /**
     * Delete component by slug
     *
     * @param string $slug Component slug.
     * @return bool
     */
    public function delete_by_slug( string $slug ): bool {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table,
            [ 'component_slug' => $slug ],
            [ '%s' ]
        );

        return false !== $result;
    }

    /**
     * Get component slugs (for autocomplete/widget dropdown)
     *
     * @return array
     */
    public function get_component_slugs(): array {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_col(
            "SELECT component_slug FROM {$table} ORDER BY component_name ASC"
        );

        return $results ?: [];
    }

    /**
     * Get components for dropdown (slug => name pairs)
     *
     * @return array
     */
    public function get_for_dropdown(): array {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            "SELECT component_slug, component_name FROM {$table} ORDER BY component_name ASC"
        );

        $dropdown = [];
        foreach ( $results as $component ) {
            $dropdown[ $component->component_slug ] = $component->component_name;
        }

        return $dropdown;
    }

    /**
     * Search components
     *
     * @param string $search Search term.
     * @return array
     */
    public function search( string $search ): array {
        global $wpdb;

        $table = $this->get_table();
        $search = '%' . $wpdb->esc_like( $search ) . '%';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE component_slug LIKE %s OR component_name LIKE %s ORDER BY component_name ASC",
                $search,
                $search
            )
        );
    }

    /**
     * Get decoded config for a component
     *
     * @param string $slug Component slug.
     * @return array|null Config array or null if not found.
     */
    public function get_config( string $slug ): ?array {
        $component = $this->get_by_slug( $slug );

        if ( ! $component ) {
            return null;
        }

        $config = json_decode( $component->config, true );

        return is_array( $config ) ? $config : null;
    }
}
