<?php
/**
 * Uploads CORS Handler
 *
 * Manages CORS headers for static files in wp-content/uploads/ via .htaccess.
 * This is required for WebGL (texImage2D) to load cross-origin images.
 *
 * @package Hubbee\Core
 */

namespace Hubbee\Core;

class UploadsCors {

    /**
     * Current version of the CORS rules.
     * Bump this to force re-write on next page load.
     */
    const VERSION = 1;

    /**
     * Option key for tracking installed version.
     */
    const OPTION_KEY = 'bz_uploads_cors_version';

    /**
     * Option key for dismissed Nginx notice.
     */
    const NGINX_NOTICE_OPTION = 'bz_nginx_cors_notice_dismissed';

    /**
     * Marker name for insert_with_markers().
     */
    const MARKER = 'Hubbee CORS';

    /**
     * Ensure CORS rules are present in uploads .htaccess.
     * Idempotent — exits immediately if version matches.
     */
    public static function ensure(): void {
        // Skip if already at current version
        if ( (int) get_option( self::OPTION_KEY, 0 ) >= self::VERSION ) {
            return;
        }

        // Skip on Nginx — .htaccess is not used
        if ( self::is_nginx() ) {
            update_option( self::OPTION_KEY, self::VERSION );
            return;
        }

        $htaccess = self::get_htaccess_path();
        if ( ! $htaccess ) {
            return;
        }

        $rules = array(
            '<IfModule mod_headers.c>',
            '  <FilesMatch "\.(jpg|jpeg|png|gif|webp|svg|avif|ico)$">',
            '    Header set Access-Control-Allow-Origin "*"',
            '  </FilesMatch>',
            '</IfModule>',
        );

        // insert_with_markers handles create + idempotent update
        self::require_markers_api();
        $result = insert_with_markers( $htaccess, self::MARKER, $rules );

        if ( $result ) {
            update_option( self::OPTION_KEY, self::VERSION );
        }
    }

    /**
     * Remove CORS rules from uploads .htaccess.
     * Called on plugin deactivation.
     */
    public static function remove(): void {
        if ( self::is_nginx() ) {
            delete_option( self::OPTION_KEY );
            delete_option( self::NGINX_NOTICE_OPTION );
            return;
        }

        $htaccess = self::get_htaccess_path();
        if ( ! $htaccess || ! file_exists( $htaccess ) ) {
            delete_option( self::OPTION_KEY );
            return;
        }

        // Write empty rules to remove the marker block
        self::require_markers_api();
        insert_with_markers( $htaccess, self::MARKER, array() );

        // Delete the file if it's now empty (only whitespace/newlines)
        $contents = file_get_contents( $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local file, not a remote URL.
        if ( $contents !== false && trim( $contents ) === '' ) {
            wp_delete_file( $htaccess );
        }

        delete_option( self::OPTION_KEY );
        delete_option( self::NGINX_NOTICE_OPTION );
    }

    /**
     * Show admin notice for Nginx servers with config snippet.
     * Hooked in Plugin::run() for is_admin() only.
     */
    public static function maybe_show_nginx_notice(): void {
        if ( ! self::is_nginx() ) {
            return;
        }

        if ( get_option( self::NGINX_NOTICE_OPTION ) ) {
            return;
        }

        add_action( 'admin_notices', array( __CLASS__, 'render_nginx_notice' ) );
        add_action( 'wp_ajax_bz_dismiss_nginx_cors_notice', array( __CLASS__, 'dismiss_nginx_notice' ) );
    }

    /**
     * Render the Nginx admin notice.
     */
    public static function render_nginx_notice(): void {
        ?>
        <div class="notice notice-info is-dismissible" id="bz-nginx-cors-notice">
            <p><strong>Hubbee:</strong> <?php esc_html_e( 'Your server uses Nginx. To enable CORS for images (required for the gallery component), add this to your Nginx config:', 'hubbee' ); ?></p>
            <pre style="background:#f1f1f1;padding:10px;overflow-x:auto;">location ~* \.(?:jpg|jpeg|png|gif|webp|svg|avif|ico)$ {
    add_header Access-Control-Allow-Origin "*";
}</pre>
        </div>
        <script>
        jQuery(function($){
            $(document).on('click','#bz-nginx-cors-notice .notice-dismiss',function(){
                $.post(ajaxurl,{action:'bz_dismiss_nginx_cors_notice',_wpnonce:'<?php echo esc_js( wp_create_nonce( 'bz_dismiss_nginx_cors' ) ); ?>'});
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler to dismiss the Nginx notice.
     */
    public static function dismiss_nginx_notice(): void {
        check_ajax_referer( 'bz_dismiss_nginx_cors', '_wpnonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( -1 );
        }

        update_option( self::NGINX_NOTICE_OPTION, 1 );
        wp_die();
    }

    /**
     * Get path to .htaccess in uploads directory.
     *
     * @return string|null Path or null if uploads dir is not writable.
     */
    private static function get_htaccess_path(): ?string {
        $upload_dir = wp_upload_dir();
        $basedir    = $upload_dir['basedir'];

        if ( ! is_dir( $basedir ) || ! wp_is_writable( $basedir ) ) {
            return null;
        }

        return trailingslashit( $basedir ) . '.htaccess';
    }

    /**
     * Detect if the server is running Nginx.
     */
    private static function is_nginx(): bool {
        $server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
        return stripos( $server_software, 'nginx' ) !== false;
    }

    /**
     * Ensure insert_with_markers() is available.
     * This function lives in wp-admin/includes/misc.php and is not loaded on frontend requests.
     */
    private static function require_markers_api(): void {
        if ( ! function_exists( 'insert_with_markers' ) ) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }
    }
}
