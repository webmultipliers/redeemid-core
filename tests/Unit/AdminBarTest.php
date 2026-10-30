<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Admin\AdminBar;

final class AdminBarTest extends TestCase {
	public function test_admin_url_uses_the_store_handle(): void {
		$this->assertSame( 'https://admin.shopify.com/store/example-store', AdminBar::admin_url( 'example-store.myshopify.com' ) );
		$this->assertSame( 'https://admin.shopify.com/store/example-store/products/42', AdminBar::admin_url( 'Example-Store.myshopify.com', '/products/42' ) );
	}

	public function test_admin_url_is_empty_for_other_domains(): void {
		$this->assertSame( '', AdminBar::admin_url( '' ) );
		$this->assertSame( '', AdminBar::admin_url( 'shop.example.com', 'orders' ) );
		$this->assertSame( '', AdminBar::admin_url( 'evil.com/x.myshopify.com' ) );
	}

	public function test_numeric_id_from_gid(): void {
		$this->assertSame( '8123', AdminBar::numeric_id( 'gid://shopify/Product/8123' ) );
		$this->assertSame( '55', AdminBar::numeric_id( 'gid://shopify/Collection/55' ) );
		$this->assertSame( '', AdminBar::numeric_id( 'gid://shopify/Product/' ) );
		$this->assertSame( '', AdminBar::numeric_id( '' ) );
	}
}
