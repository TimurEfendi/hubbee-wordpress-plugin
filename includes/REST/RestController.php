<?php
/**
 * REST Controller - Main REST API initialization
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

class RestController {

    /**
     * REST namespace
     */
    const NAMESPACE = 'bz/v1';

    /**
     * Initialize REST API
     */
    public function init(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Register all REST routes
     */
    public function register_routes(): void {
        // Ping endpoint (public, minimal - for connectivity checks)
        $ping = new PingEndpoint();
        $ping->register();

        // Push endpoint (SaaS -> WordPress)
        $push = new PushEndpoint();
        $push->register();

        // Component push endpoint (SaaS -> WordPress)
        $component_push = new ComponentPushEndpoint();
        $component_push->register();

        // Component reconcile endpoint (SaaS -> WordPress) — drops phantom
        // components that no longer match an active SaaS-side assignment.
        $component_reconcile = new ComponentReconcileEndpoint();
        $component_reconcile->register();

        // Background push endpoint (SaaS -> WordPress)
        $background_push = new BackgroundPushEndpoint();
        $background_push->register();

        // Text effect push endpoint (SaaS -> WordPress)
        $text_effect_push = new TextEffectPushEndpoint();
        $text_effect_push->register();

        // Status endpoint (requires auth - for detailed status)
        $status = new StatusEndpoint();
        $status->register();

        // Test connection endpoint
        $test = new TestConnectionEndpoint();
        $test->register();

        // Enroll endpoint (WordPress -> SaaS initiation)
        $enroll = new EnrollEndpoint();
        $enroll->register();

        // Analytics endpoint (Frontend -> WordPress -> SaaS proxy)
        $analytics = new AnalyticsEndpoint();
        $analytics->register();

        // System info endpoint (SaaS -> WordPress)
        $system_info = new SystemInfoEndpoint();
        $system_info->register();

        // Updates endpoint (SaaS -> WordPress)
        $updates = new UpdatesEndpoint();
        $updates->register();

        // Plugins endpoint (SaaS -> WordPress)
        $plugins = new PluginsEndpoint();
        $plugins->register();

        // Themes endpoint (SaaS -> WordPress)
        $themes = new ThemesEndpoint();
        $themes->register();

        // Debug log endpoint (SaaS -> WordPress) — only registered when
        // WP_DEBUG is enabled or an admin is requesting it. Production sites
        // without explicit opt-in get a 404 instead of an exposed endpoint.
        if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || current_user_can( 'manage_options' ) ) {
            $debug_log = new DebugLogEndpoint();
            $debug_log->register();
        }

        // Cron jobs endpoint (SaaS -> WordPress)
        $cron = new CronEndpoint();
        $cron->register();

        // Options endpoint (SaaS -> WordPress)
        $options = new OptionsEndpoint();
        $options->register();

        // Settings endpoint (SaaS -> WordPress)
        $settings = new SettingsEndpoint();
        $settings->register();

        // Content endpoint (SaaS -> WordPress)
        $content = new ContentEndpoint();
        $content->register();

        // Users endpoint (SaaS -> WordPress)
        $users = new UsersEndpoint();
        $users->register();

        // Poll endpoint (for external schedulers - launchd/systemd)
        $poll = new PollEndpoint();
        $poll->register();

        // Command endpoint (SaaS -> WordPress direct push)
        $command = new CommandEndpoint();
        $command->register();

        // Component status endpoint (Elementor preview polling)
        $component_status = new ComponentStatusEndpoint();
        $component_status->register();

        // Asset manifest endpoint (SaaS reconciliation cron — audit P1-8)
        $asset_manifest = new AssetManifestEndpoint();
        $asset_manifest->register();
    }

    /**
     * Get REST namespace
     *
     * @return string
     */
    public static function get_namespace(): string {
        return self::NAMESPACE;
    }

    /**
     * Get full REST URL
     *
     * @param string $endpoint Endpoint path.
     * @return string
     */
    public static function get_rest_url( string $endpoint = '' ): string {
        return rest_url( self::NAMESPACE . '/' . ltrim( $endpoint, '/' ) );
    }
}
