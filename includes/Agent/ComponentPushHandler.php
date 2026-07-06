<?php
/**
 * Component Push Handler - Process incoming component pushes from SaaS Hub
 *
 * @package Hubbee\Agent
 */

namespace Hubbee\Agent;

use Hubbee\Components\ChunkManager;
use Hubbee\Elementor\ComponentElementExtension;
use Hubbee\Storage\ComponentRepository;
use WP_Error;

class ComponentPushHandler {

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
     * Process incoming push payload
     *
     * @param array $payload Push payload from SaaS Hub.
     * @return array|WP_Error Result array or error.
     */
    public function process( array $payload ) {
        $component = $payload['component'] ?? null;
        $deleted_components = $payload['deleted_components'] ?? [];
        $request_id = $payload['request_id'] ?? '';

        // At least component or deleted_components must be provided
        if ( empty( $component ) && empty( $deleted_components ) ) {
            return new WP_Error(
                'bz_invalid_payload',
                __( 'Invalid payload: component or deleted_components is required.', 'hubbee' ),
                [ 'status' => 400 ]
            );
        }

        $results = [
            'processed'  => 0,
            'skipped'    => 0,
            'deleted'    => 0,
            'errors'     => [],
            'request_id' => $request_id,
        ];

        /**
         * Fires before processing a component push
         *
         * @param array  $payload    The push payload.
         * @param string $request_id The request ID.
         */
        do_action( 'hubbee_before_component_push', $payload, $request_id );

        // Process deletions first
        foreach ( $deleted_components as $deletion ) {
            $slug = $deletion['slug'] ?? '';
            if ( ! empty( $slug ) ) {
                // Resolve the Element-Library type from the row BEFORE deleting
                // it, so we can also drop the runtime chunk file from disk.
                // Mirrors BackgroundPushEndpoint::process() behaviour.
                $existing     = $this->repository->get_by_slug( $slug );
                $element_type = ComponentElementExtension::resolve_element_type( $existing );

                if ( $this->repository->delete_by_slug( $slug ) ) {
                    $results['deleted']++;

                    if ( '' !== $element_type ) {
                        $chunk_dir = ChunkManager::chunks_dir();
                        if ( ! is_wp_error( $chunk_dir ) ) {
                            ChunkManager::delete_with_sidecar(
                                $chunk_dir . '/el-' . sanitize_file_name( $element_type ) . '.min.js'
                            );
                        }
                    }

                    // Invalidate cache for deleted component
                    $service = new ComponentService();
                    $service->invalidate_cache( $slug );
                }
            }
        }

        // Process component update
        if ( ! empty( $component ) ) {
            $result = $this->process_component( $component );

            if ( is_wp_error( $result ) ) {
                $results['errors'][] = [
                    'slug'    => $component['slug'] ?? 'unknown',
                    'code'    => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ];
            } elseif ( 'skipped' === $result['status'] ) {
                $results['skipped']++;
            } else {
                $results['processed']++;
            }
        }

        /**
         * Fires after processing a component push
         *
         * @param array  $results    The processing results.
         * @param array  $payload    The original payload.
         * @param string $request_id The request ID.
         */
        do_action( 'hubbee_after_component_push', $results, $payload, $request_id );

        // Trigger cache purge hook if components were processed or deleted
        if ( $results['processed'] > 0 || $results['deleted'] > 0 ) {
            /**
             * Fires when a component has been updated via push
             *
             * @param string $slug Component slug.
             */
            do_action( 'hubbee_component_pushed', $component['slug'] ?? '' );

            /**
             * Fires when components have been deleted via push
             *
             * @param int $count Number of components deleted.
             */
            if ( $results['deleted'] > 0 ) {
                do_action( 'hubbee_components_deleted', $results['deleted'] );
            }

            // Generic cache purge hook for integration (WP Rocket, LiteSpeed, etc.)
            do_action( 'hubbee_cache_purge_needed' );
        }

        return $results;
    }

    /**
     * Reconcile wp_bz_components with the SaaS-side authoritative list.
     *
     * Deletes every row whose slug is NOT in `expected_slugs`, plus the
     * matching `el-{type}.min.js` chunk file (and `.sha256` sidecar).
     *
     * Repairs the phantom-component drift caused by earlier versions of the
     * SaaS frontend that silently failed to propagate uninstalls.
     *
     * @param array $payload { expected_slugs: string[], request_id: string }.
     * @return array { deleted: int, kept: int, deleted_slugs: string[], request_id: string }.
     */
    public function process_reconcile( array $payload ): array {
        $expected   = array_values( array_filter( array_map( 'sanitize_title', $payload['expected_slugs'] ?? [] ) ) );
        $request_id = (string) ( $payload['request_id'] ?? '' );

        // Site-level mutex: the snapshot-then-delete loop must not run
        // concurrently with another component reconcile/push, or two
        // requests can race on the same rows + chunk files. Same idea as
        // CommandPoller's bz_poll_lock.
        if ( ! ChunkManager::acquire_lock( 'bz_component_reconcile_lock', 60 ) ) {
            return [
                'deleted'       => 0,
                'kept'          => count( $expected ),
                'deleted_slugs' => [],
                'request_id'    => $request_id,
            ];
        }

        try {
            $all_slugs = $this->repository->get_component_slugs();
            $to_delete = array_values( array_diff( $all_slugs, $expected ) );

            do_action( 'hubbee_before_component_reconcile', $expected, $to_delete, $request_id );

            $deleted_slugs = [];

            foreach ( $to_delete as $slug ) {
                $existing     = $this->repository->get_by_slug( $slug );
                $element_type = ComponentElementExtension::resolve_element_type( $existing );

                if ( ! $this->repository->delete_by_slug( $slug ) ) {
                    continue;
                }

                $deleted_slugs[] = $slug;

                if ( '' !== $element_type ) {
                    $chunk_dir = ChunkManager::chunks_dir();
                    if ( ! is_wp_error( $chunk_dir ) ) {
                        ChunkManager::delete_with_sidecar(
                            $chunk_dir . '/el-' . sanitize_file_name( $element_type ) . '.min.js'
                        );
                    }
                }

                ( new ComponentService() )->invalidate_cache( $slug );
            }

            if ( ! empty( $deleted_slugs ) ) {
                do_action( 'hubbee_components_deleted', count( $deleted_slugs ) );
                do_action( 'hubbee_cache_purge_needed' );
            }

            do_action( 'hubbee_after_component_reconcile', $deleted_slugs, $expected, $request_id );

            return [
                'deleted'       => count( $deleted_slugs ),
                'kept'          => count( $expected ),
                'deleted_slugs' => $deleted_slugs,
                'request_id'    => $request_id,
            ];
        } finally {
            ChunkManager::release_lock( 'bz_component_reconcile_lock' );
        }
    }

    /**
     * Process a single component
     *
     * @param array $component_data Component data from push payload.
     * @return array|WP_Error Result array or error.
     */
    private function process_component( array $component_data ) {
        // Validate required fields
        $slug = $component_data['slug'] ?? '';
        if ( empty( $slug ) ) {
            return new WP_Error(
                'bz_missing_slug',
                __( 'Component slug is required.', 'hubbee' )
            );
        }

        $incoming_version = absint( $component_data['version'] ?? 0 );
        if ( $incoming_version < 1 ) {
            return new WP_Error(
                'bz_invalid_version',
                __( 'Valid version number is required.', 'hubbee' )
            );
        }

        // Check existing component
        $existing = $this->repository->get_by_slug( $slug );

        // Version comparison - only accept newer versions
        if ( $existing && $existing->version > $incoming_version ) {
            return [
                'status'           => 'skipped',
                'reason'           => 'version_not_newer',
                'current_version'  => $existing->version,
                'incoming_version' => $incoming_version,
            ];
        }

        // Prepare component data for upsert
        $config = $component_data['config'] ?? [];
        if ( is_string( $config ) ) {
            $config = json_decode( $config, true ) ?: [];
        }

        // Reject oversized configs before they hit the LONGTEXT column.
        // A multi-MB config explodes the DOM on render and is never valid.
        $size_check = ChunkManager::validate_config_size( $config, 'component:' . sanitize_title( $slug ) );
        if ( is_wp_error( $size_check ) ) {
            return $size_check;
        }

        $upsert_data = [
            'slug'      => sanitize_title( $slug ),
            'name'      => sanitize_text_field( $component_data['name'] ?? $slug ),
            'version'   => $incoming_version,
            'config'    => $config,
            'pushed_at' => current_time( 'mysql' ),
        ];

        // Upsert component
        $result = $this->repository->upsert( $upsert_data );

        if ( false === $result ) {
            return new WP_Error(
                'bz_upsert_failed',
                __( 'Failed to save component.', 'hubbee' )
            );
        }

        // Invalidate cache for this component
        $service = new ComponentService();
        $service->invalidate_cache( $slug );

        return [
            'status'       => 'processed',
            'component_id' => $result,
            'is_update'    => null !== $existing,
        ];
    }

    /**
     * Validate push payload structure
     *
     * @param array $payload Payload to validate.
     * @return true|WP_Error True if valid, WP_Error otherwise.
     */
    public function validate_payload( array $payload ) {
        $has_component = isset( $payload['component'] ) && is_array( $payload['component'] ) && ! empty( $payload['component'] );
        $has_deleted = isset( $payload['deleted_components'] ) && is_array( $payload['deleted_components'] ) && ! empty( $payload['deleted_components'] );

        // At least one of component or deleted_components must be present
        if ( ! $has_component && ! $has_deleted ) {
            return new WP_Error(
                'bz_missing_payload',
                __( 'Payload must contain component or deleted_components.', 'hubbee' ),
                [ 'status' => 400 ]
            );
        }

        // Validate component if present
        if ( $has_component ) {
            $component = $payload['component'];

            if ( ! isset( $component['slug'] ) || empty( $component['slug'] ) ) {
                return new WP_Error(
                    'bz_component_missing_slug',
                    __( 'Component is missing required "slug" field.', 'hubbee' ),
                    [ 'status' => 400 ]
                );
            }

            if ( ! isset( $component['version'] ) || absint( $component['version'] ) < 1 ) {
                return new WP_Error(
                    'bz_component_invalid_version',
                    __( 'Component has invalid version.', 'hubbee' ),
                    [ 'status' => 400 ]
                );
            }

            if ( ! isset( $component['config'] ) ) {
                return new WP_Error(
                    'bz_component_missing_config',
                    __( 'Component is missing required "config" field.', 'hubbee' ),
                    [ 'status' => 400 ]
                );
            }
        }

        // Validate deleted_components if present
        if ( $has_deleted ) {
            foreach ( $payload['deleted_components'] as $index => $deletion ) {
                if ( ! isset( $deletion['slug'] ) || empty( $deletion['slug'] ) ) {
                    return new WP_Error(
                        'bz_deletion_missing_slug',
                        sprintf(
                            /* translators: %d: deletion index */
                            __( 'Deletion at index %d is missing required "slug" field.', 'hubbee' ),
                            $index
                        ),
                        [ 'status' => 400 ]
                    );
                }
            }
        }

        return true;
    }

    /**
     * Get push statistics
     *
     * @return array
     */
    public function get_stats(): array {
        return [
            'total_components' => $this->repository->count(),
            'last_push'        => get_option( 'bz_last_component_push_received', '' ),
        ];
    }

    /**
     * Record push received
     */
    public function record_push_received(): void {
        update_option( 'bz_last_component_push_received', current_time( 'mysql' ) );
    }
}
