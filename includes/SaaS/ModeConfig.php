<?php
/**
 * Mode Configuration Handler
 *
 * Handles feature flags and mode-specific configuration.
 * All DEV vs PROD differences are controlled via Feature-Flags.
 *
 * Feature Flags:
 * - HUBBEE_MODE: 'dev' or 'prod' (main switch)
 * - HUBBEE_ENABLE_COMMAND_POLL: WP polls commands from SaaS
 * - HUBBEE_ENABLE_EVENT_PUSH: WP pushes events to SaaS
 * - HUBBEE_COMMAND_POLL_INTERVAL: Seconds between polls
 *
 * @package Hubbee\SaaS
 */

namespace Hubbee\SaaS;

class ModeConfig {

    /**
     * Mode constants
     */
    const MODE_DEV  = 'dev';
    const MODE_PROD = 'prod';

    /**
     * Default poll intervals (in seconds)
     */
    const DEFAULT_POLL_INTERVAL_DEV  = 30;  // 30 seconds
    const DEFAULT_POLL_INTERVAL_PROD = 300; // 5 minutes

    /**
     * Singleton instance
     *
     * @var ModeConfig|null
     */
    private static ?ModeConfig $instance = null;

    /**
     * Cached mode value
     *
     * @var string|null
     */
    private ?string $mode = null;

    /**
     * Get singleton instance
     *
     * @return ModeConfig
     */
    public static function get_instance(): ModeConfig {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor for singleton
     */
    private function __construct() {}

    /**
     * Get current mode
     *
     * Priority:
     * 1. WordPress constant (HUBBEE_MODE)
     * 2. Environment variable (HUBBEE_MODE)
     * 3. Database option (bz_mode)
     * 4. Default: 'dev'
     *
     * @return string 'dev' or 'prod'
     */
    public function get_mode(): string {
        if ( null !== $this->mode ) {
            return $this->mode;
        }

        // Check WordPress constant first
        if ( defined( 'HUBBEE_MODE' ) ) {
            $this->mode = $this->validate_mode( HUBBEE_MODE );
            return $this->mode;
        }

        // Check environment variable
        $env_mode = getenv( 'HUBBEE_MODE' );
        if ( false !== $env_mode && ! empty( $env_mode ) ) {
            $this->mode = $this->validate_mode( $env_mode );
            return $this->mode;
        }

        // Check database option
        $db_mode = get_option( 'bz_mode', '' );
        if ( ! empty( $db_mode ) ) {
            $this->mode = $this->validate_mode( $db_mode );
            return $this->mode;
        }

        // Auto-detect: if site URL is localhost, default to dev
        $site_url = home_url();
        if ( $this->is_localhost_url( $site_url ) ) {
            $this->mode = self::MODE_DEV;
        } else {
            $this->mode = self::MODE_PROD;
        }

        return $this->mode;
    }

    /**
     * Check if in DEV mode
     *
     * @return bool
     */
    public function is_dev_mode(): bool {
        return self::MODE_DEV === $this->get_mode();
    }

    /**
     * Check if in PROD mode
     *
     * @return bool
     */
    public function is_prod_mode(): bool {
        return self::MODE_PROD === $this->get_mode();
    }

    /**
     * Check if Command Poll is enabled
     *
     * Command Poll is MANDATORY in DEV mode (only way for SaaS → WP communication)
     * In PROD mode, it's optional (used as fallback)
     *
     * @return bool
     */
    public function is_command_poll_enabled(): bool {
        // Check WordPress constant
        if ( defined( 'HUBBEE_ENABLE_COMMAND_POLL' ) ) {
            return (bool) HUBBEE_ENABLE_COMMAND_POLL;
        }

        // Check environment variable
        $env = getenv( 'HUBBEE_ENABLE_COMMAND_POLL' );
        if ( false !== $env ) {
            return filter_var( $env, FILTER_VALIDATE_BOOLEAN );
        }

        // Default: enabled in DEV (SaaS can't reach localhost so wp-cron poll
        // is the only inbound path), disabled in PROD (VPS command-dispatcher
        // pushes commands directly via POST /bz/v1/command — running wp-cron
        // alongside is the parallel-pattern CLAUDE.md §8.1 prohibits).
        // Sites that need wp-cron as a manual PROD fallback can opt-in via
        // `define( 'HUBBEE_ENABLE_COMMAND_POLL', true )` in wp-config.php.
        return $this->is_dev_mode();
    }

    /**
     * Check if Event Push is enabled
     *
     * Event Push is the PRIMARY communication method in DEV mode.
     * WordPress pushes events (health, plugin changes, etc.) to SaaS.
     *
     * @return bool
     */
    public function is_event_push_enabled(): bool {
        // Check WordPress constant
        if ( defined( 'HUBBEE_ENABLE_EVENT_PUSH' ) ) {
            return (bool) HUBBEE_ENABLE_EVENT_PUSH;
        }

        // Check environment variable
        $env = getenv( 'HUBBEE_ENABLE_EVENT_PUSH' );
        if ( false !== $env ) {
            return filter_var( $env, FILTER_VALIDATE_BOOLEAN );
        }

        // Default: always enabled
        return true;
    }

    /**
     * Get Command Poll interval in seconds
     *
     * Priority: WP-Constant > Env-Var > poll_interval_hint > Mode-Default
     * Clamped to min/max per mode to prevent too aggressive or too lazy polling.
     *
     * @return int Seconds between polls
     */
    public function get_poll_interval(): int {
        $min = $this->is_dev_mode() ? 30 : 60;
        $max = 1800; // 30 minutes

        // Check WordPress constant (highest priority)
        if ( defined( 'HUBBEE_COMMAND_POLL_INTERVAL' ) ) {
            return max( $min, min( $max, (int) HUBBEE_COMMAND_POLL_INTERVAL ) );
        }

        // Check environment variable
        $env = getenv( 'HUBBEE_COMMAND_POLL_INTERVAL' );
        if ( false !== $env && is_numeric( $env ) ) {
            return max( $min, min( $max, (int) $env ) );
        }

        // Check SaaS-provided poll_interval_hint
        $hint = get_option( 'bz_poll_interval_hint', 0 );
        if ( $hint > 0 ) {
            return max( $min, min( $max, (int) $hint ) );
        }

        // Default based on mode
        return $this->is_dev_mode()
            ? self::DEFAULT_POLL_INTERVAL_DEV
            : self::DEFAULT_POLL_INTERVAL_PROD;
    }

    /**
     * Check if site URL is localhost/local
     *
     * Used for auto-detection of DEV mode.
     *
     * @param string $url The URL to check.
     * @return bool True if localhost/local URL.
     */
    public function is_localhost_url( string $url ): bool {
        $parsed = wp_parse_url( $url );
        if ( ! $parsed || empty( $parsed['host'] ) ) {
            return false;
        }

        $host = strtolower( $parsed['host'] );

        // Check common localhost patterns
        $localhost_patterns = [
            'localhost',
            '127.0.0.1',
            '::1',
        ];

        if ( in_array( $host, $localhost_patterns, true ) ) {
            return true;
        }

        // Check local TLDs
        $local_tlds = [
            '.local',
            '.test',
            '.localhost',
            '.dev',         // Sometimes used for local dev
            '.ddev.site',   // DDEV
            '.lndo.site',   // Lando
        ];

        foreach ( $local_tlds as $tld ) {
            if ( str_ends_with( $host, $tld ) ) {
                return true;
            }
        }

        // Check private IP ranges
        if ( preg_match( '/^192\.168\./', $host ) ||
             preg_match( '/^10\./', $host ) ||
             preg_match( '/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host ) ) {
            return true;
        }

        return false;
    }

    /**
     * Validate mode value
     *
     * @param mixed $mode Mode value to validate.
     * @return string Valid mode ('dev' or 'prod').
     */
    private function validate_mode( $mode ): string {
        $mode = strtolower( trim( (string) $mode ) );
        return in_array( $mode, [ self::MODE_DEV, self::MODE_PROD ], true )
            ? $mode
            : self::MODE_DEV;
    }

    /**
     * Get SaaS endpoint for Edge Functions
     *
     * @return string Base URL for Supabase Edge Functions
     */
    public function get_saas_functions_url(): string {
        return ConnectionManager::SAAS_ENDPOINT . '/functions/v1';
    }

    /**
     * Get M2M API base URL.
     *
     * Resolution order (first match wins):
     *   1. `HUBBEE_API_ENDPOINT` define in `wp-config.php` — operator override
     *   2. `bz_m2m_api_endpoint` WordPress option — set by enroll response
     *      or by Hubbee Admin UI for AppSumo Lifetime users so they auto-route
     *      M2M traffic (heartbeat, site-commands, command-result, event-receiver)
     *      to the lower-latency Hetzner API Server without editing wp-config.php
     *   3. Supabase Edge Functions — default, backwards-compatible for all
     *      existing sites that have neither option set
     *
     * Manual override in wp-config.php:
     *   define('HUBBEE_API_ENDPOINT', 'https://api.hubbee.io');
     *
     * @return string Base URL for M2M endpoints (no trailing slash)
     */
    public function get_m2m_api_url(): string {
        // Fallback = the M2M API lane (api.hubbee.io), NOT the gateway's
        // /functions/v1 — heartbeat/site-commands/command-result/event-receiver
        // exist only on the lane. Keeps sites that never (re)enrolled (empty
        // option + no define) on a live endpoint after the green cutover.
        $default = ConnectionManager::M2M_ENDPOINT;

        if ( defined( 'HUBBEE_API_ENDPOINT' ) && HUBBEE_API_ENDPOINT ) {
            $candidate = rtrim( (string) HUBBEE_API_ENDPOINT, '/' );
            if ( $this->is_allowed_m2m_host( $candidate ) ) {
                return $candidate;
            }
            $this->warn_invalid_endpoint( 'HUBBEE_API_ENDPOINT', $candidate );
            return $default;
        }

        $option_endpoint = get_option( 'bz_m2m_api_endpoint', '' );
        if ( is_string( $option_endpoint ) && $option_endpoint !== '' ) {
            $candidate = rtrim( $option_endpoint, '/' );
            if ( $this->is_allowed_m2m_host( $candidate ) ) {
                return $candidate;
            }
            $this->warn_invalid_endpoint( 'bz_m2m_api_endpoint', $candidate );
            return $default;
        }

        return $default;
    }

    /**
     * Validate an M2M API endpoint URL against the allowed Hubbee infrastructure.
     *
     * This is the SSRF guard for the endpoint we route signed heartbeat /
     * site-commands / command-result / event-receiver traffic to. The value can
     * originate from an untrusted enrollment response, a wp-config define, or a
     * stored option, so it must never resolve to an internal/private/metadata
     * host and (in production) must be HTTPS on a Hubbee-controlled domain.
     *
     * Dev/localhost installs are relaxed so a local API server can be used.
     * The allowlist is filterable via `hubbee_allowed_m2m_host_suffixes` for
     * white-label deployments (default behaviour unchanged).
     *
     * @param string $url Candidate endpoint URL.
     * @return bool True if the URL is safe to use.
     */
    public function is_allowed_m2m_host( string $url ): bool {
        $parsed = wp_parse_url( $url );
        if ( ! $parsed || empty( $parsed['host'] ) || empty( $parsed['scheme'] ) ) {
            return false;
        }

        $scheme = strtolower( $parsed['scheme'] );
        $host   = strtolower( $parsed['host'] );

        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            return false;
        }

        // Relax ONLY when THIS SITE is a dev/local install (it may legitimately
        // target a local API server). We must NOT relax based on the target URL
        // being localhost — that would let an enroll response point a production
        // site at an internal host (SSRF).
        if ( $this->is_dev_mode() ) {
            return true;
        }

        // Production: HTTPS only.
        if ( 'https' !== $scheme ) {
            return false;
        }

        // Reject raw IPs in private / reserved / link-local (cloud-metadata) ranges.
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            if ( ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return false;
            }
        }

        /**
         * Allowed M2M host suffixes (exact host or domain suffix match).
         *
         * @param string[] $suffixes Default Hubbee-controlled domains.
         */
        $suffixes = apply_filters(
            'hubbee_allowed_m2m_host_suffixes',
            [ 'hubbee.io', 'supabase.co' ]
        );

        foreach ( (array) $suffixes as $suffix ) {
            $suffix = ltrim( strtolower( (string) $suffix ), '.' );
            if ( '' === $suffix ) {
                continue;
            }
            if ( $host === $suffix || str_ends_with( $host, '.' . $suffix ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log a throttled warning when a configured endpoint fails validation and we
     * fall back to the safe Supabase default.
     *
     * @param string $source   Where the bad value came from (define/option name).
     * @param string $endpoint The rejected endpoint.
     */
    private function warn_invalid_endpoint( string $source, string $endpoint ): void {
        $throttle_key = 'bz_invalid_endpoint_warned_' . md5( $source . '|' . $endpoint );
        if ( get_transient( $throttle_key ) ) {
            return;
        }
        set_transient( $throttle_key, true, HOUR_IN_SECONDS );

        $host = (string) ( wp_parse_url( $endpoint, PHP_URL_HOST ) ?: 'unparseable' );
        error_log( sprintf(
            '[Hubbee ModeConfig] Rejected M2M endpoint from %s (host: %s) — not an allowed Hubbee host. Falling back to the default Supabase endpoint.',
            $source,
            $host
        ) );
    }

    /**
     * Get all config values for debugging
     *
     * @return array
     */
    public function get_debug_info(): array {
        return [
            'mode'                   => $this->get_mode(),
            'is_dev_mode'            => $this->is_dev_mode(),
            'is_prod_mode'           => $this->is_prod_mode(),
            'command_poll_enabled'   => $this->is_command_poll_enabled(),
            'event_push_enabled'     => $this->is_event_push_enabled(),
            'poll_interval'          => $this->get_poll_interval(),
            'is_localhost'           => $this->is_localhost_url( home_url() ),
            'site_url'               => home_url(),
            'saas_functions_url'     => $this->get_saas_functions_url(),
            'm2m_api_url'            => $this->get_m2m_api_url(),
        ];
    }

    /**
     * Clear cached mode (useful for testing)
     */
    public function clear_cache(): void {
        $this->mode = null;
    }
}
