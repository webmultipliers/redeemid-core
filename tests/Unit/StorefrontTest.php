<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Commerce\BuyNow;
use RedeemID\Shopify\Discovery;
use RedeemID\Shopify\Storefront;

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

	public function test_buy_now_rate_limit_is_per_buyer_ip(): void {
		$this->assertNotSame( BuyNow::rate_key( '203.0.113.1' ), BuyNow::rate_key( '203.0.113.2' ) );
		$this->assertSame( BuyNow::rate_key( '203.0.113.1' ), BuyNow::rate_key( '203.0.113.1' ) );
		$this->assertLessThanOrEqual( 172, strlen( BuyNow::rate_key( '2001:db8::1' ) ), 'Transient names stay under the options table limit.' );
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

	private static function summary_node( array $overrides = [] ): array {
		return $overrides + [
			'handle'              => 'field-notebook',
			'title'               => 'Field notebook',
			'availableForSale'    => true,
			'requiresSellingPlan' => false,
			'onlineStoreUrl'      => 'https://shop.example.com/products/field-notebook',
			'featuredImage'       => [ 'url' => 'https://cdn.shopify.com/n.jpg', 'altText' => null, 'width' => 800, 'height' => 600 ],
			'priceRange'          => [ 'minVariantPrice' => [ 'amount' => '18.0', 'currencyCode' => 'USD' ], 'maxVariantPrice' => [ 'amount' => '18.0', 'currencyCode' => 'USD' ] ],
			'compareAtPriceRange' => [ 'minVariantPrice' => [ 'amount' => '0.0', 'currencyCode' => 'USD' ] ],
			'variants'            => [ 'nodes' => [ [ 'id' => 'gid://shopify/ProductVariant/21', 'availableForSale' => true ] ] ],
		];
	}

	public function test_summary_sells_a_single_variant_straight_from_the_card(): void {
		$item = Storefront::shape_summary( self::summary_node() );

		$this->assertSame( 'gid://shopify/ProductVariant/21', $item['variant'] );
		$this->assertFalse( $item['from'] );
		$this->assertNull( $item['compareAt'], 'a zero compare-at price is no discount' );
		$this->assertSame( '', $item['image']['alt'] );
		$this->assertSame( 'https://shop.example.com/products/field-notebook', $item['url'] );
	}

	public function test_summary_needs_a_choice_for_options_plans_or_sold_out(): void {
		$two = [ 'nodes' => [ [ 'id' => 'gid://shopify/ProductVariant/21', 'availableForSale' => true ], [ 'id' => 'gid://shopify/ProductVariant/22', 'availableForSale' => true ] ] ];

		$this->assertSame( '', Storefront::shape_summary( self::summary_node( [ 'variants' => $two ] ) )['variant'], 'options' );
		$this->assertSame( '', Storefront::shape_summary( self::summary_node( [ 'requiresSellingPlan' => true ] ) )['variant'], 'selling plan' );
		$this->assertSame( '', Storefront::shape_summary( self::summary_node( [ 'variants' => [ 'nodes' => [ [ 'id' => 'gid://shopify/ProductVariant/21', 'availableForSale' => false ] ] ] ] ) )['variant'], 'sold out' );
	}

	public function test_summary_price_range_and_unsafe_url(): void {
		$item = Storefront::shape_summary(
			self::summary_node(
				[
					'onlineStoreUrl'      => 'javascript:alert(1)',
					'priceRange'          => [ 'minVariantPrice' => [ 'amount' => '10.0', 'currencyCode' => 'USD' ], 'maxVariantPrice' => [ 'amount' => '25.0', 'currencyCode' => 'USD' ] ],
					'compareAtPriceRange' => [ 'minVariantPrice' => [ 'amount' => '12.0', 'currencyCode' => 'USD' ] ],
				]
			)
		);

		$this->assertTrue( $item['from'] );
		$this->assertSame( [ 'amount' => '12.0', 'currency' => 'USD' ], $item['compareAt'] );
		$this->assertSame( '', $item['url'] );
	}

	public function test_shape_collection_pages(): void {
		$collection = Storefront::shape_collection(
			[
				'handle'   => 'stationery',
				'title'    => 'Stationery',
				'products' => [
					'nodes'    => [ self::summary_node(), [ 'title' => 'no handle' ] ],
					'pageInfo' => [ 'hasNextPage' => true, 'hasPreviousPage' => false, 'startCursor' => 'eyJzdGFydCI6MX0=', 'endCursor' => 'eyJsYXN0IjoxMn0=' ],
				],
			]
		);

		$this->assertCount( 1, $collection['products'] );
		$this->assertSame( 'eyJsYXN0IjoxMn0=', $collection['next'] );
		$this->assertSame( '', $collection['previous'], 'no previous page' );
	}

	public function test_cursor_accepts_only_opaque_tokens(): void {
		$this->assertSame( 'eyJsYXN0IjoxMn0=', Storefront::cursor( ' eyJsYXN0IjoxMn0= ' ) );
		$this->assertSame( '', Storefront::cursor( '"><script>' ) );
		$this->assertSame( '', Storefront::cursor( str_repeat( 'a', 513 ) ) );
		$this->assertSame( '', Storefront::cursor( '' ) );
	}

	public function test_has_options(): void {
		$raw = self::raw();

		$this->assertTrue( Storefront::shape_product( $raw )['has_options'], 'two variants' );

		$raw['variants']['nodes'] = [ $raw['variants']['nodes'][1] ];
		$raw['options']           = [ [ 'name' => 'Title' ] ];
		$this->assertFalse( Storefront::shape_product( $raw )['has_options'], 'default title only' );
	}
}
