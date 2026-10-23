<?php

namespace WP2_Shopify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2_Shopify\Commerce\BuyNow;
use WP2_Shopify\Shopify\Storefront;

final class StorefrontTest extends TestCase {
	private static function raw(): array {
		return [
			'id'            => 'gid://shopify/Product/1',
			'handle'        => 'gold-membership',
			'title'         => 'Gold membership',
			'description'   => 'Everything.',
			'featuredImage' => null,
			'variants'      => [
				'nodes' => [
					[ 'id' => 'gid://shopify/ProductVariant/11', 'title' => 'Monthly', 'availableForSale' => false, 'price' => [ 'amount' => '9.0', 'currencyCode' => 'USD' ], 'compareAtPrice' => null ],
					[ 'id' => 'gid://shopify/ProductVariant/12', 'title' => 'Yearly', 'availableForSale' => true, 'price' => [ 'amount' => '90.0', 'currencyCode' => 'USD' ], 'compareAtPrice' => [ 'amount' => '108.0', 'currencyCode' => 'USD' ] ],
					[ 'title' => 'broken node without id' ],
				],
			],
		];
	}

	public function test_shape_keeps_prices_as_returned_and_drops_bad_nodes(): void {
		$product = Storefront::shape_product( self::raw() );

		$this->assertCount( 2, $product['variants'] );
		$this->assertSame( [ 'amount' => '90.0', 'currency' => 'USD' ], $product['variants'][1]['price'] );
		$this->assertNull( $product['variants'][0]['compareAt'] );
		$this->assertNull( $product['image'] );
	}

	public function test_pick_variant(): void {
		$product = Storefront::shape_product( self::raw() );

		$this->assertSame( 'gid://shopify/ProductVariant/12', Storefront::pick_variant( $product )['id'], 'first available' );
		$this->assertSame( 'gid://shopify/ProductVariant/11', Storefront::pick_variant( $product, '11' )['id'], 'numeric id, even when sold out' );
		$this->assertSame( 'gid://shopify/ProductVariant/11', Storefront::pick_variant( $product, 'gid://shopify/ProductVariant/11' )['id'] );
		$this->assertSame( 'gid://shopify/ProductVariant/12', Storefront::pick_variant( $product, '999' )['id'], 'unknown id falls back' );
		$this->assertNull( Storefront::pick_variant( [ 'variants' => [] ] ) );
	}

	public function test_pick_variant_falls_back_to_first_when_all_sold_out(): void {
		$raw = self::raw();
		$raw['variants']['nodes'][1]['availableForSale'] = false;

		$this->assertSame( 'gid://shopify/ProductVariant/11', Storefront::pick_variant( Storefront::shape_product( $raw ) )['id'] );
	}

	public function test_buy_now_accepts_only_variant_ids(): void {
		$this->assertSame( 'gid://shopify/ProductVariant/42', BuyNow::variant_gid( '42' ) );
		$this->assertSame( 'gid://shopify/ProductVariant/42', BuyNow::variant_gid( ' gid://shopify/ProductVariant/42 ' ) );
		$this->assertSame( '', BuyNow::variant_gid( 'gid://shopify/Product/42' ) );
		$this->assertSame( '', BuyNow::variant_gid( '42; drop' ) );
		$this->assertSame( '', BuyNow::variant_gid( '' ) );
	}
}
