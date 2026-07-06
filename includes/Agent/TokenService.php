<?php
/**
 * Token Service - Agent token retrieval and caching
 *
 * @package Hubbee\Agent
 */

namespace Hubbee\Agent;

use Hubbee\Storage\TokenRepository;
use Hubbee\Security\Sanitizer;

class TokenService {

    /**
     * Token repository
     *
     * @var TokenRepository
     */
    private TokenRepository $repository;

    /**
     * Cache group
     */
    const CACHE_GROUP = 'hubbee';

    /**
     * Cache expiration in seconds (1 hour)
     */
    const CACHE_EXPIRATION = HOUR_IN_SECONDS;

    /**
     * Constructor
     */
    public function __construct() {
        $this->repository = new TokenRepository();
    }

    /**
     * Get token by key
     *
     * @param string $key    Token key.
     * @param string $locale Locale (empty for default, 'auto' for current locale).
     * @return object|null Token object or null.
     */
    public function get_token( string $key, string $locale = '' ): ?object {
        // Handle auto locale
        if ( 'auto' === $locale || '' === $locale ) {
            $locale = $this->get_current_locale();
        }

        // Try cache first
        $cache_key = $this->get_cache_key( $key, $locale );
        $cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

        if ( false !== $cached ) {
            return $cached ?: null;
        }

        // Get from database
        $token = $this->repository->get_by_key( $key, $locale );

        // If no token found for locale, try default (empty locale)
        if ( ! $token && ! empty( $locale ) ) {
            $token = $this->repository->get_by_key( $key, '' );
        }

        // Cache the result (including null to prevent repeated DB queries)
        wp_cache_set( $cache_key, $token ?: '', self::CACHE_GROUP, self::CACHE_EXPIRATION );

        return $token;
    }

    /**
     * Get token value
     *
     * @param string $key      Token key.
     * @param string $locale   Locale.
     * @param string $fallback Fallback value if token not found.
     * @return string Token value or fallback.
     */
    public function get_token_value( string $key, string $locale = '', string $fallback = '' ): string {
        $token = $this->get_token( $key, $locale );

        if ( ! $token || empty( $token->value_longtext ) ) {
            return $fallback;
        }

        return $token->value_longtext;
    }

    /**
     * Get escaped token value for output
     *
     * @param string $key      Token key.
     * @param string $locale   Locale.
     * @param string $fallback Fallback value if token not found.
     * @return string Escaped token value.
     */
    public function get_escaped_value( string $key, string $locale = '', string $fallback = '' ): string {
        $token = $this->get_token( $key, $locale );

        if ( ! $token || empty( $token->value_longtext ) ) {
            return esc_html( $fallback );
        }

        return Sanitizer::escape_token_value( $token->value_longtext, $token->field_type );
    }

    /**
     * Get all tokens
     *
     * @param string|null $locale Filter by locale.
     * @return array
     */
    public function get_all_tokens( ?string $locale = null ): array {
        $args = [];

        if ( null !== $locale ) {
            if ( 'auto' === $locale ) {
                $args['locale'] = $this->get_current_locale();
            } else {
                $args['locale'] = $locale;
            }
        }

        return $this->repository->get_all( $args );
    }

    /**
     * Get tokens for Elementor select, grouped by section
     *
     * @return array Key => label pairs with optgroup support.
     */
    public function get_tokens_for_select(): array {
        $grouped = $this->repository->get_grouped_by_section( '' );
        $options = [];

        foreach ( $grouped as $section => $tokens ) {
            // Add section header
            $section_label = ucfirst( str_replace( '_', ' ', $section ) );
            $options[ 'optgroup_start_' . $section ] = [
                'label'    => $section_label,
                'optgroup' => 'start',
            ];

            foreach ( $tokens as $token ) {
                $label = ! empty( $token->label ) ? $token->label : $token->token_key;
                $options[ $token->token_key ] = $label;
            }

            $options[ 'optgroup_end_' . $section ] = [
                'optgroup' => 'end',
            ];
        }

        return $options;
    }

    /**
     * Get flat tokens for select (without grouping)
     *
     * @return array Key => label pairs.
     */
    public function get_tokens_for_select_flat(): array {
        $tokens = $this->repository->get_all( [ 'locale' => '' ] );
        $options = [];

        foreach ( $tokens as $token ) {
            // External tokens have their own dedicated tags
            if ( str_starts_with( $token->token_key, 'ext_' ) ) {
                continue;
            }
            $label = ! empty( $token->label ) ? $token->label : $token->token_key;
            $options[ $token->token_key ] = $label;
        }

        return $options;
    }

    /**
     * Get token keys
     *
     * @return array
     */
    public function get_token_keys(): array {
        return $this->repository->get_token_keys();
    }

    /**
     * Get available sections
     *
     * @return array
     */
    public function get_sections(): array {
        return $this->repository->get_sections();
    }

    /**
     * Get available locales
     *
     * @return array
     */
    public function get_locales(): array {
        return $this->repository->get_locales();
    }

    /**
     * Search tokens
     *
     * @param string $search Search term.
     * @return array
     */
    public function search( string $search ): array {
        return $this->repository->search( $search );
    }

    /**
     * Invalidate token cache
     *
     * @param string $key    Token key.
     * @param string $locale Locale.
     */
    public function invalidate_cache( string $key, string $locale = '' ): void {
        $cache_key = $this->get_cache_key( $key, $locale );
        wp_cache_delete( $cache_key, self::CACHE_GROUP );

        // Also delete with auto locale
        $auto_cache_key = $this->get_cache_key( $key, $this->get_current_locale() );
        wp_cache_delete( $auto_cache_key, self::CACHE_GROUP );

        // Delete default locale version too
        if ( ! empty( $locale ) ) {
            $default_cache_key = $this->get_cache_key( $key, '' );
            wp_cache_delete( $default_cache_key, self::CACHE_GROUP );
        }
    }

    /**
     * Invalidate all token caches
     */
    public function invalidate_all_caches(): void {
        wp_cache_flush_group( self::CACHE_GROUP );

        /**
         * Fires after all token caches are invalidated
         */
        do_action( 'hubbee_cache_purged' );
    }

    /**
     * Get cache key for a token
     *
     * @param string $key    Token key.
     * @param string $locale Locale.
     * @return string
     */
    private function get_cache_key( string $key, string $locale ): string {
        return 'token_' . md5( $key . '_' . $locale );
    }

    /**
     * Get current locale
     *
     * @return string
     */
    private function get_current_locale(): string {
        $locale = get_locale();

        // If WPML is active, get current language
        if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
            $locale = ICL_LANGUAGE_CODE;
        }

        // If Polylang is active
        if ( function_exists( 'pll_current_language' ) ) {
            $locale = pll_current_language( 'locale' ) ?: $locale;
        }

        return $locale;
    }

    /**
     * Check if token exists
     *
     * @param string $key    Token key.
     * @param string $locale Locale.
     * @return bool
     */
    public function token_exists( string $key, string $locale = '' ): bool {
        return null !== $this->get_token( $key, $locale );
    }

    /**
     * Get token count
     *
     * @return int
     */
    public function get_token_count(): int {
        return $this->repository->count();
    }

    /**
     * Get token by ID
     *
     * @param int $id Token ID.
     * @return object|null
     */
    public function get_token_by_id( int $id ): ?object {
        return $this->repository->get_by_id( $id );
    }
}
