<?php
/**
 * Input Sanitization helpers
 *
 * @package Hubbee\Security
 */

namespace Hubbee\Security;

class Sanitizer {

    /**
     * Allowed HTML tags for richtext fields
     *
     * @var array
     */
    private static array $allowed_html = [
        'a'          => [
            'href'   => [],
            'title'  => [],
            'target' => [],
            'rel'    => [],
            'class'  => [],
        ],
        'br'         => [],
        'em'         => [],
        'strong'     => [],
        'b'          => [],
        'i'          => [],
        'u'          => [],
        's'          => [],
        'p'          => [ 'class' => [] ],
        'span'       => [ 'class' => [], 'style' => [] ],
        'div'        => [ 'class' => [] ],
        'ul'         => [ 'class' => [] ],
        'ol'         => [ 'class' => [] ],
        'li'         => [ 'class' => [] ],
        'h1'         => [ 'class' => [] ],
        'h2'         => [ 'class' => [] ],
        'h3'         => [ 'class' => [] ],
        'h4'         => [ 'class' => [] ],
        'h5'         => [ 'class' => [] ],
        'h6'         => [ 'class' => [] ],
        'blockquote' => [ 'class' => [] ],
        'code'       => [ 'class' => [] ],
        'pre'        => [ 'class' => [] ],
        'img'        => [
            'src'    => [],
            'alt'    => [],
            'title'  => [],
            'width'  => [],
            'height' => [],
            'class'  => [],
        ],
    ];

    /**
     * Sanitize token key
     *
     * Token keys should be lowercase alphanumeric with underscores.
     *
     * @param string $key Token key to sanitize.
     * @return string Sanitized token key.
     */
    public static function sanitize_token_key( string $key ): string {
        // Convert to lowercase
        $key = strtolower( $key );

        // Replace spaces and dashes with underscores
        $key = str_replace( [ ' ', '-' ], '_', $key );

        // Remove any character that isn't alphanumeric or underscore
        $key = preg_replace( '/[^a-z0-9_]/', '', $key );

        // Ensure it doesn't start with a number
        if ( preg_match( '/^[0-9]/', $key ) ) {
            $key = '_' . $key;
        }

        // Limit length
        return substr( $key, 0, 100 );
    }

    /**
     * Sanitize token value based on field type
     *
     * @param string $value      Value to sanitize.
     * @param string $field_type Field type (text, textarea, richtext).
     * @return string Sanitized value.
     */
    public static function sanitize_token_value( string $value, string $field_type = 'text' ): string {
        switch ( $field_type ) {
            case 'richtext':
                return self::sanitize_richtext( $value );

            case 'textarea':
                return sanitize_textarea_field( $value );

            case 'image':
            case 'url':
            case 'gallery':
                return self::sanitize_url( $value );

            case 'number':
                return is_numeric( $value ) ? $value : '';

            case 'email':
                return sanitize_email( $value );

            case 'color':
                return preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) ? $value : sanitize_text_field( $value );

            case 'date':
            case 'text':
            default:
                return sanitize_text_field( $value );
        }
    }

    /**
     * Sanitize richtext content
     *
     * @param string $content Content to sanitize.
     * @return string Sanitized content.
     */
    public static function sanitize_richtext( string $content ): string {
        return wp_kses( $content, self::$allowed_html );
    }

    /**
     * Sanitize URL
     *
     * @param string $url URL to sanitize.
     * @return string Sanitized URL.
     */
    public static function sanitize_url( string $url ): string {
        $url = esc_url_raw( $url );

        // Ensure URL has a scheme
        if ( ! empty( $url ) && ! preg_match( '/^https?:\/\//', $url ) ) {
            $url = 'https://' . $url;
        }

        // Remove trailing slash for consistency
        return rtrim( $url, '/' );
    }

    /**
     * Sanitize section name
     *
     * @param string $section Section name to sanitize.
     * @return string Sanitized section name.
     */
    public static function sanitize_section( string $section ): string {
        // Convert to lowercase
        $section = strtolower( $section );

        // Replace spaces with underscores
        $section = str_replace( ' ', '_', $section );

        // Remove any character that isn't alphanumeric or underscore
        $section = preg_replace( '/[^a-z0-9_]/', '', $section );

        // Limit length
        return substr( $section, 0, 100 );
    }

    /**
     * Sanitize field type
     *
     * @param string $field_type Field type to sanitize.
     * @return string Valid field type.
     */
    public static function sanitize_field_type( string $field_type ): string {
        $valid_types = [ 'text', 'textarea', 'richtext', 'image', 'url', 'number', 'email', 'date', 'gallery', 'color' ];

        $field_type = strtolower( trim( $field_type ) );

        if ( in_array( $field_type, $valid_types, true ) ) {
            return $field_type;
        }

        return 'text';
    }

    /**
     * Sanitize locale
     *
     * @param string $locale Locale to sanitize.
     * @return string Sanitized locale (e.g., 'en_US', 'de_DE').
     */
    public static function sanitize_locale( string $locale ): string {
        // Remove any character that isn't alphanumeric or underscore
        $locale = preg_replace( '/[^a-zA-Z0-9_]/', '', $locale );

        // Limit length
        return substr( $locale, 0, 10 );
    }

    /**
     * Get allowed HTML for richtext
     *
     * @return array Allowed HTML tags and attributes.
     */
    public static function get_allowed_html(): array {
        return self::$allowed_html;
    }

    /**
     * Escape token value for output based on field type
     *
     * @param string $value      Value to escape.
     * @param string $field_type Field type.
     * @return string Escaped value.
     */
    public static function escape_token_value( string $value, string $field_type = 'text' ): string {
        switch ( $field_type ) {
            case 'richtext':
                return wp_kses_post( $value );

            case 'image':
            case 'url':
            case 'gallery':
                return esc_url( $value );

            case 'email':
                return sanitize_email( $value );

            default:
                return esc_html( $value );
        }
    }
}
