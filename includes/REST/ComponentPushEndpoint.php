<?php
/**
 * Component Push Endpoint - Receive component updates from SaaS Hub
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Hubbee\Agent\ComponentPushHandler;
use Hubbee\Components\ChunkManager;
use Hubbee\Storage\Database;
use Hubbee\SaaS\ModeConfig;

class ComponentPushEndpoint extends RestEndpoint {

    protected function get_route(): string {
        return '/component-push';
    }

    protected function get_methods(): string {
        return 'POST';
    }

    /**
     * Handle the push request
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function handle_request( WP_REST_Request $request ) {
        $request_id = $request->get_param( 'request_id' );

        // Tag every log line emitted during this push with the request_id so
        // an Element-Library push can be traced end-to-end across CommandPoller
        // → ComponentPushHandler → ComponentRepository without grep gymnastics.
        \Hubbee\Debug\HubbeeDebug::set_request_id( $request_id ?: wp_generate_uuid4() );

        // Check for idempotency - if this request was already processed
        // We reuse the same processed_requests table with a prefixed ID
        $idempotency_key = 'component_' . $request_id;
        if ( ! empty( $request_id ) && Database::is_request_processed( $idempotency_key ) ) {
            return new WP_REST_Response(
                [
                    'success'    => true,
                    'processed'  => 0,
                    'skipped'    => 0,
                    'errors'     => [],
                    'request_id' => $request_id,
                    'message'    => __( 'Request already processed (idempotent).', 'hubbee' ),
                ],
                200
            );
        }

        $handler = new ComponentPushHandler();
        $component = $request->get_param( 'component' );

        // Get payload
        $payload = [
            'component'          => $component,
            'deleted_components' => $request->get_param( 'deleted_components' ) ?: [],
            'request_id'         => $request_id ?: wp_generate_uuid4(),
        ];

        // Validate payload
        $validation = $handler->validate_payload( $payload );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        // Process the push (upserts into wp_bz_components)
        $result = $handler->process( $payload );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Element-Library assets carry a runtime chunk URL. Download it now
        // into wp-content/uploads/hubbee/chunks/el-{type}.min.js so the
        // ComponentWidget can enqueue it at render time. If the download
        // fails we respond 502 so the VPS job-runner flips the assignment
        // to `failed` — otherwise the UI would show "installed" while the
        // site silently has nothing to render.
        $chunk_info = null;
        $chunk_hash_match = null;
        if ( is_array( $component ) ) {
            $element_type = isset( $component['element_type'] ) ? (string) $component['element_type'] : '';
            $chunk_url    = isset( $component['chunk_url'] ) ? (string) $component['chunk_url'] : '';
            $chunk_hash   = isset( $component['chunk_hash'] ) ? (string) $component['chunk_hash'] : '';
            if ( '' !== $element_type && '' !== $chunk_url ) {
                $download_result = $this->download_element_chunk( $element_type, $chunk_url, $chunk_hash );
                $chunk_hash_match = $download_result['hash_match'];
                $chunk_info = [
                    'element_type'     => $element_type,
                    'chunk_downloaded' => $download_result['downloaded'],
                    'chunk_hash_match' => $chunk_hash_match,
                ];
                if ( ! $download_result['downloaded'] ) {
                    return new WP_REST_Response(
                        [
                            'success'    => false,
                            'error'      => 'chunk_download_failed',
                            'message'    => sprintf(
                                /* translators: %s: element type */
                                __( 'Component config saved but runtime chunk for element "%s" could not be downloaded.', 'hubbee' ),
                                $element_type
                            ),
                            'request_id' => $result['request_id'],
                        ],
                        502
                    );
                }
            }
        }

        // Mark request as processed for idempotency
        if ( ! empty( $request_id ) ) {
            Database::mark_request_processed( $idempotency_key, $result['processed'] );
        }

        // Record push received
        $handler->record_push_received();

        // Purge component cache so the frontend picks up the new version
        // immediately. Mirrors the token-push flow (TokenService::purgeCaches).
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( 'hubbee_components' );
        }
        do_action(
            'hubbee_component_cache_purged',
            isset( $component['slug'] ) ? (string) $component['slug'] : '',
            isset( $component['element_type'] ) ? (string) $component['element_type'] : ''
        );

        // Confirmed-install handshake: re-read the component so the response
        // only reports it present if it genuinely persisted. The job-runner
        // marks the assignment `active` only when `verified` is true.
        $component_slug = isset( $component['slug'] ) ? (string) $component['slug'] : '';
        $persisted      = '' !== $component_slug
            ? ( new \Hubbee\Storage\ComponentRepository() )->get_by_slug( $component_slug )
            : null;
        $verified       = null !== $persisted;
        $installed_hash = $verified
            ? AssetManifestEndpoint::compute_config_hash( json_decode( (string) $persisted->config, true ) )
            : null;

        $response = [
            'success'              => true,
            'processed'            => $result['processed'],
            'skipped'              => $result['skipped'],
            'deleted'              => $result['deleted'] ?? 0,
            'errors'               => $result['errors'],
            'request_id'           => $result['request_id'],
            'chunk_hash_match'     => $chunk_hash_match,
            'verified'             => $verified,
            'installed_config_hash' => $installed_hash,
        ];
        if ( null !== $chunk_info ) {
            $response['chunk'] = $chunk_info;
        }

        return new WP_REST_Response( $response, 200 );
    }

    /**
     * Download the Element-Library runtime chunk (el-{type}.min.js) from
     * Supabase Storage into wp-content/uploads/hubbee/chunks/.
     *
     * Content-addressed: when chunk_hash matches the existing file we skip
     * the download entirely (saves ~40–113 KB of HTTP per unchanged push).
     * On download we verify the SHA-256 against the expected hash before
     * persisting, so a corrupted or tampered response never reaches disk.
     * Also writes a .sha256 sidecar so ComponentElementExtension can use
     * it as a stable cache-bust version across servers.
     *
     * Mirrors BackgroundPushEndpoint::download_chunk().
     *
     * @param string $element_type Element type slug (e.g. "bounce-cards").
     * @param string $chunk_url    Absolute URL to the chunk (public Supabase storage).
     * @param string $chunk_hash   Expected SHA-256 as "sha256:<hex>" (may be '').
     * @return array{downloaded:bool,hash_match:?bool}
     */
    private function download_element_chunk( string $element_type, string $chunk_url, string $chunk_hash = '' ): array {
        $safe_type = sanitize_file_name( $element_type );
        if ( '' === $safe_type ) {
            return [ 'downloaded' => false, 'hash_match' => null ];
        }

        // A10: SSRF guard. chunk_url is attacker-influenceable payload data; even though the push is
        // HMAC/JWT-authenticated, the URL must be restricted to Hubbee-controlled hosts (https only,
        // no private/reserved/metadata IPs) — the same allowlist used for the M2M endpoint.
        if ( ! ModeConfig::get_instance()->is_allowed_m2m_host( $chunk_url ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            hubbee_debug_log( sprintf( '[Hubbee][ComponentPush] Rejected chunk_url (host not allowed): %s', esc_url_raw( $chunk_url ) ) );
            return [ 'downloaded' => false, 'hash_match' => null ];
        }

        $chunk_dir = ChunkManager::chunks_dir();
        if ( is_wp_error( $chunk_dir ) ) {
            return [ 'downloaded' => false, 'hash_match' => null ];
        }
        $chunk_file   = $chunk_dir . '/el-' . $safe_type . '.min.js';
        $sidecar_file = $chunk_file . '.sha256';

        if ( ! wp_mkdir_p( $chunk_dir ) ) {
            return [ 'downloaded' => false, 'hash_match' => null ];
        }

        // Skip-if-same: content-addressed dedupe against the local copy.
        if ( '' !== $chunk_hash && file_exists( $chunk_file ) ) {
            $existing_hash = 'sha256:' . hash_file( 'sha256', $chunk_file );
            if ( $existing_hash === $chunk_hash ) {
                // Make sure sidecar exists (older installs may be missing it).
                if ( ! file_exists( $sidecar_file ) ) {
                    global $wp_filesystem;
                    if ( empty( $wp_filesystem ) ) {
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                        WP_Filesystem();
                    }
                    $wp_filesystem->put_contents( $sidecar_file, $chunk_hash, FS_CHMOD_FILE );
                }
                return [ 'downloaded' => true, 'hash_match' => true ];
            }
        }

        $response = wp_remote_get(
            $chunk_url,
            [
                'timeout'   => 30,
                'sslverify' => true,
            ]
        );

        if ( is_wp_error( $response ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            hubbee_debug_log(
                sprintf(
                    '[Hubbee][ComponentPush] Chunk download failed for el-%s (transport error): %s — url=%s',
                    $safe_type,
                    $response->get_error_message(),
                    esc_url_raw( $chunk_url )
                )
            );
            return [ 'downloaded' => false, 'hash_match' => null ];
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $status_code ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            hubbee_debug_log(
                sprintf(
                    '[Hubbee][ComponentPush] Chunk download for el-%s returned HTTP %d — url=%s',
                    $safe_type,
                    $status_code,
                    esc_url_raw( $chunk_url )
                )
            );
            return [ 'downloaded' => false, 'hash_match' => null ];
        }

        $body = wp_remote_retrieve_body( $response );
        if ( '' === $body ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            hubbee_debug_log(
                sprintf(
                    '[Hubbee][ComponentPush] Chunk download for el-%s returned an empty body — url=%s',
                    $safe_type,
                    esc_url_raw( $chunk_url )
                )
            );
            return [ 'downloaded' => false, 'hash_match' => null ];
        }

        // Atomic write: integrity-check, temp-file, rename, sidecar — never
        // leaves a partial or hash-mismatched chunk visible.
        $written = ChunkManager::write_chunk_atomic( $chunk_file, $body, $chunk_hash );
        if ( is_wp_error( $written ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            hubbee_debug_log(
                sprintf(
                    '[Hubbee][ComponentPush] Chunk write failed for el-%s: %s — url=%s',
                    $safe_type,
                    $written->get_error_message(),
                    esc_url_raw( $chunk_url )
                )
            );
            $hash_match = ( 'bz_chunk_hash_mismatch' === $written->get_error_code() ) ? false : null;
            return [ 'downloaded' => false, 'hash_match' => $hash_match ];
        }

        return [ 'downloaded' => true, 'hash_match' => '' !== $chunk_hash ? true : false ];
    }

    /**
     * Get endpoint arguments schema
     *
     * @return array
     */
    protected function get_args(): array {
        return [
            'component'          => [
                'description' => __( 'Component to push.', 'hubbee' ),
                'type'        => 'object',
                'required'    => false,
                'properties'  => [
                    'slug'    => [
                        'type'        => 'string',
                        'required'    => true,
                        'description' => __( 'Unique component slug.', 'hubbee' ),
                    ],
                    'name'    => [
                        'type'        => 'string',
                        'description' => __( 'Component display name.', 'hubbee' ),
                    ],
                    'version' => [
                        'type'        => 'integer',
                        'required'    => true,
                        'minimum'     => 1,
                        'description' => __( 'Version number (must be higher than existing).', 'hubbee' ),
                    ],
                    'config'  => [
                        'type'        => 'object',
                        'required'    => true,
                        'description' => __( 'Component configuration (sections, globalStyles).', 'hubbee' ),
                    ],
                ],
            ],
            'request_id'         => [
                'description' => __( 'Unique request identifier for idempotency.', 'hubbee' ),
                'type'        => 'string',
                'required'    => false,
            ],
            'deleted_components' => [
                'description' => __( 'Array of component slugs to delete.', 'hubbee' ),
                'type'        => 'array',
                'required'    => false,
                'default'     => [],
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'slug' => [
                            'type'        => 'string',
                            'required'    => true,
                            'description' => __( 'Component slug to delete.', 'hubbee' ),
                        ],
                    ],
                ],
            ],
        ];
    }
}
