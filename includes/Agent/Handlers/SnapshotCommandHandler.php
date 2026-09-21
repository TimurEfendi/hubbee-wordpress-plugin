<?php
/**
 * Snapshot collection.
 *
 * Owns: snapshot_collect — the unified "give me all the things" command
 * that gathers settings/users/content/system data and pushes a single
 * `snapshot_data` event to SaaS via EventPusher.
 *
 * The command type matches the SaaS architecture contract (underscore
 * notation, see CLAUDE.md). Earlier versions registered `snapshot.collect`
 * which silently failed with "Unbekannter Befehlstyp" on every enrollment
 * because the SaaS enrol path queues `snapshot_collect`.
 *
 * Helpers (collect_system_info, get_directory_size, get_database_info)
 * stay private to this handler since no other command needs them.
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

use Hubbee\Agent\EventPusher;
use Hubbee\REST\ContentEndpoint;
use Hubbee\REST\SettingsEndpoint;
use Hubbee\REST\UsersEndpoint;
use WP_Error;

class SnapshotCommandHandler implements CommandHandler {

    public static function command_types(): array {
        return [ 'snapshot_collect' ];
    }

    public function execute( string $type, array $payload ) {
        unset( $type );

        $sections    = $payload['sections'] ?? [ 'all' ];
        $command_id  = $payload['command_id'] ?? '';
        $collect_all = in_array( 'all', $sections, true );

        $collected = [];

        if ( $collect_all || in_array( 'settings', $sections, true ) ) {
            $settings_endpoint     = new SettingsEndpoint();
            $collected['settings'] = [
                'general'    => $settings_endpoint->get_general_settings_data(),
                'reading'    => $settings_endpoint->get_reading_settings_data(),
                'discussion' => $settings_endpoint->get_discussion_settings_data(),
                'media'      => $settings_endpoint->get_media_settings_data(),
                'permalinks' => $settings_endpoint->get_permalink_settings_data(),
                'ssl'        => $settings_endpoint->get_ssl_info_data(),
            ];
        }

        if ( $collect_all || in_array( 'users', $sections, true ) ) {
            $users_endpoint     = new UsersEndpoint();
            $collected['users'] = $users_endpoint->get_users_data( [] );
        }

        if ( $collect_all || in_array( 'content', $sections, true ) ) {
            $content_endpoint = new ContentEndpoint();

            $overview        = $content_endpoint->get_content_overview_data();
            $posts_data      = $content_endpoint->get_posts_data( [ 'per_page' => 100 ] );
            $pages_data      = $content_endpoint->get_pages_data( [ 'per_page' => 100 ] );
            $media_data      = $content_endpoint->get_media_data( [ 'per_page' => 100 ] );
            $comments_data   = $content_endpoint->get_comments_data( [ 'per_page' => 100 ] );
            $categories_data = $content_endpoint->get_categories_data( [] );
            $tags_data       = $content_endpoint->get_tags_data( [] );
            $post_types_data = $content_endpoint->get_post_types_data();
            $taxonomies_data = $content_endpoint->get_taxonomies_data();

            $collected['content'] = [
                'success'    => true,
                'stats'      => $overview['stats'] ?? [],
                'recent'     => $overview['recent'] ?? [],
                'posts'      => $posts_data['posts'] ?? [],
                'pages'      => $pages_data['pages'] ?? [],
                'media'      => $media_data['media'] ?? [],
                'comments'   => $comments_data['comments'] ?? [],
                'categories' => $categories_data['categories'] ?? [],
                'tags'       => $tags_data['tags'] ?? [],
                'post_types' => $post_types_data['post_types'] ?? [],
                'taxonomies' => $taxonomies_data['taxonomies'] ?? [],
                'mime_stats' => $media_data['mime_stats'] ?? [],
            ];
        }

        if ( $collect_all || in_array( 'system', $sections, true ) ) {
            $collected['system'] = $this->collect_system_info();
        }

        $push_result = EventPusher::get_instance()->push( 'snapshot_data', [
            'sections'   => $collected,
            'command_id' => $command_id,
        ] );

        if ( is_wp_error( $push_result ) ) {
            return [
                'success'  => false,
                'error'    => $push_result->get_error_message(),
                'sections' => array_keys( $collected ),
            ];
        }

        return [
            'success'  => true,
            'sections' => array_keys( $collected ),
            'pushed'   => true,
        ];
    }

    private function collect_system_info(): array {
        global $wpdb;

        $upload_dir  = wp_upload_dir();
        $upload_path = $upload_dir['basedir'];

        $disk_total = @disk_total_space( $upload_path );
        $disk_free  = @disk_free_space( $upload_path );

        $info = [
            'wp_version'         => get_bloginfo( 'version' ),
            'php_version'        => phpversion(),
            'php_sapi'           => php_sapi_name(),
            'mysql_version'      => $wpdb->db_version(),
            'server_software'    => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown',
            'is_multisite'       => is_multisite(),
            'is_ssl'             => is_ssl(),
            'locale'             => get_locale(),
            'timezone'           => wp_timezone_string(),
            'memory_limit'       => ini_get( 'memory_limit' ),
            'max_execution_time' => (int) ini_get( 'max_execution_time' ),
            'upload_max_size'    => size_format( wp_max_upload_size() ),
            'active_plugins'     => count( get_option( 'active_plugins', [] ) ),
            'active_theme'       => get_stylesheet(),
            'debug_mode'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'collected_at'       => current_time( 'mysql' ),
        ];

        if ( false !== $disk_total ) {
            $info['disk_total_bytes'] = (int) $disk_total;
        }
        if ( false !== $disk_free ) {
            $info['disk_free_bytes'] = (int) $disk_free;
        }

        $info['uploads_size_bytes'] = $this->get_directory_size( $upload_path );
        $info                       = array_merge( $info, $this->get_database_info() );

        return $info;
    }

    /**
     * Recursively walk a directory and sum file sizes. Returns 0 on any
     * read error (silent fail — the snapshot must not 500 just because one
     * upload subdir is unreadable).
     */
    private function get_directory_size( string $path ): int {
        if ( ! is_dir( $path ) ) {
            return 0;
        }

        $size = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ( $iterator as $file ) {
                if ( $file->isFile() ) {
                    $size += $file->getSize();
                }
            }
        } catch ( \Exception $e ) {
            return 0;
        }
        return $size;
    }

    private function get_database_info(): array {
        global $wpdb;

        $size_result = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = %s',
                DB_NAME
            )
        );
        $table_count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s',
                DB_NAME
            )
        );
        $wp_table_count = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s',
                DB_NAME,
                $wpdb->prefix . '%'
            )
        );
        $overhead = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT SUM(data_free) FROM information_schema.tables WHERE table_schema = %s',
                DB_NAME
            )
        );

        return [
            'db_size_bytes'     => (int) $size_result,
            'db_table_count'    => (int) $table_count,
            'db_wp_table_count' => (int) $wp_table_count,
            'db_overhead_bytes' => (int) $overhead,
        ];
    }
}
