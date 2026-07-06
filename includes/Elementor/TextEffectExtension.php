<?php
/**
 * Text Effect Extension - Adds Hubbee text effects to Elementor Heading & Text Editor widgets
 *
 * Mirrors ContainerBackgroundExtension pattern exactly:
 * - Dropdown stores slug from TextEffectRepository
 * - inject_attributes looks up slug → gets effect_type + config from DB
 * - Scripts loaded from uploads (pushed from SaaS)
 *
 * Loading strategy:
 *   - Frontend: inject only the `data-bz-textfx` render attributes; the
 *     DOM-driven hubbee-asset-loader (ElementorManager) lazy-loads
 *     vendor-react + textfx-runtime + tx-*.min.js on viewport intersection.
 *     Reliable under Elementor's element cache; loads only on-screen chunks.
 *   - Editor preview: `elementor/preview/enqueue_scripts` eagerly enqueues
 *     vendor + runtime + every installed tx chunk (AJAX re-renders, no loader).
 *
 * @package Hubbee\Elementor
 */

namespace Hubbee\Elementor;

use Hubbee\Storage\TextEffectRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TextEffectExtension {

    /**
     * Widget types that support text effects
     */
    private const SUPPORTED_WIDGETS = [ 'heading', 'text-editor' ];

    /**
     * Initialize the extension
     */
    public function init(): void {
        // Register controls on Heading widget (after Typography section)
        add_action(
            'elementor/element/heading/section_title_style/after_section_end',
            [ $this, 'register_controls' ],
            10,
            2
        );

        // Register controls on Text Editor widget (after Typography section)
        add_action(
            'elementor/element/text-editor/section_style/after_section_end',
            [ $this, 'register_controls' ],
            10,
            2
        );

        // Inject the data-bz-textfx render attributes during render. The
        // frontend loads chunks via the DOM-driven hubbee-asset-loader
        // (ElementorManager) on viewport intersection — reliable under
        // Elementor's element cache, unlike the old per-render enqueue.
        add_action( 'elementor/frontend/widget/before_render', [ $this, 'inject_attributes' ] );

        // Editor preview iframe: widgets re-render via AJAX (no asset-loader),
        // so keep the eager enqueue-all behaviour there.
        add_action( 'elementor/preview/enqueue_scripts', [ $this, 'enqueue_all_scripts' ] );
    }

    /**
     * Enqueue vendor + runtime + every installed tx-*.min.js chunk. Used by
     * the Elementor editor preview (AJAX re-renders, no asset-loader).
     */
    public function enqueue_all_scripts(): void {
        $repository = new TextEffectRepository();
        $all = $repository->get_all();
        if ( empty( $all ) ) {
            return;
        }

        $types = [];
        foreach ( $all as $te ) {
            $types[ $te->effect_type ] = true;
        }

        $this->enqueue_tx_assets( array_keys( $types ) );
    }

    /**
     * Enqueue the shared vendor, textfx runtime and the given text-effect
     * chunks. Shared by the conditional path and the fallback path.
     *
     * @param string[] $types Text effect type slugs to enqueue chunks for.
     */
    private function enqueue_tx_assets( array $types ): void {
        if ( empty( $types ) ) {
            return;
        }

        // Reuse background vendor React (single global React instance for all
        // systems) via the shared guard.
        if ( ! ComponentElementExtension::ensure_shared_react_vendor() ) {
            return;
        }

        // Runtime
        $runtime_path = BZ_PLUGIN_DIR . 'assets/js/textfx/textfx-runtime.min.js';
        if ( file_exists( $runtime_path ) ) {
            wp_enqueue_script(
                'hubbee-tx-runtime',
                BZ_PLUGIN_URL . 'assets/js/textfx/textfx-runtime.min.js',
                [ 'hubbee-bg-vendor' ],
                ComponentElementExtension::chunk_version( $runtime_path ),
                true
            );
        }

        // Per-type chunks from uploads (pushed dynamically from SaaS).
        $upload_dir = wp_upload_dir();
        $chunks_dir = $upload_dir['basedir'] . '/hubbee/chunks';
        $chunks_url = set_url_scheme( $upload_dir['baseurl'] ) . '/hubbee/chunks';

        foreach ( $types as $effect_type ) {
            $chunk_file = $chunks_dir . '/tx-' . sanitize_file_name( $effect_type ) . '.min.js';
            if ( file_exists( $chunk_file ) ) {
                wp_enqueue_script(
                    'hubbee-tx-chunk-' . sanitize_title( $effect_type ),
                    $chunks_url . '/tx-' . sanitize_file_name( $effect_type ) . '.min.js',
                    [ 'hubbee-bg-vendor', 'hubbee-tx-runtime' ],
                    ComponentElementExtension::chunk_version( $chunk_file ),
                    true
                );
            }
        }
    }

    /**
     * Register text effect controls on supported widgets
     *
     * @param \Elementor\Element_Base $element The element instance.
     * @param array                   $args    Section arguments.
     */
    public function register_controls( $element, $args ): void {
        $element->start_controls_section(
            'hubbee_textfx_section',
            [
                'label' => __( 'Hubbee Text Effect', 'hubbee' ),
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $repository = new TextEffectRepository();
        $options = array_merge(
            [ '' => __( '-- None --', 'hubbee' ) ],
            $repository->get_for_dropdown()
        );

        $element->add_control(
            'hubbee_textfx_slug',
            [
                'label'   => __( 'Text Effect', 'hubbee' ),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'options' => $options,
                'default' => '',
            ]
        );

        $element->end_controls_section();
    }

    /**
     * Inject data attributes on the widget wrapper before render and record
     * the effect_type so the late footer hook can enqueue only the chunks
     * this page uses.
     *
     * Mirrors ContainerBackgroundExtension:
     * 1. Get slug from Elementor settings
     * 2. Look up in TextEffectRepository
     * 3. Use effect_type + config from DB record
     *
     * @param \Elementor\Element_Base $element The element instance.
     */
    public function inject_attributes( $element ): void {
        if ( ! in_array( $element->get_name(), self::SUPPORTED_WIDGETS, true ) ) {
            return;
        }

        $settings = $element->get_settings_for_display();
        $slug = $settings['hubbee_textfx_slug'] ?? '';

        if ( empty( $slug ) ) {
            return;
        }

        $repository = new TextEffectRepository();
        $te = $repository->get_by_slug( $slug );

        if ( ! $te ) {
            return;
        }

        $element->add_render_attribute( '_wrapper', 'class', 'has-bz-textfx' );
        $element->add_render_attribute( '_wrapper', 'data-bz-textfx', $te->effect_type );
        $element->add_render_attribute(
            '_wrapper',
            'data-bz-textfx-config',
            wp_json_encode( json_decode( $te->config, true ) )
        );
    }
}
