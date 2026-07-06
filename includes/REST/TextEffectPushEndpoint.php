<?php
/**
 * Text Effect Push Endpoint - Receive text effect updates from SaaS Hub
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Hubbee\Components\ChunkManager;
use Hubbee\Storage\TextEffectRepository;
use Hubbee\Storage\Database;
use Hubbee\SaaS\ModeConfig;

class TextEffectPushEndpoint extends RestEndpoint {

    protected function get_route(): string {
        return '/text-effect-push';
    }

    protected function get_methods(): string {
        return 'POST';
    }

    public function handle_request( WP_REST_Request $request ) {
        $request_id = $request->get_param( 'request_id' );

        $idempotency_key = 'text_effect_' . $request_id;
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

        $text_effect          = $request->get_param( 'text_effect' );
        $deleted_text_effects = $request->get_param( 'deleted_text_effects' );
        $repository           = new TextEffectRepository();
        $deleted_count        = 0;

        // Process deletions first. The read-snapshot-then-delete loop is
        // wrapped in a site-level mutex so it cannot race with a concurrent
        // text-effect push/reconcile (same idea as CommandPoller's
        // bz_poll_lock).
        if ( ! empty( $deleted_text_effects ) && is_array( $deleted_text_effects ) ) {
            if ( ChunkManager::acquire_lock( 'bz_text_effect_reconcile_lock', 60 ) ) {
                try {
                    $chunk_dir = ChunkManager::chunks_dir();

                    foreach ( $deleted_text_effects as $deletion ) {
                        $slug        = $deletion['slug'] ?? '';
                        $effect_type = $deletion['effect_type'] ?? '';

                        // Delete by type (all entries with this effect_type) when no slug provided
                        if ( empty( $slug ) && ! empty( $effect_type ) ) {
                            $deleted_count += $repository->delete_by_type( $effect_type );

                            if ( ! is_wp_error( $chunk_dir ) ) {
                                ChunkManager::delete_with_sidecar(
                                    $chunk_dir . '/tx-' . sanitize_file_name( $effect_type ) . '.min.js'
                                );
                            }
                            continue;
                        }

                        if ( empty( $slug ) ) {
                            continue;
                        }

                        if ( $repository->delete_by_slug( $slug ) ) {
                            $deleted_count++;

                            if ( ! empty( $effect_type ) && ! is_wp_error( $chunk_dir ) ) {
                                ChunkManager::delete_with_sidecar(
                                    $chunk_dir . '/tx-' . sanitize_file_name( $effect_type ) . '.min.js'
                                );
                            }
                        }
                    }
                } finally {
                    ChunkManager::release_lock( 'bz_text_effect_reconcile_lock' );
                }
            }
        }

        // If only deletions, return early
        if ( empty( $text_effect ) || ! is_array( $text_effect ) ) {
            if ( $deleted_count > 0 ) {
                if ( ! empty( $request_id ) ) {
                    Database::mark_request_processed( $idempotency_key, $deleted_count );
                }
                do_action( 'hubbee_cache_purge_needed', 'text_effect_delete' );

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
                __( 'Text effect data or deleted_text_effects is required.', 'hubbee' ),
                [ 'status' => 400 ]
            );
        }

        // Validate required fields
        if ( empty( $text_effect['slug'] ) ) {
            return new WP_Error( 'missing_slug', __( 'Text effect slug is required.', 'hubbee' ), [ 'status' => 400 ] );
        }

        if ( empty( $text_effect['effect_type'] ) ) {
            return new WP_Error( 'missing_effect_type', __( 'Effect type is required.', 'hubbee' ), [ 'status' => 400 ] );
        }

        if ( empty( $text_effect['config'] ) ) {
            return new WP_Error( 'missing_config', __( 'Text effect config is required.', 'hubbee' ), [ 'status' => 400 ] );
        }

        // Reject oversized configs before they hit the LONGTEXT column.
        $size_check = ChunkManager::validate_config_size(
            $text_effect['config'],
            'text_effect:' . sanitize_title( (string) $text_effect['slug'] )
        );
        if ( is_wp_error( $size_check ) ) {
            return $size_check;
        }

        // Upsert to repository
        $result_id = $repository->upsert( [
            'slug'        => $text_effect['slug'],
            'name'        => $text_effect['name'] ?? $text_effect['slug'],
            'effect_type' => $text_effect['effect_type'],
            'config'      => $text_effect['config'],
            'pushed_at'   => $text_effect['pushed_at'] ?? current_time( 'mysql' ),
        ] );

        if ( false === $result_id ) {
            return new WP_Error( 'upsert_failed', __( 'Failed to save text effect.', 'hubbee' ), [ 'status' => 500 ] );
        }

        // Download chunk if chunk_url is provided. A failed download must
        // propagate as 502 so the VPS job-runner flips the assignment to
        // `failed` and the UI surfaces a retry button — a silent false on
        // a 200-OK response was masking broken pushes (audit P0-6).
        $chunk_downloaded = false;
        if ( ! empty( $text_effect['chunk_url'] ) ) {
            $chunk_downloaded = $this->download_chunk(
                $text_effect['effect_type'],
                $text_effect['chunk_url'],
                $text_effect['chunk_hash'] ?? ''
            );
            if ( ! $chunk_downloaded ) {
                return new WP_REST_Response(
                    [
                        'success'    => false,
                        'error'      => 'chunk_download_failed',
                        'message'    => sprintf(
                            /* translators: %s: effect type */
                            __( 'Text effect config saved but runtime chunk for "%s" could not be downloaded.', 'hubbee' ),
                            $text_effect['effect_type']
                        ),
                        'request_id' => $request_id ?: wp_generate_uuid4(),
                    ],
                    502
                );
            }
        }

        if ( ! empty( $request_id ) ) {
            Database::mark_request_processed( $idempotency_key, 1 );
        }

        // Reconcile: remove WP entries that no longer exist in SaaS
        $valid_slugs = $request->get_param( 'valid_slugs' );
        if ( is_array( $valid_slugs ) && ! empty( $valid_slugs ) ) {
            $this->reconcile_orphans( $repository, $valid_slugs );
        }

        // Confirmed-install handshake: re-read the row AFTER the upsert + the
        // reconcile_orphans prune, so the response only reports the record as
        // installed if it genuinely survived the whole request. The job-runner
        // flips the assignment to `active` only when `verified` is true — so the
        // SaaS "installed" state can never be a lie (a prune/persist failure
        // surfaces as `failed`, not a false `active`).
        $persisted     = $repository->get_by_slug( $text_effect['slug'] );
        $verified      = null !== $persisted;
        $installed_hash = $verified
            ? AssetManifestEndpoint::compute_config_hash( json_decode( (string) $persisted->config, true ) )
            : null;

        // Fire hooks + flush cache so the frontend picks up the new effect
        // immediately. Symmetry with Background/Component push (bombenfest
        // push reliability initiative, 2026-04-24).
        do_action( 'hubbee_text_effect_pushed', $text_effect['slug'], $text_effect );
        do_action( 'hubbee_cache_purge_needed', 'text_effect_push' );
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( 'hubbee_text_effects' );
        }
        do_action( 'hubbee_text_effect_cache_purged', $text_effect['slug'], $text_effect['effect_type'] );

        return new WP_REST_Response(
            [
                'success'              => true,
                'processed'            => 1,
                'request_id'           => $request_id ?: wp_generate_uuid4(),
                'chunk_downloaded'     => $chunk_downloaded,
                'chunk_hash_match'     => ! empty( $text_effect['chunk_hash'] ) && $chunk_downloaded,
                'verified'             => $verified,
                'installed_config_hash' => $installed_hash,
            ],
            200
        );
    }

    /**
     * Reconcile orphaned text effects: delete WP entries not in the valid_slugs list from SaaS
     *
     * @param TextEffectRepository $repository Repository instance.
     * @param array                $valid_slugs Slugs that should exist on this site.
     */
    private function reconcile_orphans( TextEffectRepository $repository, array $valid_slugs ): void {
        // Site-level mutex: the snapshot-then-delete loop must not run
        // concurrently with another text-effect push/reconcile, or two
        // requests can race on the same rows + chunk files. Same idea as
        // CommandPoller's bz_poll_lock.
        if ( ! ChunkManager::acquire_lock( 'bz_text_effect_reconcile_lock', 60 ) ) {
            return;
        }

        try {
            $all = $repository->get_all();

            $chunks_dir = ChunkManager::chunks_dir();

            foreach ( $all as $te ) {
                if ( in_array( $te->text_effect_slug, $valid_slugs, true ) ) {
                    continue;
                }

                // This slug is not in the valid list — delete it
                $repository->delete_by_slug( $te->text_effect_slug );

                // Check if any other effect of the same type remains
                $same_type_remains = false;
                foreach ( $all as $other ) {
                    if ( $other->text_effect_slug !== $te->text_effect_slug
                        && $other->effect_type === $te->effect_type
                        && in_array( $other->text_effect_slug, $valid_slugs, true )
                    ) {
                        $same_type_remains = true;
                        break;
                    }
                }

                // Delete chunk file (+ sidecar) only if no other effect of this type remains
                if ( ! $same_type_remains && ! is_wp_error( $chunks_dir ) ) {
                    ChunkManager::delete_with_sidecar(
                        $chunks_dir . '/tx-' . sanitize_file_name( $te->effect_type ) . '.min.js'
                    );
                }
            }
        } finally {
            ChunkManager::release_lock( 'bz_text_effect_reconcile_lock' );
        }
    }

    /**
     * Download text effect chunk file from Supabase storage
     */
    private function download_chunk( string $effect_type, string $chunk_url, string $chunk_hash ): bool {
        $safe_type = sanitize_file_name( $effect_type );

        // A10: SSRF guard — chunk_url must point at a Hubbee-controlled host (https, no private IPs).
        if ( ! ModeConfig::get_instance()->is_allowed_m2m_host( $chunk_url ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( sprintf( '[Hubbee][TextEffectPush] Rejected chunk_url (host not allowed): %s', esc_url_raw( $chunk_url ) ) );
            return false;
        }

        $chunk_dir = ChunkManager::chunks_dir();
        if ( is_wp_error( $chunk_dir ) ) {
            return false;
        }
        $chunk_file = $chunk_dir . '/tx-' . $safe_type . '.min.js';

        if ( file_exists( $chunk_file ) && ! empty( $chunk_hash ) ) {
            $existing_hash = 'sha256:' . hash_file( 'sha256', $chunk_file );
            if ( $existing_hash === $chunk_hash ) {
                return true;
            }
        }

        if ( ! wp_mkdir_p( $chunk_dir ) ) {
            return false;
        }

        $response = wp_remote_get( $chunk_url, [
            'timeout'   => 30,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(
                sprintf(
                    '[Hubbee][TextEffectPush] Chunk download failed for tx-%s (transport error): %s — url=%s',
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
            error_log(
                sprintf(
                    '[Hubbee][TextEffectPush] Chunk download for tx-%s returned HTTP %d — url=%s',
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
            error_log(
                sprintf(
                    '[Hubbee][TextEffectPush] Chunk download for tx-%s returned an empty body — url=%s',
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
            error_log(
                sprintf(
                    '[Hubbee][TextEffectPush] Chunk write failed for tx-%s: %s — url=%s',
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
     */
    protected function get_args(): array {
        return [
            'text_effect'  => [
                'description' => __( 'Text effect to push.', 'hubbee' ),
                'type'        => 'object',
                'required'    => false,
                'properties'  => [
                    'slug'        => [ 'type' => 'string', 'required' => true ],
                    'name'        => [ 'type' => 'string' ],
                    'effect_type' => [ 'type' => 'string', 'required' => true ],
                    'config'      => [ 'type' => 'object', 'required' => true ],
                    'chunk_url'   => [ 'type' => 'string' ],
                    'chunk_hash'  => [ 'type' => 'string' ],
                ],
            ],
            'deleted_text_effects' => [
                'description' => __( 'Text effects to delete.', 'hubbee' ),
                'type'        => 'array',
                'required'    => false,
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'slug'        => [ 'type' => 'string', 'required' => true ],
                        'effect_type' => [ 'type' => 'string' ],
                    ],
                ],
            ],
            'valid_slugs' => [
                'description' => __( 'All valid text effect slugs for this site (for reconciliation).', 'hubbee' ),
                'type'        => 'array',
                'required'    => false,
                'items'       => [ 'type' => 'string' ],
            ],
            'request_id'  => [
                'description' => __( 'Unique request identifier for idempotency.', 'hubbee' ),
                'type'        => 'string',
                'required'    => false,
            ],
        ];
    }
}
