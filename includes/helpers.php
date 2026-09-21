<?php
/**
 * Global helper functions (intentionally NOT namespaced so unqualified calls
 * from the plugin's sub-namespaces resolve here via the global fallback).
 *
 * @package Hubbee
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'hubbee_debug_log' ) ) {
    /**
     * Write a diagnostic line to the PHP error log ONLY when WP_DEBUG is on.
     *
     * WordPress.org guidelines discourage unconditional error_log() output in
     * production. Routing the plugin's diagnostics through this gate keeps it
     * silent on production sites while preserving the logs developers rely on
     * with WP_DEBUG enabled. Structured diagnostics use Hubbee\Debug\HubbeeDebug.
     *
     * @param mixed $message String message, or any value (json-encoded).
     * @return void
     */
    function hubbee_debug_log( $message ) {
        if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
            return;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( is_string( $message ) ? $message : wp_json_encode( $message ) );
    }
}
