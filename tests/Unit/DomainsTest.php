<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Shopify\Domains;

final class DomainsTest extends TestCase {
	public function test_custom_domains_from_shopify_are_used(): void {
		$this->assertSame(
			[ 'storefront' => 'https://shop-demo.redeemid.com', 'account' => 'https://account-demo.redeemid.com' ],
			Domains::shape( [ 'storefront' => 'https://shop-demo.redeemid.com/', 'account' => 'https://account-demo.redeemid.com' ], 'hf96dz-hn.myshopify.com' )
		);
	}

	public function test_missing_or_unsafe_answers_fall_back_to_myshopify(): void {
		$fallback = [ 'storefront' => 'https://hf96dz-hn.myshopify.com', 'account' => 'https://hf96dz-hn.myshopify.com/account' ];

		$this->assertSame( $fallback, Domains::shape( [], 'hf96dz-hn.myshopify.com' ) );
		$this->assertSame( $fallback, Domains::shape( [ 'storefront' => 'http://shop.example.com', 'account' => 'javascript:alert(1)' ], 'hf96dz-hn.myshopify.com' ) );
		$this->assertSame( $fallback, Domains::shape( [ 'storefront' => 'https://shop.example.com/?x=1', 'account' => [ 'x' ] ], 'hf96dz-hn.myshopify.com' ) );
	}

	public function test_account_url_on_shopify_hosted_path_is_kept(): void {
		$this->assertSame( 'https://shopify.com/100884545843/account', Domains::shape( [ 'account' => 'https://shopify.com/100884545843/account' ], 'a.myshopify.com' )['account'] );
	}
}
