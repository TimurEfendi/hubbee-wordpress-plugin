<?php
/**
 * Push Endpoint - Receive token updates from SaaS Hub
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Hubbee\Agent\PushHandler;
use Hubbee\Storage\Database;

class PushEndpoint extends RestEndpoint {

    protected function get_route(): string {
        return '/push';
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
        // a token push from VPS can be traced end-to-end without grep gymnastics.
        \Hubbee\Debug\HubbeeDebug::set_request_id( $request_id ?: wp_generate_uuid4() );

        // Check for idempotency - if this request was already processed
        if ( ! empty( $request_id ) && Database::is_request_processed( $request_id ) ) {
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

        $handler = new PushHandler();

        // Get payload
        $payload = [
            'tokens'                 => $request->get_param( 'tokens' ),
            'deleted_tokens'         => $request->get_param( 'deleted_tokens' ) ?: [],
            'expected_external_keys' => $request->get_param( 'expected_external_keys' ),
            'expected_keys'          => $request->get_param( 'expected_keys' ),
            'keys_checksum'          => $request->get_param( 'keys_checksum' ),
            'reconcile_empty_ok'     => (bool) $request->get_param( 'reconcile_empty_ok' ),
            'request_id'             => $request_id ?: wp_generate_uuid4(),
        ];

        // Validate payload
        $validation = $handler->validate_payload( $payload );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        // Process the push
        $result = $handler->process( $payload );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Mark request as processed for idempotency
        if ( ! empty( $request_id ) ) {
            Database::mark_request_processed( $request_id, $result['processed'] );
        }

        // Record push received only if at least 1 token was processed or deleted
        if ( $result['processed'] > 0 || ( $result['deleted'] ?? 0 ) > 0 ) {
            $handler->record_push_received();
        }

        // Probabilistic cleanup of old processed requests (1% of calls)
        if ( wp_rand( 1, 100 ) === 1 ) {
            Database::cleanup_processed_requests();
        }

        return new WP_REST_Response(
            [
                'success'         => true,
                'processed'       => $result['processed'],
                'skipped'         => $result['skipped'],
                'deleted'         => $result['deleted'] ?? 0,
                'reconciled'      => $result['reconciled'] ?? 0,
                'needs_reconcile' => $result['needs_reconcile'] ?? false,
                'errors'          => $result['errors'],
                'token_results'   => $result['token_results'] ?? [],
                'request_id'      => $result['request_id'],
            ],
            200
        );
    }

    /**
     * Get endpoint arguments schema
     *
     * @return array
     */
    protected function get_args(): array {
        return [
            'tokens'     => [
                'description' => __( 'Array of tokens to push.', 'hubbee' ),
                'type'        => 'array',
                'required'    => true,
                'maxItems'    => 500,
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'key'         => [
                            'type'        => 'string',
                            'required'    => true,
                            'description' => __( 'Unique token key.', 'hubbee' ),
                        ],
                        'label'       => [
                            'type'        => 'string',
                            'description' => __( 'Human-readable label.', 'hubbee' ),
                        ],
                        'description' => [
                            'type'        => 'string',
                            'description' => __( 'Token description.', 'hubbee' ),
                        ],
                        'field_type'  => [
                            'type'        => 'string',
                            'enum'        => [ 'text', 'textarea', 'richtext', 'image', 'url', 'number', 'email', 'date', 'gallery', 'color' ],
                            'default'     => 'text',
                            'description' => __( 'Field type for the token.', 'hubbee' ),
                        ],
                        'value'       => [
                            'type'        => 'string',
                            'description' => __( 'Token value.', 'hubbee' ),
                        ],
                        'locale'      => [
                            'type'        => 'string',
                            'default'     => '',
                            'description' => __( 'Locale for the token (empty for default).', 'hubbee' ),
                        ],
                        'version'     => [
                            'type'        => 'integer',
                            'required'    => true,
                            'minimum'     => 1,
                            'description' => __( 'Version number (must be higher than existing).', 'hubbee' ),
                        ],
                        'section'     => [
                            'type'        => 'string',
                            'default'     => 'general',
                            'description' => __( 'Section/category for grouping.', 'hubbee' ),
                        ],
                    ],
                ],
            ],
            'request_id'     => [
                'description' => __( 'Unique request identifier for idempotency.', 'hubbee' ),
                'type'        => 'string',
                'required'    => false,
            ],
            'deleted_tokens' => [
                'description' => __( 'Array of token keys to delete.', 'hubbee' ),
                'type'        => 'array',
                'required'    => false,
                'default'     => [],
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'key' => [
                            'type'        => 'string',
                            'required'    => true,
                            'description' => __( 'Token key to delete.', 'hubbee' ),
                        ],
                    ],
                ],
            ],
            'expected_external_keys' => [
                'description' => __( 'Array of external token keys that should exist. All ext_* tokens not in this list will be removed.', 'hubbee' ),
                'type'        => 'array',
                'required'    => false,
                'items'       => [
                    'type' => 'string',
                ],
            ],
            'expected_keys' => [
                'description' => __( 'Array of regular token keys that should exist. All non-ext_* tokens not in this list will be removed (full-push reconciliation).', 'hubbee' ),
                'type'        => 'array',
                'required'    => false,
                'items'       => [
                    'type' => 'string',
                ],
            ],
            'keys_checksum' => [
                'description' => __( 'SHA-256 of the expected non-external key set. On mismatch the response sets needs_reconcile=true; nothing is deleted.', 'hubbee' ),
                'type'        => 'string',
                'required'    => false,
            ],
            'reconcile_empty_ok' => [
                'description' => __( 'Authorize reconciliation against an empty expected_keys list (delete all). Without it, an empty list is a no-op.', 'hubbee' ),
                'type'        => 'boolean',
                'required'    => false,
                'default'     => false,
            ],
        ];
    }
}
