<?php

namespace WP2_Shopify\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP2_Shopify\Commerce\CartBridge;

final class CartBridgeTest extends TestCase {
	public static function carts(): array {
		return [
			'with key'         => [ 'gid://shopify/Cart/Z2NwLXVzLWVhc3QxOjAxSjk4?key=4f1b2c3d', true ],
			'without key'      => [ 'gid://shopify/Cart/Z2NwLXVzLWVhc3QxOjAxSjk4', true ],
			'padded'           => [ "  gid://shopify/Cart/abc_123-x \n", true ],
			'empty'            => [ '', false ],
			'other type'       => [ 'gid://shopify/Customer/123', false ],
			'extra query'      => [ 'gid://shopify/Cart/abc?key=1&x=2', false ],
			'script injection' => [ 'gid://shopify/Cart/abc"><script>', false ],
			'bare token'       => [ 'Z2NwLXVzLWVhc3QxOjAxSjk4', false ],
		];
	}

	#[DataProvider( 'carts' )]
	public function test_cart_gid( string $raw, bool $valid ): void {
		$this->assertSame( $valid ? trim( $raw ) : '', CartBridge::cart_gid( $raw ) );
	}
}
