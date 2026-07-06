<?php
/**
 * Text Token Dynamic Tag
 *
 * @package Hubbee\Elementor
 */

namespace Hubbee\Elementor;

use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;
use Elementor\Controls_Manager;
use Hubbee\Agent\TokenService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TextTokenTag extends Tag {

    /**
     * Get tag name
     *
     * @return string
     */
    public function get_name(): string {
        return 'bz-text-token';
    }

    /**
     * Get tag title
     *
     * @return string
     */
    public function get_title(): string {
        return __( 'Text', 'hubbee' );
    }

    /**
     * Get tag group
     *
     * @return string
     */
    public function get_group(): string {
        return ElementorManager::GROUP_NAME;
    }

    /**
     * Get tag icon
     *
     * @return string
     */
    public function get_icon(): string {
        return 'eicon-t-letter';
    }

    /**
     * Get tag categories
     *
     * @return array
     */
    public function get_categories(): array {
        return [
            Module::TEXT_CATEGORY,
            Module::POST_META_CATEGORY,
        ];
    }

    /**
     * Register controls
     */
    protected function register_controls(): void {
        $this->add_control(
            'token_key',
            [
                'label'       => __( 'Token', 'hubbee' ),
                'type'        => Controls_Manager::SELECT2,
                'options'     => $this->get_token_options(),
                'default'     => '',
                'label_block' => true,
                'description' => __( 'Select the token to display.', 'hubbee' ),
            ]
        );

        $this->add_control(
            'locale',
            [
                'label'       => __( 'Locale Override', 'hubbee' ),
                'type'        => Controls_Manager::TEXT,
                'default'     => '',
                'placeholder' => __( 'auto', 'hubbee' ),
                'description' => __( 'Leave empty for auto-detection based on site language.', 'hubbee' ),
            ]
        );

        $this->add_control(
            'fallback',
            [
                'label'       => __( 'Fallback Value', 'hubbee' ),
                'type'        => Controls_Manager::TEXT,
                'default'     => '',
                'description' => __( 'Text to display if token is empty or not found.', 'hubbee' ),
            ]
        );
    }

    /**
     * Render the tag
     */
    public function render(): void {
        $token_key = $this->get_settings( 'token_key' );

        if ( empty( $token_key ) ) {
            // Show placeholder in editor
            if ( ElementorManager::is_elementor_editor() ) {
                echo '<span style="color: #999;">[' . esc_html__( 'Select a token', 'hubbee' ) . ']</span>';
            }
            return;
        }

        $locale = $this->get_settings( 'locale' );
        $fallback = $this->get_settings( 'fallback' );

        $service = new TokenService();
        $token = $service->get_token( $token_key, $locale ?: 'auto' );

        if ( $token && ! empty( $token->value_longtext ) ) {
            // Output based on field type
            if ( 'richtext' === $token->field_type ) {
                echo wp_kses_post( $token->value_longtext );
            } else {
                echo esc_html( $token->value_longtext );
            }
        } elseif ( ! empty( $fallback ) ) {
            echo esc_html( $fallback );
        } elseif ( ElementorManager::is_elementor_editor() ) {
            // Show placeholder in editor if token is empty
            echo '<span style="color: #999;">[' . esc_html( $token_key ) . ': ' . esc_html__( 'No value', 'hubbee' ) . ']</span>';
        }
    }

    /**
     * Get token options for select (grouped by section)
     *
     * @return array
     */
    private function get_token_options(): array {
        $service = new TokenService();
        $tokens = $service->get_tokens_for_select_flat();

        if ( empty( $tokens ) ) {
            return [
                '' => __( 'No tokens available', 'hubbee' ),
            ];
        }

        return array_merge(
            [ '' => __( '— Select Token —', 'hubbee' ) ],
            $tokens
        );
    }

    /**
     * Get panel template setting key
     *
     * @return string
     */
    public function get_panel_template_setting_key(): string {
        return 'token_key';
    }

    /**
     * Check if settings are required
     *
     * @return bool
     */
    public function is_settings_required(): bool {
        return true;
    }
}
