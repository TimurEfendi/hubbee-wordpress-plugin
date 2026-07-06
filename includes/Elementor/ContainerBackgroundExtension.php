<?php
/**
 * Container Background Extension - Adds Hubbee backgrounds to Elementor containers
 *
 * Loading strategy:
 *   - Frontend: during `elementor/frontend/container/before_render` we only
 *     inject the `data-bz-bg` render attributes. Chunk loading is handled by
 *     the DOM-driven hubbee-asset-loader (see ElementorManager), which lazy-
 *     loads the vendor chain + bg-*.min.js chunk on viewport intersection.
 *     This is reliable under Elementor's element cache (the attributes are
 *     baked into the cached HTML) and loads the heavy Three.js/R3F chain only
 *     when a background actually reaches the viewport.
 *   - Editor preview: `elementor/preview/enqueue_scripts` eagerly enqueues
 *     the vendor chain + every installed bg chunk (AJAX re-renders, no loader).
 *
 * @package Hubbee\Elementor
 */

namespace Hubbee\Elementor;

use Hubbee\Storage\BackgroundRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ContainerBackgroundExtension {

    /**
     * Initialize the extension
     */
    public function init(): void {
        add_action(
            'elementor/element/container/section_background/after_section_end',
            [ $this, 'register_controls' ],
            10,
            2
        );

        // Inject the data-bz-bg render attributes during render. The frontend
        // loads chunks via the DOM-driven hubbee-asset-loader (ElementorManager),
        // so we no longer collect/enqueue per-render here — that was unreliable
        // under Elementor's element cache.
        add_action(
            'elementor/frontend/container/before_render',
            [ $this, 'inject_attributes' ]
        );

        // Editor preview iframe: widgets re-render via AJAX (no asset-loader),
        // so keep the eager enqueue-all behaviour there.
        add_action( 'elementor/preview/enqueue_scripts', [ $this, 'enqueue_all_scripts' ] );
    }

    /**
     * Enqueue the four shared bundles every effect chunk depends on:
     * vendor-react → vendor-three → vendor-r3f → runtime.
     * Returns the dependency chain handle list so chunk-enqueues can pin to it.
     *
     * vendor-react is enqueued via the shared guard so the single global
     * React instance is reused across the bg / textfx / element extensions.
     *
     * @return string[]
     */
    private function enqueue_vendor_bundles(): array {
        $registered = [];

        // Shared React vendor — single global instance for all systems.
        if ( ComponentElementExtension::ensure_shared_react_vendor() ) {
            $registered[] = 'hubbee-bg-vendor';
        }

        $bundles = [
            'hubbee-bg-three'   => [ 'file' => 'vendor-three.min.js', 'deps' => [] ],
            'hubbee-bg-r3f'     => [ 'file' => 'vendor-r3f.min.js',   'deps' => [ 'hubbee-bg-vendor', 'hubbee-bg-three' ] ],
            'hubbee-bg-runtime' => [ 'file' => 'runtime.min.js',      'deps' => [ 'hubbee-bg-vendor', 'hubbee-bg-three', 'hubbee-bg-r3f' ] ],
        ];

        foreach ( $bundles as $handle => $info ) {
            $path = BZ_PLUGIN_DIR . 'assets/js/backgrounds/' . $info['file'];
            if ( ! file_exists( $path ) ) {
                continue;
            }
            wp_enqueue_script(
                $handle,
                BZ_PLUGIN_URL . 'assets/js/backgrounds/' . $info['file'],
                $info['deps'],
                ComponentElementExtension::chunk_version( $path ),
                true
            );
            $registered[] = $handle;
        }
        return $registered;
    }

    /**
     * Enqueue vendor bundles + every installed bg-*.min.js chunk. Used by the
     * Elementor editor preview (AJAX re-renders, no asset-loader).
     */
    public function enqueue_all_scripts(): void {
        $repository = new BackgroundRepository();
        $all = $repository->get_all();
        if ( empty( $all ) ) {
            return;
        }

        $types = [];
        foreach ( $all as $bg ) {
            $types[ $bg->bg_type ] = true;
        }

        $this->enqueue_bg_assets( array_keys( $types ) );
    }

    /**
     * Enqueue the vendor bundle chain + the given background chunks. Shared
     * by the conditional path and the fallback path.
     *
     * @param string[] $types Background type slugs to enqueue chunks for.
     */
    private function enqueue_bg_assets( array $types ): void {
        if ( empty( $types ) ) {
            return;
        }

        $deps = $this->enqueue_vendor_bundles();
        // Without vendor-three the effect chunks would crash with "undefined".
        // Skip chunk enqueue and surface an admin notice instead of a broken page.
        if ( ! in_array( 'hubbee-bg-three', $deps, true ) ) {
            $this->bg_vendor_missing_notice( 'vendor-three.min.js' );
            return;
        }

        $upload_dir = wp_upload_dir();
        $chunks_dir = $upload_dir['basedir'] . '/hubbee/chunks';
        $chunks_url = set_url_scheme( $upload_dir['baseurl'] ) . '/hubbee/chunks';

        foreach ( $types as $bg_type ) {
            $chunk_file = $chunks_dir . '/bg-' . sanitize_file_name( $bg_type ) . '.min.js';
            if ( file_exists( $chunk_file ) ) {
                wp_enqueue_script(
                    'hubbee-bg-chunk-' . sanitize_title( $bg_type ),
                    $chunks_url . '/bg-' . sanitize_file_name( $bg_type ) . '.min.js',
                    $deps,
                    ComponentElementExtension::chunk_version( $chunk_file ),
                    true
                );
            }
        }
    }

    /**
     * Surface a one-shot admin notice when a required vendor bundle is missing
     * from the plugin ZIP. Does not spam the frontend.
     *
     * @param string $missing_file Filename of the missing bundle.
     */
    private function bg_vendor_missing_notice( string $missing_file ): void {
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'admin_notices', function () use ( $missing_file ) {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }
            printf(
                '<div class="notice notice-error"><p><strong>Hubbee:</strong> %s</p></div>',
                esc_html( sprintf(
                    'Background asset "%s" missing from the plugin. Effects are disabled until the plugin is reinstalled.',
                    $missing_file
                ) )
            );
        } );
    }

    /**
     * Register background controls on the container element
     *
     * @param \Elementor\Element_Base $element The element instance.
     * @param array                   $args    Section arguments.
     */
    public function register_controls( $element, $args ): void {
        $element->start_controls_section(
            'hubbee_background_section',
            [
                'label' => __( 'Hubbee Background', 'hubbee' ),
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        // Filter dropdown by manifest (only show backgrounds active in SaaS)
        $manifest_service = new \Hubbee\Components\BackgroundManifestService();
        $manifest = $manifest_service->get_manifest();
        $repository = new BackgroundRepository();

        if ( null !== $manifest && ! empty( $manifest['backgrounds'] ) ) {
            $manifest_slugs = array_column( $manifest['backgrounds'], 'slug' );
            $all_dropdown   = $repository->get_for_dropdown();
            $filtered       = array_intersect_key( $all_dropdown, array_flip( $manifest_slugs ) );
            $options        = array_merge( [ '' => __( '-- None --', 'hubbee' ) ], $filtered );
        } else {
            // Fail-open: show all local backgrounds
            $options = array_merge(
                [ '' => __( '-- None --', 'hubbee' ) ],
                $repository->get_for_dropdown()
            );
        }

        $element->add_control(
            'hubbee_bg_slug',
            [
                'label'   => __( 'Background', 'hubbee' ),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'options' => $options,
                'default' => '',
            ]
        );

        $element->add_control(
            'hubbee_bg_opacity',
            [
                'label'     => __( 'Opacity', 'hubbee' ),
                'type'      => \Elementor\Controls_Manager::SLIDER,
                'range'     => [
                    'px' => [
                        'min'  => 0,
                        'max'  => 1,
                        'step' => 0.05,
                    ],
                ],
                'default'   => [ 'size' => 1 ],
                'condition' => [ 'hubbee_bg_slug!' => '' ],
            ]
        );

        $element->end_controls_section();
    }

    /**
     * Inject data attributes on the container wrapper before render and
     * record the bg_type so the late footer hook can enqueue only the
     * chunks this page uses.
     *
     * @param \Elementor\Element_Base $element The element instance.
     */
    public function inject_attributes( $element ): void {
        if ( 'container' !== $element->get_name() ) {
            return;
        }

        $settings = $element->get_settings_for_display();
        $slug = $settings['hubbee_bg_slug'] ?? '';

        if ( empty( $slug ) ) {
            return;
        }

        $repository = new BackgroundRepository();
        $bg = $repository->get_by_slug( $slug );

        if ( ! $bg ) {
            return;
        }

        $element->add_render_attribute( '_wrapper', 'class', 'has-bz-background' );
        $element->add_render_attribute( '_wrapper', 'data-bz-bg', $bg->bg_type );
        $element->add_render_attribute(
            '_wrapper',
            'data-bz-bg-config',
            wp_json_encode( json_decode( $bg->config, true ) )
        );

        $opacity = $settings['hubbee_bg_opacity']['size'] ?? 1;
        if ( (float) $opacity < 1 ) {
            $element->add_render_attribute( '_wrapper', 'data-bz-bg-opacity', (string) $opacity );
        }
    }
}
