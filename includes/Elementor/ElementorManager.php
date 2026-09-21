<?php
/**
 * Elementor Manager - Initialize Elementor integration
 *
 * @package Hubbee\Elementor
 */

namespace Hubbee\Elementor;

use Hubbee\REST\RestController;

class ElementorManager {

    /**
     * Dynamic tags group name
     */
    const GROUP_NAME = 'bz-tokens';

    /**
     * Initialize Elementor integration
     */
    public function init(): void {
        // Register dynamic tags group and tags
        // Elementor 3.5+ uses 'elementor/dynamic_tags/register'
        add_action( 'elementor/dynamic_tags/register', [ $this, 'register_group' ], 5 );
        add_action( 'elementor/dynamic_tags/register', [ $this, 'register_tags' ] );

        // Inject custom icon CSS into Elementor editor
        add_action( 'elementor/editor/after_enqueue_styles', [ $this, 'enqueue_icon_styles' ] );

        // Register widgets
        add_action( 'elementor/widgets/register', [ $this, 'register_widgets' ] );

        // Register container background extension
        $bg_extension = new ContainerBackgroundExtension();
        $bg_extension->init();

        // Register text effect extension
        $tx_extension = new TextEffectExtension();
        $tx_extension->init();

        // Register Element-Library extension: enqueues element-runtime
        // + el-*.min.js chunks globally for every installed element-type
        // component, so ComponentWidget's data-bz-el mountpoints hydrate.
        $el_extension = new ComponentElementExtension();
        $el_extension->init();

        // Frontend: single tiny asset-loader that lazy-loads each effect's
        // dependency chain on viewport intersection (replaces the per-element
        // conditional enqueue, which Elementor's element cache could skip).
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_loader' ], 20 );

        // Enqueue scripts in Elementor preview iframe
        add_action( 'elementor/preview/enqueue_scripts', [ $this, 'enqueue_preview_scripts' ] );

        // Enqueue script in Elementor editor frame (parent) to listen for component updates
        add_action( 'elementor/editor/after_enqueue_scripts', [ $this, 'enqueue_editor_scripts' ] );
    }

    /**
     * Register Elementor widgets
     *
     * @param \Elementor\Widgets_Manager $widgets_manager Widgets manager.
     */
    public function register_widgets( $widgets_manager ): void {
        $widgets_manager->register( new ComponentWidget() );
    }

    /**
     * Register dynamic tags group
     *
     * @param \Elementor\Core\DynamicTags\Manager $dynamic_tags_manager Dynamic tags manager.
     */
    public function register_group( $dynamic_tags_manager ): void {
        $dynamic_tags_manager->register_group(
            self::GROUP_NAME,
            [
                'title' => __( 'Hubbee', 'hubbee' ),
            ]
        );
    }

    /**
     * Enqueue custom icon styles for Elementor editor
     */
    public function enqueue_icon_styles(): void {
        $icon_url = BZ_PLUGIN_URL . 'assets/img/hubbee-icon.svg';
        $css = ".elementor-tags-list__group-title .eicon-hubbee {
            display: inline-block;
            width: 16px;
            height: 16px;
            background: url('{$icon_url}') center/contain no-repeat;
        }";
        wp_add_inline_style( 'elementor-editor', $css );
    }

    /**
     * Register dynamic tags
     *
     * @param \Elementor\Core\DynamicTags\Manager $dynamic_tags_manager Dynamic tags manager.
     */
    public function register_tags( $dynamic_tags_manager ): void {
        // Check Elementor version for compatibility
        if ( version_compare( ELEMENTOR_VERSION, '3.5.0', '>=' ) ) {
            // Elementor 3.5.0+ uses register() method
            $dynamic_tags_manager->register( new TextTokenTag() );
            $dynamic_tags_manager->register( new ExternalTextTag() );
            $dynamic_tags_manager->register( new ExternalImageTag() );
            $dynamic_tags_manager->register( new ExternalUrlTag() );
        } else {
            // Older versions use register_tag() method
            $dynamic_tags_manager->register_tag( TextTokenTag::class );
            $dynamic_tags_manager->register_tag( ExternalTextTag::class );
            $dynamic_tags_manager->register_tag( ExternalImageTag::class );
            $dynamic_tags_manager->register_tag( ExternalUrlTag::class );
        }
    }

    /**
     * Check if Elementor is active
     *
     * @return bool
     */
    public static function is_elementor_active(): bool {
        return did_action( 'elementor/loaded' );
    }

    /**
     * Check if we're in Elementor editor
     *
     * @return bool
     */
    public static function is_elementor_editor(): bool {
        if ( ! self::is_elementor_active() ) {
            return false;
        }

        return \Elementor\Plugin::$instance->editor->is_edit_mode();
    }

    /**
     * Check if we're in Elementor preview
     *
     * @return bool
     */
    public static function is_elementor_preview(): bool {
        if ( ! self::is_elementor_active() ) {
            return false;
        }

        return \Elementor\Plugin::$instance->preview->is_preview_mode();
    }

    /**
     * Frontend asset loader.
     *
     * Enqueues one tiny always-present script (hubbee-asset-loader.min.js)
     * that observes data-bz-{bg,textfx,el} containers and lazy-loads each
     * one's dependency chain (vendor → runtime → chunk) only when it nears
     * the viewport. This is DOM-driven, so unlike the old per-element render
     * hooks it is immune to Elementor's element cache, and it loads only the
     * chunks for assets actually on screen (the heavy Three.js / R3F vendor
     * chain stays deferred until a background scrolls into view).
     *
     * The Elementor editor preview keeps the eager enqueue-all path (each
     * extension hooks `elementor/preview/enqueue_scripts`), so this loader is
     * skipped inside the preview iframe.
     */
    public function enqueue_frontend_loader(): void {
        if ( self::is_elementor_active() && \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
            return;
        }

        $loader_path = BZ_PLUGIN_DIR . 'assets/js/hubbee-asset-loader.min.js';
        if ( ! file_exists( $loader_path ) ) {
            return;
        }

        wp_enqueue_script(
            'hubbee-asset-loader',
            BZ_PLUGIN_URL . 'assets/js/hubbee-asset-loader.min.js',
            [],
            (string) filemtime( $loader_path ),
            true
        );

        $upload_dir = wp_upload_dir();
        $assets = [
            'chunkBase'  => set_url_scheme( $upload_dir['baseurl'] ) . '/hubbee/chunks',
            'pluginBase' => rtrim( BZ_PLUGIN_URL, '/' ) . '/assets/js',
            'pluginVer'  => defined( 'BZ_VERSION' ) ? BZ_VERSION : (string) filemtime( $loader_path ),
            'chunkVers'  => self::get_chunk_versions(),
        ];

        wp_add_inline_script(
            'hubbee-asset-loader',
            'window.__bzAssets = ' . wp_json_encode( $assets ) . ';',
            'before'
        );
    }

    /**
     * Build a {chunk-stem → cache-bust token} map from the installed chunk
     * files in wp-content/uploads/hubbee/chunks. Keyed by the full stem
     * (e.g. "tx-true-focus", "el-card-swap", "bg-grainient") so cross-system
     * slug collisions are impossible. Prefers the `.sha256` sidecar (stable
     * across servers) and falls back to filemtime. The chunk set is small
     * (only assets pushed to this site), so this is read fresh each request
     * to avoid stale cache-bust tokens after a re-push.
     *
     * @return array<string,string>
     */
    private static function get_chunk_versions(): array {
        $upload_dir = wp_upload_dir();
        $chunks_dir = $upload_dir['basedir'] . '/hubbee/chunks';
        $map = [];
        if ( ! is_dir( $chunks_dir ) ) {
            return $map;
        }
        foreach ( ( glob( $chunks_dir . '/*.min.js' ) ?: [] ) as $file ) {
            $stem    = basename( $file, '.min.js' );
            $sidecar = $file . '.sha256';
            if ( file_exists( $sidecar ) ) {
                $hash = trim( (string) file_get_contents( $sidecar ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local file, not a remote URL.
                $map[ $stem ] = substr( str_replace( 'sha256:', '', $hash ), 0, 16 );
            } else {
                $map[ $stem ] = (string) filemtime( $file );
            }
        }
        return $map;
    }

    /**
     * Enqueue scripts in the Elementor preview iframe.
     *
     * Fires on 'elementor/preview/enqueue_scripts' which runs in the
     * preview iframe on page load. The existing MutationObserver in
     * ComponentHydrator handles all subsequent AJAX widget re-renders.
     */
    public function enqueue_preview_scripts(): void {
        $script_path = BZ_PLUGIN_DIR . 'assets/js/hubbee-live.min.js';
        if ( ! file_exists( $script_path ) ) {
            return;
        }

        $script_url = BZ_PLUGIN_URL . 'assets/js/hubbee-live.min.js';
        $style_url  = BZ_PLUGIN_URL . 'assets/js/style.css';

        // filemtime-based cache busting
        $js_version = (string) filemtime( $script_path );
        $css_path   = BZ_PLUGIN_DIR . 'assets/js/style.css';
        $css_version = file_exists( $css_path ) ? (string) filemtime( $css_path ) : $js_version;

        // CSS (extracted by Vite)
        wp_enqueue_style( 'hubbee-live', $style_url, [], $css_version );

        // JS Bundle
        wp_enqueue_script( 'hubbee-live', $script_url, [], $js_version, true );

        // Manifest + Config inline (before the bundle)
        add_action( 'wp_footer', [ $this, 'output_preview_inline_data' ], 5 );

        // Component-status polling: notifies the editor when new components are pushed
        $status_url = rest_url( RestController::NAMESPACE . '/component-status' );
        $inline_poll = sprintf(
            '(function(){var u=%s,last="";setInterval(function(){fetch(u).then(function(r){return r.json()}).then(function(d){if(last&&d.last_push&&d.last_push!==last){window.parent.postMessage({type:"bz-components-updated"},"*")}last=d.last_push||""}).catch(function(){})},10000)})();',
            wp_json_encode( $status_url )
        );
        wp_add_inline_script( 'hubbee-live', $inline_poll );
    }

    /**
     * Enqueue script in the Elementor editor (parent frame).
     *
     * Listens for 'bz-components-updated' postMessage from the preview
     * iframe and reloads the preview so new/updated widgets appear.
     */
    public function enqueue_editor_scripts(): void {
        wp_register_script( 'hubbee-editor-refresh', '', [], BZ_VERSION, true );
        wp_enqueue_script( 'hubbee-editor-refresh' );
        wp_add_inline_script(
            'hubbee-editor-refresh',
            'window.addEventListener("message",function(e){if(e.data&&e.data.type==="bz-components-updated"&&window.elementor){elementor.reloadPreview()}});'
        );
    }

    /**
     * Output config inline data for Elementor preview.
     * (Component manifest was retired with the legacy Component Editor.)
     */
    public function output_preview_inline_data(): void {
        printf(
            '<script id="bz-config">window.hubbeeConfig = %s;</script>',
            wp_json_encode( [
                'siteUrl' => get_site_url(),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
            ] )
        );
    }
}
