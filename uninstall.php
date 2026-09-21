<?php
/**
 * Uninstall Hubbee
 *
 * Cleans up all plugin data when the plugin is deleted. Runs in an isolated
 * context (the plugin bootstrap/autoloader is NOT loaded), so the Database
 * class is required explicitly to keep the table list a single source of truth.
 *
 * @package Hubbee
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

/**
 * Drop all database tables (single source of truth: Database::drop_tables()).
 * Falls back to a hardcoded list if the class cannot be loaded for any reason.
 */
$database_class = __DIR__ . '/includes/Storage/Database.php';
if ( file_exists( $database_class ) ) {
    require_once $database_class;
}

if ( class_exists( '\\Hubbee\\Storage\\Database' ) ) {
    \Hubbee\Storage\Database::drop_tables();
} else {
    $tables = array(
        $wpdb->prefix . 'bz_tokens',
        $wpdb->prefix . 'bz_processed_requests',
        $wpdb->prefix . 'bz_components',
        $wpdb->prefix . 'bz_backgrounds',
        $wpdb->prefix . 'bz_text_effects',
    );
    foreach ( $tables as $table ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
    }
}

/**
 * Clear any scheduled cron events the plugin may have left behind.
 * (Deactivation normally clears these, but a direct delete must not leak them.)
 */
$cron_hooks = array(
    'hubbee_poll_commands',   // CommandPoller
    'hubbee_heartbeat',       // HeartbeatScheduler
    'hubbee_health_snapshot', // HealthScheduler (snapshot)
    'hubbee_health_deep',     // HealthScheduler (deep)
    'hubbee_health_ping',     // deprecated v2.1.0
    'bz_token_sync',          // legacy v1.x
    'bz_cleanup_logs',        // legacy v1.x
);
foreach ( $cron_hooks as $hook ) {
    wp_clear_scheduled_hook( $hook );
}

/**
 * Delete ALL plugin options (every `bz_` prefixed option, incl. runtime
 * counters, interval hints, failure counters and the M2M endpoint).
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( 'bz_' ) . '%'
    )
);

/**
 * Delete transients (value + timeout rows).
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_' . $wpdb->esc_like( 'bz_' ) . '%',
        '_transient_timeout_' . $wpdb->esc_like( 'bz_' ) . '%'
    )
);

/**
 * Delete user meta.
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like( 'bz_' ) . '%'
    )
);

/**
 * Remove capabilities from roles.
 */
$capabilities = array(
    'bz_manage_settings',
);

$roles = array( 'administrator' );

foreach ( $roles as $role_name ) {
    $role = get_role( $role_name );
    if ( $role ) {
        foreach ( $capabilities as $cap ) {
            $role->remove_cap( $cap );
        }
    }
}

/**
 * Clean up uploads .htaccess CORS rules.
 */
$upload_dir = wp_upload_dir();
$htaccess   = trailingslashit( $upload_dir['basedir'] ) . '.htaccess';
if ( file_exists( $htaccess ) ) {
    insert_with_markers( $htaccess, 'Hubbee CORS', array() );
    // Remove file if now empty.
    $contents = file_get_contents( $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local file, not a remote URL.
    if ( $contents !== false && trim( $contents ) === '' ) {
        wp_delete_file( $htaccess );
    }
}

/**
 * Remove the pushed chunk directory (background/text-effect/element chunks).
 */
$chunks_dir = trailingslashit( $upload_dir['basedir'] ) . 'hubbee/chunks';
if ( is_dir( $chunks_dir ) ) {
    $files = glob( $chunks_dir . '/*' );
    if ( is_array( $files ) ) {
        foreach ( $files as $file ) {
            if ( is_file( $file ) ) {
                wp_delete_file( $file );
            }
        }
    }
    @rmdir( $chunks_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own empty upload directory during uninstall.
    @rmdir( trailingslashit( $upload_dir['basedir'] ) . 'hubbee' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own empty upload directory during uninstall.
}

/**
 * Clear object cache.
 */
wp_cache_flush();
