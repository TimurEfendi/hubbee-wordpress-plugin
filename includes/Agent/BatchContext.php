<?php
/**
 * BatchContext — global flag indicating that the current PHP request is
 * processing a batched command (CommandExecutor::execute_batch).
 *
 * Handlers that emit per-mutation events (e.g. PostCommandHandler firing
 * `post_deleted` after each delete) consult this flag and suppress their
 * push() so the batch can emit a single consolidated event at the end.
 *
 * Replaces the legacy `$this->in_batch` instance flag on CommandExecutor —
 * with handlers extracted into separate classes, a static cross-cutting
 * service is the simplest correct way to share that "are we batching?"
 * signal without coupling every handler to the executor.
 *
 * @package Hubbee\Agent
 */

namespace Hubbee\Agent;

class BatchContext {

    private static bool $active = false;

    /** Mark batch processing as started. Idempotent. */
    public static function enter(): void {
        self::$active = true;
    }

    /** Mark batch processing as finished. Idempotent. */
    public static function leave(): void {
        self::$active = false;
    }

    /** True iff the current request is inside an `execute_batch` call. */
    public static function is_active(): bool {
        return self::$active;
    }
}
