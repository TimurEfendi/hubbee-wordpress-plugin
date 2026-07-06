<?php
/**
 * Component Widget - Elementor Widget for Hubbee Components
 *
 * @package Hubbee\Elementor
 */

namespace Hubbee\Elementor;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Hubbee\Agent\ComponentService;
use Hubbee\Storage\BindingResolver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ComponentWidget extends Widget_Base {

    /**
     * Get widget name
     *
     * @return string
     */
    public function get_name(): string {
        return 'bz_component';
    }

    /**
     * Get widget title
     *
     * @return string
     */
    public function get_title(): string {
        return __( 'Hubbee Component', 'hubbee' );
    }

    /**
     * Get widget icon
     *
     * @return string
     */
    public function get_icon(): string {
        return 'eicon-code';
    }

    /**
     * Get widget categories
     *
     * @return array
     */
    public function get_categories(): array {
        return [ 'general' ];
    }

    /**
     * Get widget keywords
     *
     * @return array
     */
    public function get_keywords(): array {
        return [ 'hubbee', 'component', 'saas', 'dynamic' ];
    }

    /**
     * Register widget controls
     */
    protected function register_controls(): void {
        $this->start_controls_section(
            'section_content',
            [
                'label' => __( 'Component', 'hubbee' ),
                'tab'   => Controls_Manager::TAB_CONTENT,
            ]
        );

        $components = $this->get_component_options();

        $this->add_control(
            'component_slug',
            [
                'label'       => __( 'Select Component', 'hubbee' ),
                'type'        => Controls_Manager::SELECT,
                'default'     => '',
                'options'     => $components,
                'description' => __( 'Choose a component that has been pushed from the Hubbee SaaS.', 'hubbee' ),
            ]
        );

        $this->add_control(
            'fallback_content',
            [
                'label'       => __( 'Fallback Content', 'hubbee' ),
                'type'        => Controls_Manager::WYSIWYG,
                'default'     => '',
                'description' => __( 'Content to display if the component is not found.', 'hubbee' ),
            ]
        );

        $this->add_control(
            'show_version',
            [
                'label'        => __( 'Show Version Info', 'hubbee' ),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => __( 'Show', 'hubbee' ),
                'label_off'    => __( 'Hide', 'hubbee' ),
                'return_value' => 'yes',
                'default'      => '',
                'description'  => __( 'Display version info in admin bar for debugging.', 'hubbee' ),
            ]
        );

        $this->end_controls_section();

        // Height & Overflow section
        $this->start_controls_section(
            'section_height_overflow',
            [
                'label' => __( 'Height & Overflow', 'hubbee' ),
                'tab'   => Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'height_mode',
            [
                'label'   => __( 'Height Mode', 'hubbee' ),
                'type'    => Controls_Manager::SELECT,
                'default' => 'auto',
                'options' => [
                    'auto' => __( 'Auto', 'hubbee' ),
                    'vh'   => __( 'Viewport Height (vh)', 'hubbee' ),
                    'px'   => __( 'Pixels (px)', 'hubbee' ),
                    'rem'  => __( 'Rem', 'hubbee' ),
                ],
            ]
        );

        $this->add_control(
            'height_value',
            [
                'label'     => __( 'Height Value', 'hubbee' ),
                'type'      => Controls_Manager::NUMBER,
                'default'   => 80,
                'min'       => 1,
                'max'       => 5000,
                'condition' => [
                    'height_mode!' => 'auto',
                ],
            ]
        );

        $this->add_control(
            'overflow_mode',
            [
                'label'   => __( 'Overflow', 'hubbee' ),
                'type'    => Controls_Manager::SELECT,
                'default' => 'visible',
                'options' => [
                    'visible' => __( 'Visible', 'hubbee' ),
                    'hidden'  => __( 'Hidden', 'hubbee' ),
                    'auto'    => __( 'Auto', 'hubbee' ),
                ],
            ]
        );

        $this->end_controls_section();

        // Style section
        $this->start_controls_section(
            'section_style',
            [
                'label' => __( 'Container', 'hubbee' ),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'container_class',
            [
                'label'       => __( 'Additional CSS Class', 'hubbee' ),
                'type'        => Controls_Manager::TEXT,
                'default'     => '',
                'description' => __( 'Add custom CSS classes to the component wrapper.', 'hubbee' ),
            ]
        );

        $this->end_controls_section();
    }

    /**
     * Render widget output
     */
    protected function render(): void {
        $settings = $this->get_settings_for_display();
        $slug = $settings['component_slug'] ?? '';

        if ( empty( $slug ) ) {
            if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
                echo '<div class="bz-component-placeholder" style="padding: 40px; text-align: center; background: #f0f0f0; border: 2px dashed #ccc;">';
                echo '<p><strong>' . esc_html__( 'Hubbee Component', 'hubbee' ) . '</strong></p>';
                echo '<p>' . esc_html__( 'Select a component from the settings panel.', 'hubbee' ) . '</p>';
                echo '</div>';
            }
            return;
        }

        $service = new ComponentService();
        $component = $service->get( $slug );

        // Check if component exists locally (Asset-Library Element world).
        if ( null === $component ) {
            $fallback = $settings['fallback_content'] ?? '';
            if ( ! empty( $fallback ) ) {
                echo wp_kses_post( $fallback );
            } elseif ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
                echo '<div class="bz-component-error" style="padding: 20px; background: #fff3cd; border: 1px solid #ffc107; color: #856404;">';
                echo '<strong>' . esc_html__( 'Component not found:', 'hubbee' ) . '</strong> ' . esc_html( $slug );
                echo '</div>';
            }
            return;
        }

        $attributes = [];
        if ( ! empty( $settings['container_class'] ) ) {
            $attributes['class'] = sanitize_html_class( $settings['container_class'] ) . ' bz-elementor-widget';
        } else {
            $attributes['class'] = 'bz-elementor-widget';
        }

        // Build CSS variable style string for height/overflow
        $style_parts = [];
        $height_mode = $settings['height_mode'] ?? 'auto';
        if ( 'auto' !== $height_mode ) {
            $height_value = absint( $settings['height_value'] ?? 80 );
            $allowed_units = [ 'vh', 'px', 'rem' ];
            if ( in_array( $height_mode, $allowed_units, true ) ) {
                $style_parts[] = '--bz-height: ' . $height_value . $height_mode;
            }
        }

        $overflow_mode = $settings['overflow_mode'] ?? 'visible';
        $allowed_overflow = [ 'visible', 'hidden', 'auto' ];
        if ( in_array( $overflow_mode, $allowed_overflow, true ) ) {
            $style_parts[] = '--bz-overflow: ' . $overflow_mode;
        }

        if ( ! empty( $style_parts ) ) {
            $attributes['style'] = implode( '; ', $style_parts );
        }

        // Element-Library assets render through a dedicated mountpoint
        // (data-bz-el) that element-runtime.min.js hydrates.
        $element_type = ComponentElementExtension::resolve_element_type( $component );
        if ( '' !== $element_type ) {
            $this->render_element_mountpoint( $component, $element_type, $attributes );
        } elseif ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
            echo '<div class="bz-component-error" style="padding: 20px; background: #fff3cd; border: 1px solid #ffc107; color: #856404;">';
            echo '<strong>' . esc_html__( 'Unknown component type:', 'hubbee' ) . '</strong> ' . esc_html( $slug );
            echo '</div>';
        }

        // Show version info if enabled and user is admin
        if ( 'yes' === $settings['show_version'] && current_user_can( 'manage_options' ) ) {
            $component = $service->get( $slug );
            if ( $component ) {
                echo '<div class="bz-component-debug" style="font-size: 11px; color: #666; margin-top: 5px;">';
                echo sprintf(
                    /* translators: 1: component slug, 2: version number */
                    esc_html__( 'Component: %1$s (v%2$d)', 'hubbee' ),
                    esc_html( $slug ),
                    esc_html( $component->version )
                );
                echo '</div>';
            }
        }
    }

    /**
     * Render widget output in editor
     */
    protected function content_template(): void {
        // Empty: Forces Elementor to use server-side render() via AJAX.
        // MutationObserver in ComponentHydrator hydrates automatically.
    }

    /**
     * Render a data-bz-el mountpoint for Element-Library assets. The
     * element-runtime bundle picks it up via IntersectionObserver +
     * MutationObserver and mounts the type-specific React component
     * into the container.
     *
     * @param object|array $component    Component row from ComponentService::get().
     * @param string       $element_type Element-Library type slug.
     * @param array        $attributes   Base HTML attributes from widget render.
     */
    private function render_element_mountpoint( $component, string $element_type, array $attributes ): void {
        // Pull the first section's content/design/tokenBindings — the
        // Element-Library registers one section per component (see
        // useElementPush.componentConfig).
        $config_raw = is_array( $component )
            ? ( $component['config'] ?? null )
            : ( $component->config ?? null );
        $config = is_string( $config_raw ) ? json_decode( $config_raw, true ) : $config_raw;
        $section = is_array( $config ) && isset( $config['sections'][0] ) ? $config['sections'][0] : [];
        $content = $section['content'] ?? [];
        $design  = $section['design'] ?? [];

        // Pass the combined content+design as the element config so the
        // React mount function receives everything it needs in one object.
        $el_config = is_array( $content ) ? $content : [];
        if ( is_array( $design ) ) {
            foreach ( $design as $key => $value ) {
                if ( ! array_key_exists( $key, $el_config ) ) {
                    $el_config[ $key ] = $value;
                }
            }
        }

        // Resolve tokenBindings against the WP token store so the configured
        // token value lands inside data-bz-el-config. Without this step the
        // chunk would render the raw user-typed fallback even though a token
        // is bound on the SaaS side.
        $bindings = ( isset( $section['tokenBindings'] ) && is_array( $section['tokenBindings'] ) )
            ? $section['tokenBindings']
            : [];
        if ( ! empty( $bindings ) ) {
            $resolver  = new BindingResolver();
            $el_config = $resolver->apply( $el_config, $bindings );
        }

        $merged_attributes = array_merge(
            [
                'class'             => 'bz-el-mountpoint ' . ( $attributes['class'] ?? '' ),
                'data-bz-el'        => $element_type,
                'data-bz-el-config' => wp_json_encode( $el_config ),
            ],
            array_diff_key( $attributes, [ 'class' => true ] )
        );

        $attr_string = '';
        foreach ( $merged_attributes as $key => $value ) {
            if ( 'data-bz-el-config' === $key ) {
                // JSON in an attribute: single-quote wrapper + esc_attr
                $attr_string .= sprintf( " %s='%s'", esc_attr( $key ), esc_attr( $value ) );
            } else {
                $attr_string .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
            }
        }

        // element-runtime hydrates on intersection — emit the bare mountpoint
        // (no SSR fallback; Elementor already renders a placeholder cell).
        echo '<div' . $attr_string . '></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Get component options for select control
     *
     * @return array
     */
    private function get_component_options(): array {
        $service = new ComponentService();
        $components = $service->get_for_dropdown();

        // Add empty option at the beginning
        return array_merge(
            [ '' => __( '-- Select Component --', 'hubbee' ) ],
            $components
        );
    }
}
