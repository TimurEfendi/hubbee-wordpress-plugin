<?php
/**
 * Content-token frontend rendering without Elementor Pro.
 *
 * Provides two render paths the readme documents:
 *   - Shortcode:  [bz_text key="hero_title" locale="auto" fallback="…"]
 *   - Block:      hubbee/token (dynamic, server-rendered)
 *
 * Both reuse the same server-side token resolution as the Elementor Dynamic
 * Tag (Hubbee\Agent\TokenService), so a site on Elementor Free / no Elementor
 * can still render Hubbee content tokens.
 *
 * @package Hubbee\Frontend
 */

namespace Hubbee\Frontend;

use Hubbee\Agent\TokenService;

class TokenShortcode {

    public function init(): void {
        add_shortcode( 'bz_text', [ $this, 'render_shortcode' ] );
        add_action( 'init', [ $this, 'register_block' ] );
    }

    /**
     * [bz_text key="hero_title" locale="auto" fallback="Welcome"]
     *
     * @param array|string $atts Shortcode attributes.
     */
    public function render_shortcode( $atts ): string {
        $atts = shortcode_atts(
            [
                'key'      => '',
                'locale'   => 'auto',
                'fallback' => '',
            ],
            is_array( $atts ) ? $atts : [],
            'bz_text'
        );

        return $this->render_token(
            sanitize_text_field( (string) $atts['key'] ),
            sanitize_text_field( (string) $atts['locale'] ),
            (string) $atts['fallback']
        );
    }

    /**
     * Register the dynamic hubbee/token block (no build step — the editor
     * script uses the wp-* script handles as globals).
     */
    public function register_block(): void {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }

        $handle = 'hubbee-token-block';
        $path   = BZ_PLUGIN_DIR . 'assets/js/blocks/token-block.js';
        $ver    = file_exists( $path ) ? (string) filemtime( $path ) : BZ_VERSION;

        wp_register_script(
            $handle,
            BZ_PLUGIN_URL . 'assets/js/blocks/token-block.js',
            [ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ],
            $ver,
            true
        );

        register_block_type(
            'hubbee/token',
            [
                'api_version'     => 2,
                'editor_script'   => $handle,
                'render_callback' => [ $this, 'render_block' ],
                'attributes'      => [
                    'tokenKey' => [ 'type' => 'string', 'default' => '' ],
                    'locale'   => [ 'type' => 'string', 'default' => 'auto' ],
                    'fallback' => [ 'type' => 'string', 'default' => '' ],
                ],
            ]
        );
    }

    /**
     * Server-side render for the hubbee/token block.
     *
     * @param array $attributes Block attributes.
     */
    public function render_block( $attributes ): string {
        $attributes = is_array( $attributes ) ? $attributes : [];

        return $this->render_token(
            sanitize_text_field( (string) ( $attributes['tokenKey'] ?? '' ) ),
            sanitize_text_field( (string) ( $attributes['locale'] ?? 'auto' ) ),
            (string) ( $attributes['fallback'] ?? '' )
        );
    }

    /**
     * Resolve a content token to its rendered value. Mirrors the Elementor
     * TextTokenTag output contract: richtext → wp_kses_post, else esc_html.
     */
    private function render_token( string $key, string $locale, string $fallback ): string {
        if ( '' === $key ) {
            return esc_html( $fallback );
        }

        $service = new TokenService();
        $token   = $service->get_token( $key, $locale ?: 'auto' );

        if ( $token && ! empty( $token->value_longtext ) ) {
            if ( isset( $token->field_type ) && 'richtext' === $token->field_type ) {
                return wp_kses_post( $token->value_longtext );
            }
            return esc_html( $token->value_longtext );
        }

        return esc_html( $fallback );
    }
}
