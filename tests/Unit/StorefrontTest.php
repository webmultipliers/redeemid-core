<?php

namespace WP2_Shopify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2_Shopify\Commerce\BuyNow;
use WP2_Shopify\Shopify\Discovery;
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

	public function test_title_query_uses_prefix_terms(): void {
		$this->assertNull( Storefront::title_query( '  ' ) );
		$this->assertSame( 'title:gold*', Storefront::title_query( 'gold' ) );
		$this->assertSame( 'title:gold* title:memb*', Storefront::title_query( ' gold   memb ' ) );
	}

	public function test_customer_api_url_keeps_the_pinned_version(): void {
		$this->assertSame(
			'https://shopify.com/100/account/customer/api/2026-10/graphql',
			Discovery::pin_version( 'https://shopify.com/100/account/customer/api/2027-01/graphql', '2026-10' )
		);
		$this->assertSame( 'https://example.test/other', Discovery::pin_version( 'https://example.test/other', '2026-10' ) );
	}

	private static function subscription(): array {
		$raw = self::raw();

		$raw['requiresSellingPlan'] = true;
		$raw['variants']['nodes'][1]['sellingPlanAllocations']['nodes'] = [
			[ 'sellingPlan' => [ 'id' => 'gid://shopify/SellingPlan/7', 'name' => 'Billed yearly', 'recurringDeliveries' => true ], 'priceAdjustments' => [ [ 'price' => [ 'amount' => '81.0', 'currencyCode' => 'USD' ], 'compareAtPrice' => [ 'amount' => '90.0', 'currencyCode' => 'USD' ] ] ] ],
			[ 'sellingPlan' => [ 'id' => 'gid://shopify/SellingPlan/8', 'name' => 'Billed monthly', 'recurringDeliveries' => true ], 'priceAdjustments' => [] ],
			[ 'sellingPlan' => null ],
		];

		return $raw;
	}

	public function test_shape_keeps_selling_plans_with_their_prices(): void {
		$variant = Storefront::shape_product( self::subscription() )['variants'][1];

		$this->assertCount( 2, $variant['plans'] );
		$this->assertSame( [ 'amount' => '81.0', 'currency' => 'USD' ], $variant['plans'][0]['price'] );
		$this->assertNull( $variant['plans'][1]['price'] );
		$this->assertSame( [], Storefront::shape_product( self::raw() )['variants'][0]['plans'] );
	}

	public function test_pick_plan(): void {
		$product = Storefront::shape_product( self::subscription() );
		$variant = $product['variants'][1];

		$this->assertSame( 'Billed monthly', Storefront::pick_plan( $product, $variant, '8' )['name'] );
		$this->assertSame( 'Billed yearly', Storefront::pick_plan( $product, $variant )['name'], 'subscription-only: first plan' );

		$product['requires_plan'] = false;
		$this->assertNull( Storefront::pick_plan( $product, $variant ), 'one-time purchase unless asked' );
		$this->assertNull( Storefront::pick_plan( $product, $variant, '999' ) );
	}

	public function test_buy_now_accepts_only_selling_plan_ids(): void {
		$this->assertSame( 'gid://shopify/SellingPlan/7', BuyNow::selling_plan_gid( '7' ) );
		$this->assertSame( 'gid://shopify/SellingPlan/7', BuyNow::selling_plan_gid( 'gid://shopify/SellingPlan/7' ) );
		$this->assertSame( '', BuyNow::selling_plan_gid( 'gid://shopify/ProductVariant/7' ) );
		$this->assertSame( '', BuyNow::selling_plan_gid( '' ) );
	}

	public function test_in_context_adds_the_country_directive(): void {
		$this->assertSame( "query Q(\$h: String!) @inContext(country: CA) {\n x }", Storefront::in_context( "query Q(\$h: String!) {\n x }", 'CA' ) );
		$this->assertSame( 'mutation M @inContext(country: GB) { y }', Storefront::in_context( 'mutation M { y }', 'GB' ) );
		$this->assertSame( 'query Q { z }', Storefront::in_context( 'query Q { z }', '' ) );
	}
}
