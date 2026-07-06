<?php
/**
 * Read-only fetch commands.
 *
 * Owns: settings.fetch, content.fetch, users.fetch.
 *
 * Each command is a thin dispatcher that forwards to the matching public
 * `get_*_data()` method on the corresponding REST endpoint class. Those
 * endpoint classes already produce SaaS-shaped payloads — duplicating the
 * logic here would be the wrong abstraction.
 *
 * @package Hubbee\Agent\Handlers
 */

namespace Hubbee\Agent\Handlers;

use Hubbee\REST\ContentEndpoint;
use Hubbee\REST\SettingsEndpoint;
use Hubbee\REST\UsersEndpoint;
use WP_Error;

class FetchCommandHandler implements CommandHandler {

    public static function command_types(): array {
        return [ 'settings.fetch', 'content.fetch', 'users.fetch' ];
    }

    public function execute( string $type, array $payload ) {
        switch ( $type ) {
            case 'settings.fetch':
                return $this->settings_fetch( $payload );
            case 'content.fetch':
                return $this->content_fetch( $payload );
            case 'users.fetch':
                return $this->users_fetch( $payload );
            default:
                return new WP_Error( 'bz_unknown_fetch_command', sprintf( 'Unknown fetch command: %s', $type ) );
        }
    }

    private function settings_fetch( array $payload ): array {
        $action            = $payload['action'] ?? 'all';
        $settings_endpoint = new SettingsEndpoint();

        switch ( $action ) {
            case 'all':
                return [
                    'success'    => true,
                    'general'    => $settings_endpoint->get_general_settings_data(),
                    'reading'    => $settings_endpoint->get_reading_settings_data(),
                    'discussion' => $settings_endpoint->get_discussion_settings_data(),
                    'media'      => $settings_endpoint->get_media_settings_data(),
                    'permalinks' => $settings_endpoint->get_permalink_settings_data(),
                    'ssl'        => $settings_endpoint->get_ssl_info_data(),
                ];
            case 'general':
                return [ 'success' => true, 'settings' => $settings_endpoint->get_general_settings_data() ];
            case 'reading':
                return [
                    'success'         => true,
                    'settings'        => $settings_endpoint->get_reading_settings_data(),
                    'available_pages' => $settings_endpoint->get_available_pages_data(),
                ];
            case 'discussion':
                return [ 'success' => true, 'settings' => $settings_endpoint->get_discussion_settings_data() ];
            case 'media':
                return [ 'success' => true, 'settings' => $settings_endpoint->get_media_settings_data() ];
            case 'permalinks':
                return [ 'success' => true, 'settings' => $settings_endpoint->get_permalink_settings_data() ];
            case 'menus':
                return $settings_endpoint->get_menus_data();
            case 'widgets':
                return $settings_endpoint->get_widgets_data();
            case 'ssl':
                return [ 'success' => true, 'ssl' => $settings_endpoint->get_ssl_info_data() ];
            default:
                return [ 'success' => false, 'error' => 'Unknown settings action: ' . $action ];
        }
    }

    private function content_fetch( array $payload ): array {
        $action           = $payload['action'] ?? 'overview';
        $content_endpoint = new ContentEndpoint();

        switch ( $action ) {
            case 'overview':
                return $content_endpoint->get_content_overview_data();
            case 'posts':
                return $content_endpoint->get_posts_data( $payload );
            case 'pages':
                return $content_endpoint->get_pages_data( $payload );
            case 'media':
                return $content_endpoint->get_media_data( $payload );
            case 'post-types':
                return $content_endpoint->get_post_types_data();
            case 'taxonomies':
                return $content_endpoint->get_taxonomies_data();
            case 'categories':
                return $content_endpoint->get_categories_data( $payload );
            case 'tags':
                return $content_endpoint->get_tags_data( $payload );
            case 'comments':
                return $content_endpoint->get_comments_data( $payload );
            default:
                return [ 'success' => false, 'error' => 'Unknown content action: ' . $action ];
        }
    }

    private function users_fetch( array $payload ): array {
        $action         = $payload['action'] ?? 'list';
        $users_endpoint = new UsersEndpoint();

        switch ( $action ) {
            case 'list':
                return $users_endpoint->get_users_data( $payload );
            case 'roles':
                return $users_endpoint->get_roles_data();
            case 'by-role':
                return $users_endpoint->get_users_by_role_data( $payload );
            case 'stats':
                return $users_endpoint->get_user_stats_data();
            default:
                return [ 'success' => false, 'error' => 'Unknown users action: ' . $action ];
        }
    }
}
