<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Api\Events;
use RedeemID\Commerce\Attribution;

final class AttributionTest extends TestCase {
	public function test_a_post_gives_its_path_and_id(): void {
		$this->assertSame(
			[
				[ 'key' => Attribution::SOURCE, 'value' => '/reports/harbor-fees/' ],
				[ 'key' => Attribution::POST, 'value' => '42' ],
			],
			Attribution::attributes( '/reports/harbor-fees/', 42 )
		);
	}

	public function test_a_page_that_is_not_a_post_gives_its_path_only(): void {
		$this->assertSame( [ [ 'key' => Attribution::SOURCE, 'value' => '/shop/' ] ], Attribution::attributes( '/shop/', 0 ) );
		$this->assertSame( [], Attribution::attributes( '', 0 ) );
	}

	public function test_keys_start_with_an_underscore_so_checkout_hides_them(): void {
		$this->assertStringStartsWith( '_', Attribution::SOURCE );
		$this->assertStringStartsWith( '_', Attribution::POST );
	}

	public function test_clean_drops_malformed_pairs_and_keeps_one_per_key(): void {
		$this->assertSame(
			[
				[ 'key' => 'a', 'value' => '2' ],
				[ 'key' => 'b', 'value' => '3' ],
			],
			Attribution::clean(
				[
					[ 'key' => 'a', 'value' => '1' ],
					'nonsense',
					[ 'key' => '', 'value' => 'x' ],
					[ 'key' => 'c', 'value' => '' ],
					[ 'key' => 'd', 'value' => [ 'nested' ] ],
					[ 'key' => 'a', 'value' => '2' ],
					[ 'key' => 'b', 'value' => 3 ],
				]
			)
		);
	}

	public function test_clean_cuts_long_values(): void {
		$this->assertSame( 255, strlen( Attribution::clean( [ [ 'key' => 'k', 'value' => str_repeat( 'x', 400 ) ] ] )[0]['value'] ) );
	}

	public function test_checkout_started_carries_the_post(): void {
		$event = Events::envelope( Events::CHECKOUT_STARTED, [ 'variant' => 'gid://shopify/ProductVariant/1', 'post_id' => 42 ], 1 );

		$this->assertSame( 42, $event['props']['post_id'] );
	}
}
