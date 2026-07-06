<?php
/**
 * Health Event Handler
 *
 * Handles event-based health report triggers.
 *
 * @package Hubbee\Health
 */

namespace Hubbee\Health;

class HealthEventHandler {

    /**
     * Health reporter instance
     *
     * @var HealthReporter
     */
    private HealthReporter $reporter;

    /**
     * Constructor
     */
    public function __construct() {
        $this->reporter = new HealthReporter();
    }

    /**
     * Initialize event handlers
     */
    public function init(): void {
        // After successful token push
        add_action( 'hubbee_after_push', [ $this, 'on_push_complete' ], 10, 3 );

        // On errors
        add_action( 'hubbee_error', [ $this, 'on_error' ], 10, 2 );

        // After enrollment
        add_action( 'hubbee_enrolled', [ $this, 'on_enrolled' ], 10, 2 );

        // After disconnection
        add_action( 'hubbee_disconnected', [ $this, 'on_disconnected' ] );

        // On successful connection test
        add_action( 'hubbee_connection_verified', [ $this, 'on_connection_verified' ] );
    }

    /**
     * Handle push complete event
     *
     * Sends a snapshot report after successful token push.
     *
     * @param array  $results    Push results array.
     * @param array  $payload    Original payload.
     * @param string $request_id Request ID for idempotency.
     */
    public function on_push_complete( array $results, array $payload, string $request_id ): void {
        // Count processed and skipped tokens
        $processed = 0;
        $skipped = 0;

        foreach ( $results as $result ) {
            if ( isset( $result['status'] ) ) {
                if ( $result['status'] === 'success' || $result['status'] === 'updated' ) {
                    $processed++;
                } elseif ( $result['status'] === 'skipped' ) {
                    $skipped++;
                }
            }
        }

        // Send snapshot with push context
        $this->reporter->send_report( HealthReporter::LEVEL_SNAPSHOT, [
            'last_push_request_id' => $request_id,
            'tokens_processed'     => $processed,
            'tokens_skipped'       => $skipped,
        ] );

        // Reset failure tracking on successful push
        $this->reporter->reset_failures();
    }

    /**
     * Handle error event
     *
     * Sends a deep report when an error occurs.
     *
     * @param string $code    Error code.
     * @param string $message Error message.
     */
    public function on_error( string $code, string $message ): void {
        // Store the error
        update_option( 'bz_last_error', sprintf( '[%s] %s', $code, $message ) );

        // Send deep report with error context
        $this->reporter->send_report( HealthReporter::LEVEL_DEEP, [
            'error_code'    => $code,
            'error_message' => $message,
            'error_time'    => current_time( 'mysql' ),
        ] );
    }

    /**
     * Handle enrollment event
     *
     * Sends an initial deep report after successful enrollment.
     *
     * @param string $site_id Site ID from SaaS.
     * @param array  $data    Enrollment response data.
     */
    public function on_enrolled( string $site_id, array $data ): void {
        // Reset any previous errors
        $this->reporter->reset_failures();

        // Send initial deep report
        $this->reporter->send_report( HealthReporter::LEVEL_DEEP, [
            'event'     => 'enrollment',
            'site_name' => $data['site_name'] ?? '',
        ] );

        // NOTE: Plugin/theme lists are NOT pushed here anymore.
        // The SaaS frontend now uses snapshot-pull Edge Function to fetch all data
        // directly from WordPress REST APIs. This is faster and more reliable than
        // the async push approach which had PHP fire-and-forget limitations.
    }

    /**
     * Handle disconnection event
     *
     * Note: After disconnect, we can't send reports anymore.
     * This is mainly for cleanup.
     */
    public function on_disconnected(): void {
        // Clear all health-related options
        delete_option( 'bz_last_health_report' );
        delete_option( 'bz_last_health_level' );
        delete_option( 'bz_last_error' );
        delete_option( 'bz_health_report_failures' );

        // Unschedule all health checks
        HealthScheduler::unschedule_all();
    }

    /**
     * Handle successful connection verification
     *
     * Sends a snapshot report when connection is verified.
     */
    public function on_connection_verified(): void {
        // Reset failure tracking
        $this->reporter->reset_failures();

        // Send snapshot to confirm connection
        $this->reporter->send_report( HealthReporter::LEVEL_SNAPSHOT, [
            'event' => 'connection_verified',
        ] );
    }
}
