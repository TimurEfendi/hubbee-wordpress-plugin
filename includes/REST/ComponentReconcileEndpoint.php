<?php
/**
 * Component Reconcile Endpoint - Drop wp_bz_components rows that no longer
 * have a matching SaaS-side `site_component_assignments` entry, plus the
 * runtime chunk file from disk.
 *
 * Used to repair phantom-component drift on WP sites: earlier versions of
 * the SaaS frontend silently failed to propagate uninstalls, leaving the
 * Elementor "Hubbee Component" widget showing rows that no longer exist
 * server-side. This endpoint accepts the SaaS's authoritative list of
 * expected slugs for the site and deletes everything else.
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use Hubbee\Agent\ComponentPushHandler;
use Hubbee\Storage\Database;
use WP_REST_Request;
use WP_REST_Response;

class ComponentReconcileEndpoint extends RestEndpoint {

    protected function get_route(): string {
        return '/component-reconcile';
    }

    protected function get_methods(): string {
        return 'POST';
    }

    /**
     * Handle the reconcile request.
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function handle_request( WP_REST_Request $request ) {
        $request_id = (string) $request->get_param( 'request_id' );

        // Tag every log line emitted during this reconcile with the request_id.
        \Hubbee\Debug\HubbeeDebug::set_request_id( $request_id ?: wp_generate_uuid4() );

        // Idempotency — same prefix scheme as ComponentPushEndpoint.
        $idempotency_key = 'reconcile_' . $request_id;
        if ( ! empty( $request_id ) && Database::is_request_processed( $idempotency_key ) ) {
            return new WP_REST_Response(
                [
                    'success'       => true,
                    'deleted'       => 0,
                    'kept'          => 0,
                    'deleted_slugs' => [],
                    'request_id'    => $request_id,
                    'message'       => __( 'Request already processed (idempotent).', 'hubbee' ),
                ],
                200
            );
        }

        $expected_slugs = $request->get_param( 'expected_slugs' );
        if ( ! is_array( $expected_slugs ) ) {
            // Empty or missing list is valid — means "WP should hold nothing".
            $expected_slugs = [];
        }

        $payload = [
            'expected_slugs' => $expected_slugs,
            'request_id'     => $request_id ?: wp_generate_uuid4(),
        ];

        $handler = new ComponentPushHandler();
        $result  = $handler->process_reconcile( $payload );

        if ( ! empty( $request_id ) ) {
            Database::mark_request_processed( $idempotency_key, $result['deleted'] );
        }

        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( 'hubbee_components' );
        }

        return new WP_REST_Response(
            [
                'success'       => true,
                'deleted'       => (int) ( $result['deleted'] ?? 0 ),
                'kept'          => (int) ( $result['kept'] ?? 0 ),
                'deleted_slugs' => array_values( $result['deleted_slugs'] ?? [] ),
                'request_id'    => $result['request_id'] ?? $payload['request_id'],
            ],
            200
        );
    }

    protected function get_args(): array {
        return [
            'expected_slugs' => [
                'description' => __( 'Slugs of components that should remain on the site. Everything else is deleted.', 'hubbee' ),
                'type'        => 'array',
                'required'    => true,
                'items'       => [
                    'type' => 'string',
                ],
            ],
            'request_id'     => [
                'description' => __( 'Unique request identifier for idempotency.', 'hubbee' ),
                'type'        => 'string',
                'required'    => false,
            ],
        ];
    }
}
