<?php
/**
 * Component Service - Component retrieval with caching
 *
 * @package Hubbee\Agent
 */

namespace Hubbee\Agent;

use Hubbee\Storage\ComponentRepository;

class ComponentService {

    /**
     * Cache group
     */
    const CACHE_GROUP = 'hubbee_components';

    /**
     * Cache TTL in seconds (1 hour)
     */
    const CACHE_TTL = HOUR_IN_SECONDS;

    /**
     * Component repository
     *
     * @var ComponentRepository
     */
    private ComponentRepository $repository;

    /**
     * Constructor
     */
    public function __construct() {
        $this->repository = new ComponentRepository();
    }

    /**
     * Get component by slug with caching
     *
     * @param string $slug Component slug.
     * @return object|null Component object or null.
     */
    public function get( string $slug ): ?object {
        $cache_key = $this->get_cache_key( $slug );

        // Try cache first
        $cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
        if ( false !== $cached ) {
            return $cached ?: null;
        }

        // Get from database
        $component = $this->repository->get_by_slug( $slug );

        // Cache the result (even null to prevent repeated DB queries)
        wp_cache_set( $cache_key, $component ?: '', self::CACHE_GROUP, self::CACHE_TTL );

        return $component;
    }

    /**
     * Get decoded config for a component with caching
     *
     * @param string $slug Component slug.
     * @return array|null Config array or null.
     */
    public function get_config( string $slug ): ?array {
        $cache_key = $this->get_cache_key( $slug, 'config' );

        // Try cache first
        $cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
        if ( false !== $cached ) {
            return is_array( $cached ) ? $cached : null;
        }

        // Get from database
        $config = $this->repository->get_config( $slug );

        // Cache the result
        wp_cache_set( $cache_key, $config ?: '', self::CACHE_GROUP, self::CACHE_TTL );

        return $config;
    }

    /**
     * Get all components (no cache - always fresh from database)
     *
     * @return array
     */
    public function get_all(): array {
        return $this->repository->get_all();
    }

    /**
     * Get components for dropdown (no cache - always fresh from database)
     *
     * @return array
     */
    public function get_for_dropdown(): array {
        return $this->repository->get_for_dropdown();
    }

    /**
     * Invalidate cache for a specific component
     *
     * @param string $slug Component slug.
     */
    public function invalidate_cache( string $slug ): void {
        $cache_key = $this->get_cache_key( $slug );
        $config_cache_key = $this->get_cache_key( $slug, 'config' );

        wp_cache_delete( $cache_key, self::CACHE_GROUP );
        wp_cache_delete( $config_cache_key, self::CACHE_GROUP );

        // Also clear list transients
        delete_transient( 'bz_components_list' );
        delete_transient( 'bz_components_dropdown' );
    }

    /**
     * Invalidate all component caches
     */
    public function invalidate_all_cache(): void {
        // Clear transients
        delete_transient( 'bz_components_list' );
        delete_transient( 'bz_components_dropdown' );

        // Flush the entire cache group if object cache is available
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( self::CACHE_GROUP );
        }
    }

    /**
     * Get cache key for a component
     *
     * @param string $slug   Component slug.
     * @param string $suffix Optional suffix.
     * @return string
     */
    private function get_cache_key( string $slug, string $suffix = '' ): string {
        $key = 'component_' . md5( $slug );
        if ( $suffix ) {
            $key .= '_' . $suffix;
        }
        return $key;
    }

    /**
     * Check if a component exists
     *
     * @param string $slug Component slug.
     * @return bool
     */
    public function exists( string $slug ): bool {
        return null !== $this->get( $slug );
    }

    /**
     * Get component count
     *
     * @return int
     */
    public function count(): int {
        return $this->repository->count();
    }
}
