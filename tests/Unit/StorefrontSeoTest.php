<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Commerce\Seo;

final class StorefrontSeoTest extends TestCase {
	private const PAGE = [
		'title'       => 'Gold membership',
		'description' => 'A year of Gold.',
		'url'         => 'https://example.com/products/gold/',
		'image'       => [ 'url' => 'https://cdn.shopify.com/gold.png' ],
		'product'     => [ 'title' => 'Gold membership' ],
	];

	public function test_the_seo_framework_tags_describe_the_storefront_page(): void {
		$tags = [
			'canonical' => [ 'tag' => 'link', 'attributes' => [ 'rel' => 'canonical', 'href' => 'https://example.com/' ] ],
			'og:title'  => [ 'attributes' => [ 'property' => 'og:title', 'content' => 'Home' ] ],
			'og:locale' => [ 'attributes' => [ 'property' => 'og:locale', 'content' => 'en_US' ] ],
		];

		$out = Seo::tsf_render_data( $tags, self::PAGE );

		$this->assertSame( [ 'rel' => 'canonical', 'href' => self::PAGE['url'] ], $out['canonical']['attributes'] );
		$this->assertSame( 'link', $out['canonical']['tag'] );
		$this->assertSame( 'Gold membership', $out['og:title']['attributes']['content'] );
		$this->assertSame( 'A year of Gold.', $out['description']['attributes']['content'] );
		$this->assertSame( 'product', $out['og:type']['attributes']['content'] );
		$this->assertSame( self::PAGE['url'], $out['og:url']['attributes']['content'] );
		$this->assertSame( 'https://cdn.shopify.com/gold.png', $out['og:image']['attributes']['content'] );
		$this->assertSame( 'en_US', $out['og:locale']['attributes']['content'] );
	}

	public function test_an_empty_description_drops_the_tags_the_seo_framework_guessed(): void {
		$tags = [ 'description' => [ 'attributes' => [ 'name' => 'description', 'content' => 'Something else' ] ] ];

		$out = Seo::tsf_render_data( $tags, [ 'description' => '', 'image' => null, 'product' => null ] + self::PAGE );

		$this->assertArrayNotHasKey( 'description', $out );
		$this->assertArrayNotHasKey( 'og:description', $out );
		$this->assertSame( 'website', $out['og:type']['attributes']['content'] );
	}
}
