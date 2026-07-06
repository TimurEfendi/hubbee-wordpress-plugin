<?php
/**
 * Binding Resolver - Resolves Element-Library tokenBindings against the WP token store.
 *
 * Mirrors src/pages/asset-library/components/ElementConfigurator.tsx previewConfig()
 * so SaaS preview and WP render produce identical output. Bindings live inside each
 * pushed element config under sections[0].tokenBindings as a fieldKey => tokenKey map.
 *
 * @package Hubbee\Storage
 */

namespace Hubbee\Storage;

use Hubbee\Agent\TokenService;

class BindingResolver {

    private TokenService $token_service;

    public function __construct( ?TokenService $token_service = null ) {
        $this->token_service = $token_service ?? new TokenService();
    }

    /**
     * Apply token bindings to an element config.
     *
     * @param array $el_config Merged content+design array.
     * @param array $bindings  fieldKey => tokenKey map (sections[0].tokenBindings).
     * @return array Resolved config with token values substituted in place.
     */
    public function apply( array $el_config, array $bindings ): array {
        if ( empty( $bindings ) ) {
            return $el_config;
        }

        $top_level_keys = [ 'headline', 'altText', 'captionText', 'overlayText' ];
        foreach ( $top_level_keys as $top_key ) {
            $token_key = $bindings[ $top_key ] ?? null;
            if ( ! is_string( $token_key ) || '' === $token_key ) {
                continue;
            }
            $value = $this->resolve_value( $token_key );
            if ( null === $value ) {
                continue;
            }

            // altText is nested inside imageSrc.alt for the tilted-card content shape.
            if ( 'altText' === $top_key && isset( $el_config['imageSrc'] ) && is_array( $el_config['imageSrc'] ) ) {
                $el_config['imageSrc']['alt'] = $value;
            } else {
                $el_config[ $top_key ] = $value;
            }
        }

        $id_field_map = [
            'item_text_'     => 'text',
            'item_title_'    => 'text', // alias used by InfiniteMenu in the SaaS configurator.
            'item_desc_'     => 'description',
            'item_subtitle_' => 'subtitle',
            'item_handle_'   => 'handle',
            'item_location_' => 'location',
            'item_link_'     => 'link', // InfiniteMenu item link (SaaS configurator).
        ];
        if ( isset( $el_config['items'] ) && is_array( $el_config['items'] ) ) {
            foreach ( $el_config['items'] as $idx => $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $item_id = isset( $item['id'] ) ? (string) $item['id'] : null;
                if ( null === $item_id || '' === $item_id ) {
                    continue;
                }
                foreach ( $id_field_map as $prefix => $target_field ) {
                    $binding_key = $prefix . $item_id;
                    $token_key   = $bindings[ $binding_key ] ?? null;
                    if ( ! is_string( $token_key ) || '' === $token_key ) {
                        continue;
                    }
                    $value = $this->resolve_value( $token_key );
                    if ( null !== $value ) {
                        $el_config['items'][ $idx ][ $target_field ] = $value;
                    }
                }
            }
        }

        if ( isset( $el_config['items'] ) && is_array( $el_config['items'] ) ) {
            foreach ( $bindings as $binding_key => $token_key ) {
                if ( ! is_string( $token_key ) || '' === $token_key ) {
                    continue;
                }
                if ( ! preg_match( '/^items\[(\d+)\]\.(.+)$/', $binding_key, $m ) ) {
                    continue;
                }
                $idx   = (int) $m[1];
                $field = $m[2];
                if ( ! isset( $el_config['items'][ $idx ] ) || ! is_array( $el_config['items'][ $idx ] ) ) {
                    continue;
                }
                $value = $this->resolve_value( $token_key );
                if ( null !== $value ) {
                    $el_config['items'][ $idx ][ $field ] = $value;
                }
            }
        }

        $skip_prefixes = [
            'item_text_',
            'item_title_',
            'item_desc_',
            'item_subtitle_',
            'item_handle_',
            'item_location_',
            'item_link_',
        ];
        foreach ( $bindings as $binding_key => $token_key ) {
            if ( ! is_string( $binding_key ) || ! is_string( $token_key ) || '' === $token_key ) {
                continue;
            }
            if ( str_starts_with( $binding_key, 'items[' ) ) {
                continue;
            }
            $is_id_pattern = false;
            foreach ( $skip_prefixes as $prefix ) {
                if ( str_starts_with( $binding_key, $prefix ) ) {
                    $is_id_pattern = true;
                    break;
                }
            }
            if ( $is_id_pattern ) {
                continue;
            }
            if ( in_array( $binding_key, $top_level_keys, true ) ) {
                continue;
            }

            $value = $this->resolve_value( $token_key );
            if ( null !== $value ) {
                $el_config[ $binding_key ] = $value;
            }
        }

        return $el_config;
    }

    /**
     * Look up a token value via TokenService. Returns null when the token is missing
     * or has no value so the caller can leave the raw config field intact.
     */
    private function resolve_value( string $token_key ): ?string {
        $token = $this->token_service->get_token( $token_key, 'auto' );
        if ( ! $token || empty( $token->value_longtext ) ) {
            return null;
        }
        return (string) $token->value_longtext;
    }
}
