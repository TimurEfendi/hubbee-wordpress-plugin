<?php
/**
 * Analytics Tracker
 *
 * Loads tracking JavaScript on frontend pages.
 *
 * @package Hubbee\Analytics
 */

namespace Hubbee\Analytics;

use Hubbee\SaaS\ConnectionManager;

class Tracker {

    /**
     * Initialize the tracker
     */
    public function init(): void {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_tracking_script' ] );
    }

    /**
     * Enqueue the tracking script on frontend
     */
    public function enqueue_tracking_script(): void {
        // Visitor analytics is OPT-IN. It runs only when the site owner has
        // explicitly enabled it (option bz_analytics_enabled, set from the
        // dashboard) or via the hubbee_analytics_enabled filter. Default OFF so
        // no visitor data ever leaves the site without consent — required for
        // WordPress.org guideline 7 and to honor the readme's privacy claims.
        $analytics_enabled = (bool) get_option( 'bz_analytics_enabled', false );
        $analytics_enabled = (bool) apply_filters( 'hubbee_analytics_enabled', $analytics_enabled );
        if ( ! $analytics_enabled ) {
            return;
        }

        // Track admin users by default (can be disabled via filter)
        if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
            $track_admins = apply_filters( 'hubbee_track_admins', true );
            if ( ! $track_admins ) {
                return;
            }
        }

        // Don't track in admin area
        if ( is_admin() ) {
            return;
        }

        // Don't track preview pages
        if ( is_preview() ) {
            return;
        }

        // Check if connected to SaaS
        $connection = new ConnectionManager();
        if ( ! $connection->is_connected() ) {
            return;
        }

        // Get site ID from ConnectionManager
        $site_id = $connection->get_saas_site_id();
        if ( empty( $site_id ) ) {
            return;
        }

        // Enqueue the tracking script. Version by file mtime so changes to
        // tracking.js bust visitor caches even without a plugin-version bump.
        $tracking_path = BZ_PLUGIN_DIR . 'assets/js/tracking.js';
        $tracking_ver  = file_exists( $tracking_path ) ? (string) filemtime( $tracking_path ) : BZ_VERSION;
        wp_enqueue_script(
            'hubbee-tracking',
            BZ_PLUGIN_URL . 'assets/js/tracking.js',
            [],
            $tracking_ver,
            true // Load in footer
        );

        // Pass configuration to JavaScript
        wp_localize_script(
            'hubbee-tracking',
            'hubbeeTracking',
            [
                'endpoint' => rest_url( 'bz/v1/analytics' ),
                'siteId'   => $site_id,
                'nonce'    => wp_create_nonce( 'wp_rest' ),
            ]
        );
    }
}
