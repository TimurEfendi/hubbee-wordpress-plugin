<?php
/**
 * Push Handler - Process incoming token pushes from SaaS Hub
 *
 * @package Hubbee\Agent
 */

namespace Hubbee\Agent;

use Hubbee\Storage\TokenRepository;
use Hubbee\Security\Sanitizer;
use Hubbee\Agent\ComponentService;
use WP_Error;

class PushHandler {

    /**
     * Token repository
     *
     * @var TokenRepository
     */
    private TokenRepository $repository;

    /**
     * Token service for cache invalidation
     *
     * @var TokenService
     */
    private TokenService $token_service;

    /**
     * Constructor
     */
    public function __construct() {
        $this->repository = new TokenRepository();
        $this->token_service = new TokenService();
    }

    /**
     * Process incoming push payload
     *
     * @param array $payload Push payload from SaaS Hub.
     * @return array|WP_Error Result array or error.
     */
    public function process( array $payload ) {
        $tokens = $payload['tokens'] ?? [];
        $deleted_tokens = $payload['deleted_tokens'] ?? [];
        $expected_external_keys = $payload['expected_external_keys'] ?? null;
        $expected_keys = $payload['expected_keys'] ?? null;
        $keys_checksum = isset( $payload['keys_checksum'] ) ? (string) $payload['keys_checksum'] : null;
        // Delta-push safety: an empty expected_keys list only wipes all tokens when the
        // caller EXPLICITLY opts in. A caller that failed to compute its key set (e.g. a
        // transient DB error) sends no flag, so an accidental empty list is a no-op instead
        // of mass-deletion.
        $reconcile_empty_ok = ! empty( $payload['reconcile_empty_ok'] );
        $request_id = $payload['request_id'] ?? '';

        // At least tokens, deleted_tokens, expected_external_keys, expected_keys, or keys_checksum must be provided
        $has_tokens = ! empty( $tokens ) && is_array( $tokens );
        $has_deleted = ! empty( $deleted_tokens ) && is_array( $deleted_tokens );
        $has_expected = is_array( $expected_external_keys );
        $has_expected_keys = is_array( $expected_keys );
        $has_checksum = null !== $keys_checksum && '' !== $keys_checksum;

        if ( ! $has_tokens && ! $has_deleted && ! $has_expected && ! $has_expected_keys && ! $has_checksum ) {
            return new WP_Error(
                'bz_invalid_payload',
                __( 'Invalid payload: tokens, deleted_tokens, expected_external_keys, expected_keys, or keys_checksum is required.', 'hubbee' ),
                [ 'status' => 400 ]
            );
        }

        $results = [
            'processed'      => 0,
            'skipped'        => 0,
            'deleted'        => 0,
            'errors'         => [],
            'token_results'  => [],
            'needs_reconcile' => false,
            'request_id'     => $request_id,
        ];

        /**
         * Fires before processing a push
         *
         * @param array  $payload    The push payload.
         * @param string $request_id The request ID.
         */
        do_action( 'hubbee_before_push', $payload, $request_id );

        // Wrap deletions + upserts in a transaction for atomicity
        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );

        try {
            $results = $this->process_tokens_and_deletions( $tokens, $deleted_tokens, $results );

            // Reconcile external tokens: remove stale ext_* keys not in expected list.
            // Guard: an empty list only deletes everything when explicitly authorized.
            if ( is_array( $expected_external_keys ) && ( ! empty( $expected_external_keys ) || $reconcile_empty_ok ) ) {
                $reconciled = $this->repository->delete_external_not_in_keys( $expected_external_keys );
                if ( $reconciled > 0 ) {
                    $results['deleted'] += $reconciled;
                    $results['reconciled'] = ( $results['reconciled'] ?? 0 ) + $reconciled;
                    do_action( 'hubbee_cache_purge_needed' );
                }
            }

            // Reconcile regular tokens: remove stale non-ext keys not in expected list.
            // Guard: an empty list only deletes everything when explicitly authorized.
            if ( is_array( $expected_keys ) && ( ! empty( $expected_keys ) || $reconcile_empty_ok ) ) {
                $reconciled = $this->repository->delete_not_in_keys( $expected_keys );
                if ( $reconciled > 0 ) {
                    $results['deleted'] += $reconciled;
                    $results['reconciled'] = ( $results['reconciled'] ?? 0 ) + $reconciled;
                    do_action( 'hubbee_cache_purge_needed' );
                }
            }

            // Delta-push drift detection: the caller sent a checksum of the full expected
            // NON-external key set (instead of the whole list). If ours differs, WP has
            // drifted (stale/extra tokens) and the caller should follow up with an explicit
            // expected_keys reconcile pass. We never delete here — checksum is read-only.
            if ( $has_checksum && ! is_array( $expected_keys ) ) {
                if ( ! hash_equals( $this->compute_keys_checksum(), $keys_checksum ) ) {
                    $results['needs_reconcile'] = true;
                }
            }

            $wpdb->query( 'COMMIT' );
        } catch ( \Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error(
                'bz_transaction_failed',
                $e->getMessage(),
                [ 'status' => 500 ]
            );
        }

        /**
         * Fires after processing a push
         *
         * @param array  $results    The processing results.
         * @param array  $payload    The original payload.
         * @param string $request_id The request ID.
         */
        do_action( 'hubbee_after_push', $results, $payload, $request_id );

        if ( $results['processed'] > 0 ) {
            do_action( 'hubbee_tokens_pushed', $results['processed'] );
        }
        if ( $results['deleted'] > 0 ) {
            do_action( 'hubbee_tokens_deleted', $results['deleted'] );
        }

        // Always purge cache after push — explicit user intent
        do_action( 'hubbee_cache_purge_needed' );

        // Element-Library configs cache resolved tokenBindings into the
        // hubbee_components group; flushing here lets the next render pick
        // up the new token values without needing a separate component push.
        if ( $results['processed'] > 0 || $results['deleted'] > 0 ) {
            ( new ComponentService() )->invalidate_all_cache();
        }

        return $results;
    }

    /**
     * Process deletions and token upserts (called within transaction)
     *
     * @param array $tokens         Token data array.
     * @param array $deleted_tokens Deletion data array.
     * @param array $results        Results accumulator.
     * @return array Updated results.
     */
    private function process_tokens_and_deletions( array $tokens, array $deleted_tokens, array $results ): array {
        // Process deletions first
        foreach ( $deleted_tokens as $deletion ) {
            $key = $deletion['key'] ?? '';
            if ( ! empty( $key ) ) {
                // Delete token (for all locales)
                if ( $this->repository->delete_all_locales( $key ) ) {
                    $results['deleted']++;
                    $results['token_results'][] = [
                        'key'    => $key,
                        'status' => 'deleted',
                    ];

                    // Invalidate cache for deleted token
                    $this->token_service->invalidate_cache( $key );
                } else {
                    $results['errors'][] = [
                        'key'     => $key,
                        'code'    => 'delete_failed',
                        'message' => 'Failed to delete token',
                    ];
                    $results['token_results'][] = [
                        'key'    => $key,
                        'status' => 'error',
                        'reason' => 'delete_failed',
                    ];
                }
            }
        }

        // Then process token updates
        foreach ( $tokens as $index => $token_data ) {
            $result = $this->process_token( $token_data );
            $token_key = $token_data['key'] ?? 'unknown';

            if ( is_wp_error( $result ) ) {
                $error_data = $result->get_error_data();
                $results['errors'][] = [
                    'index'    => $index,
                    'key'      => $token_key,
                    'code'     => $result->get_error_code(),
                    'message'  => $result->get_error_message(),
                    'db_error' => $error_data['db_error'] ?? '',
                ];
                $results['token_results'][] = [
                    'key'    => $token_key,
                    'status' => 'error',
                ];
                continue;
            }

            if ( 'skipped' === $result['status'] ) {
                $results['skipped']++;
                $results['token_results'][] = [
                    'key'    => $token_key,
                    'status' => 'skipped',
                ];
            } else {
                $results['processed']++;
                $results['token_results'][] = [
                    'key'    => $token_key,
                    'status' => 'processed',
                ];
            }
        }

        return $results;
    }

    /**
     * Compute a checksum over the site's full set of NON-external token keys.
     *
     * Must match the SaaS/VPS computation exactly: distinct token_key values
     * (excluding ext_* external tokens), byte-sorted ascending, joined by "\n",
     * SHA-256 hex. Token keys are sanitized to [a-z0-9_], so byte order and the
     * VPS-side sort agree.
     *
     * @return string Lowercase SHA-256 hex digest.
     */
    private function compute_keys_checksum(): string {
        $keys = $this->repository->get_non_external_keys();
        sort( $keys, SORT_STRING );
        return hash( 'sha256', implode( "\n", $keys ) );
    }

    /**
     * Process a single token
     *
     * @param array $token_data Token data from push payload.
     * @return array|WP_Error Result array or error.
     */
    private function process_token( array $token_data ) {
        // Validate required fields
        $key = $token_data['key'] ?? '';
        if ( empty( $key ) ) {
            return new WP_Error(
                'bz_missing_key',
                __( 'Token key is required.', 'hubbee' )
            );
        }

        $incoming_version = absint( $token_data['version'] ?? 0 );
        if ( $incoming_version < 1 ) {
            return new WP_Error(
                'bz_invalid_version',
                __( 'Valid version number is required.', 'hubbee' )
            );
        }

        $locale = Sanitizer::sanitize_locale( $token_data['locale'] ?? '' );

        // Prepare field_type early — needed for value comparison below
        $field_type = Sanitizer::sanitize_field_type( $token_data['field_type'] ?? 'text' );

        // Check existing token
        $existing = $this->repository->get_by_key( $key, $locale );

        // Version comparison — SaaS is source of truth
        // Only skip if WP has higher version AND the value is unchanged.
        // If the value differs, accept the push (SaaS version may have been reset).
        if ( $existing && $existing->version > $incoming_version ) {
            $incoming_value = Sanitizer::sanitize_token_value( $token_data['value'] ?? '', $field_type );
            if ( $existing->value_longtext === $incoming_value ) {
                return [
                    'status'           => 'skipped',
                    'reason'           => 'version_not_newer',
                    'current_version'  => $existing->version,
                    'incoming_version' => $incoming_version,
                ];
            }
        }

        // Prepare token data for upsert
        $section = Sanitizer::sanitize_section( $token_data['section'] ?? 'general' );

        $upsert_data = [
            'token_key'      => Sanitizer::sanitize_token_key( $key ),
            'label'          => sanitize_text_field( $token_data['label'] ?? '' ),
            'description'    => sanitize_textarea_field( $token_data['description'] ?? '' ),
            'field_type'     => $field_type,
            'value_longtext' => Sanitizer::sanitize_token_value( $token_data['value'] ?? '', $field_type ),
            'locale'         => $locale,
            'version'        => $incoming_version,
            'section'        => $section,
            'updated_at'     => current_time( 'mysql' ),
        ];

        // Upsert token
        $result = $this->repository->upsert( $upsert_data );

        if ( false === $result ) {
            return new WP_Error(
                'bz_upsert_failed',
                __( 'Failed to save token.', 'hubbee' ),
                [ 'db_error' => $GLOBALS['hubbee_last_db_error'] ?? '' ]
            );
        }

        // Invalidate cache for this token
        $this->token_service->invalidate_cache( $key, $locale );

        return [
            'status'    => 'processed',
            'token_id'  => $result,
            'is_update' => null !== $existing,
        ];
    }

    /**
     * Validate push payload structure
     *
     * @param array $payload Payload to validate.
     * @return true|WP_Error True if valid, WP_Error otherwise.
     */
    public function validate_payload( array $payload ) {
        $has_tokens = isset( $payload['tokens'] ) && is_array( $payload['tokens'] ) && ! empty( $payload['tokens'] );
        $has_deleted = isset( $payload['deleted_tokens'] ) && is_array( $payload['deleted_tokens'] ) && ! empty( $payload['deleted_tokens'] );
        $has_expected = isset( $payload['expected_external_keys'] ) && is_array( $payload['expected_external_keys'] );
        $has_expected_keys = isset( $payload['expected_keys'] ) && is_array( $payload['expected_keys'] );

        // At least one of tokens, deleted_tokens, expected_external_keys, or expected_keys must be present
        if ( ! $has_tokens && ! $has_deleted && ! $has_expected && ! $has_expected_keys ) {
            return new WP_Error(
                'bz_missing_payload',
                __( 'Payload must contain tokens, deleted_tokens, expected_external_keys, or expected_keys.', 'hubbee' ),
                [ 'status' => 400 ]
            );
        }

        // Validate tokens array if present
        if ( $has_tokens ) {
            foreach ( $payload['tokens'] as $index => $token ) {
                if ( ! isset( $token['key'] ) || empty( $token['key'] ) ) {
                    return new WP_Error(
                        'bz_token_missing_key',
                        sprintf(
                            /* translators: %d: token index */
                            __( 'Token at index %d is missing required "key" field.', 'hubbee' ),
                            $index
                        ),
                        [ 'status' => 400 ]
                    );
                }

                if ( ! isset( $token['version'] ) || absint( $token['version'] ) < 1 ) {
                    return new WP_Error(
                        'bz_token_invalid_version',
                        sprintf(
                            /* translators: %d: token index */
                            __( 'Token at index %d has invalid version.', 'hubbee' ),
                            $index
                        ),
                        [ 'status' => 400 ]
                    );
                }
            }
        }

        // Validate deleted_tokens array if present
        if ( $has_deleted ) {
            foreach ( $payload['deleted_tokens'] as $index => $deletion ) {
                if ( ! isset( $deletion['key'] ) || empty( $deletion['key'] ) ) {
                    return new WP_Error(
                        'bz_deletion_missing_key',
                        sprintf(
                            /* translators: %d: deletion index */
                            __( 'Deletion at index %d is missing required "key" field.', 'hubbee' ),
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
            'total_tokens' => $this->repository->count(),
            'last_push'    => get_option( 'bz_last_push_received', '' ),
        ];
    }

    /**
     * Record push received
     */
    public function record_push_received(): void {
        update_option( 'bz_last_push_received', current_time( 'mysql' ) );
    }
}
