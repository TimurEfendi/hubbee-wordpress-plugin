<?php
/**
 * Hubbee Debug Logger
 *
 * Provides structured logging for debugging component rendering,
 * manifest operations, and cache behavior.
 *
 * Usage:
 * - Enable debug mode: define( 'HUBBEE_DEBUG', true ); in wp-config.php
 * - Or via filter: add_filter( 'hubbee_debug_enabled', '__return_true' );
 *
 * Log levels:
 * - DEBUG: Verbose information for deep debugging
 * - INFO: General operational information
 * - WARN: Potential issues that don't prevent operation
 * - ERROR: Errors that affect functionality
 *
 * @package Hubbee\Debug
 */

namespace Hubbee\Debug;

class HubbeeDebug {

    /**
     * Log levels
     */
    const DEBUG = 'DEBUG';
    const INFO  = 'INFO';
    const WARN  = 'WARN';
    const ERROR = 'ERROR';

    /**
     * Context categories
     */
    const CTX_MANIFEST  = 'Manifest';
    const CTX_RENDER    = 'Render';
    const CTX_CACHE     = 'Cache';
    const CTX_PUSH      = 'Push';
    const CTX_HYDRATOR  = 'Hydrator';
    const CTX_COMPONENT = 'Component';
    const CTX_CONTACT   = 'Contact';
    const CTX_GENERAL   = 'General';

    /**
     * Whether debug is enabled (cached)
     *
     * @var bool|null
     */
    private static ?bool $is_enabled = null;

    /**
     * Per-request correlation id, propagated into every log line so a
     * single VPS-triggered command can be traced through CommandEndpoint
     * → CommandExecutor → handler → response without grep gymnastics.
     *
     * @var string
     */
    private static string $request_id = '';

    /**
     * Log entries buffer for admin display
     *
     * @var array
     */
    private static array $log_buffer = [];

    /**
     * Maximum buffer size
     */
    const MAX_BUFFER_SIZE = 100;

    /**
     * Check if debug mode is enabled
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        if ( null !== self::$is_enabled ) {
            return self::$is_enabled;
        }

        // Check constant first
        if ( defined( 'HUBBEE_DEBUG' ) && HUBBEE_DEBUG ) {
            self::$is_enabled = true;
            return true;
        }

        // Check WP_DEBUG as fallback
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            self::$is_enabled = true;
            return true;
        }

        // Check filter
        self::$is_enabled = apply_filters( 'hubbee_debug_enabled', false );

        return self::$is_enabled;
    }

    /**
     * Log a debug message
     *
     * @param string $context Context category (use CTX_* constants).
     * @param string $message Log message.
     * @param array  $data    Optional structured data.
     * @param string $level   Log level (use level constants).
     */
    public static function log(
        string $context,
        string $message,
        array $data = [],
        string $level = self::DEBUG
    ): void {
        if ( ! self::is_enabled() ) {
            return;
        }

        $timestamp = gmdate( 'Y-m-d H:i:s' );
        $req_chunk = '' !== self::$request_id ? '[req=' . self::$request_id . '] ' : '';
        $log_entry = sprintf(
            '[%s] [Hubbee:%s] [%s] %s%s',
            $timestamp,
            $context,
            $level,
            $req_chunk,
            $message
        );

        if ( ! empty( $data ) ) {
            $log_entry .= ' ' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
        }

        // Write to error log
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( $log_entry );

        // Buffer for admin display
        self::buffer_entry([
            'timestamp' => $timestamp,
            'context'   => $context,
            'level'     => $level,
            'message'   => $message,
            'data'      => $data,
        ]);
    }

    /**
     * Log debug level message
     *
     * @param string $context Context category.
     * @param string $message Log message.
     * @param array  $data    Optional data.
     */
    public static function debug( string $context, string $message, array $data = [] ): void {
        self::log( $context, $message, $data, self::DEBUG );
    }

    /**
     * Log info level message
     *
     * @param string $context Context category.
     * @param string $message Log message.
     * @param array  $data    Optional data.
     */
    public static function info( string $context, string $message, array $data = [] ): void {
        self::log( $context, $message, $data, self::INFO );
    }

    /**
     * Log warning level message
     *
     * @param string $context Context category.
     * @param string $message Log message.
     * @param array  $data    Optional data.
     */
    public static function warn( string $context, string $message, array $data = [] ): void {
        self::log( $context, $message, $data, self::WARN );
    }

    /**
     * Log error level message
     *
     * @param string $context Context category.
     * @param string $message Log message.
     * @param array  $data    Optional data.
     */
    public static function error( string $context, string $message, array $data = [] ): void {
        self::log( $context, $message, $data, self::ERROR );
    }

    /**
     * Set the per-request correlation id. Pass an empty string to clear.
     * Safe to call multiple times — last setter wins for the rest of the
     * PHP request lifecycle.
     *
     * @param string $id Correlation id (uuid or short token).
     */
    public static function set_request_id( string $id ): void {
        self::$request_id = trim( $id );
    }

    /**
     * Get the active correlation id ('' if none).
     *
     * @return string
     */
    public static function get_request_id(): string {
        return self::$request_id;
    }

    /**
     * Buffer log entry for admin display
     *
     * @param array $entry Log entry.
     */
    private static function buffer_entry( array $entry ): void {
        self::$log_buffer[] = $entry;

        // Trim buffer if too large
        if ( count( self::$log_buffer ) > self::MAX_BUFFER_SIZE ) {
            array_shift( self::$log_buffer );
        }
    }

    /**
     * Get buffered log entries
     *
     * @return array
     */
    public static function get_buffer(): array {
        return self::$log_buffer;
    }

    /**
     * Clear buffer
     */
    public static function clear_buffer(): void {
        self::$log_buffer = [];
    }

    /**
     * Create a timer for performance measurement
     *
     * @param string $label Timer label.
     * @return callable Function to call when done (returns elapsed ms).
     */
    public static function timer( string $label ): callable {
        $start = microtime( true );

        return function () use ( $label, $start ) {
            $elapsed_ms = round( ( microtime( true ) - $start ) * 1000, 2 );

            self::debug(
                self::CTX_GENERAL,
                "Timer [{$label}]: {$elapsed_ms}ms"
            );

            return $elapsed_ms;
        };
    }

    /**
     * Log component render event
     *
     * @param string $slug       Component slug.
     * @param bool   $is_active  Whether component is active.
     * @param string $outcome    Render outcome.
     */
    public static function log_render(
        string $slug,
        bool $is_active,
        string $outcome
    ): void {
        self::info(
            self::CTX_RENDER,
            "Rendering component",
            [
                'slug'      => $slug,
                'is_active' => $is_active,
                'outcome'   => $outcome,
            ]
        );
    }

    /**
     * Log manifest operation
     *
     * @param string $operation Operation name.
     * @param array  $details   Operation details.
     */
    public static function log_manifest( string $operation, array $details = [] ): void {
        self::debug(
            self::CTX_MANIFEST,
            $operation,
            $details
        );
    }

    /**
     * Log cache operation
     *
     * @param string $operation Operation name.
     * @param string $key       Cache key.
     * @param bool   $hit       Whether cache was hit.
     */
    public static function log_cache( string $operation, string $key, bool $hit ): void {
        self::debug(
            self::CTX_CACHE,
            "{$operation}: " . ( $hit ? 'HIT' : 'MISS' ),
            [ 'key' => $key ]
        );
    }

    /**
     * Output debug script tag for client-side debugging
     *
     * Call this in wp_head if debug mode is enabled.
     */
    public static function output_debug_script(): void {
        if ( ! self::is_enabled() ) {
            return;
        }

        echo '<script id="hubbee-debug-init">';
        echo 'window.HUBBEE_DEBUG=true;';
        echo 'console.log("[Hubbee] Debug mode enabled");';
        echo '</script>';
    }

    /**
     * Add debug info to HTML attributes
     *
     * @param array $attributes Existing attributes.
     * @return array Attributes with debug info.
     */
    public static function add_debug_attrs( array $attributes ): array {
        if ( ! self::is_enabled() ) {
            return $attributes;
        }

        $attributes['data-bz-debug'] = 'true';
        $attributes['data-bz-debug-time'] = gmdate( 'Y-m-d\TH:i:s\Z' );

        return $attributes;
    }
}
