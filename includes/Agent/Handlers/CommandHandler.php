<?php
/**
 * Contract for a single Hubbee command handler.
 *
 * Each handler owns one or more command types (e.g. `cache.clear`,
 * `cache.flush`). Migration of the legacy 3500-LOC CommandExecutor monolith
 * happens incrementally — handlers are pulled out one bundle at a time and
 * registered via HandlerRegistry. Anything not yet migrated falls back to
 * CommandExecutor's `execute_*()` method dispatch.
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

interface CommandHandler {

    /**
     * Command types this handler implements (e.g. ['cache.clear', 'cache.flush']).
     *
     * @return string[]
     */
    public static function command_types(): array;

    /**
     * Execute the command.
     *
     * @param string $type    Command type (handler may serve multiple).
     * @param array  $payload Command payload.
     * @return array|\WP_Error Result data or WP_Error on failure.
     */
    public function execute( string $type, array $payload );
}
