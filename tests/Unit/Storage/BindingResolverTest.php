<?php
/**
 * Pure-unit test for BindingResolver. Mocks TokenService so the resolver
 * can be exercised without a database. Mirrors the SaaS-side
 * ElementConfigurator.previewConfig contract — keep them in sync.
 */

namespace Hubbee\Tests\Unit\Storage;

use Hubbee\Agent\TokenService;
use Hubbee\Storage\BindingResolver;
use PHPUnit\Framework\TestCase;

class BindingResolverTest extends TestCase {

    /**
     * Build a TokenService double that hands back the given key→value map.
     * Unknown keys resolve to null so the resolver leaves the raw value alone.
     */
    private function token_service_with_values( array $values ): TokenService {
        $stub = $this->getMockBuilder( TokenService::class )
            ->disableOriginalConstructor()
            ->onlyMethods( [ 'get_token' ] )
            ->getMock();

        $stub->method( 'get_token' )->willReturnCallback( function ( $key ) use ( $values ) {
            if ( ! array_key_exists( $key, $values ) ) {
                return null;
            }
            $token = new \stdClass();
            $token->value_longtext = $values[ $key ];
            return $token;
        } );

        return $stub;
    }

    public function test_returns_input_unchanged_when_bindings_empty(): void {
        $resolver = new BindingResolver( $this->token_service_with_values( [] ) );
        $config   = [ 'headline' => 'raw', 'items' => [] ];

        self::assertSame( $config, $resolver->apply( $config, [] ) );
    }

    public function test_resolves_top_level_headline(): void {
        $resolver = new BindingResolver(
            $this->token_service_with_values( [ 'hero_title' => 'Resolved Hero' ] )
        );

        $result = $resolver->apply(
            [ 'headline' => 'raw fallback' ],
            [ 'headline' => 'hero_title' ]
        );

        self::assertSame( 'Resolved Hero', $result['headline'] );
    }

    public function test_resolves_alt_text_inside_image_src_for_tilted_card(): void {
        $resolver = new BindingResolver(
            $this->token_service_with_values( [ 'alt_token' => 'A11Y description' ] )
        );

        $result = $resolver->apply(
            [ 'imageSrc' => [ 'url' => 'https://x', 'alt' => 'old' ] ],
            [ 'altText' => 'alt_token' ]
        );

        self::assertSame( 'A11Y description', $result['imageSrc']['alt'] );
    }

    public function test_resolves_caption_and_overlay_text(): void {
        $resolver = new BindingResolver(
            $this->token_service_with_values( [
                'cap_token'     => 'caption',
                'overlay_token' => 'overlay',
            ] )
        );

        $result = $resolver->apply(
            [ 'captionText' => 'old', 'overlayText' => 'old' ],
            [ 'captionText' => 'cap_token', 'overlayText' => 'overlay_token' ]
        );

        self::assertSame( 'caption', $result['captionText'] );
        self::assertSame( 'overlay', $result['overlayText'] );
    }

    public function test_resolves_per_item_text_by_id(): void {
        $resolver = new BindingResolver(
            $this->token_service_with_values( [ 'item1_token' => 'Resolved Item 1' ] )
        );

        $result = $resolver->apply(
            [
                'items' => [
                    [ 'id' => '1', 'text' => 'old' ],
                    [ 'id' => '2', 'text' => 'unaffected' ],
                ],
            ],
            [ 'item_text_1' => 'item1_token' ]
        );

        self::assertSame( 'Resolved Item 1', $result['items'][0]['text'] );
        self::assertSame( 'unaffected', $result['items'][1]['text'] );
    }

    public function test_item_title_alias_writes_to_text_field(): void {
        $resolver = new BindingResolver(
            $this->token_service_with_values( [ 'inf_token' => 'Infinite Menu Title' ] )
        );

        $result = $resolver->apply(
            [ 'items' => [ [ 'id' => 'abc', 'text' => 'old' ] ] ],
            [ 'item_title_abc' => 'inf_token' ]
        );

        self::assertSame( 'Infinite Menu Title', $result['items'][0]['text'] );
    }

    public function test_resolves_per_item_description_subtitle_handle_location(): void {
        $resolver = new BindingResolver(
            $this->token_service_with_values( [
                'desc_t' => 'desc-value',
                'sub_t'  => 'sub-value',
                'hand_t' => '@handle',
                'loc_t'  => 'Berlin',
            ] )
        );

        $result = $resolver->apply(
            [ 'items' => [ [ 'id' => 'x', 'description' => '', 'subtitle' => '', 'handle' => '', 'location' => '' ] ] ],
            [
                'item_desc_x'     => 'desc_t',
                'item_subtitle_x' => 'sub_t',
                'item_handle_x'   => 'hand_t',
                'item_location_x' => 'loc_t',
            ]
        );

        self::assertSame( 'desc-value', $result['items'][0]['description'] );
        self::assertSame( 'sub-value', $result['items'][0]['subtitle'] );
        self::assertSame( '@handle', $result['items'][0]['handle'] );
        self::assertSame( 'Berlin', $result['items'][0]['location'] );
    }

    public function test_resolves_index_based_binding(): void {
        $resolver = new BindingResolver(
            $this->token_service_with_values( [ 'idx_token' => 'value-at-2' ] )
        );

        $result = $resolver->apply(
            [
                'items' => [
                    [ 'subtitle' => 'a' ],
                    [ 'subtitle' => 'b' ],
                    [ 'subtitle' => 'c' ],
                ],
            ],
            [ 'items[2].subtitle' => 'idx_token' ]
        );

        self::assertSame( 'value-at-2', $result['items'][2]['subtitle'] );
        self::assertSame( 'a', $result['items'][0]['subtitle'] );
    }

    public function test_resolves_arbitrary_top_level_binding(): void {
        $resolver = new BindingResolver(
            $this->token_service_with_values( [ 'gen_token' => 'generic' ] )
        );

        $result = $resolver->apply(
            [ 'someCustomField' => 'fallback' ],
            [ 'someCustomField' => 'gen_token' ]
        );

        self::assertSame( 'generic', $result['someCustomField'] );
    }

    public function test_missing_token_leaves_raw_value_intact(): void {
        $resolver = new BindingResolver(
            $this->token_service_with_values( [] )
        );

        $result = $resolver->apply(
            [ 'headline' => 'keep me' ],
            [ 'headline' => 'token_that_does_not_exist' ]
        );

        self::assertSame( 'keep me', $result['headline'] );
    }

    public function test_empty_token_value_leaves_raw_value_intact(): void {
        $resolver = new BindingResolver(
            $this->token_service_with_values( [ 'empty_token' => '' ] )
        );

        $result = $resolver->apply(
            [ 'headline' => 'fallback' ],
            [ 'headline' => 'empty_token' ]
        );

        self::assertSame( 'fallback', $result['headline'] );
    }

    public function test_combines_top_level_per_id_and_index_bindings_in_one_pass(): void {
        $resolver = new BindingResolver(
            $this->token_service_with_values( [
                'hero'  => 'Hero',
                'item_a_text' => 'Item A',
                'item_idx1_loc' => 'Item-1 location',
            ] )
        );

        $result = $resolver->apply(
            [
                'headline' => 'old',
                'items'    => [
                    [ 'id' => 'a', 'text' => 'old-a', 'location' => 'old-a-loc' ],
                    [ 'id' => 'b', 'text' => 'old-b', 'location' => 'old-b-loc' ],
                ],
            ],
            [
                'headline'              => 'hero',
                'item_text_a'           => 'item_a_text',
                'items[1].location'     => 'item_idx1_loc',
            ]
        );

        self::assertSame( 'Hero', $result['headline'] );
        self::assertSame( 'Item A', $result['items'][0]['text'] );
        self::assertSame( 'old-a-loc', $result['items'][0]['location'] );
        self::assertSame( 'old-b', $result['items'][1]['text'] );
        self::assertSame( 'Item-1 location', $result['items'][1]['location'] );
    }
}
