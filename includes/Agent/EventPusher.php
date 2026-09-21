<?php
/**
 * Event Pusher
 *
 * Pushes events from WordPress to SaaS via the event-receiver Edge Function.
 * This is the PRIMARY communication method in DEV mode.
 *
 * Event Types:
 * - health_report: Periodic health data
 * - plugin_activated / plugin_deactivated: Plugin state changes
 * - theme_switched: Theme change
 * - update_available: New updates detected
 * - token_synced: Token values confirmed synced
 *
 * @package Hubbee\Agent
 */

namespace Hubbee\Agent;

use Hubbee\SaaS\ConnectionManager;
use Hubbee\SaaS\ModeConfig;
use Hubbee\SaaS\SignatureValidator;
use Hubbee\Security\JwtGenerator;

class EventPusher {

    /**
     * Singleton instance
     *
     * @var EventPusher|null
     */
    private static ?EventPusher $instance = null;

    /**
     * Connection manager
     *
     * @var ConnectionManager
     */
    private ConnectionManager $connection;

    /**
     * Mode config
     *
     * @var ModeConfig
     */
    private ModeConfig $config;

    /**
     * Signature validator for HMAC auth
     *
     * @var SignatureValidator
     */
    private SignatureValidator $validator;

    /**
     * JWT generator for API auth
     *
     * @var JwtGenerator
     */
    private JwtGenerator $jwt;

    /**
     * Pending content events for batched flush
     *
     * @var array
     */
    private array $pending_content_events = [];

    /**
     * Whether the shutdown flush has been scheduled
     *
     * @var bool
     */
    private bool $content_flush_scheduled = false;

    /**
     * Get singleton instance
     *
     * @return EventPusher
     */
    public static function get_instance(): EventPusher {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton
     */
    private function __construct() {
        $this->connection = new ConnectionManager();
        $this->config     = ModeConfig::get_instance();
        $this->validator  = new SignatureValidator();
        $this->jwt        = JwtGenerator::get_instance();
    }

    /**
     * Initialize event hooks
     *
     * Call this in Plugin::run() or during init.
     */
    public function init(): void {
        // Only init if connected and event push is enabled
        if ( ! $this->connection->is_connected() || ! $this->config->is_event_push_enabled() ) {
            return;
        }

        // Plugin activation/deactivation/deletion
        add_action( 'activated_plugin', [ $this, 'on_plugin_activated' ], 10, 2 );
        add_action( 'deactivated_plugin', [ $this, 'on_plugin_deactivated' ], 10, 2 );
        add_action( 'deleted_plugin', [ $this, 'on_plugin_deleted' ], 10, 2 );

        // Theme switch/deletion
        add_action( 'switch_theme', [ $this, 'on_theme_switched' ], 10, 3 );
        add_action( 'deleted_theme', [ $this, 'on_theme_deleted' ], 10, 2 );

        // Update checks
        add_action( 'set_site_transient_update_plugins', [ $this, 'on_plugin_updates_available' ] );
        add_action( 'set_site_transient_update_themes', [ $this, 'on_theme_updates_available' ] );

        // WordPress core update
        add_action( 'set_site_transient_update_core', [ $this, 'on_core_update_available' ] );

        // Upgrader complete (after updates applied)
        add_action( 'upgrader_process_complete', [ $this, 'on_upgrader_complete' ], 10, 2 );

        // Content hooks (posts, pages, media, comments, taxonomies)
        add_action( 'save_post',                 [ $this, 'on_post_saved' ],              10, 3 );
        add_action( 'wp_trash_post',             [ $this, 'on_post_trashed' ],            10, 1 );
        add_action( 'before_delete_post',        [ $this, 'on_post_deleted' ],            10, 2 );
        add_action( 'wp_insert_comment',         [ $this, 'on_comment_created' ],         10, 2 );
        add_action( 'edit_comment',              [ $this, 'on_comment_updated' ],         10, 2 );
        add_action( 'delete_comment',            [ $this, 'on_comment_deleted' ],         10, 2 );
        add_action( 'transition_comment_status', [ $this, 'on_comment_status_changed' ],  10, 3 );
        add_action( 'add_attachment',            [ $this, 'on_media_uploaded' ],           10, 1 );
        add_action( 'edit_attachment',           [ $this, 'on_media_updated' ],            10, 1 );
        add_action( 'delete_attachment',         [ $this, 'on_media_deleted' ],            10, 1 );
        add_action( 'created_term',              [ $this, 'on_term_changed' ],             10, 3 );
        add_action( 'edited_term',               [ $this, 'on_term_changed' ],             10, 3 );
        add_action( 'delete_term',               [ $this, 'on_term_deleted' ],             10, 5 );
    }

    /**
     * Push an event to SaaS
     *
     * Uses HMAC-SHA256 signature authentication (same as health-report).
     *
     * @param string $event_type Event type identifier.
     * @param array  $data       Event data payload.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function push( string $event_type, array $data = [] ) {
        // Check if connected
        if ( ! $this->connection->is_connected() ) {
            return new \WP_Error( 'bz_not_connected', __( 'Not connected to SaaS.', 'hubbee' ) );
        }

        // Check if event push is enabled
        if ( ! $this->config->is_event_push_enabled() ) {
            return new \WP_Error( 'bz_event_push_disabled', __( 'Event Push is disabled.', 'hubbee' ) );
        }

        // Get credentials for HMAC signature
        $site_id    = $this->connection->get_saas_site_id();
        $api_secret = $this->connection->get_api_secret();

        if ( empty( $site_id ) || empty( $api_secret ) ) {
            return new \WP_Error( 'bz_credentials_missing', __( 'Credentials are missing.', 'hubbee' ) );
        }

        // Build request
        $endpoint   = $this->config->get_m2m_api_url() . '/event-receiver';
        $request_id = wp_generate_uuid4();
        $timestamp  = time();

        $payload = [
            'event_type' => $event_type,
            'data'       => $data,
            'request_id' => $request_id,
        ];

        $body = wp_json_encode( $payload );
        $this->warn_if_payload_large( $event_type, $body );

        // Create HMAC-SHA256 signature (same format as health-report)
        $signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $api_secret );

        $response = wp_remote_post(
            $endpoint,
            [
                'headers' => [
                    'Content-Type'         => 'application/json',
                    'X-Hubbee-Signature'   => $signature,
                    'X-Hubbee-Timestamp'   => (string) $timestamp,
                    'X-Hubbee-Site-Id'     => $site_id,
                    'X-Hubbee-Request-Id'  => $request_id,
                ],
                'body'    => $body,
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'Event push failed', [
                'event_type' => $event_type,
                'error'      => $response->get_error_message(),
            ] );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body_response = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 === $status_code && ! empty( $body_response['received'] ) ) {
            $this->log_success( 'Event pushed', [
                'event_type' => $event_type,
                'event_id'   => $body_response['event_id'] ?? null,
            ] );
            return true;
        }

        // Handle errors
        $error_message = $body_response['error'] ?? sprintf(
            /* translators: %d: HTTP status code */
            __( 'HTTP %d', 'hubbee' ),
            $status_code
        );

        $this->log_error( 'Event push rejected', [
            'event_type'  => $event_type,
            'status_code' => $status_code,
            'error'       => $error_message,
        ] );

        return new \WP_Error( 'bz_push_failed', $error_message );
    }

    /**
     * Push an event to SaaS asynchronously (non-blocking)
     *
     * Fire-and-forget: sends the request but doesn't wait for response.
     * Use this when you don't need to handle the result.
     *
     * @param string $event_type Event type identifier.
     * @param array  $data       Event data payload.
     */
    public function push_async( string $event_type, array $data = [] ): void {
        // Check if connected
        if ( ! $this->connection->is_connected() ) {
            return;
        }

        // Check if event push is enabled
        if ( ! $this->config->is_event_push_enabled() ) {
            return;
        }

        // Get credentials for HMAC signature
        $site_id    = $this->connection->get_saas_site_id();
        $api_secret = $this->connection->get_api_secret();

        if ( empty( $site_id ) || empty( $api_secret ) ) {
            return;
        }

        // Build request
        $endpoint   = $this->config->get_m2m_api_url() . '/event-receiver';
        $request_id = wp_generate_uuid4();
        $timestamp  = time();

        $payload = [
            'event_type' => $event_type,
            'data'       => $data,
            'request_id' => $request_id,
        ];

        $body = wp_json_encode( $payload );
        $this->warn_if_payload_large( $event_type, $body );

        // Create HMAC-SHA256 signature
        $signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $api_secret );

        // Non-blocking request. A 0.01s timeout previously truncated larger
        // payloads (e.g. plugin/theme lists) before they reached the socket;
        // a small, filterable timeout lets the request flush while staying
        // effectively fire-and-forget.
        $timeout = (float) apply_filters( 'hubbee_async_push_timeout', 0.5 );

        $response = wp_remote_post(
            $endpoint,
            [
                'headers'  => [
                    'Content-Type'         => 'application/json',
                    'X-Hubbee-Signature'   => $signature,
                    'X-Hubbee-Timestamp'   => (string) $timestamp,
                    'X-Hubbee-Site-Id'     => $site_id,
                    'X-Hubbee-Request-Id'  => $request_id,
                ],
                'body'     => $body,
                'timeout'  => $timeout,
                'blocking' => false, // Don't wait for response.
            ]
        );

        // A WP_Error here means the request could not be dispatched at all
        // (bad URL, cURL init failure) — log it unconditionally instead of
        // silently reporting success.
        if ( is_wp_error( $response ) ) {
            $this->log_error( 'Async event dispatch failed', [
                'event_type' => $event_type,
                'error'      => $response->get_error_message(),
            ] );
            return;
        }

        $this->log_success( 'Event dispatched async', [
            'event_type' => $event_type,
        ] );
    }

    /**
     * Push health report event
     *
     * @param array $health_data Health data from HealthReporter.
     * @return bool|\WP_Error
     */
    public function push_health_report( array $health_data ) {
        return $this->push( 'health_report', $health_data );
    }

    /**
     * Push full plugins list to SaaS via event-receiver
     *
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function push_plugins_list() {
        $plugins_list = $this->build_plugins_list();

        $result = $this->push( 'plugin_list', [
            'plugins'   => $plugins_list,
            'timestamp' => current_time( 'c' ),
        ] );

        if ( true === $result ) {
            $this->log_success( 'Plugins list pushed', [
                'plugins_count' => count( $plugins_list ),
            ] );
        }

        return $result;
    }

    /**
     * Push full plugins list asynchronously (non-blocking)
     */
    public function push_plugins_list_async(): void {
        $plugins_list = $this->build_plugins_list();

        $this->push_async( 'plugin_list', [
            'plugins'   => $plugins_list,
            'timestamp' => current_time( 'c' ),
        ] );
    }

    /**
     * Push full themes list to SaaS via event-receiver
     *
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function push_themes_list() {
        $themes_list = $this->build_themes_list();

        $result = $this->push( 'theme_list', [
            'themes'    => $themes_list,
            'timestamp' => current_time( 'c' ),
        ] );

        if ( true === $result ) {
            $this->log_success( 'Themes list pushed', [
                'themes_count' => count( $themes_list ),
            ] );
        }

        return $result;
    }

    /**
     * Push full themes list asynchronously (non-blocking)
     */
    public function push_themes_list_async(): void {
        $themes_list = $this->build_themes_list();

        $this->push_async( 'theme_list', [
            'themes'    => $themes_list,
            'timestamp' => current_time( 'c' ),
        ] );
    }

    /**
     * Build the complete plugins inventory array
     *
     * @return array List of plugin data arrays.
     */
    private function build_plugins_list(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $update_plugins = get_site_transient( 'update_plugins' );
        $auto_update_plugins = get_option( 'auto_update_plugins', [] );

        $plugins_list = [];
        foreach ( $all_plugins as $file => $data ) {
            $slug = dirname( $file );
            if ( $slug === '.' ) {
                $slug = basename( $file, '.php' );
            }

            $has_update = isset( $update_plugins->response[ $file ] );
            $new_version = $has_update ? $update_plugins->response[ $file ]->new_version : null;

            $plugins_list[] = [
                'slug'             => $slug,
                'file'             => $file,
                'name'             => $data['Name'] ?? $slug,
                'version'          => $data['Version'] ?? 'unknown',
                'author'           => $data['Author'] ?? '',
                'description'      => $data['Description'] ?? '',
                'plugin_uri'       => $data['PluginURI'] ?? '',
                'is_active'        => is_plugin_active( $file ),
                'auto_update'      => in_array( $file, $auto_update_plugins, true ),
                'update_available' => $has_update,
                'new_version'      => $new_version,
                'requires_php'     => $data['RequiresPHP'] ?? null,
                'requires_wp'      => $data['RequiresWP'] ?? null,
            ];
        }

        return $plugins_list;
    }

    /**
     * Build the complete themes inventory array
     *
     * @return array List of theme data arrays.
     */
    private function build_themes_list(): array {
        $all_themes = wp_get_themes();
        $update_themes = get_site_transient( 'update_themes' );
        $auto_update_themes = get_option( 'auto_update_themes', [] );
        if ( ! is_array( $auto_update_themes ) ) {
            $auto_update_themes = [];
        }
        $active_theme = get_stylesheet();
        $active_theme_obj = wp_get_theme();

        $themes_list = [];
        foreach ( $all_themes as $slug => $theme ) {
            $has_update = isset( $update_themes->response[ $slug ] );
            $new_version = $has_update ? $update_themes->response[ $slug ]['new_version'] : null;

            $is_active = $slug === $active_theme;
            $is_parent = ! $is_active && $active_theme_obj->parent() && $slug === $active_theme_obj->get_template();

            $themes_list[] = [
                'slug'             => $slug,
                'name'             => $theme->get( 'Name' ),
                'version'          => $theme->get( 'Version' ),
                'author'           => $theme->get( 'Author' ),
                'description'      => $theme->get( 'Description' ),
                'screenshot_url'   => $theme->get_screenshot(),
                'is_active'        => $is_active,
                'is_parent'        => $is_parent,
                'auto_update'      => in_array( $slug, $auto_update_themes, true ),
                'update_available' => $has_update,
                'new_version'      => $new_version,
                'requires_php'     => $theme->get( 'RequiresPHP' ) ?: null,
                'requires_wp'      => $theme->get( 'RequiresWP' ) ?: null,
                'parent_theme'     => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
            ];
        }

        return $themes_list;
    }

    /**
     * Handler: Plugin activated
     *
     * @param string $plugin       Path to the plugin file relative to the plugins directory.
     * @param bool   $network_wide Whether to enable the plugin for all sites in the network.
     */
    public function on_plugin_activated( string $plugin, bool $network_wide = false ): void {
        if ( defined( 'HUBBEE_COMMAND_EXECUTION' ) ) {
            return;
        }

        $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );

        $this->push( 'plugin_activated', [
            'plugin'       => $plugin,
            'name'         => $plugin_data['Name'] ?? $plugin,
            'version'      => $plugin_data['Version'] ?? 'unknown',
            'network_wide' => $network_wide,
            'timestamp'    => current_time( 'mysql' ),
        ] );
    }

    /**
     * Handler: Plugin deactivated
     *
     * @param string $plugin       Path to the plugin file relative to the plugins directory.
     * @param bool   $network_wide Whether the plugin was deactivated for all sites in the network.
     */
    public function on_plugin_deactivated( string $plugin, bool $network_wide = false ): void {
        if ( defined( 'HUBBEE_COMMAND_EXECUTION' ) ) {
            return;
        }

        $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );

        $this->push( 'plugin_deactivated', [
            'plugin'       => $plugin,
            'name'         => $plugin_data['Name'] ?? $plugin,
            'version'      => $plugin_data['Version'] ?? 'unknown',
            'network_wide' => $network_wide,
            'timestamp'    => current_time( 'mysql' ),
        ] );
    }

    /**
     * Handler: Plugin deleted
     *
     * @param string $plugin  Path to the plugin file relative to the plugins directory.
     * @param bool   $deleted Whether the plugin deletion was successful.
     */
    public function on_plugin_deleted( string $plugin, bool $deleted ): void {
        if ( ! $deleted ) {
            return;
        }

        // Determine plugin slug from file path
        $slug = dirname( $plugin );
        if ( $slug === '.' ) {
            $slug = basename( $plugin, '.php' );
        }

        $this->push( 'plugin_deleted', [
            'plugin_file' => $plugin,
            'plugin_slug' => $slug,
            'timestamp'   => current_time( 'mysql' ),
        ] );
    }

    /**
     * Handler: Theme switched
     *
     * @param string    $new_name  Name of the new theme.
     * @param \WP_Theme $new_theme WP_Theme instance of the new theme.
     * @param \WP_Theme $old_theme WP_Theme instance of the old theme.
     */
    public function on_theme_switched( string $new_name, \WP_Theme $new_theme, \WP_Theme $old_theme ): void {
        if ( defined( 'HUBBEE_COMMAND_EXECUTION' ) ) {
            return;
        }

        $this->push( 'theme_switched', [
            'new_theme'   => [
                'name'      => $new_name,
                'version'   => $new_theme->get( 'Version' ),
                'template'  => $new_theme->get_template(),
                'stylesheet' => $new_theme->get_stylesheet(),
            ],
            'old_theme'   => [
                'name'      => $old_theme->get( 'Name' ),
                'version'   => $old_theme->get( 'Version' ),
                'template'  => $old_theme->get_template(),
            ],
            'timestamp'   => current_time( 'mysql' ),
        ] );
    }

    /**
     * Handler: Theme deleted
     *
     * @param string $stylesheet Stylesheet of the theme to delete.
     * @param bool   $deleted    Whether the theme deletion was successful.
     */
    public function on_theme_deleted( string $stylesheet, bool $deleted ): void {
        if ( ! $deleted ) {
            return;
        }

        $this->push( 'theme_deleted', [
            'theme_slug' => $stylesheet,
            'timestamp'  => current_time( 'mysql' ),
        ] );
    }

    /**
     * Handler: Plugin updates available
     *
     * @param object $transient Update transient data.
     */
    public function on_plugin_updates_available( $transient ): void {
        if ( empty( $transient->response ) ) {
            return;
        }

        // Debounce: only push once per hour max
        if ( get_transient( 'bz_plugin_updates_pushed' ) ) {
            return;
        }

        $updates = [];
        foreach ( $transient->response as $plugin => $data ) {
            $updates[] = [
                'plugin'      => $plugin,
                'new_version' => $data->new_version ?? 'unknown',
                'slug'        => $data->slug ?? '',
            ];
        }

        if ( ! empty( $updates ) ) {
            $this->push( 'plugin_updates_available', [
                'updates'   => $updates,
                'count'     => count( $updates ),
                'timestamp' => current_time( 'mysql' ),
            ] );

            set_transient( 'bz_plugin_updates_pushed', true, HOUR_IN_SECONDS );
        }
    }

    /**
     * Handler: Theme updates available
     *
     * @param object $transient Update transient data.
     */
    public function on_theme_updates_available( $transient ): void {
        if ( empty( $transient->response ) ) {
            return;
        }

        // Debounce: only push once per hour max
        if ( get_transient( 'bz_theme_updates_pushed' ) ) {
            return;
        }

        $updates = [];
        foreach ( $transient->response as $theme => $data ) {
            $updates[] = [
                'theme'       => $theme,
                'new_version' => $data['new_version'] ?? 'unknown',
            ];
        }

        if ( ! empty( $updates ) ) {
            $this->push( 'theme_updates_available', [
                'updates'   => $updates,
                'count'     => count( $updates ),
                'timestamp' => current_time( 'mysql' ),
            ] );

            set_transient( 'bz_theme_updates_pushed', true, HOUR_IN_SECONDS );
        }
    }

    /**
     * Handler: WordPress core update available
     *
     * @param object $transient Update transient data.
     */
    public function on_core_update_available( $transient ): void {
        if ( empty( $transient->updates ) ) {
            return;
        }

        // Debounce: only push once per day max
        if ( get_transient( 'bz_core_update_pushed' ) ) {
            return;
        }

        // Find upgrade available
        foreach ( $transient->updates as $update ) {
            if ( 'upgrade' === $update->response ) {
                $this->push( 'core_update_available', [
                    'current_version' => get_bloginfo( 'version' ),
                    'new_version'     => $update->version,
                    'locale'          => $update->locale,
                    'timestamp'       => current_time( 'mysql' ),
                ] );

                set_transient( 'bz_core_update_pushed', true, DAY_IN_SECONDS );
                break;
            }
        }
    }

    /**
     * Handler: Upgrader process complete
     *
     * Handles both install and update actions for plugins, themes, and core.
     *
     * @param \WP_Upgrader $upgrader WP_Upgrader instance.
     * @param array        $options  Array of update data.
     */
    public function on_upgrader_complete( \WP_Upgrader $upgrader, array $options ): void {
        $type   = $options['type'] ?? 'unknown';
        $action = $options['action'] ?? 'unknown';

        // Handle install action (NEW!)
        if ( 'install' === $action ) {
            if ( 'plugin' === $type ) {
                $this->handle_plugin_installed( $upgrader );
                return;
            }
            if ( 'theme' === $type ) {
                $this->handle_theme_installed( $upgrader );
                return;
            }
            return;
        }

        // Handle update action (existing behavior)
        if ( 'update' !== $action ) {
            return;
        }

        $items = [];

        if ( 'plugin' === $type && ! empty( $options['plugins'] ) ) {
            $items = $options['plugins'];
        } elseif ( 'theme' === $type && ! empty( $options['themes'] ) ) {
            $items = $options['themes'];
        } elseif ( 'core' === $type ) {
            $items = [ 'wordpress-core' ];
        }

        if ( ! empty( $items ) ) {
            $this->push( 'update_completed', [
                'type'      => $type,
                'items'     => $items,
                'timestamp' => current_time( 'mysql' ),
            ] );

            // Push fresh list for full reconciliation (catches version changes, removes stale flags)
            // Also clear the 1-hour debounce transient so the next WP update check can push immediately
            if ( 'plugin' === $type ) {
                $this->push_plugins_list_async();
                delete_transient( 'bz_plugin_updates_pushed' );
            }
            if ( 'theme' === $type ) {
                $this->push_themes_list_async();
                delete_transient( 'bz_theme_updates_pushed' );
            }
        }
    }

    /**
     * Handle plugin installation - push plugin_installed event
     *
     * @param \WP_Upgrader $upgrader WP_Upgrader instance.
     */
    private function handle_plugin_installed( \WP_Upgrader $upgrader ): void {
        $result = $upgrader->result;
        if ( empty( $result['destination_name'] ) ) {
            return;
        }

        // The destination_name is the plugin folder/slug
        $slug = $result['destination_name'];

        // Load plugin.php if not already loaded
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Invalidate object cache to force fresh filesystem scan
        wp_cache_delete( 'plugins', 'plugins' );
        $all_plugins = get_plugins();

        // Find the installed plugin by matching the slug
        foreach ( $all_plugins as $file => $data ) {
            // Determine plugin slug from file path
            $installed_slug = strpos( $file, '/' ) !== false
                ? dirname( $file )
                : str_replace( '.php', '', $file );

            if ( $installed_slug === $slug ) {
                $this->push( 'plugin_installed', [
                    'plugin_file'  => $file,
                    'plugin_slug'  => $slug,
                    'plugin_name'  => $data['Name'] ?? $slug,
                    'version'      => $data['Version'] ?? '',
                    'author'       => $data['Author'] ?? '',
                    'description'  => $data['Description'] ?? '',
                    'plugin_uri'   => $data['PluginURI'] ?? '',
                    'is_active'    => is_plugin_active( $file ),
                    'timestamp'    => current_time( 'mysql' ),
                ] );
                return;
            }
        }

        // Fallback: plugin not found by slug match, log warning
        $this->log_error( 'Plugin installed but not found in get_plugins()', [
            'destination_name' => $slug,
        ] );
    }

    /**
     * Handle theme installation - push theme_installed event
     *
     * @param \WP_Upgrader $upgrader WP_Upgrader instance.
     */
    private function handle_theme_installed( \WP_Upgrader $upgrader ): void {
        $result = $upgrader->result;
        if ( empty( $result['destination_name'] ) ) {
            return;
        }

        // The destination_name is the theme folder/slug
        $slug = $result['destination_name'];
        $theme = wp_get_theme( $slug );

        if ( ! $theme->exists() ) {
            $this->log_error( 'Theme installed but not found', [
                'destination_name' => $slug,
            ] );
            return;
        }

        $active_theme = get_stylesheet();

        $this->push( 'theme_installed', [
            'theme_slug'     => $slug,
            'theme_name'     => $theme->get( 'Name' ),
            'version'        => $theme->get( 'Version' ),
            'author'         => $theme->get( 'Author' ),
            'description'    => $theme->get( 'Description' ),
            'screenshot_url' => $theme->get_screenshot(),
            'is_active'      => $slug === $active_theme,
            'parent_theme'   => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
            'timestamp'      => current_time( 'mysql' ),
        ] );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CONTENT CHANGE HOOKS — Debounced batch push
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Queue a content event for batched push at shutdown.
     *
     * Events are deduplicated per content type + ID (last event wins).
     * Skipped when triggered by SaaS command execution (infinite loop protection).
     *
     * @param string $type   Content type (post, page, media, comment, category, tag).
     * @param string $action Action (created, updated, deleted, trashed, status_changed).
     * @param array  $data   Event data.
     */
    private function queue_content_event( string $type, string $action, array $data ): void {
        // Skip if triggered by SaaS command execution (prevents infinite loop)
        if ( defined( 'HUBBEE_COMMAND_EXECUTION' ) ) {
            return;
        }

        $this->pending_content_events[] = [
            'type'   => $type,
            'action' => $action,
            'data'   => $data,
        ];

        if ( ! $this->content_flush_scheduled ) {
            $this->content_flush_scheduled = true;
            add_action( 'shutdown', [ $this, 'flush_content_events' ] );
        }
    }

    /**
     * Flush pending content events as a single batch.
     *
     * Called on WordPress shutdown hook (end of request).
     * Deduplicates: last event per type:id wins.
     */
    public function flush_content_events(): void {
        if ( empty( $this->pending_content_events ) ) {
            return;
        }

        // Deduplicate: last event per type:id wins
        $deduped = [];
        foreach ( $this->pending_content_events as $event ) {
            $key = $event['type'] . ':' . (
                $event['data']['post_id']
                ?? $event['data']['comment_id']
                ?? $event['data']['media_id']
                ?? $event['data']['term_id']
                ?? 'unknown'
            );
            $deduped[ $key ] = $event;
        }

        // Send as single batch event
        $this->push_async( 'content_changed_batch', [
            'changes'   => array_values( $deduped ),
            'timestamp' => current_time( 'mysql' ),
        ] );

        $this->pending_content_events = [];
    }

    /**
     * Handler: Post saved (create or update)
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     * @param bool     $update  Whether this is an existing post being updated.
     */
    public function on_post_saved( int $post_id, \WP_Post $post, bool $update ): void {
        // Skip revisions and auto-saves
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        // Only handle posts and pages
        $type = $post->post_type;
        if ( ! in_array( $type, [ 'post', 'page' ], true ) ) {
            return;
        }

        $action = $update ? 'updated' : 'created';

        $this->queue_content_event( $type, $action, [
            'post_id'  => $post_id,
            $type      => $this->format_content_item( $post_id, $type ),
        ] );
    }

    /**
     * Handler: Post trashed
     *
     * @param int $post_id Post ID.
     */
    public function on_post_trashed( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
            return;
        }

        $this->queue_content_event( $post->post_type, 'trashed', [
            'post_id' => $post_id,
        ] );
    }

    /**
     * Handler: Post permanently deleted
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     */
    public function on_post_deleted( int $post_id, \WP_Post $post ): void {
        if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
            return;
        }

        $this->queue_content_event( $post->post_type, 'deleted', [
            'post_id' => $post_id,
        ] );
    }

    /**
     * Handler: Comment created
     *
     * @param int         $comment_id Comment ID.
     * @param \WP_Comment $comment    Comment object.
     */
    public function on_comment_created( int $comment_id, \WP_Comment $comment ): void {
        $this->queue_content_event( 'comment', 'created', [
            'comment_id' => $comment_id,
            'comment'    => $this->format_comment_item( $comment ),
        ] );
    }

    /**
     * Handler: Comment updated
     *
     * @param int   $comment_id Comment ID.
     * @param array $data       Comment data.
     */
    public function on_comment_updated( int $comment_id, array $data ): void {
        $comment = get_comment( $comment_id );
        if ( ! $comment ) {
            return;
        }

        $this->queue_content_event( 'comment', 'updated', [
            'comment_id' => $comment_id,
            'comment'    => $this->format_comment_item( $comment ),
        ] );
    }

    /**
     * Handler: Comment deleted
     *
     * @param int   $comment_id Comment ID.
     * @param array $comment    Comment data.
     */
    public function on_comment_deleted( int $comment_id, $comment ): void {
        $this->queue_content_event( 'comment', 'deleted', [
            'comment_id' => $comment_id,
        ] );
    }

    /**
     * Handler: Comment status changed
     *
     * @param string      $new_status New comment status.
     * @param string      $old_status Old comment status.
     * @param \WP_Comment $comment    Comment object.
     */
    public function on_comment_status_changed( string $new_status, string $old_status, \WP_Comment $comment ): void {
        $this->queue_content_event( 'comment', 'moderated', [
            'comment_id' => (int) $comment->comment_ID,
            'new_status' => $new_status,
            'old_status' => $old_status,
            'comment'    => $this->format_comment_item( $comment ),
        ] );
    }

    /**
     * Handler: Media uploaded
     *
     * @param int $attachment_id Attachment ID.
     */
    public function on_media_uploaded( int $attachment_id ): void {
        $this->queue_content_event( 'media', 'created', [
            'media_id' => $attachment_id,
            'media'    => $this->format_media_item( $attachment_id ),
        ] );
    }

    /**
     * Handler: Media updated
     *
     * @param int $attachment_id Attachment ID.
     */
    public function on_media_updated( int $attachment_id ): void {
        $this->queue_content_event( 'media', 'updated', [
            'media_id' => $attachment_id,
            'media'    => $this->format_media_item( $attachment_id ),
        ] );
    }

    /**
     * Handler: Media deleted
     *
     * @param int $attachment_id Attachment ID.
     */
    public function on_media_deleted( int $attachment_id ): void {
        $this->queue_content_event( 'media', 'deleted', [
            'media_id' => $attachment_id,
        ] );
    }

    /**
     * Handler: Term created or edited
     *
     * @param int    $term_id  Term ID.
     * @param int    $tt_id    Term taxonomy ID.
     * @param string $taxonomy Taxonomy slug.
     */
    public function on_term_changed( int $term_id, int $tt_id, string $taxonomy ): void {
        $type = $this->taxonomy_to_content_type( $taxonomy );
        if ( ! $type ) {
            return;
        }

        $term = get_term( $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
            return;
        }

        $this->queue_content_event( $type, 'updated', [
            'term_id' => $term_id,
            $type     => [
                'id'    => $term->term_id,
                'name'  => $term->name,
                'slug'  => $term->slug,
                'count' => $term->count,
            ],
        ] );
    }

    /**
     * Handler: Term deleted
     *
     * @param int    $term_id      Term ID.
     * @param int    $tt_id        Term taxonomy ID.
     * @param string $taxonomy     Taxonomy slug.
     * @param mixed  $deleted_term Deleted term object.
     * @param array  $object_ids   Object IDs.
     */
    public function on_term_deleted( int $term_id, int $tt_id, string $taxonomy, $deleted_term, array $object_ids ): void {
        $type = $this->taxonomy_to_content_type( $taxonomy );
        if ( ! $type ) {
            return;
        }

        $this->queue_content_event( $type, 'deleted', [
            'term_id' => $term_id,
        ] );
    }

    /**
     * Map WordPress taxonomy to content type.
     *
     * @param string $taxonomy Taxonomy slug.
     * @return string|null Content type or null if not tracked.
     */
    private function taxonomy_to_content_type( string $taxonomy ): ?string {
        $map = [
            'category' => 'category',
            'post_tag' => 'tag',
        ];
        return $map[ $taxonomy ] ?? null;
    }

    /**
     * Format a post/page for event payload.
     *
     * @param int    $post_id Post ID.
     * @param string $type    Post type (post or page).
     * @return array Formatted post data.
     */
    private function format_content_item( int $post_id, string $type ): array {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return [];
        }

        $data = [
            'id'       => $post->ID,
            'title'    => $post->post_title,
            'slug'     => $post->post_name,
            'excerpt'  => get_the_excerpt( $post ),
            'status'   => $post->post_status,
            'date'     => $post->post_date,
            'modified' => $post->post_modified,
            'author'   => [
                'id'   => (int) $post->post_author,
                'name' => get_the_author_meta( 'display_name', $post->post_author ),
            ],
            'comment_status' => $post->comment_status,
            'comment_count'  => (int) $post->comment_count,
            'permalink'      => get_permalink( $post ),
        ];

        // Add taxonomy data for posts
        if ( 'post' === $type ) {
            $categories = [];
            $terms = get_the_terms( $post_id, 'category' );
            if ( $terms && ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $categories[] = [
                        'id'   => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    ];
                }
            }
            $data['categories'] = $categories;

            $tags = [];
            $tag_terms = get_the_terms( $post_id, 'post_tag' );
            if ( $tag_terms && ! is_wp_error( $tag_terms ) ) {
                foreach ( $tag_terms as $term ) {
                    $tags[] = [
                        'id'   => $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    ];
                }
            }
            $data['tags'] = $tags;
        }

        // Featured image
        $thumbnail_id = get_post_thumbnail_id( $post_id );
        if ( $thumbnail_id ) {
            $data['featured_image'] = [
                'id'  => $thumbnail_id,
                'url' => wp_get_attachment_url( $thumbnail_id ),
            ];
        }

        return $data;
    }

    /**
     * Format a comment for event payload.
     *
     * @param \WP_Comment $comment Comment object.
     * @return array Formatted comment data.
     */
    private function format_comment_item( \WP_Comment $comment ): array {
        return [
            'id'        => (int) $comment->comment_ID,
            'post_id'   => (int) $comment->comment_post_ID,
            'author'    => $comment->comment_author,
            'email'     => $comment->comment_author_email,
            'content'   => $comment->comment_content,
            'status'    => $comment->comment_approved,
            'date'      => $comment->comment_date,
            'parent'    => (int) $comment->comment_parent,
        ];
    }

    /**
     * Format a media item for event payload.
     *
     * @param int $attachment_id Attachment ID.
     * @return array Formatted media data.
     */
    private function format_media_item( int $attachment_id ): array {
        $attachment = get_post( $attachment_id );
        if ( ! $attachment ) {
            return [];
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );

        return [
            'id'        => $attachment->ID,
            'title'     => $attachment->post_title,
            'filename'  => basename( get_attached_file( $attachment_id ) ?: '' ),
            'mime_type' => $attachment->post_mime_type,
            'url'       => wp_get_attachment_url( $attachment_id ),
            'alt_text'  => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
            'date'      => $attachment->post_date,
            'filesize'  => $metadata['filesize'] ?? null,
            'width'     => $metadata['width'] ?? null,
            'height'    => $metadata['height'] ?? null,
        ];
    }

    /**
     * Warn when an event payload is large enough to risk unreliable delivery.
     *
     * Large plugin/theme inventories on sites with hundreds of plugins can
     * exceed practical request limits. We log (rather than silently truncate)
     * so the oversize is observable; the SaaS event-receiver still receives the
     * full list when transport allows.
     *
     * @param string $event_type Event type identifier.
     * @param string $body       JSON-encoded request body.
     */
    private function warn_if_payload_large( string $event_type, string $body ): void {
        $bytes = strlen( $body );
        if ( $bytes > 1048576 ) {
            hubbee_debug_log( sprintf(
                '[Hubbee EventPusher] Large event payload: %s is %d bytes (>1MB) — delivery may be unreliable.',
                $event_type,
                $bytes
            ) );
        }
    }

    /**
     * Log success (for debugging)
     *
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    private function log_success( string $message, array $context = [] ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            hubbee_debug_log( sprintf( '[Hubbee EventPusher] %s: %s', $message, wp_json_encode( $context ) ) );
        }
    }

    /**
     * Log error
     *
     * @param string $message Log message.
     * @param array  $context Additional context.
     */
    private function log_error( string $message, array $context = [] ): void {
        hubbee_debug_log( sprintf( '[Hubbee EventPusher ERROR] %s: %s', $message, wp_json_encode( $context ) ) );
        ErrorReporter::get_instance()->record( 'EventPusher', $message, $context );
    }
}
