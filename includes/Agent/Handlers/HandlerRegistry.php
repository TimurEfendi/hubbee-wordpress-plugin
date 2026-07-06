<?php
/**
 * Registry of CommandHandler implementations keyed by command type.
 *
 * CommandExecutor::execute() consults this registry first; any type not
 * found here falls through to the legacy `execute_*` method dispatch on
 * CommandExecutor itself. Once every command has been migrated to a
 * handler, the legacy fallback can be deleted along with CommandExecutor's
 * giant `execute_*` body.
 *
 * Handlers are instantiated lazily on first use to keep the boot path cheap.
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

class HandlerRegistry {

    private static ?HandlerRegistry $instance = null;

    /** @var array<string, class-string<CommandHandler>> */
    private array $type_to_class = [];

    /** @var array<class-string<CommandHandler>, CommandHandler> */
    private array $instances = [];

    public static function get_instance(): HandlerRegistry {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->register_defaults();
        }
        return self::$instance;
    }

    /**
     * Register a handler class against every command type it claims.
     *
     * @param class-string<CommandHandler> $handler_class
     */
    public function register( string $handler_class ): void {
        if ( ! is_subclass_of( $handler_class, CommandHandler::class ) ) {
            return;
        }
        foreach ( $handler_class::command_types() as $type ) {
            $this->type_to_class[ $type ] = $handler_class;
        }
    }

    /**
     * Resolve the handler for a command type, instantiating it lazily.
     */
    public function resolve( string $type ): ?CommandHandler {
        if ( ! isset( $this->type_to_class[ $type ] ) ) {
            return null;
        }
        $class = $this->type_to_class[ $type ];
        if ( ! isset( $this->instances[ $class ] ) ) {
            $this->instances[ $class ] = new $class();
        }
        return $this->instances[ $class ];
    }

    /**
     * Whether a handler is registered for this command type.
     */
    public function has( string $type ): bool {
        return isset( $this->type_to_class[ $type ] );
    }

    /**
     * Default handler set — extend as more commands migrate out of
     * CommandExecutor's monolith.
     */
    private function register_defaults(): void {
        $this->register( CacheCommandHandler::class );
        $this->register( HealthCommandHandler::class );
        $this->register( PluginCommandHandler::class );
        $this->register( ThemeCommandHandler::class );
        $this->register( UpdateCommandHandler::class );
        $this->register( PostCommandHandler::class );
        $this->register( PageCommandHandler::class );
        $this->register( MediaCommandHandler::class );
        $this->register( CommentCommandHandler::class );
        $this->register( TaxonomyCommandHandler::class );
        $this->register( FetchCommandHandler::class );
        $this->register( SnapshotCommandHandler::class );
        $this->register( LifecycleCommandHandler::class );
    }
}
