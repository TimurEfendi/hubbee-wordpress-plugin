<?php
/**
 * Background Manifest Service
 *
 * Fetches and caches the background manifest from SaaS.
 * The manifest is the source of truth for which backgrounds are active.
 * Orphaned backgrounds (not in manifest) get auto-deleted with their chunks.
 *
 * @package Hubbee\Components
 */

namespace Hubbee\Components;

use Hubbee\SaaS\ConnectionManager;
use Hubbee\Storage\BackgroundRepository;

class BackgroundManifestService {

    const CACHE_KEY       = 'bz_background_manifest';
    const CACHE_TTL       = 300; // 5 minutes
    const ETAG_KEY        = 'bz_background_manifest_etag';
    const STALE_CACHE_KEY = 'bz_bg_manifest_stale_cache';

    private ConnectionManager $connection;
    private ?array $cached_manifest = null;

    public function __construct() {
        $this->connection = new ConnectionManager();
    }

    /**
     * Get the background manifest (3-layer cache)
     */
    public function get_manifest( bool $force_refresh = false ): ?array {
        // Layer 1: Per-request memory
        if ( ! $force_refresh && null !== $this->cached_manifest ) {
            return $this->cached_manifest;
        }

        // Layer 2: Transient cache
        if ( ! $force_refresh ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( false !== $cached && is_array( $cached ) ) {
                $this->cached_manifest = $cached;
                return $cached;
            }
        }

        // Check connection
        if ( ! $this->connection->is_connected() ) {
            return $this->get_stale_fallback();
        }

        // Fetch from SaaS
        $manifest = $this->fetch_from_saas();

        if ( null !== $manifest ) {
            set_transient( self::CACHE_KEY, $manifest, self::CACHE_TTL );
            update_option( self::STALE_CACHE_KEY, $manifest, false );
            $this->cached_manifest = $manifest;
            return $manifest;
        }

        // Network failure: stale cache
        $stale = $this->get_stale_fallback();
        if ( null !== $stale ) {
            return $stale;
        }

        // No cache: fail open
        return null;
    }

    /**
     * Check if a background is active in the manifest
     */
    public function is_background_active( string $slug ): bool {
        $manifest = $this->get_manifest();

        if ( null === $manifest || empty( $manifest['backgrounds'] ) ) {
            return true; // Fail open
        }

        foreach ( $manifest['backgrounds'] as $bg ) {
            if ( isset( $bg['slug'] ) && $bg['slug'] === $slug ) {
                return isset( $bg['active'] ) ? (bool) $bg['active'] : true;
            }
        }

        return false;
    }

    /**
     * Get all active backgrounds from manifest
     */
    public function get_active_backgrounds(): array {
        $manifest = $this->get_manifest();

        if ( null === $manifest || empty( $manifest['backgrounds'] ) ) {
            return [];
        }

        return array_filter(
            $manifest['backgrounds'],
            fn( $bg ) => isset( $bg['active'] ) && $bg['active']
        );
    }

    /**
     * Get active background slugs from manifest
     */
    public function get_active_slugs(): array {
        return array_column( $this->get_active_backgrounds(), 'slug' );
    }

    /**
     * Sync local backgrounds with manifest.
     * Deletes orphaned backgrounds + their chunk files.
     */
    public function sync_with_manifest(): array {
        $manifest = $this->get_manifest( true );

        if ( null === $manifest ) {
            return [ 'deleted' => 0, 'error' => 'No manifest available' ];
        }

        $manifest_slugs = ! empty( $manifest['backgrounds'] )
            ? array_column( $manifest['backgrounds'], 'slug' )
            : [];

        $repository = new BackgroundRepository();
        $local      = $repository->get_all();
        $deleted    = 0;

        $upload_dir = wp_upload_dir();
        $chunks_dir = $upload_dir['basedir'] . '/hubbee/chunks';

        foreach ( $local as $bg ) {
            if ( ! in_array( $bg->background_slug, $manifest_slugs, true ) ) {
                // Orphaned: delete from DB
                $repository->delete_by_slug( $bg->background_slug );

                // Delete chunk file
                $chunk_file = $chunks_dir . '/bg-' . sanitize_file_name( $bg->bg_type ) . '.min.js';
                if ( file_exists( $chunk_file ) ) {
                    wp_delete_file( $chunk_file );
                }

                $this->log_debug( "Sync: removed orphaned background '{$bg->background_slug}'" );
                $deleted++;
            }
        }

        return [
            'deleted'        => $deleted,
            'manifest_count' => count( $manifest_slugs ),
            'local_count'    => count( $local ),
        ];
    }

    /**
     * Invalidate manifest cache
     */
    public function invalidate( bool $include_stale = false ): void {
        delete_transient( self::CACHE_KEY );
        delete_transient( self::ETAG_KEY );
        $this->cached_manifest = null;

        if ( $include_stale ) {
            delete_option( self::STALE_CACHE_KEY );
        }
    }

    /**
     * Fetch manifest from SaaS
     */
    private function fetch_from_saas(): ?array {
        $site_id    = $this->connection->get_saas_site_id();
        $api_secret = $this->connection->get_api_secret();
        $endpoint   = $this->connection->get_saas_endpoint() . '/functions/v1/background-manifest';

        if ( empty( $site_id ) || empty( $api_secret ) ) {
            return null;
        }

        $timestamp = time();
        $signature = hash_hmac( 'sha256', $timestamp . '.', $api_secret );

        $headers = [
            'Content-Type'       => 'application/json',
            'X-Hubbee-Signature' => $signature,
            'X-Hubbee-Timestamp' => (string) $timestamp,
            'X-Hubbee-Site-Id'   => $site_id,
        ];

        $cached_etag = get_transient( self::ETAG_KEY );
        if ( ! empty( $cached_etag ) ) {
            $headers['If-None-Match'] = $cached_etag;
        }

        $response = wp_remote_get( $endpoint, [
            'headers' => $headers,
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( 304 === $status_code ) {
            $cached = get_transient( self::CACHE_KEY );
            return is_array( $cached ) ? $cached : null;
        }

        if ( 200 === $status_code ) {
            $body     = wp_remote_retrieve_body( $response );
            $manifest = json_decode( $body, true );

            if ( ! is_array( $manifest ) ) {
                return null;
            }

            $etag = wp_remote_retrieve_header( $response, 'etag' );
            if ( ! empty( $etag ) ) {
                set_transient( self::ETAG_KEY, $etag, self::CACHE_TTL * 2 );
            }

            return $manifest;
        }

        return null;
    }

    private function get_stale_fallback(): ?array {
        $stale = get_option( self::STALE_CACHE_KEY );
        if ( is_array( $stale ) && ! empty( $stale['backgrounds'] ) ) {
            $this->cached_manifest = $stale;
            return $stale;
        }
        return null;
    }

    private function log_debug( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[Hubbee:BgManifest] ' . $message );
        }
    }
}
