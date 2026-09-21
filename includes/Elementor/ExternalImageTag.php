<?php
/**
 * External Image Token Dynamic Tag
 *
 * Renders external source tokens as image data in Elementor.
 * Extends Data_Tag for compatibility with Image/Media widgets.
 *
 * @package Hubbee\Elementor
 */

namespace Hubbee\Elementor;

use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module;
use Elementor\Controls_Manager;
use Hubbee\Agent\TokenService;
use Hubbee\Storage\TokenRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ExternalImageTag extends Data_Tag {

    /**
     * Get tag name
     *
     * @return string
     */
    public function get_name(): string {
        return 'bz-external-image-token';
    }

    /**
     * Get tag title
     *
     * @return string
     */
    public function get_title(): string {
        return __( 'Ext. Image', 'hubbee' );
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
        return 'eicon-image';
    }

    /**
     * Get tag categories
     *
     * @return array
     */
    public function get_categories(): array {
        return [
            Module::IMAGE_CATEGORY,
            Module::MEDIA_CATEGORY,
        ];
    }

    /**
     * Register controls
     */
    protected function register_controls(): void {
        $this->add_control(
            'token_key',
            [
                'label'       => __( 'External Image Token', 'hubbee' ),
                'type'        => Controls_Manager::SELECT2,
                'options'     => $this->get_token_options(),
                'default'     => '',
                'label_block' => true,
                'description' => __( 'Select the external image token.', 'hubbee' ),
            ]
        );

        $this->add_control(
            'fallback_url',
            [
                'label'       => __( 'Fallback Image URL', 'hubbee' ),
                'type'        => Controls_Manager::URL,
                'default'     => [
                    'url' => '',
                ],
                'description' => __( 'Image to display if the token is empty.', 'hubbee' ),
            ]
        );
    }

    /**
     * Get value — returns Elementor image array format
     *
     * @param array $options Options.
     * @return array Image array with 'url' and 'id' keys.
     */
    public function get_value( array $options = [] ): array {
        $token_key = $this->get_settings( 'token_key' );

        if ( empty( $token_key ) ) {
            return $this->get_fallback_image();
        }

        $service = new TokenService();
        $token = $service->get_token( $token_key, '' );

        if ( $token && ! empty( $token->value_longtext ) ) {
            $url = esc_url( $token->value_longtext );
            if ( ! empty( $url ) ) {
                return [
                    'url' => $url,
                    'id'  => 0, // External images have no WP attachment ID
                ];
            }
        }

        return $this->get_fallback_image();
    }

    /**
     * Get fallback image from settings
     *
     * @return array
     */
    private function get_fallback_image(): array {
        $fallback = $this->get_settings( 'fallback_url' );
        $url = ! empty( $fallback['url'] ) ? esc_url( $fallback['url'] ) : '';

        return [
            'url' => $url,
            'id'  => 0,
        ];
    }

    /**
     * Get token options for select (external image tokens only)
     *
     * @return array
     */
    private function get_token_options(): array {
        $repo = new TokenRepository();
        $tokens = $repo->get_external_tokens_by_types( [ 'image', 'gallery' ] );

        if ( empty( $tokens ) ) {
            return [
                '' => __( 'No external image tokens available', 'hubbee' ),
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
