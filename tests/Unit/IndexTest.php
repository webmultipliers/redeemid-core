<?php

namespace WP2_Shopify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2_Shopify\Access\Context;
use WP2_Shopify\Access\Index;
use WP2_Shopify\Access\Level;
use WP2_Shopify\Access\Levels;
use WP2_Shopify\Access\Checker;
use WP2_Shopify\Access\EffectiveRule;
use WP2_Shopify\Access\Surfaces;
use WP2_Shopify\Access\Teaser;
use WP2_Shopify\Access\TermRule;
use WP2_Shopify\Access\Viewer;
use WP2_Shopify\Entitlement\Evaluator;

final class IndexTest extends TestCase {
	private const NOW = 1800000000;

	public function test_no_dates_means_no_next_change(): void {
		$this->assertNull( Index::next_change( new Context( item_mode: Context::ITEM_LEVEL, item_levels: [ 'gold' ] ), self::NOW ) );
	}

	public function test_next_change_is_the_soonest_future_date(): void {
		$context = new Context(
			terms: [ new TermRule( 'Tag', 'Free', TermRule::OPENS, [], self::NOW + 500 ), new TermRule( 'Category', 'Gold', TermRule::REQUIRES, [ 'gold' ] ) ],
			locked_until: self::NOW + 900,
			public_until: self::NOW - 10,
			published: self::NOW - 100,
			type_public_after: 300,
		);

		$this->assertSame( self::NOW + 200, Index::next_change( $context, self::NOW ), 'Archive window, the soonest' );
		$this->assertSame( self::NOW + 500, Index::next_change( $context, self::NOW + 200 ), 'Then the free tag' );
		$this->assertNull( Index::next_change( $context, self::NOW + 900 ), 'Nothing left once every date has passed' );
	}

	public function test_strip_removes_a_nested_block_and_its_placeholder(): void {
		$members = [ 'blockName' => 'wp2-shopify/membership-gate-content', 'attrs' => [], 'innerBlocks' => [ [ 'blockName' => 'core/paragraph', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<p>SECRET</p>', 'innerContent' => [ '<p>SECRET</p>' ] ] ], 'innerHTML' => '', 'innerContent' => [ null ] ];
		$teaser  = [ 'blockName' => 'wp2-shopify/membership-gate-fallback', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '<p>Join</p>', 'innerContent' => [ '<p>Join</p>' ] ];
		$gate    = [ 'blockName' => 'wp2-shopify/membership-gate', 'attrs' => [], 'innerBlocks' => [ $members, $teaser ], 'innerHTML' => '<div></div>', 'innerContent' => [ '<div>', null, '', null, '</div>' ] ];
		$group   = [ 'blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [ $gate ], 'innerHTML' => '<div></div>', 'innerContent' => [ '<div>', null, '</div>' ] ];

		$out = Teaser::strip( [ $group ], 'wp2-shopify/membership-gate-content' );

		$this->assertStringNotContainsString( 'SECRET', (string) json_encode( $out ) );
		$kept = $out[0]['innerBlocks'][0];
		$this->assertSame( [ 'wp2-shopify/membership-gate-fallback' ], array_column( $kept['innerBlocks'], 'blockName' ) );
		$this->assertSame( [ '<div>', '', null, '</div>' ], $kept['innerContent'], 'One placeholder per kept inner block' );
	}

	public function test_search_sql_points_content_at_public_text(): void {
		$sql = "((wp_posts.post_title LIKE '%x%') OR (wp_posts.post_excerpt LIKE '%x%') OR (wp_posts.post_content LIKE '%x%'))";

		$this->assertSame(
			"((wp_posts.post_title LIKE '%x%') OR (wp_posts.post_excerpt LIKE '%x%') OR (COALESCE(wp2s.meta_value, wp_posts.post_content) LIKE '%x%'))",
			Surfaces::public_column( $sql, 'wp_posts' )
		);
	}

	public function test_every_term_must_match(): void {
		$this->assertTrue( Surfaces::matches( 'Council clears the bridge', [ 'council', 'BRIDGE' ] ) );
		$this->assertFalse( Surfaces::matches( 'Council clears the bridge', [ 'council', 'secret' ] ) );
		$this->assertTrue( Surfaces::matches( 'anything', [] ) );
	}

	public function test_preview_member_holds_exactly_the_level(): void {
		$levels  = Levels::from_config(
			[
				'silver' => [ 'label' => 'Silver', 'groups' => [ [ 'tags' => [ 'silver-member' ] ] ] ],
				'gold'   => [ 'label' => 'Gold', 'groups' => [ [ 'tags' => [ 'gold-member' ] ], [ 'tags' => [ 'vip', 'press' ], 'match' => 'all' ] ] ],
			]
		);
		$checker = new Checker( $levels, new Evaluator() );
		$gold    = Viewer::member_for( $levels->get( 'gold' ), self::NOW );

		$this->assertTrue( $checker->check( new EffectiveRule( [ [ 'gold' ] ], 'x' ), $gold, self::NOW )->granted() );
		$this->assertFalse( $checker->check( new EffectiveRule( [ [ 'silver' ] ], 'x' ), $gold, self::NOW )->granted() );
		$this->assertTrue( $checker->check( new EffectiveRule( [ [ Level::SIGNED_IN ] ], 'x' ), Viewer::member_for( $levels->get( Level::SIGNED_IN ), self::NOW ), self::NOW )->granted() );
		$this->assertFalse( Viewer::member_for( $levels->get( Level::PUBLIC ), self::NOW )->signed_in );
	}
}
