<?php
/**
 * Background Push Endpoint - Receive background updates from SaaS Hub
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Hubbee\Components\ChunkManager;
use Hubbee\Storage\BackgroundRepository;
use Hubbee\Storage\Database;
use Hubbee\SaaS\ModeConfig;

class BackgroundPushEndpoint extends RestEndpoint {

    protected function get_route(): string {
        return '/background-push';
    }

    protected function get_methods(): string {
        return 'POST';
    }

    public function handle_request( WP_REST_Request $request ) {
        $request_id = $request->get_param( 'request_id' );

        // Check for idempotency - if this request was already processed
        $idempotency_key = 'background_' . $request_id;
        if ( ! empty( $request_id ) && Database::is_request_processed( $idempotency_key ) ) {
            return new WP_REST_Response(
                [
                    'success'    => true,
                    'processed'  => 0,
                    'request_id' => $request_id,
                    'message'    => __( 'Request already processed (idempotent).', 'hubbee' ),
                ],
                200
            );
        }

        $background          = $request->get_param( 'background' );
        $deleted_backgrounds = $request->get_param( 'deleted_backgrounds' );
        $repository          = new BackgroundRepository();
        $deleted_count       = 0;

        // Process deletions first (like ComponentPushHandler). The
        // read-snapshot-then-delete loop is wrapped in a site-level mutex so
        // it cannot race with a concurrent background push/reconcile (same
        // idea as CommandPoller's bz_poll_lock).
        if ( ! empty( $deleted_backgrounds ) && is_array( $deleted_backgrounds ) ) {
            if ( ChunkManager::acquire_lock( 'bz_background_reconcile_lock', 60 ) ) {
                try {
                    $chunk_dir = ChunkManager::chunks_dir();

                    foreach ( $deleted_backgrounds as $deletion ) {
                        $slug    = $deletion['slug'] ?? '';
                        $bg_type = $deletion['bg_type'] ?? '';

                        if ( empty( $slug ) ) {
                            continue;
                        }

                        if ( $repository->delete_by_slug( $slug ) ) {
                            $deleted_count++;

                            // Delete the chunk file + its .sha256 sidecar.
                            if ( ! empty( $bg_type ) && ! is_wp_error( $chunk_dir ) ) {
                                ChunkManager::delete_with_sidecar(
                                    $chunk_dir . '/bg-' . sanitize_file_name( $bg_type ) . '.min.js'
                                );
                            }
                        }
                    }
                } finally {
                    ChunkManager::release_lock( 'bz_background_reconcile_lock' );
                }
            }
        }

        // If only deletions (no background to upsert), return early
        if ( empty( $background ) || ! is_array( $background ) ) {
            if ( $deleted_count > 0 ) {
                if ( ! empty( $request_id ) ) {
                    Database::mark_request_processed( $idempotency_key, $deleted_count );
                }
                do_action( 'hubbee_cache_purge_needed', 'background_delete' );
                ( new \Hubbee\Components\BackgroundManifestService() )->invalidate();

                return new WP_REST_Response(
                    [
                        'success'   => true,
                        'deleted'   => $deleted_count,
                        'processed' => 0,
                    ],
                    200
                );
            }

            return new WP_Error(
                'missing_data',
                __( 'Background data or deleted_backgrounds is required.', 'hubbee' ),
                [ 'status' => 400 ]
            );
        }

        // Validate required fields
        if ( empty( $background['slug'] ) ) {
            return new WP_Error(
                'missing_slug',
                __( 'Background slug is required.', 'hubbee' ),
                [ 'status' => 400 ]
            );
        }

        if ( empty( $background['bg_type'] ) ) {
            return new WP_Error(
                'missing_bg_type',
                __( 'Background type (bg_type) is required.', 'hubbee' ),
                [ 'status' => 400 ]
            );
        }

        if ( empty( $background['config'] ) ) {
            return new WP_Error(
                'missing_config',
                __( 'Background config is required.', 'hubbee' ),
                [ 'status' => 400 ]
            );
        }

        // Reject oversized configs before they hit the LONGTEXT column.
        $size_check = ChunkManager::validate_config_size(
            $background['config'],
            'background:' . sanitize_title( (string) $background['slug'] )
        );
        if ( is_wp_error( $size_check ) ) {
            return $size_check;
        }

        // Upsert to BackgroundRepository
        $result_id = $repository->upsert( [
            'slug'      => $background['slug'],
            'name'      => $background['name'] ?? $background['slug'],
            'bg_type'   => $background['bg_type'],
            'config'    => $background['config'],
            'pushed_at' => $background['pushed_at'] ?? current_time( 'mysql' ),
        ] );

        if ( false === $result_id ) {
            return new WP_Error(
                'upsert_failed',
                __( 'Failed to save background.', 'hubbee' ),
                [ 'status' => 500 ]
            );
        }

        // Download chunk if chunk_url is provided. Failed download =>
        // 502 so the VPS job-runner flips the assignment to `failed`
        // (audit P0-6). Previously this returned 200/chunk_downloaded=false
        // and the UI happily showed "installed" while the site had nothing
        // to render.
        $chunk_downloaded = false;
        if ( ! empty( $background['chunk_url'] ) ) {
            $chunk_downloaded = $this->download_chunk(
                $background['bg_type'],
                $background['chunk_url'],
                $background['chunk_hash'] ?? ''
            );
            if ( ! $chunk_downloaded ) {
                return new WP_REST_Response(
                    [
                        'success'    => false,
                        'error'      => 'chunk_download_failed',
                        'message'    => sprintf(
                            /* translators: %s: background type */
                            __( 'Background config saved but runtime chunk for "%s" could not be downloaded.', 'hubbee' ),
                            $background['bg_type']
                        ),
                        'request_id' => $request_id ?: wp_generate_uuid4(),
                    ],
                    502
                );
            }
        }

        // Mark request as processed for idempotency
        if ( ! empty( $request_id ) ) {
            Database::mark_request_processed( $idempotency_key, 1 );
        }

        // Fire hooks + invalidate caches so the frontend picks up the new
        // config immediately. Without manifest invalidation the 5-min
        // transient kept serving the old background until it expired,
        // which looked like "push doesn't stick" to the user (bombenfest
        // push reliability initiative, 2026-04-24).
        do_action( 'hubbee_background_pushed', $background['slug'], $background );
        do_action( 'hubbee_cache_purge_needed', 'background_push' );
        ( new \Hubbee\Components\BackgroundManifestService() )->invalidate();
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( 'hubbee_backgrounds' );
        }
        do_action( 'hubbee_background_cache_purged', $background['slug'], $background['bg_type'] );

        // Confirmed-install handshake: re-read the row so the response only
        // reports the record as present if it genuinely persisted. The
        // job-runner marks the assignment `active` only when `verified` is true.
        $persisted      = $repository->get_by_slug( $background['slug'] );
        $verified       = null !== $persisted;
        $installed_hash = $verified
            ? AssetManifestEndpoint::compute_config_hash( json_decode( (string) $persisted->config, true ) )
            : null;

        return new WP_REST_Response(
            [
                'success'              => true,
                'processed'            => 1,
                'request_id'           => $request_id ?: wp_generate_uuid4(),
                'chunk_downloaded'     => $chunk_downloaded,
                'chunk_hash_match'     => ! empty( $background['chunk_hash'] ) && $chunk_downloaded,
                'verified'             => $verified,
                'installed_config_hash' => $installed_hash,
            ],
            200
        );
    }

    /**
     * Download background chunk file from Supabase storage
     *
     * @param string $bg_type    Background type.
     * @param string $chunk_url  URL to download chunk from.
     * @param string $chunk_hash Expected hash for cache validation.
     * @return bool Whether the chunk was downloaded successfully.
     */
    private function download_chunk( string $bg_type, string $chunk_url, string $chunk_hash ): bool {
        $safe_type = sanitize_file_name( $bg_type );

        // A10: SSRF guard — chunk_url must point at a Hubbee-controlled host (https, no private IPs).
        if ( ! ModeConfig::get_instance()->is_allowed_m2m_host( $chunk_url ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            hubbee_debug_log( sprintf( '[Hubbee][BackgroundPush] Rejected chunk_url (host not allowed): %s', esc_url_raw( $chunk_url ) ) );
            return false;
        }

        $chunk_dir = ChunkManager::chunks_dir();
        if ( is_wp_error( $chunk_dir ) ) {
            return false;
        }
        $chunk_file = $chunk_dir . '/bg-' . $safe_type . '.min.js';

        // Only download if file doesn't exist or chunk_hash differs
        if ( file_exists( $chunk_file ) && ! empty( $chunk_hash ) ) {
            $existing_hash = 'sha256:' . hash_file( 'sha256', $chunk_file );
            if ( $existing_hash === $chunk_hash ) {
                return true; // Already up to date
            }
        }

        // Create directory if needed
        if ( ! wp_mkdir_p( $chunk_dir ) ) {
            return false;
        }

        // Download the chunk
        $response = wp_remote_get( $chunk_url, [
            'timeout'   => 30,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            hubbee_debug_log(
                sprintf(
                    '[Hubbee][BackgroundPush] Chunk download failed for bg-%s (transport error): %s — url=%s',
                    $safe_type,
                    $response->get_error_message(),
                    esc_url_raw( $chunk_url )
                )
            );
            return false;
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status_code ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            hubbee_debug_log(
                sprintf(
                    '[Hubbee][BackgroundPush] Chunk download for bg-%s returned HTTP %d — url=%s',
                    $safe_type,
                    $status_code,
                    esc_url_raw( $chunk_url )
                )
            );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            hubbee_debug_log(
                sprintf(
                    '[Hubbee][BackgroundPush] Chunk download for bg-%s returned an empty body — url=%s',
                    $safe_type,
                    esc_url_raw( $chunk_url )
                )
            );
            return false;
        }

        // Atomic write: integrity-check, temp-file, rename, sidecar — never
        // leaves a partial or hash-mismatched chunk visible.
        $written = ChunkManager::write_chunk_atomic( $chunk_file, $body, $chunk_hash ?? '' );
        if ( is_wp_error( $written ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            hubbee_debug_log(
                sprintf(
                    '[Hubbee][BackgroundPush] Chunk write failed for bg-%s: %s — url=%s',
                    $safe_type,
                    $written->get_error_message(),
                    esc_url_raw( $chunk_url )
                )
            );
            return false;
        }

        return true;
    }

    /**
     * Get endpoint arguments schema
     *
     * @return array
     */
    protected function get_args(): array {
        return [
            'background'  => [
                'description' => __( 'Background to push.', 'hubbee' ),
                'type'        => 'object',
                'required'    => false,
                'properties'  => [
                    'slug'       => [
                        'type'        => 'string',
                        'required'    => true,
                        'description' => __( 'Unique background slug.', 'hubbee' ),
                    ],
                    'name'       => [
                        'type'        => 'string',
                        'description' => __( 'Background display name.', 'hubbee' ),
                    ],
                    'bg_type'    => [
                        'type'        => 'string',
                        'required'    => true,
                        'description' => __( 'Background type (e.g. aurora, particles).', 'hubbee' ),
                    ],
                    'config'     => [
                        'type'        => 'object',
                        'required'    => true,
                        'description' => __( 'Background configuration.', 'hubbee' ),
                    ],
                    'chunk_url'  => [
                        'type'        => 'string',
                        'description' => __( 'URL to download the background chunk JS.', 'hubbee' ),
                    ],
                    'chunk_hash' => [
                        'type'        => 'string',
                        'description' => __( 'SHA-256 hash for cache validation.', 'hubbee' ),
                    ],
                ],
            ],
            'deleted_backgrounds' => [
                'description' => __( 'Backgrounds to delete.', 'hubbee' ),
                'type'        => 'array',
                'required'    => false,
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'slug'    => [ 'type' => 'string', 'required' => true ],
                        'bg_type' => [ 'type' => 'string' ],
                    ],
                ],
            ],
            'request_id'  => [
                'description' => __( 'Unique request identifier for idempotency.', 'hubbee' ),
                'type'        => 'string',
                'required'    => false,
            ],
        ];
    }
}
