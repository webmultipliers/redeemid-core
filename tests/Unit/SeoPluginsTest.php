<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Integrations\SeoPlugins;

final class SeoPluginsTest extends TestCase {
	public function test_paywall_markup_joins_the_page_entity(): void {
		$graph = [
			[ '@type' => 'WebSite', '@id' => 'https://example.com/#/schema/WebSite' ],
			[ '@type' => 'WebPage', '@id' => 'https://example.com/story/' ],
		];

		$marked = SeoPlugins::mark_paywall( $graph );

		$this->assertArrayNotHasKey( 'isAccessibleForFree', $marked[0] );
		$this->assertFalse( $marked[1]['isAccessibleForFree'] );
		$this->assertSame( '.redeemid-paywall', $marked[1]['hasPart']['cssSelector'] );
	}

	public function test_other_page_types_count_and_a_graph_without_one_is_unchanged(): void {
		$this->assertFalse( SeoPlugins::mark_paywall( [ [ '@type' => [ 'CollectionPage' ] ] ] )[0]['isAccessibleForFree'] );

		$graph = [ [ '@type' => 'Organization' ] ];
		$this->assertSame( $graph, SeoPlugins::mark_paywall( $graph ) );
	}
}
