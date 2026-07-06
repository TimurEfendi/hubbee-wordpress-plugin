<?php
/**
 * Migration handler for HubText to Hubbee
 *
 * @package Hubbee\Core
 */

namespace Hubbee\Core;

class Migrator {

    /**
     * Migrate data from HubText plugin if it exists
     *
     * @return array Migration result with status and message
     */
    public static function migrate_from_hubtext(): array {
        global $wpdb;

        // Check if migration was already completed
        if ( get_option( 'bz_migrated_from_hubtext' ) ) {
            return [
                'status'  => 'skip',
                'message' => 'Migration already completed',
            ];
        }

        $old_table = $wpdb->prefix . 'ht_tokens';
        $new_table = $wpdb->prefix . 'bz_tokens';

        // Check if old table exists
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
        $old_table_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table )
        ) === $old_table;

        if ( ! $old_table_exists ) {
            return [
                'status'  => 'skip',
                'message' => 'No HubText data to migrate',
            ];
        }

        // Check if new table already has data
        $new_table_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$new_table}" );

        if ( $new_table_count > 0 ) {
            // Mark migration as complete since we already have data
            update_option( 'bz_migrated_from_hubtext', true );
            return [
                'status'  => 'skip',
                'message' => 'New table already has data',
            ];
        }

        // Copy data with schema transformation
        // Note: We exclude is_editable and updated_by columns
        // and add 'general' as default section
        $result = $wpdb->query(
            "INSERT INTO {$new_table}
            (token_key, label, description, field_type, value_longtext, locale, version, section, updated_at)
            SELECT
                token_key,
                label,
                description,
                field_type,
                value_longtext,
                locale,
                version,
                'general',
                updated_at
            FROM {$old_table}"
        );
        // phpcs:enable

        $migrated_count = $result !== false ? $wpdb->rows_affected : 0;

        // Migrate site_id option
        $old_site_id = get_option( 'ht_site_id' );
        if ( $old_site_id && ! get_option( 'bz_site_id' ) ) {
            update_option( 'bz_site_id', $old_site_id );
        }

        // Migrate last push received timestamp
        $old_last_push = get_option( 'ht_last_push_received' );
        if ( $old_last_push ) {
            update_option( 'bz_last_push_received', $old_last_push );
        }

        // Mark migration as complete
        update_option( 'bz_migrated_from_hubtext', true );
        update_option( 'bz_migration_date', current_time( 'mysql' ) );
        update_option( 'bz_migration_count', $migrated_count );

        return [
            'status'   => 'success',
            'message'  => sprintf( 'Migrated %d tokens from HubText', $migrated_count ),
            'migrated' => $migrated_count,
        ];
    }

    /**
     * Check if migration from HubText is available
     *
     * @return bool
     */
    public static function can_migrate(): bool {
        global $wpdb;

        if ( get_option( 'bz_migrated_from_hubtext' ) ) {
            return false;
        }

        $old_table = $wpdb->prefix . 'ht_tokens';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table )
        ) === $old_table;
        // phpcs:enable

        return $exists;
    }

    /**
     * Get migration status
     *
     * @return array
     */
    public static function get_migration_status(): array {
        return [
            'migrated'       => (bool) get_option( 'bz_migrated_from_hubtext' ),
            'migration_date' => get_option( 'bz_migration_date', '' ),
            'migrated_count' => (int) get_option( 'bz_migration_count', 0 ),
            'can_migrate'    => self::can_migrate(),
        ];
    }
}
