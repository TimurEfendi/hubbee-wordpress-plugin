<?php
/**
 * Error Reporter
 *
 * Scattered error_log() calls are invisible to the SaaS — a fleet-wide problem
 * (e.g. a JWT-secret rotation causing 401 spirals on hundreds of sites) only
 * surfaces when an operator SSHes into individual sites. This collector batches
 * recorded errors in-memory and pushes a sanitised, sampled batch to the SaaS on
 * shutdown (non-blocking, HMAC-signed via EventPusher), giving central visibility
 * without per-error network chatter.
 *
 * @package Hubbee\Agent
 */

namespace Hubbee\Agent;

class ErrorReporter {

    /**
     * Max errors retained per request (bounds memory on error storms).
     */
    const MAX_ERRORS = 50;

    /**
     * Throttle so at most one batch is pushed per window across requests.
     */
    const THROTTLE_KEY = 'bz_error_report_throttle';
    const THROTTLE_SECONDS = 60;

    /**
     * Keys never forwarded (defence-in-depth against secret leakage).
     */
    const BLOCKED_KEYS = [ 'response_body', 'api_secret', 'authorization', 'token', 'secret', 'password' ];

    /**
     * Singleton instance.
     *
     * @var ErrorReporter|null
     */
    private static ?ErrorReporter $instance = null;

    /**
     * Collected errors for this request.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $errors = [];

    /**
     * Guard so the shutdown flush runs at most once.
     *
     * @var bool
     */
    private bool $flushed = false;

    /**
     * Get singleton instance.
     *
     * @return ErrorReporter
     */
    public static function get_instance(): ErrorReporter {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Record an error for the current request.
     *
     * @param string $source  Component name (e.g. 'CommandPoller').
     * @param string $message Short error message.
     * @param array  $context Additional context (sanitised before forwarding).
     */
    public function record( string $source, string $message, array $context = [] ): void {
        if ( count( $this->errors ) >= self::MAX_ERRORS ) {
            return;
        }

        $this->errors[] = [
            'source'  => $source,
            'message' => mb_substr( $message, 0, 500 ),
            'context' => $this->sanitize( $context ),
            'at'      => time(),
        ];
    }

    /**
     * Push the collected errors to the SaaS (non-blocking), sampled by throttle.
     * Safe to call on the `shutdown` hook.
     */
    public function flush(): void {
        if ( $this->flushed || empty( $this->errors ) ) {
            return;
        }
        $this->flushed = true;

        $batch        = $this->errors;
        $this->errors = [];

        // Sampled: at most one batch per throttle window per site.
        if ( get_transient( self::THROTTLE_KEY ) ) {
            return;
        }
        set_transient( self::THROTTLE_KEY, 1, self::THROTTLE_SECONDS );

        // Fire-and-forget; EventPusher::push_async no-ops when disconnected.
        EventPusher::get_instance()->push_async( 'agent_error_batch', [
            'errors'         => $batch,
            'count'          => count( $batch ),
            'plugin_version' => BZ_VERSION,
            'timestamp'      => current_time( 'c' ),
        ] );
    }

    /**
     * Strip sensitive keys and bound the size of context values.
     *
     * @param array $context Raw context.
     * @return array Sanitised context.
     */
    private function sanitize( array $context ): array {
        $clean = [];
        foreach ( $context as $key => $value ) {
            if ( in_array( strtolower( (string) $key ), self::BLOCKED_KEYS, true ) ) {
                continue;
            }
            if ( is_scalar( $value ) || null === $value ) {
                $clean[ $key ] = is_string( $value ) ? mb_substr( $value, 0, 500 ) : $value;
            }
        }
        return $clean;
    }
}
