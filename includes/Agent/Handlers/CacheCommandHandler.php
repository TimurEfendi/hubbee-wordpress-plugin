<?php
/**
 * Cache-related commands (`cache.clear`, `cache.flush`).
 *
 * Migrated out of CommandExecutor::execute_cache_clear / _cache_flush as
 * the pilot for the HandlerRegistry pattern. Behaviour is identical to the
 * legacy implementation — same caches, same hook, same return shape — so
 * the move is invisible to callers.
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

class CacheCommandHandler implements CommandHandler {

    public static function command_types(): array {
        return [ 'cache.clear', 'cache.flush' ];
    }

    public function execute( string $type, array $payload ) {
        unset( $payload ); // payload currently unused — kept for future cache-key scoping.

        $cleared = [];

        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
            $cleared[] = 'object_cache';
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" );
        $cleared[] = 'transients';

        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
            $cleared[] = 'wp_rocket';
        }

        if ( function_exists( 'w3tc_flush_all' ) ) {
            w3tc_flush_all();
            $cleared[] = 'w3_total_cache';
        }

        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            wp_cache_clear_cache();
            $cleared[] = 'wp_super_cache';
        }

        if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
            \LiteSpeed_Cache_API::purge_all();
            $cleared[] = 'litespeed';
        }

        if ( class_exists( 'autoptimizeCache' ) ) {
            \autoptimizeCache::clearall();
            $cleared[] = 'autoptimize';
        }

        /**
         * Allow other cache plugins to hook in.
         *
         * @param array $cleared List of cleared caches.
         */
        do_action( 'hubbee_cache_clear', $cleared );

        return [
            'success' => true,
            'cleared' => $cleared,
        ];
    }
}
