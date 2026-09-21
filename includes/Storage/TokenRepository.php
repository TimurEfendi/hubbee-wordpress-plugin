<?php
/**
 * Token Repository - Agent token storage
 *
 * @package Hubbee\Storage
 */

namespace Hubbee\Storage;

use Hubbee\Security\Sanitizer;

class TokenRepository {

    /**
     * Get table name
     *
     * @return string
     */
    private function get_table(): string {
        return Database::get_tokens_table();
    }

    /**
     * Get token by key and locale
     *
     * @param string $key    Token key.
     * @param string $locale Locale (empty for default).
     * @return object|null Token object or null.
     */
    public function get_by_key( string $key, string $locale = '' ): ?object {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE token_key = %s AND locale = %s",
                $key,
                $locale
            )
        );

        return $result;
    }

    /**
     * Get token by ID
     *
     * @param int $id Token ID.
     * @return object|null Token object or null.
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
     * Get all tokens
     *
     * @param array $args Query arguments.
     * @return array Array of token objects.
     */
    public function get_all( array $args = [] ): array {
        global $wpdb;

        $table = $this->get_table();

        $defaults = [
            'locale'  => null,
            'section' => null,
            'orderby' => 'token_key',
            'order'   => 'ASC',
            'limit'   => 0,
            'offset'  => 0,
        ];

        $args = wp_parse_args( $args, $defaults );

        $where = '1=1';
        $params = [];

        if ( null !== $args['locale'] ) {
            $where .= ' AND locale = %s';
            $params[] = $args['locale'];
        }

        if ( null !== $args['section'] ) {
            $where .= ' AND section = %s';
            $params[] = $args['section'];
        }

        $orderby = in_array( $args['orderby'], [ 'token_key', 'label', 'updated_at', 'version', 'section' ], true )
            ? $args['orderby']
            : 'token_key';

        $order = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}";

        if ( $args['limit'] > 0 ) {
            $sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );
        }

        if ( ! empty( $params ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
            return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $sql );
    }

    /**
     * Get tokens grouped by section
     *
     * @param string|null $locale Filter by locale.
     * @return array Associative array of section => tokens.
     */
    public function get_grouped_by_section( ?string $locale = null ): array {
        $tokens = $this->get_all( [
            'locale'  => $locale,
            'orderby' => 'section',
            'order'   => 'ASC',
        ] );

        $grouped = [];
        foreach ( $tokens as $token ) {
            $section = $token->section ?: 'general';
            if ( ! isset( $grouped[ $section ] ) ) {
                $grouped[ $section ] = [];
            }
            $grouped[ $section ][] = $token;
        }

        return $grouped;
    }

    /**
     * Get distinct sections
     *
     * @return array
     */
    public function get_sections(): array {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_col(
            "SELECT DISTINCT section FROM {$table} WHERE section IS NOT NULL AND section != '' ORDER BY section ASC"
        );

        return $results ?: [ 'general' ];
    }

    /**
     * Count tokens
     *
     * @param string|null $locale Filter by locale.
     * @return int
     */
    public function count( ?string $locale = null ): int {
        global $wpdb;

        $table = $this->get_table();

        if ( null !== $locale ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE locale = %s",
                    $locale
                )
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    /**
     * Insert or update a token
     *
     * @param array $data Token data.
     * @return int|false Token ID on success, false on failure.
     */
    public function upsert( array $data ) {
        global $wpdb;

        $table = $this->get_table();

        // Sanitize data
        $token_key = Sanitizer::sanitize_token_key( $data['token_key'] ?? '' );
        $locale = Sanitizer::sanitize_locale( $data['locale'] ?? '' );

        if ( empty( $token_key ) ) {
            return false;
        }

        $field_type = Sanitizer::sanitize_field_type( $data['field_type'] ?? 'text' );
        $section = Sanitizer::sanitize_section( $data['section'] ?? 'general' );

        $insert_data = [
            'token_key'      => $token_key,
            'label'          => sanitize_text_field( $data['label'] ?? '' ),
            'description'    => sanitize_textarea_field( $data['description'] ?? '' ),
            'field_type'     => $field_type,
            'value_longtext' => Sanitizer::sanitize_token_value( $data['value_longtext'] ?? '', $field_type ),
            'locale'         => $locale,
            'version'        => absint( $data['version'] ?? 1 ),
            'section'        => $section,
            'updated_at'     => $data['updated_at'] ?? current_time( 'mysql' ),
        ];

        // Check if token exists
        $existing = $this->get_by_key( $token_key, $locale );

        if ( $existing ) {
            // Update existing
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $result = $wpdb->update(
                $table,
                $insert_data,
                [ 'id' => $existing->id ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ],
                [ '%d' ]
            );

            // Capture database error for debugging
            if ( false === $result ) {
                $GLOBALS['hubbee_last_db_error'] = $wpdb->last_error;
            }

            return false !== $result ? (int) $existing->id : false;
        }

        // Insert new
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            $insert_data,
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        // Capture database error for debugging
        if ( false === $result ) {
            $GLOBALS['hubbee_last_db_error'] = $wpdb->last_error;
        }

        return false !== $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Delete token
     *
     * @param int $id Token ID.
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
     * Delete token by key and locale
     *
     * @param string $key    Token key.
     * @param string $locale Locale (empty for default).
     * @return bool
     */
    public function delete_by_key( string $key, string $locale = '' ): bool {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table,
            [
                'token_key' => $key,
                'locale'    => $locale,
            ],
            [ '%s', '%s' ]
        );

        return false !== $result;
    }

    /**
     * Delete all locales for a token key
     *
     * @param string $key Token key.
     * @return bool
     */
    public function delete_all_locales( string $key ): bool {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete(
            $table,
            [ 'token_key' => $key ],
            [ '%s' ]
        );

        return false !== $result;
    }

    /**
     * Get unique token keys (for autocomplete)
     *
     * @return array
     */
    public function get_token_keys(): array {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_col(
            "SELECT DISTINCT token_key FROM {$table} ORDER BY token_key ASC"
        );

        return $results ?: [];
    }

    /**
     * Search tokens
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
                "SELECT * FROM {$table} WHERE token_key LIKE %s OR label LIKE %s ORDER BY token_key ASC",
                $search,
                $search
            )
        );
    }

    /**
     * Get external tokens filtered by field types
     *
     * Returns tokens from the 'external' section matching the given field types,
     * grouped by token_key (returns only the default locale row).
     *
     * @param array $field_types Array of field_type values to include.
     * @return array Array of token objects.
     */
    public function get_external_tokens_by_types( array $field_types ): array {
        global $wpdb;

        $table = $this->get_table();

        if ( empty( $field_types ) ) {
            return [];
        }

        // Build safe placeholders for IN clause
        $placeholders = implode( ',', array_fill( 0, count( $field_types ), '%s' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE section = 'external'
                  AND field_type IN ({$placeholders})
                  AND locale = ''
                ORDER BY token_key ASC",
                ...$field_types
            )
        ) ?: [];
    }

    /**
     * Delete external tokens whose key is NOT in the expected list.
     *
     * Removes all rows where token_key starts with 'ext_' and is not
     * present in $expected_keys. Used for reconciliation after base/table changes.
     *
     * @param array $expected_keys Array of token_key strings that should be kept.
     * @return int Number of deleted rows.
     */
    public function delete_external_not_in_keys( array $expected_keys ): int {
        global $wpdb;

        $table = $this->get_table();

        if ( empty( $expected_keys ) ) {
            // No expected keys means delete ALL external tokens
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $deleted = $wpdb->query(
                "DELETE FROM {$table} WHERE token_key LIKE 'ext\\_%'"
            );
            return max( 0, (int) $deleted );
        }

        $placeholders = implode( ',', array_fill( 0, count( $expected_keys ), '%s' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE token_key LIKE %s AND token_key NOT IN ({$placeholders})",
                $wpdb->esc_like( 'ext_' ) . '%',
                ...$expected_keys
            )
        );

        return max( 0, (int) $deleted );
    }

    /**
     * Get the distinct NON-external token keys currently stored.
     *
     * Mirrors the scope of delete_not_in_keys() (token_key NOT LIKE 'ext\_%').
     * Used to compute the delta-push drift checksum.
     *
     * @return string[] Distinct non-external token_key values.
     */
    public function get_non_external_keys(): array {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $keys = $wpdb->get_col(
            "SELECT DISTINCT token_key FROM {$table} WHERE token_key NOT LIKE 'ext\\_%'"
        );

        return is_array( $keys ) ? $keys : [];
    }

    /**
     * Delete non-external tokens whose key is NOT in the expected list.
     *
     * Removes all rows where token_key does NOT start with 'ext_' and is not
     * present in $expected_keys. Used for full-push reconciliation.
     * External tokens (ext_*) are managed separately via delete_external_not_in_keys().
     *
     * @param array $expected_keys Array of token_key strings that should be kept.
     * @return int Number of deleted rows.
     */
    public function delete_not_in_keys( array $expected_keys ): int {
        global $wpdb;

        $table = $this->get_table();

        if ( empty( $expected_keys ) ) {
            // No expected keys means delete ALL non-external tokens
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $deleted = $wpdb->query(
                "DELETE FROM {$table} WHERE token_key NOT LIKE 'ext\\_%'"
            );
            return max( 0, (int) $deleted );
        }

        $placeholders = implode( ',', array_fill( 0, count( $expected_keys ), '%s' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE token_key NOT LIKE %s AND token_key NOT IN ({$placeholders})",
                $wpdb->esc_like( 'ext_' ) . '%',
                ...$expected_keys
            )
        );

        return max( 0, (int) $deleted );
    }

    /**
     * Get distinct locales in use
     *
     * @return array
     */
    public function get_locales(): array {
        global $wpdb;

        $table = $this->get_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_col(
            "SELECT DISTINCT locale FROM {$table} ORDER BY locale ASC"
        );

        return $results ?: [ '' ];
    }
}
