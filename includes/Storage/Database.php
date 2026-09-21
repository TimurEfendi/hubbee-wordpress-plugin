<?php
/**
 * Database management class
 *
 * @package Hubbee\Storage
 */

namespace Hubbee\Storage;

class Database {

    /**
     * Database version
     *
     * @var string
     */
    const DB_VERSION = '2.5.0';

    /**
     * Options that are only read in cron/admin/diagnostic contexts and must NOT
     * be autoloaded on every request (avoids wp_options bloat at fleet scale).
     * Hot-path options (credentials, db_version) are intentionally excluded.
     *
     * @var string[]
     */
    const COLD_OPTIONS = [
        'bz_poll_interval_hint',
        'bz_heartbeat_interval',
        'bz_health_check_interval',
        'bz_poll_consecutive_failures',
        'bz_poll_auth_failures',
        'bz_heartbeat_failures',
        'bz_health_report_failures',
        'bz_last_health_report',
        'bz_last_health_level',
        'bz_last_error',
        'bz_m2m_api_endpoint',
        'bz_migrated_from_hubtext',
        'bz_migrated_at',
        'bz_nginx_cors_notice_dismissed',
        'bz_uploads_cors_version',
    ];

    /**
     * Get tokens table name
     *
     * @return string
     */
    public static function get_tokens_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'bz_tokens';
    }

    /**
     * Get processed requests table name (for idempotency)
     *
     * @return string
     */
    public static function get_requests_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'bz_processed_requests';
    }

    /**
     * Get components table name
     *
     * @return string
     */
    public static function get_components_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'bz_components';
    }

    /**
     * Get backgrounds table name
     *
     * @return string
     */
    public static function get_backgrounds_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'bz_backgrounds';
    }

    /**
     * Get text effects table name
     *
     * @return string
     */
    public static function get_text_effects_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'bz_text_effects';
    }

    /**
     * Create all database tables
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Tokens table
        $tokens_table = self::get_tokens_table();
        $sql_tokens = "CREATE TABLE {$tokens_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            token_key VARCHAR(100) NOT NULL,
            label VARCHAR(255) NOT NULL DEFAULT '',
            description TEXT,
            field_type VARCHAR(50) NOT NULL DEFAULT 'text',
            value_longtext LONGTEXT,
            locale VARCHAR(10) DEFAULT '',
            version BIGINT UNSIGNED NOT NULL DEFAULT 1,
            section VARCHAR(100) DEFAULT 'general',
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token_locale (token_key, locale),
            KEY version (version),
            KEY section (section)
        ) {$charset_collate};";

        dbDelta( $sql_tokens );

        // Processed requests table (for idempotency)
        $requests_table = self::get_requests_table();
        $sql_requests = "CREATE TABLE {$requests_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id VARCHAR(36) NOT NULL,
            processed_at DATETIME NOT NULL,
            tokens_count INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY request_id (request_id),
            KEY processed_at (processed_at)
        ) {$charset_collate};";

        dbDelta( $sql_requests );

        // Components table (pushed from SaaS)
        $components_table = self::get_components_table();
        $sql_components = "CREATE TABLE {$components_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            component_slug VARCHAR(191) NOT NULL,
            component_name VARCHAR(255) NOT NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            config LONGTEXT NOT NULL,
            pushed_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY component_slug (component_slug),
            KEY version (version)
        ) {$charset_collate};";

        dbDelta( $sql_components );

        // Backgrounds table (pushed from SaaS)
        $table_backgrounds = self::get_backgrounds_table();
        $sql_backgrounds = "CREATE TABLE {$table_backgrounds} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            background_slug varchar(191) NOT NULL,
            background_name varchar(255) NOT NULL,
            bg_type varchar(100) NOT NULL,
            config longtext NOT NULL,
            pushed_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY background_slug (background_slug)
        ) {$charset_collate};";

        dbDelta( $sql_backgrounds );

        // Text effects table (pushed from SaaS)
        $table_text_effects = self::get_text_effects_table();
        $sql_text_effects = "CREATE TABLE {$table_text_effects} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            text_effect_slug varchar(191) NOT NULL,
            text_effect_name varchar(255) NOT NULL,
            effect_type varchar(100) NOT NULL,
            config longtext NOT NULL,
            pushed_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY text_effect_slug (text_effect_slug)
        ) {$charset_collate};";

        dbDelta( $sql_text_effects );

        // Demote cold options to autoload=no (idempotent; runs on each upgrade).
        self::set_cold_options_no_autoload();

        // Store database version
        update_option( 'bz_db_version', self::DB_VERSION );
    }

    /**
     * Demote cold options to autoload=no so they are not loaded on every request.
     *
     * Idempotent: only existing options are touched, and re-running is a no-op.
     * Uses wp_set_option_autoload() (WP 6.4+); silently skipped on older cores
     * where it is unavailable (perf optimisation only, not correctness).
     */
    public static function set_cold_options_no_autoload(): void {
        if ( ! function_exists( 'wp_set_option_autoload' ) ) {
            return;
        }

        foreach ( self::COLD_OPTIONS as $option ) {
            // Skip options that do not exist yet (avoids creating empty rows).
            if ( false === get_option( $option, false ) ) {
                continue;
            }
            wp_set_option_autoload( $option, false );
        }
    }

    /**
     * Drop all database tables
     */
    public static function drop_tables(): void {
        global $wpdb;

        // Table names come from the plugin's own trusted accessors (no user input);
        // identifiers can't be passed through $wpdb->prepare().
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( 'DROP TABLE IF EXISTS ' . self::get_tokens_table() );
        $wpdb->query( 'DROP TABLE IF EXISTS ' . self::get_requests_table() );
        $wpdb->query( 'DROP TABLE IF EXISTS ' . self::get_components_table() );
        $wpdb->query( 'DROP TABLE IF EXISTS ' . self::get_backgrounds_table() );
        $wpdb->query( 'DROP TABLE IF EXISTS ' . self::get_text_effects_table() );
        // phpcs:enable

        delete_option( 'bz_db_version' );
    }

    /**
     * Check if tables need upgrade
     *
     * @return bool
     */
    public static function needs_upgrade(): bool {
        $current_version = get_option( 'bz_db_version', '0' );
        return version_compare( $current_version, self::DB_VERSION, '<' );
    }

    /**
     * Clean up old processed requests (older than 24 hours)
     */
    public static function cleanup_processed_requests(): void {
        global $wpdb;

        $table = self::get_requests_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE processed_at < %s",
                gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) )
            )
        );
    }

    /**
     * Check if a request ID was already processed
     *
     * @param string $request_id The request ID to check.
     * @return bool
     */
    public static function is_request_processed( string $request_id ): bool {
        global $wpdb;

        $table = self::get_requests_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE request_id = %s",
                $request_id
            )
        );

        return (int) $exists > 0;
    }

    /**
     * Mark a request as processed
     *
     * @param string $request_id   The request ID.
     * @param int    $tokens_count Number of tokens processed.
     * @return bool
     */
    public static function mark_request_processed( string $request_id, int $tokens_count = 0 ): bool {
        global $wpdb;

        $table = self::get_requests_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            [
                'request_id'    => $request_id,
                'processed_at'  => current_time( 'mysql' ),
                'tokens_count'  => $tokens_count,
            ],
            [ '%s', '%s', '%d' ]
        );

        return false !== $result;
    }

    /**
     * Atomically CLAIM a request ID at entry (before execution).
     *
     * The UNIQUE(request_id) key makes a concurrent or duplicate INSERT fail, so
     * exactly one caller wins the claim. Unlike marking on success, claiming at
     * entry closes the CONCURRENT re-dispatch window (B-SUS-1): a retry that
     * arrives while the first execution is still running server-side (the >180s
     * case) loses the claim and must NOT start a second Upgrader on the same item.
     * Release with release_request() on failure so a genuinely-failed command
     * stays retryable.
     *
     * @param string $request_id The request ID to claim.
     * @return bool True if this caller claimed it, false if already claimed/processed.
     */
    public static function claim_request( string $request_id ): bool {
        global $wpdb;

        $table = self::get_requests_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table,
            [
                'request_id'    => $request_id,
                'processed_at'  => current_time( 'mysql' ),
                'tokens_count'  => 0,
            ],
            [ '%s', '%s', '%d' ]
        );

        return false !== $result;
    }

    /**
     * Release a claimed request ID so it can be retried.
     *
     * Called when execution failed after a claim_request() — the command did not
     * complete, so the idempotency marker must be removed or the retry would be
     * swallowed as an idempotent no-op.
     *
     * @param string $request_id The request ID to release.
     * @return void
     */
    public static function release_request( string $request_id ): void {
        global $wpdb;

        $table = self::get_requests_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->delete( $table, [ 'request_id' => $request_id ], [ '%s' ] );
    }
}
