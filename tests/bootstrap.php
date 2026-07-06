<?php
/**
 * PHPUnit bootstrap.
 *
 * Hubbee plugin uses pure-unit tests that don't load WordPress. Tests that
 * require the full WP runtime (DB, hooks, options) belong in a separate
 * `tests/Integration/` suite that runs against `wp-cli scaffold plugin-tests`
 * — that scaffold is intentionally NOT pulled in here so the unit suite stays
 * fast and runnable on plain PHP CI.
 *
 * Anything WP-side a unit test needs is stubbed in tests/Stubs/.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/Stubs/' );
}
if ( ! defined( 'BZ_PLUGIN_DIR' ) ) {
    define( 'BZ_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'BZ_VERSION' ) ) {
    define( 'BZ_VERSION', 'test' );
}

// Minimal stubs for the tiny WP surface that pure-unit tests touch.
require_once __DIR__ . '/Stubs/wp-stubs.php';
