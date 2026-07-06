<?php
/**
 * System Info Endpoint - Detailed system diagnostics
 *
 * Returns comprehensive server, WordPress, and PHP information
 * for remote monitoring via Hubbee SaaS.
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Hubbee\SaaS\ConnectionManager;

class SystemInfoEndpoint extends RestEndpoint {

    protected function get_route(): string {
        return '/system-info';
    }

    protected function get_methods(): string {
        return 'POST';
    }

    /**
     * Handle the system info request
     *
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response
     */
    public function handle_request( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response(
            [
                'success'    => true,
                'timestamp'  => current_time( 'mysql' ),
                'wordpress'  => $this->get_wordpress_info(),
                'php'        => $this->get_php_info(),
                'server'     => $this->get_server_info(),
                'database'   => $this->get_database_info(),
                'resources'  => $this->get_resource_usage(),
                'extensions' => $this->get_php_extensions(),
                'constants'  => $this->get_wp_constants(),
                'plugins'    => $this->get_plugins_summary(),
                'themes'     => $this->get_themes_summary(),
                'cron'       => $this->get_cron_summary(),
            ],
            200
        );
    }

    /**
     * Get WordPress information
     *
     * @return array
     */
    private function get_wordpress_info(): array {
        global $wp_version;

        $multisite = is_multisite();

        return [
            'version'          => $wp_version,
            'db_version'       => get_option( 'db_version' ),
            'site_url'         => get_site_url(),
            'home_url'         => get_home_url(),
            'admin_email'      => get_option( 'admin_email' ),
            'language'         => get_locale(),
            'timezone'         => wp_timezone_string(),
            'date_format'      => get_option( 'date_format' ),
            'time_format'      => get_option( 'time_format' ),
            'multisite'        => $multisite,
            'network_id'       => $multisite ? get_current_network_id() : null,
            'blog_id'          => get_current_blog_id(),
            'is_ssl'           => is_ssl(),
            'permalink_struct' => get_option( 'permalink_structure' ) ?: 'Plain',
            'uploads_dir'      => wp_upload_dir()['basedir'],
            'content_dir'      => WP_CONTENT_DIR,
            'plugin_dir'       => WP_PLUGIN_DIR,
            'theme_dir'        => get_theme_root(),
            'abspath'          => ABSPATH,
        ];
    }

    /**
     * Get PHP information
     *
     * @return array
     */
    private function get_php_info(): array {
        return [
            'version'           => PHP_VERSION,
            'version_id'        => PHP_VERSION_ID,
            'sapi'              => php_sapi_name(),
            'memory_limit'      => ini_get( 'memory_limit' ),
            'max_execution'     => ini_get( 'max_execution_time' ),
            'max_input_time'    => ini_get( 'max_input_time' ),
            'upload_max_size'   => ini_get( 'upload_max_filesize' ),
            'post_max_size'     => ini_get( 'post_max_size' ),
            'max_input_vars'    => ini_get( 'max_input_vars' ),
            'display_errors'    => ini_get( 'display_errors' ),
            'log_errors'        => ini_get( 'log_errors' ),
            'error_log'         => ini_get( 'error_log' ),
            'error_reporting'   => error_reporting(),
            'opcache_enabled'   => function_exists( 'opcache_get_status' ) && @opcache_get_status() !== false,
            'curl_version'      => function_exists( 'curl_version' ) ? curl_version()['version'] : null,
            'openssl_version'   => defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : null,
            'zlib_enabled'      => extension_loaded( 'zlib' ),
            'mbstring_enabled'  => extension_loaded( 'mbstring' ),
            'imagick_enabled'   => extension_loaded( 'imagick' ),
            'gd_enabled'        => extension_loaded( 'gd' ),
        ];
    }

    /**
     * Get server information
     *
     * @return array
     */
    private function get_server_info(): array {
        $server_software = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';

        return [
            'software'     => $server_software,
            'hostname'     => gethostname() ?: 'Unknown',
            'ip'           => $_SERVER['SERVER_ADDR'] ?? null,
            'protocol'     => $_SERVER['SERVER_PROTOCOL'] ?? null,
            'document_root'=> $_SERVER['DOCUMENT_ROOT'] ?? null,
            'os'           => PHP_OS,
            'os_family'    => PHP_OS_FAMILY,
            'architecture' => php_uname( 'm' ),
            'uname'        => php_uname(),
        ];
    }

    /**
     * Get database information
     *
     * @return array
     */
    private function get_database_info(): array {
        global $wpdb;

        $db_version = $wpdb->get_var( 'SELECT VERSION()' );

        // Get database size
        $db_size = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(data_length + index_length)
                 FROM information_schema.tables
                 WHERE table_schema = %s",
                DB_NAME
            )
        );

        // Get table count
        $table_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema = %s",
                DB_NAME
            )
        );

        // Get WordPress tables count
        $wp_table_count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema = %s
                 AND table_name LIKE %s",
                DB_NAME,
                $wpdb->prefix . '%'
            )
        );

        return [
            'type'           => 'MySQL/MariaDB',
            'version'        => $db_version,
            'name'           => DB_NAME,
            'host'           => DB_HOST,
            'charset'        => DB_CHARSET,
            'collate'        => DB_COLLATE ?: $wpdb->collate,
            'prefix'         => $wpdb->prefix,
            'size_bytes'     => (int) $db_size,
            'size_formatted' => size_format( (int) $db_size ),
            'table_count'    => (int) $table_count,
            'wp_table_count' => (int) $wp_table_count,
        ];
    }

    /**
     * Get resource usage information
     *
     * @return array
     */
    private function get_resource_usage(): array {
        $upload_dir = wp_upload_dir();
        $upload_path = $upload_dir['basedir'];

        // Memory usage
        $memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        $memory_used = memory_get_usage( true );
        $memory_peak = memory_get_peak_usage( true );

        // Disk usage
        $disk_total = @disk_total_space( $upload_path );
        $disk_free = @disk_free_space( $upload_path );
        $disk_used = $disk_total && $disk_free ? $disk_total - $disk_free : null;

        // Upload directory size
        $uploads_size = $this->get_directory_size( $upload_path );

        return [
            'memory' => [
                'limit'           => $memory_limit,
                'limit_formatted' => size_format( $memory_limit ),
                'used'            => $memory_used,
                'used_formatted'  => size_format( $memory_used ),
                'peak'            => $memory_peak,
                'peak_formatted'  => size_format( $memory_peak ),
                'percent'         => $memory_limit > 0 ? round( ( $memory_used / $memory_limit ) * 100, 1 ) : 0,
            ],
            'disk' => [
                'total'           => $disk_total,
                'total_formatted' => $disk_total ? size_format( $disk_total ) : null,
                'free'            => $disk_free,
                'free_formatted'  => $disk_free ? size_format( $disk_free ) : null,
                'used'            => $disk_used,
                'used_formatted'  => $disk_used ? size_format( $disk_used ) : null,
                'percent'         => $disk_total > 0 ? round( ( $disk_used / $disk_total ) * 100, 1 ) : 0,
            ],
            'uploads' => [
                'path'            => $upload_path,
                'size'            => $uploads_size,
                'size_formatted'  => size_format( $uploads_size ),
            ],
        ];
    }

    /**
     * Get loaded PHP extensions
     *
     * @return array
     */
    private function get_php_extensions(): array {
        $extensions = get_loaded_extensions();
        sort( $extensions );

        // Group by category
        $critical = [ 'curl', 'json', 'mbstring', 'openssl', 'mysqli', 'pdo_mysql' ];
        $recommended = [ 'gd', 'imagick', 'intl', 'zip', 'zlib', 'exif', 'fileinfo' ];
        $caching = [ 'opcache', 'apcu', 'memcached', 'redis' ];

        return [
            'total'       => count( $extensions ),
            'list'        => $extensions,
            'critical'    => array_filter( $critical, 'extension_loaded' ),
            'recommended' => array_filter( $recommended, 'extension_loaded' ),
            'caching'     => array_filter( $caching, 'extension_loaded' ),
            'missing_critical' => array_diff( $critical, array_filter( $critical, 'extension_loaded' ) ),
        ];
    }

    /**
     * Get WordPress constants
     *
     * @return array
     */
    private function get_wp_constants(): array {
        return [
            'WP_DEBUG'           => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'WP_DEBUG_LOG'       => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
            'WP_DEBUG_DISPLAY'   => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
            'SCRIPT_DEBUG'       => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
            'WP_CACHE'           => defined( 'WP_CACHE' ) && WP_CACHE,
            'CONCATENATE_SCRIPTS'=> defined( 'CONCATENATE_SCRIPTS' ) ? CONCATENATE_SCRIPTS : null,
            'COMPRESS_SCRIPTS'   => defined( 'COMPRESS_SCRIPTS' ) ? COMPRESS_SCRIPTS : null,
            'COMPRESS_CSS'       => defined( 'COMPRESS_CSS' ) ? COMPRESS_CSS : null,
            'WP_MEMORY_LIMIT'    => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : null,
            'WP_MAX_MEMORY_LIMIT'=> defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : null,
            'DISALLOW_FILE_EDIT' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
            'DISALLOW_FILE_MODS' => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
            'AUTOMATIC_UPDATER_DISABLED' => defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED,
            'WP_AUTO_UPDATE_CORE'=> defined( 'WP_AUTO_UPDATE_CORE' ) ? WP_AUTO_UPDATE_CORE : null,
            'FS_METHOD'          => defined( 'FS_METHOD' ) ? FS_METHOD : null,
            'FORCE_SSL_ADMIN'    => defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN,
        ];
    }

    /**
     * Get plugins summary
     *
     * @return array
     */
    private function get_plugins_summary(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $active_plugins = get_option( 'active_plugins', [] );
        $update_plugins = get_site_transient( 'update_plugins' );

        $updates_available = 0;
        if ( $update_plugins && ! empty( $update_plugins->response ) ) {
            $updates_available = count( $update_plugins->response );
        }

        return [
            'total'             => count( $all_plugins ),
            'active'            => count( $active_plugins ),
            'inactive'          => count( $all_plugins ) - count( $active_plugins ),
            'updates_available' => $updates_available,
            'must_use'          => count( get_mu_plugins() ),
            'dropins'           => count( get_dropins() ),
        ];
    }

    /**
     * Get themes summary
     *
     * @return array
     */
    private function get_themes_summary(): array {
        $all_themes = wp_get_themes();
        $active_theme = wp_get_theme();
        $update_themes = get_site_transient( 'update_themes' );

        $updates_available = 0;
        if ( $update_themes && ! empty( $update_themes->response ) ) {
            $updates_available = count( $update_themes->response );
        }

        $parent_theme = $active_theme->parent();

        return [
            'total'             => count( $all_themes ),
            'updates_available' => $updates_available,
            'active'            => [
                'name'       => $active_theme->get( 'Name' ),
                'version'    => $active_theme->get( 'Version' ),
                'author'     => $active_theme->get( 'Author' ),
                'stylesheet' => $active_theme->get_stylesheet(),
                'template'   => $active_theme->get_template(),
                'is_child'   => $active_theme->parent() !== false,
            ],
            'parent' => $parent_theme ? [
                'name'    => $parent_theme->get( 'Name' ),
                'version' => $parent_theme->get( 'Version' ),
            ] : null,
        ];
    }

    /**
     * Get cron summary
     *
     * @return array
     */
    private function get_cron_summary(): array {
        $cron_array = _get_cron_array();
        $schedules = wp_get_schedules();

        $total_events = 0;
        $hubbee_events = 0;
        $next_event = null;

        if ( $cron_array ) {
            foreach ( $cron_array as $timestamp => $cron ) {
                foreach ( $cron as $hook => $events ) {
                    $total_events += count( $events );

                    if ( strpos( $hook, 'bz_' ) === 0 || strpos( $hook, 'hubbee' ) === 0 ) {
                        $hubbee_events += count( $events );
                    }

                    if ( $next_event === null || $timestamp < $next_event['timestamp'] ) {
                        $next_event = [
                            'hook'      => $hook,
                            'timestamp' => $timestamp,
                            'datetime'  => wp_date( 'Y-m-d H:i:s', $timestamp ),
                        ];
                    }
                }
            }
        }

        return [
            'total_events'    => $total_events,
            'hubbee_events'   => $hubbee_events,
            'schedules_count' => count( $schedules ),
            'next_event'      => $next_event,
            'cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
            'alternate_cron'  => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
        ];
    }

    /**
     * Calculate directory size recursively
     *
     * @param string $path Directory path.
     * @return int Size in bytes.
     */
    private function get_directory_size( string $path ): int {
        $size = 0;

        if ( ! is_dir( $path ) ) {
            return 0;
        }

        // Use a simple estimation for large directories to avoid timeout
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $count = 0;
        $max_files = 10000; // Limit to prevent timeout

        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $size += $file->getSize();
                $count++;

                if ( $count >= $max_files ) {
                    // Return estimated size based on average
                    $avg_size = $size / $count;
                    $estimated_total = $avg_size * iterator_count( $iterator );
                    return (int) $estimated_total;
                }
            }
        }

        return $size;
    }
}
