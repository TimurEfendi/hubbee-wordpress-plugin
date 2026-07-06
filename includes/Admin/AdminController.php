<?php
/**
 * Admin Controller - Main admin initialization
 *
 * @package Hubbee\Admin
 */

namespace Hubbee\Admin;

use Hubbee\Security\Capabilities;

class AdminController {

    /**
     * Menu builder
     *
     * @var MenuBuilder
     */
    private MenuBuilder $menu_builder;

    /**
     * Asset manager
     *
     * @var AssetManager
     */
    private AssetManager $asset_manager;

    /**
     * Constructor
     */
    public function __construct() {
        $this->menu_builder = new MenuBuilder();
        $this->asset_manager = new AssetManager();
    }

    /**
     * Initialize admin functionality
     */
    public function init(): void {
        // Register menus
        add_action( 'admin_menu', [ $this->menu_builder, 'register_menus' ] );

        // Enqueue assets
        add_action( 'admin_enqueue_scripts', [ $this->asset_manager, 'enqueue_assets' ] );

        // Admin notices
        add_action( 'admin_notices', [ $this, 'display_notices' ] );

        // Plugin action links
        add_filter( 'plugin_action_links_' . BZ_PLUGIN_BASENAME, [ $this, 'add_action_links' ] );

        // Handle AJAX requests
        add_action( 'wp_ajax_bz_enroll', [ $this, 'ajax_enroll' ] );
        add_action( 'wp_ajax_bz_disconnect', [ $this, 'ajax_disconnect' ] );
        add_action( 'wp_ajax_bz_test_connection', [ $this, 'ajax_test_connection' ] );
    }

    /**
     * Display admin notices
     */
    public function display_notices(): void {
        // Welcome notice after activation
        if ( get_transient( 'bz_activated' ) ) {
            delete_transient( 'bz_activated' );
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'Hubbee activated!', 'hubbee' ); ?></strong>
                    <?php
                    printf(
                        /* translators: %s: settings page URL */
                        esc_html__( 'Connect your site to Hubbee in the %s.', 'hubbee' ),
                        '<a href="' . esc_url( admin_url( 'admin.php?page=hubbee' ) ) . '">' . esc_html__( 'Settings', 'hubbee' ) . '</a>'
                    );
                    ?>
                </p>
            </div>
            <?php
        }

        // Auto-reset notice (copied plugin detection)
        $connection = new \Hubbee\SaaS\ConnectionManager();
        if ( $connection->was_auto_reset() ) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'Hubbee connection reset', 'hubbee' ); ?></strong>
                    <?php esc_html_e( 'The plugin was copied to a new installation. Please reconnect the site.', 'hubbee' ); ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=hubbee' ) ); ?>">
                        <?php esc_html_e( 'Connect now', 'hubbee' ); ?>
                    </a>
                </p>
            </div>
            <?php
            // Clear the notice after displaying once
            $connection->clear_reset_notice();
        }

        // Version-skew notice: SaaS signalled this plugin is below the minimum
        // supported version (set by CommandPoller from the poll response).
        $min_version = get_option( 'bz_min_plugin_version', '' );
        if ( $min_version && version_compare( BZ_VERSION, $min_version, '<' ) ) {
            ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e( 'Hubbee plugin update required', 'hubbee' ); ?></strong>
                    <?php
                    printf(
                        /* translators: 1: current version, 2: required version */
                        esc_html__( 'This site runs Hubbee %1$s, but the Hubbee Cloud now requires at least %2$s. Please update the plugin to avoid losing functionality.', 'hubbee' ),
                        esc_html( BZ_VERSION ),
                        esc_html( $min_version )
                    );
                    ?>
                </p>
            </div>
            <?php
        }

        // Elementor not active notice (only on plugin pages)
        $screen = get_current_screen();
        if ( $screen && strpos( $screen->id, 'hubbee' ) !== false ) {
            if ( ! did_action( 'elementor/loaded' ) ) {
                ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php esc_html_e( 'Elementor not detected.', 'hubbee' ); ?></strong>
                        <?php esc_html_e( 'Hubbee Dynamic Tags require an active Elementor plugin.', 'hubbee' ); ?>
                    </p>
                </div>
                <?php
            }
        }
    }

    /**
     * Add plugin action links
     *
     * @param array $links Existing links.
     * @return array
     */
    public function add_action_links( array $links ): array {
        $plugin_links = [
            '<a href="' . esc_url( admin_url( 'admin.php?page=hubbee' ) ) . '">' . esc_html__( 'Settings', 'hubbee' ) . '</a>',
        ];

        return array_merge( $plugin_links, $links );
    }

    /**
     * AJAX handler for enrollment
     */
    public function ajax_enroll(): void {
        check_ajax_referer( 'bz_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'hubbee' ) ] );
        }

        $token = sanitize_text_field( $_POST['onboarding_token'] ?? '' );

        if ( empty( $token ) ) {
            wp_send_json_error( [ 'message' => __( 'Onboarding token is required.', 'hubbee' ) ] );
        }

        $enrollment = new \Hubbee\SaaS\EnrollmentService();
        $result = $enrollment->enroll( $token );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX handler for disconnect
     */
    public function ajax_disconnect(): void {
        check_ajax_referer( 'bz_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'hubbee' ) ] );
        }

        $enrollment = new \Hubbee\SaaS\EnrollmentService();
        $result = $enrollment->disconnect();

        wp_send_json_success( $result );
    }

    /**
     * AJAX handler for testing connection
     */
    public function ajax_test_connection(): void {
        check_ajax_referer( 'bz_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'hubbee' ) ] );
        }

        $connection = new \Hubbee\SaaS\ConnectionManager();
        $result = $connection->test_connection();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [
                'message' => $result->get_error_message(),
                'code'    => $result->get_error_code(),
            ] );
        }

        wp_send_json_success( $result );
    }

    /**
     * Check if user has access to admin
     *
     * @return bool
     */
    public static function user_can_access(): bool {
        return Capabilities::current_user_can_manage();
    }
}
