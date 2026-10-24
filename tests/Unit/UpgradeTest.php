<?php

namespace WP2_Shopify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2_Shopify\Blocks\Gate;
use WP2_Shopify\Blocks\Upgrade;

final class UpgradeTest extends TestCase {
	public function test_plain_string_urls_become_link_values(): void {
		$parsed = Upgrade::block( [ 'blockName' => Gate::PARENT, 'attrs' => [ 'redirectUrl' => '/join/', 'fallbackCtaUrl' => [ 'url' => '/plans/' ] ] ] );

		$this->assertSame( [ 'url' => '/join/' ], $parsed['attrs']['redirectUrl'] );
		$this->assertSame( [ 'url' => '/plans/' ], $parsed['attrs']['fallbackCtaUrl'], 'Values already in the new shape are left alone.' );
	}

	public function test_nested_editor_values_are_upgraded_too(): void {
		$parsed = Upgrade::block( [ 'blockName' => 'wp2-shopify/auth-action', 'attrs' => [ 'blockstudio' => [ 'attributes' => [ 'redirectAfterLogout' => '/' ] ] ] ] );

		$this->assertSame( [ 'url' => '/' ], $parsed['attrs']['blockstudio']['attributes']['redirectAfterLogout'] );
	}

	public function test_a_bare_product_handle_becomes_a_select_value(): void {
		$parsed = Upgrade::block( [ 'blockName' => 'wp2-shopify/plan-card', 'attrs' => [ 'productHandle' => 'gold-membership' ] ] );

		$this->assertSame( [ 'value' => 'gold-membership', 'label' => 'gold-membership' ], $parsed['attrs']['productHandle'] );
	}

	public function test_other_blocks_are_untouched(): void {
		$block = [ 'blockName' => 'core/paragraph', 'attrs' => [ 'redirectUrl' => '/x/' ] ];

		$this->assertSame( $block, Upgrade::block( $block ) );
	}

	public function test_url_and_option_read_both_shapes(): void {
		$this->assertSame( '/join/', Gate::url( [ 'url' => ' /join/ ', 'title' => 'Join' ] ) );
		$this->assertSame( '/join/', Gate::url( '/join/' ) );
		$this->assertSame( '', Gate::url( null ) );
		$this->assertSame( 'gold', Gate::option( [ 'value' => 'gold', 'label' => 'Gold' ], 'x' ) );
		$this->assertSame( 'gold', Gate::option( 'gold', 'x' ) );
		$this->assertSame( 'x', Gate::option( '', 'x' ) );
	}
}
