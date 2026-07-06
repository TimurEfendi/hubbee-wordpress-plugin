<?php
/**
 * Asset Manifest Endpoint
 *
 * Exposes a read-only manifest of locally-installed backgrounds, text
 * effects, and components so the SaaS reconciliation cron can detect drift
 * between what SaaS thinks is installed and what is actually present.
 *
 * Audit P1-8. HMAC-authenticated (SaaS -> WordPress). Not paginated — the
 * per-site asset count is small (typically < 100).
 *
 * @package Hubbee\REST
 */

namespace Hubbee\REST;

use WP_REST_Request;
use WP_REST_Response;
use Hubbee\Storage\BackgroundRepository;
use Hubbee\Storage\TextEffectRepository;
use Hubbee\Storage\ComponentRepository;

class AssetManifestEndpoint extends RestEndpoint {

    protected function get_route(): string {
        return '/asset-manifest';
    }

    protected function get_methods(): string {
        return 'GET';
    }

    public function handle_request( WP_REST_Request $request ) {
        $backgrounds  = $this->collect_backgrounds();
        $text_effects = $this->collect_text_effects();
        $components   = $this->collect_components();

        return new WP_REST_Response(
            [
                'success'      => true,
                'backgrounds'  => $backgrounds,
                'text_effects' => $text_effects,
                'components'   => $components,
                'generated_at' => gmdate( 'c' ),
            ],
            200
        );
    }

    /**
     * @return array<int, array{slug:string, bg_type:string, config_hash:string}>
     */
    private function collect_backgrounds(): array {
        $out  = [];
        $repo = new BackgroundRepository();
        foreach ( $repo->get_all() as $row ) {
            $config = $this->decode_config( $row->config ?? null );
            $out[]  = [
                'slug'        => (string) $row->background_slug,
                'bg_type'     => (string) ( $row->bg_type ?? '' ),
                'config_hash' => self::compute_config_hash( $config ),
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array{slug:string, effect_type:string, config_hash:string}>
     */
    private function collect_text_effects(): array {
        $out  = [];
        if ( ! class_exists( TextEffectRepository::class ) ) return $out;
        $repo = new TextEffectRepository();
        foreach ( $repo->get_all() as $row ) {
            $config = $this->decode_config( $row->config ?? null );
            $out[]  = [
                'slug'        => (string) $row->text_effect_slug,
                'effect_type' => (string) ( $row->effect_type ?? '' ),
                'config_hash' => self::compute_config_hash( $config ),
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array{slug:string, version:int, config_hash:string}>
     */
    private function collect_components(): array {
        $out  = [];
        if ( ! class_exists( ComponentRepository::class ) ) return $out;
        $repo = new ComponentRepository();
        foreach ( $repo->get_all() as $row ) {
            $config = $this->decode_config( $row->config ?? null );
            $out[]  = [
                'slug'        => (string) $row->component_slug,
                'version'     => (int) ( $row->version ?? 0 ),
                'config_hash' => self::compute_config_hash( $config ),
            ];
        }
        return $out;
    }

    /**
     * Config rows are stored as LONGTEXT JSON. Some repositories already
     * decode them, others don't — normalise before hashing.
     *
     * @param mixed $raw
     * @return mixed
     */
    private function decode_config( $raw ) {
        if ( is_string( $raw ) ) {
            $decoded = json_decode( $raw, true );
            return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
        }
        return $raw;
    }

    /**
     * Deterministic SHA-256 of a config value. Must match the JS
     * `computeConfigHash` in `supabase/functions/_shared/hash.ts` — key-sorted
     * stable JSON, then sha256 hex. Any divergence would cause false drift.
     *
     * @param mixed $value
     */
    public static function compute_config_hash( $value ): string {
        return hash( 'sha256', self::stable_stringify( $value ) );
    }

    /**
     * @param mixed $value
     */
    private static function stable_stringify( $value ): string {
        if ( $value === null ) return 'null';
        if ( is_bool( $value ) ) return $value ? 'true' : 'false';
        if ( is_int( $value ) || is_float( $value ) ) {
            // Match JSON.stringify integer formatting (no trailing .0 for whole floats)
            if ( is_float( $value ) && floor( $value ) === $value && ! is_infinite( $value ) ) {
                return (string) (int) $value;
            }
            return json_encode( $value );
        }
        if ( is_string( $value ) ) return json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        // Detect associative vs sequential arrays
        if ( is_array( $value ) ) {
            $is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
            if ( $is_list ) {
                $parts = array_map( [ self::class, 'stable_stringify' ], $value );
                return '[' . implode( ',', $parts ) . ']';
            }
            $keys = array_keys( $value );
            sort( $keys );
            $parts = [];
            foreach ( $keys as $k ) {
                $parts[] = json_encode( (string) $k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
                    . ':' . self::stable_stringify( $value[ $k ] );
            }
            return '{' . implode( ',', $parts ) . '}';
        }

        if ( is_object( $value ) ) {
            return self::stable_stringify( (array) $value );
        }

        // resources etc. — fall back to string repr
        return json_encode( (string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }
}
