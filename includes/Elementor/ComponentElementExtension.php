<?php
/**
 * Component Element Extension — wire Element-Library assets into WP rendering.
 *
 * Element-Library assets (bounce-cards, chroma-grid, circular-gallery, …)
 * are pushed through the shared `component-push` pipeline and stored in
 * `wp_bz_components`, but they require their own JS runtime + per-effect
 * chunk (el-*.min.js) to hydrate. Classic Component Builder configs
 * continue to render through MountpointRenderer + hubbee-live.min.js.
 *
 * Loading strategy:
 *   - Frontend: ComponentWidget emits the `data-bz-el` mountpoint; the
 *     DOM-driven hubbee-asset-loader (ElementorManager) lazy-loads
 *     vendor-react + element-runtime + el-*.min.js on viewport intersection.
 *     Reliable under Elementor's element cache; loads only on-screen chunks.
 *   - Editor preview: `elementor/preview/enqueue_scripts` eagerly enqueues
 *     vendor + runtime + every installed el chunk (AJAX re-renders, no loader).
 *
 * This class also owns the shared React-vendor guard + chunk cache-bust
 * version helper used by all three Elementor extensions, and resolves a
 * component's Element-Library type (used by ComponentWidget + push cleanup).
 *
 * @package Hubbee\Elementor
 */

namespace Hubbee\Elementor;

use Hubbee\Storage\ComponentRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ComponentElementExtension {

    /**
     * Shared React vendor handle. Enqueued (at most) once per request across
     * all three Elementor extensions — guarded by ensure_shared_react_vendor().
     */
    private const VENDOR_REACT_HANDLE = 'hubbee-bg-vendor';

    /**
     * Initialize the extension.
     *
     * Frontend chunk loading is handled by the DOM-driven hubbee-asset-loader
     * (ElementorManager) — it reads the data-bz-el mountpoints and lazy-loads
     * each element's chunk on viewport intersection, so we no longer collect
     * types or enqueue per render here (that was dropped under Elementor's
     * element cache). The editor preview keeps the eager enqueue-all path.
     */
    public function init(): void {
        // Editor preview iframe: render hooks do not fire predictably for the
        // AJAX-rendered widgets, so keep the eager enqueue-all behaviour.
        add_action( 'elementor/preview/enqueue_scripts', [ $this, 'enqueue_all_scripts' ] );
    }

    /**
     * Ensure the shared React vendor bundle is enqueued exactly once per
     * request, regardless of which extension (bg / textfx / element) needs
     * it first. The handle name is identical across all three extensions so
     * dependent scripts pin to the same global React instance.
     *
     * @return bool True if the vendor handle is enqueued/registered, false if
     *              the physical file is missing from the plugin.
     */
    public static function ensure_shared_react_vendor(): bool {
        if ( wp_script_is( self::VENDOR_REACT_HANDLE, 'enqueued' ) || wp_script_is( self::VENDOR_REACT_HANDLE, 'registered' ) ) {
            return true;
        }

        $vendor_path = BZ_PLUGIN_DIR . 'assets/js/backgrounds/vendor-react.min.js';
        if ( ! file_exists( $vendor_path ) ) {
            return false;
        }

        wp_enqueue_script(
            self::VENDOR_REACT_HANDLE,
            BZ_PLUGIN_URL . 'assets/js/backgrounds/vendor-react.min.js',
            [],
            self::chunk_version( $vendor_path ),
            true
        );
        return true;
    }

    /**
     * Determine the Element-Library type from a component row's config, if
     * the first section has one. Returns '' for classic CB configs.
     *
     * @param object|array|null $component Row from ComponentRepository::get_* (stdClass or array-like).
     * @return string Element type slug or ''.
     */
    public static function resolve_element_type( $component ): string {
        if ( empty( $component ) ) {
            return '';
        }
        $config_raw = is_array( $component )
            ? ( $component['config'] ?? null )
            : ( $component->config ?? null );
        if ( empty( $config_raw ) ) {
            return '';
        }

        $config = is_string( $config_raw ) ? json_decode( $config_raw, true ) : $config_raw;
        if ( ! is_array( $config ) ) {
            return '';
        }

        $sections = $config['sections'] ?? [];
        if ( ! is_array( $sections ) || empty( $sections ) ) {
            return '';
        }

        $type = isset( $sections[0]['type'] ) ? (string) $sections[0]['type'] : '';
        return self::is_element_type( $type ) ? $type : '';
    }

    /**
     * Is this an Element-Library type?
     *
     * Single source of truth: a type is an Element-Library asset iff its
     * runtime chunk (`el-{type}.min.js`) has been installed into
     * wp-content/uploads/hubbee/chunks/. The chunk is downloaded at push
     * time by ComponentPushEndpoint::download_element_chunk() (which returns
     * 502 on failure), so by render/enqueue/delete time the file is present
     * for every genuinely-pushed element. This is symmetric with the push
     * Edge Function, which gates on the published chunk manifest — so new
     * elements work with zero code changes here: build + upload the chunk
     * and push it, and WP recognises it automatically.
     */
    public static function is_element_type( string $type ): bool {
        if ( '' === $type ) {
            return false;
        }
        $safe_type = sanitize_file_name( $type );
        if ( '' === $safe_type ) {
            return false;
        }
        $upload_dir = wp_upload_dir();
        $chunk_file = $upload_dir['basedir'] . '/hubbee/chunks/el-' . $safe_type . '.min.js';
        return file_exists( $chunk_file );
    }

    /**
     * Enqueue element vendor + runtime + every installed el-*.min.js chunk.
     * Used by the Elementor editor preview (AJAX re-renders, no asset-loader).
     */
    public function enqueue_all_scripts(): void {
        $repository = new ComponentRepository();
        $components = $repository->get_all();
        if ( empty( $components ) ) {
            return;
        }

        $active_types = [];
        foreach ( $components as $comp ) {
            $type = self::resolve_element_type( $comp );
            if ( '' !== $type ) {
                $active_types[ $type ] = true;
            }
        }

        if ( empty( $active_types ) ) {
            return;
        }

        $this->enqueue_element_assets( array_keys( $active_types ) );
    }

    /**
     * Enqueue the shared vendor, element runtime, element stylesheet and the
     * given Element-Library chunks. Shared by the conditional path, the
     * fallback path and the editor preview path.
     *
     * @param string[] $types Element-Library type slugs to enqueue chunks for.
     */
    private function enqueue_element_assets( array $types ): void {
        if ( empty( $types ) ) {
            return;
        }

        // Shared React vendor (also used by bg + textfx). One physical file
        // under assets/js/backgrounds/ — the el-/tx-specific copies were
        // byte-identical and have been dropped.
        if ( ! self::ensure_shared_react_vendor() ) {
            return;
        }

        // Element runtime (hydrates data-bz-el containers via
        // window.__bz_el_register(name, mountFn)).
        $runtime_path = BZ_PLUGIN_DIR . 'assets/js/elements/element-runtime.min.js';
        if ( file_exists( $runtime_path ) ) {
            wp_enqueue_script(
                'hubbee-el-runtime',
                BZ_PLUGIN_URL . 'assets/js/elements/element-runtime.min.js',
                [ self::VENDOR_REACT_HANDLE ],
                self::chunk_version( $runtime_path ),
                true
            );
        }

        // Shared element stylesheet (flowing-menu etc.)
        $style_path = BZ_PLUGIN_DIR . 'assets/js/elements/style.css';
        if ( file_exists( $style_path ) ) {
            wp_enqueue_style(
                'hubbee-el-style',
                BZ_PLUGIN_URL . 'assets/js/elements/style.css',
                [],
                self::chunk_version( $style_path )
            );
        }

        // Per-type chunks from uploads (pushed dynamically from SaaS).
        $upload_dir = wp_upload_dir();
        $chunks_dir = $upload_dir['basedir'] . '/hubbee/chunks';
        $chunks_url = set_url_scheme( $upload_dir['baseurl'] ) . '/hubbee/chunks';

        foreach ( $types as $type ) {
            $safe_type  = sanitize_file_name( $type );
            $chunk_file = $chunks_dir . '/el-' . $safe_type . '.min.js';
            if ( file_exists( $chunk_file ) ) {
                wp_enqueue_script(
                    'hubbee-el-chunk-' . sanitize_title( $type ),
                    $chunks_url . '/el-' . $safe_type . '.min.js',
                    [ self::VENDOR_REACT_HANDLE, 'hubbee-el-runtime' ],
                    self::chunk_version( $chunk_file ),
                    true
                );
            }
        }
    }

    /**
     * Cache-bust version for a chunk or bundled asset. Prefers the `.sha256`
     * sidecar written at push time (stable across servers + restarts) and
     * falls back to filemtime for assets that predate / never ship a sidecar
     * (e.g. plugin-bundled vendor/runtime files).
     *
     * Shared SSoT for cache-busting across all three Elementor extensions.
     */
    public static function chunk_version( string $chunk_file ): string {
        $sidecar = $chunk_file . '.sha256';
        if ( file_exists( $sidecar ) ) {
            $hash = trim( (string) file_get_contents( $sidecar ) );
            if ( '' !== $hash ) {
                // Use the hash hex as a compact cache-bust token. Strip the
                // "sha256:" prefix to keep the URL query param short.
                return substr( str_replace( 'sha256:', '', $hash ), 0, 16 );
            }
        }
        return (string) filemtime( $chunk_file );
    }
}
