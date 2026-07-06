<?php
/**
 * Minimal WordPress stubs for pure-unit tests.
 * Add a stub only when a test under tests/Unit/ actually exercises it.
 */

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( string $hook, $value, ...$args ) {
        return $value;
    }
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
    function wp_generate_uuid4(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int( 0, 0xffff ), random_int( 0, 0xffff ),
            random_int( 0, 0xffff ),
            random_int( 0, 0x0fff ) | 0x4000,
            random_int( 0, 0x3fff ) | 0x8000,
            random_int( 0, 0xffff ), random_int( 0, 0xffff ), random_int( 0, 0xffff )
        );
    }
}

if ( ! function_exists( '__' ) ) {
    function __( string $text, string $domain = 'default' ): string {
        return $text;
    }
}

// WordPress time constants used by cache TTLs etc.
foreach ( [
    'MINUTE_IN_SECONDS' => 60,
    'HOUR_IN_SECONDS'   => 3600,
    'DAY_IN_SECONDS'    => 86400,
    'WEEK_IN_SECONDS'   => 604800,
] as $__bz_const => $__bz_value ) {
    if ( ! defined( $__bz_const ) ) {
        define( $__bz_const, $__bz_value );
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( string $url, int $component = -1 ) {
        return parse_url( $url, $component );
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $name, $default = false ) {
        return $GLOBALS['__wp_options'][ $name ] ?? $default;
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url( string $path = '' ): string {
        return ( $GLOBALS['__wp_home_url'] ?? 'https://example.com' ) . $path;
    }
}
