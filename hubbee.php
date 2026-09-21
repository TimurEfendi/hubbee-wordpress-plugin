<?php
/**
 * Plugin Name: Hubbee
 * Plugin URI: https://hubbee.io
 * Description: Token-based text management with Elementor Dynamic Tags integration. Connect to your Hubbee SaaS dashboard to manage content centrally.
 * Version: 2.2.0
 * Author: 2BrandsMedia
 * Author URI: https://2brandsmedia.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hubbee
 * Domain Path: /languages
 * Requires at least: 6.4
 * Tested up to: 7.0
 * Requires PHP: 8.1
 *
 * @package Hubbee
 */

namespace Hubbee;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'BZ_VERSION', '2.2.0' );
define( 'BZ_PLUGIN_FILE', __FILE__ );
define( 'BZ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BZ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BZ_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Minimum PHP version guard.
 *
 * The codebase uses union return types (PHP 8.0+). We require PHP 8.1 as the
 * supported floor (8.0 and 7.4 are end-of-life). Bail with an admin notice
 * instead of triggering a parse fatal when WordPress autoloads a typed class
 * on an unsupported PHP version.
 */
if ( PHP_VERSION_ID < 80100 ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html( sprintf(
            /* translators: %s: current PHP version */
            __( 'Hubbee requires PHP 8.1 or newer. Your server is running PHP %s. The plugin has been disabled to prevent errors. Please update PHP and try again.', 'hubbee' ),
            PHP_VERSION
        ) );
        echo '</p></div>';
    } );
    return; // Do not register the autoloader or any hooks on unsupported PHP.
}

/**
 * PSR-4 style Autoloader
 */
spl_autoload_register( function ( $class ) {
    $prefix = 'Hubbee\\';
    $base_dir = BZ_PLUGIN_DIR . 'includes/';

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
} );

// Global helper functions (not autoloaded — they are namespace-less by design).
require_once BZ_PLUGIN_DIR . 'includes/helpers.php';

/**
 * Plugin activation hook
 */
function bz_activate() {
    require_once BZ_PLUGIN_DIR . 'includes/Core/Activator.php';
    Core\Activator::activate();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\bz_activate' );

/**
 * Plugin deactivation hook
 */
function bz_deactivate() {
    require_once BZ_PLUGIN_DIR . 'includes/Core/Deactivator.php';
    Core\Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\bz_deactivate' );

/**
 * Initialize the plugin
 */
function bz_init() {
    // Bootstrap the plugin
    $plugin = Core\Plugin::get_instance();
    $plugin->run();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bz_init' );

/**
 * Load bundled translations from /languages. Hooked on 'init' (not earlier) so
 * WP 6.7+ does not warn about early textdomain loading. Required because the
 * plugin is distributed outside wordpress.org (bundled .mo files), where the
 * just-in-time loader does not cover the supported WP 6.4-6.6 range.
 */
function bz_load_textdomain() {
    load_plugin_textdomain( 'hubbee', false, dirname( BZ_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\bz_load_textdomain' );
