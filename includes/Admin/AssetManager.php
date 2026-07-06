<?php
/**
 * Asset Manager - Handle CSS/JS enqueuing
 *
 * @package Hubbee\Admin
 */

namespace Hubbee\Admin;

class AssetManager {

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets( string $hook ): void {
        // Only load on our plugin pages
        if ( strpos( $hook, 'hubbee' ) === false ) {
            return;
        }

        // Admin CSS
        wp_enqueue_style(
            'hubbee-admin',
            BZ_PLUGIN_URL . 'assets/css/admin.css',
            [],
            BZ_VERSION
        );

        // Admin JS
        wp_enqueue_script(
            'hubbee-admin',
            BZ_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            BZ_VERSION,
            true
        );

        // Localize script - only AJAX URL and nonce, no API endpoints in the frontend!
        wp_localize_script(
            'hubbee-admin',
            'bzAdmin',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'bz_admin_nonce' ),
                'strings' => [
                    'connecting'         => __( 'Connecting...', 'hubbee' ),
                    'connected'          => __( 'Connected successfully!', 'hubbee' ),
                    'disconnected'       => __( 'Disconnected', 'hubbee' ),
                    'error_occurred'     => __( 'An error occurred. Please try again.', 'hubbee' ),
                    'enter_token'        => __( 'Please enter an onboarding token.', 'hubbee' ),
                    'connection_failed'  => __( 'Connection failed.', 'hubbee' ),
                    'disconnect_failed'  => __( 'Disconnect failed.', 'hubbee' ),
                    'confirm_disconnect' => __( 'Are you sure you want to disconnect from Hubbee? Your tokens will remain stored locally.', 'hubbee' ),
                    'saved'              => __( 'Saved!', 'hubbee' ),
                ],
            ]
        );
    }
}
