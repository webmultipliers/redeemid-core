<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RedeemID\Commerce\Cart;
use RedeemID\Commerce\Pages;
use RedeemID\Commerce\Seo;
use RedeemID\Shopify\Storefront;

final class StorefrontPagesTest extends TestCase {
	public static function paths(): array {
		return [
			'plain'                 => [ 'products', 'products' ],
			'slashes and case'      => [ ' /Shop/P/ ', 'shop/p' ],
			'unsafe characters'     => [ 'sh op?<x>/ca$rt', 'shopx/cart' ],
			'at most three'         => [ 'a/b/c/d', 'a/b/c' ],
			'nothing usable'        => [ '/?/', '' ],
			'hyphen and underscore' => [ 'my-shop/the_cart', 'my-shop/the_cart' ],
		];
	}

	#[DataProvider( 'paths' )]
	public function test_clean_path( string $raw, string $expected ): void {
		$this->assertSame( $expected, Pages::clean_path( $raw ) );
	}

	public function test_rules_for_each_kind(): void {
		$rules = Pages::rules_for( [ Pages::PRODUCT => 'shop/p', Pages::COLLECTION => 'collections', Pages::CART => 'basket' ] );

		$this->assertSame(
			[
				'^shop/p/([^/]+)/?$'      => 'index.php?redeemid_product=$matches[1]',
				'^collections/([^/]+)/?$' => 'index.php?redeemid_collection=$matches[1]',
				'^basket/?$'              => 'index.php?redeemid_cart=1',
			],
			$rules
		);
	}

	public function test_default_rules_share_keys_with_shopifys_plugin(): void {
		// Shopify's plugin 1.0.0 uses these exact keys, which is how RedeemID stands down or takes precedence.
		$keys = array_keys( Pages::rules_for( [ Pages::PRODUCT => 'products', Pages::COLLECTION => 'collections', Pages::CART => 'cart' ] ) );

		$this->assertSame( [ '^products/([^/]+)/?$', '^collections/([^/]+)/?$', '^cart/?$' ], $keys );
	}

	public function test_cart_inputs(): void {
		$this->assertSame( str_repeat( 'ab', 16 ), Cart::clean_key( str_repeat( 'ab', 16 ) ) );
		$this->assertSame( '', Cart::clean_key( 'gid://shopify/Cart/abc' ), 'never a cart ID' );
		$this->assertSame( '', Cart::clean_key( str_repeat( 'AB', 16 ) ) );

		$this->assertSame( 1, Cart::clean_quantity( 0 ) );
		$this->assertSame( 0, Cart::clean_quantity( 0, 0 ) );
		$this->assertSame( Cart::MAX_QUANTITY, Cart::clean_quantity( 5000 ) );

		$this->assertSame( 'gid://shopify/CartLine/1a2b-3c?cart=Z2NwLXVz', Cart::line_gid( ' gid://shopify/CartLine/1a2b-3c?cart=Z2NwLXVz ' ) );
		$this->assertSame( '', Cart::line_gid( 'gid://shopify/ProductVariant/1' ) );
	}

	public function test_same_site(): void {
		$this->assertTrue( Cart::same_site( '' ), 'browsers without the header' );
		$this->assertTrue( Cart::same_site( 'same-origin' ) );
		$this->assertTrue( Cart::same_site( 'none' ), 'typed or bookmarked' );
		$this->assertFalse( Cart::same_site( 'cross-site' ) );
	}

	public function test_shape_cart(): void {
		$cart = Storefront::shape_cart(
			[
				'id'            => 'gid://shopify/Cart/c1?key=k',
				'checkoutUrl'   => 'https://shop.example.com/cart/c/c1',
				'totalQuantity' => 3,
				'cost'          => [ 'subtotalAmount' => [ 'amount' => '58.0', 'currencyCode' => 'USD' ], 'totalAmount' => [ 'amount' => '58.0', 'currencyCode' => 'USD' ] ],
				'discountCodes' => [ [ 'code' => 'WELCOME', 'applicable' => false ] ],
				'lines'         => [
					'nodes' => [
						[
							'id'                    => 'gid://shopify/CartLine/1?cart=c1',
							'quantity'              => 2,
							'cost'                  => [ 'totalAmount' => [ 'amount' => '36.0', 'currencyCode' => 'USD' ] ],
							'sellingPlanAllocation' => null,
							'merchandise'           => [
								'id'      => 'gid://shopify/ProductVariant/9',
								'title'   => 'Default Title',
								'image'   => null,
								'product' => [ 'handle' => 'notebook', 'title' => 'Notebook', 'featuredImage' => [ 'url' => 'https://cdn.shopify.com/n.jpg', 'altText' => null, 'width' => 10, 'height' => 10 ] ],
							],
						],
						[ 'id' => 'gid://shopify/CartLine/2', 'merchandise' => [] ],
					],
				],
			]
		);

		$this->assertSame( 3, $cart['count'] );
		$this->assertCount( 1, $cart['lines'], 'lines without merchandise are dropped' );
		$this->assertSame( '', $cart['lines'][0]['option'], 'Default Title is no option' );
		$this->assertSame( 'https://cdn.shopify.com/n.jpg', $cart['lines'][0]['image']['url'], 'falls back to the product image' );
		$this->assertSame( [ [ 'code' => 'WELCOME', 'applies' => false ] ], $cart['codes'] );
		$this->assertSame( '', Storefront::shape_cart( [ 'checkoutUrl' => 'javascript:x' ] )['checkout'] );
	}

	public function test_excerpt(): void {
		$this->assertSame( 'Short and sweet.', Seo::excerpt( "  Short\n and   sweet. " ) );

		$long = Seo::excerpt( str_repeat( 'word ', 60 ), 40 );
		$this->assertLessThanOrEqual( 40, mb_strlen( $long ) );
		$this->assertStringEndsWith( 'word…', $long, 'cut at a word' );
	}

	public function test_product_schema(): void {
		$product = Storefront::shape_product(
			[
				'id'            => 'gid://shopify/Product/1',
				'handle'        => 'tee',
				'title'         => 'Tee',
				'description'   => '',
				'featuredImage' => null,
				'variants'      => [
					'nodes' => [
						[ 'id' => 'gid://shopify/ProductVariant/1', 'title' => 'Small', 'availableForSale' => true, 'price' => [ 'amount' => '20.0', 'currencyCode' => 'USD' ], 'compareAtPrice' => null ],
						[ 'id' => 'gid://shopify/ProductVariant/2', 'title' => 'Large', 'availableForSale' => false, 'price' => [ 'amount' => '22.0', 'currencyCode' => 'USD' ], 'compareAtPrice' => null ],
					],
				],
			]
		);

		$schema = Seo::product_schema( $product, 'https://example.com/products/tee/' );

		$this->assertSame( 'Product', $schema['@type'] );
		$this->assertArrayNotHasKey( 'description', $schema, 'empty fields are left out' );
		$this->assertArrayNotHasKey( 'image', $schema );
		$this->assertSame( [ '20.0', 'USD', 'https://schema.org/InStock' ], [ $schema['offers'][0]['price'], $schema['offers'][0]['priceCurrency'], $schema['offers'][0]['availability'] ] );
		$this->assertSame( 'https://schema.org/OutOfStock', $schema['offers'][1]['availability'] );
	}
}
