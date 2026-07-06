<?php
/**
 * External Text Token Dynamic Tag
 *
 * Renders external source tokens as text content in Elementor.
 * Supports field_types: text, textarea, richtext, number, email, date, color.
 *
 * @package Hubbee\Elementor
 */

namespace Hubbee\Elementor;

use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module;
use Elementor\Controls_Manager;
use Hubbee\Agent\TokenService;
use Hubbee\Storage\TokenRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExternalTextTag extends Tag {

    /**
     * Get tag name
     *
     * @return string
     */
    public function get_name(): string {
        return 'bz-external-text-token';
    }

    /**
     * Get tag title
     *
     * @return string
     */
    public function get_title(): string {
        return __( 'Ext. Text', 'hubbee' );
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
        return 'eicon-database';
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
                'label'       => __( 'External Token', 'hubbee' ),
                'type'        => Controls_Manager::SELECT2,
                'options'     => $this->get_token_options(),
                'default'     => '',
                'label_block' => true,
                'description' => __( 'Select the external token to display.', 'hubbee' ),
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
            if ( ElementorManager::is_elementor_editor() ) {
                echo '<span style="color: #999;">[' . esc_html__( 'Select an external token', 'hubbee' ) . ']</span>';
            }
            return;
        }

        $fallback = $this->get_settings( 'fallback' );

        $service = new TokenService();
        $token = $service->get_token( $token_key, '' );

        if ( $token && ! empty( $token->value_longtext ) ) {
            if ( 'richtext' === $token->field_type ) {
                echo wp_kses_post( $token->value_longtext );
            } else {
                echo esc_html( $token->value_longtext );
            }
        } elseif ( ! empty( $fallback ) ) {
            echo esc_html( $fallback );
        } elseif ( ElementorManager::is_elementor_editor() ) {
            echo '<span style="color: #999;">[' . esc_html( $token_key ) . ': ' . esc_html__( 'No value', 'hubbee' ) . ']</span>';
        }
    }

    /**
     * Get token options for select (external text-compatible tokens only)
     *
     * @return array
     */
    private function get_token_options(): array {
        $repo = new TokenRepository();
        $tokens = $repo->get_external_tokens_by_types( [ 'text', 'textarea', 'richtext', 'number', 'email', 'date', 'color' ] );

        if ( empty( $tokens ) ) {
            return [
                '' => __( 'No external tokens available', 'hubbee' ),
            ];
        }

        // Collect labels
        $labels = [];
        foreach ( $tokens as $token ) {
            $labels[ $token->token_key ] = ! empty( $token->label ) ? $token->label : $token->token_key;
        }

        // Detect duplicates
        $label_counts = array_count_values( $labels );

        $options = [ '' => __( '— Select Token —', 'hubbee' ) ];
        foreach ( $tokens as $token ) {
            $label = $labels[ $token->token_key ];
            if ( $label_counts[ $label ] > 1 ) {
                $label .= ' [' . substr( $token->token_key, 0, 25 ) . ']';
            }
            $options[ $token->token_key ] = $label;
        }

        return $options;
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
