<?php

namespace WP2_Shopify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2_Shopify\Access\Context;
use WP2_Shopify\Access\Rules;
use WP2_Shopify\Access\TermRule;
use WP2_Shopify\Access\TermRules;

final class RulesTest extends TestCase {
	public function test_empty_config_gates_every_type_with_no_rule(): void {
		$rules = Rules::from_array( [] );

		$this->assertSame( Context::CONFLICT_MOST, $rules->conflict );
		$this->assertSame( [], $rules->site_levels );
		$this->assertSame( [ 'enabled' => true, 'levels' => [], 'teaser' => [ 'mode' => 'blocks', 'count' => 2 ], 'public_after_days' => 0 ], $rules->type( 'post' ) );
	}

	public function test_taxonomy_defaults_inherit_only_when_hierarchical(): void {
		$rules = Rules::from_array( [] );

		$this->assertTrue( $rules->taxonomy( 'category', true )['inherit'] );
		$this->assertFalse( $rules->taxonomy( 'post_tag', false )['inherit'] );
		$this->assertTrue( $rules->taxonomy( 'post_tag' )['enabled'] );
	}

	public function test_config_is_cleaned(): void {
		$rules = Rules::from_array(
			[
				'site_levels' => [ 'Gold', 'gold', '' ],
				'conflict'    => 'loudest',
				'types'       => [
					'lesson' => [ 'enabled' => '', 'levels' => 'Silver', 'teaser' => [ 'mode' => 'paragraphs', 'count' => 5000 ], 'public_after_days' => -3 ],
					0        => [ 'levels' => [ 'gold' ] ],
				],
				'taxonomies'  => [ 'topic' => [ 'priority' => 500, 'inherit' => 1 ] ],
			]
		);

		$this->assertSame( [ 'gold' ], $rules->site_levels );
		$this->assertSame( Context::CONFLICT_MOST, $rules->conflict );
		$this->assertSame( [ 'lesson' ], array_keys( $rules->types ) );
		$this->assertSame( [ 'enabled' => false, 'levels' => [ 'silver' ], 'teaser' => [ 'mode' => 'blocks', 'count' => 1000 ], 'public_after_days' => 0 ], $rules->type( 'lesson' ) );
		$this->assertSame( [ 'enabled' => true, 'inherit' => true, 'priority' => 100 ], $rules->taxonomy( 'topic' ) );
	}

	public function test_round_trip(): void {
		$rules = Rules::from_array( [ 'conflict' => 'priority', 'types' => [ 'post' => [ 'levels' => [ 'gold' ] ] ] ] );

		$this->assertEquals( $rules, Rules::from_array( $rules->to_array() ) );
	}

	public function test_context_round_trip_keeps_terms_and_windows(): void {
		$context = new Context(
			item_mode: Context::ITEM_ADD,
			item_levels: [ 'gold' ],
			terms: [ new TermRule( 'Category', 'Culture', TermRule::OPENS, [], 1900000000, 3000 ) ],
			type_levels: [ 'silver' ],
			type_label: 'Post type: Post',
			conflict: Context::CONFLICT_LEAST,
			locked_until: 1800000000,
			published: 1700000000,
			type_public_after: 86400,
			meter_exempt: true,
		);

		$this->assertEquals( $context, Context::from_array( json_decode( (string) json_encode( $context->to_array() ), true ) ) );
	}

	public function test_term_rule_sanitize_drops_fields_the_kind_does_not_use(): void {
		$this->assertSame( [ 'kind' => '', 'levels' => [], 'opens_until' => null, 'priority' => 0 ], TermRules::sanitize( 'x' ) );
		$this->assertSame( [ 'kind' => 'requires', 'levels' => [ 'gold' ], 'opens_until' => null, 'priority' => -100 ], TermRules::sanitize( [ 'kind' => 'requires', 'levels' => [ 'Gold' ], 'opens_until' => 1900000000, 'priority' => -999 ] ) );
		$this->assertSame( [ 'kind' => 'opens', 'levels' => [], 'opens_until' => 1900000000, 'priority' => 0 ], TermRules::sanitize( [ 'kind' => 'opens', 'levels' => [ 'gold' ], 'opens_until' => '1900000000' ] ) );
	}

	public function test_opens_needs_a_future_end_date(): void {
		$now = 1800000000;

		$this->assertSame( 'opens_needs_expiry', TermRules::problem( [ 'kind' => 'opens' ], $now ) );
		$this->assertSame( 'opens_expiry_past', TermRules::problem( [ 'kind' => 'opens', 'opens_until' => $now ], $now ) );
		$this->assertNull( TermRules::problem( [ 'kind' => 'opens', 'opens_until' => $now + 60 ], $now ) );
	}

	public function test_requires_needs_a_level_and_none_is_fine(): void {
		$this->assertSame( 'requires_needs_level', TermRules::problem( [ 'kind' => 'requires' ], 0 ) );
		$this->assertNull( TermRules::problem( [ 'kind' => 'requires', 'levels' => [ 'gold' ] ], 0 ) );
		$this->assertNull( TermRules::problem( [], 0 ) );
	}

	public function test_nearest_rule_walks_up_when_inheriting(): void {
		$rules  = [ 1 => [ 'kind' => 'requires' ], 2 => [ 'kind' => '' ] ];
		$parent = [ 3 => 2, 2 => 1, 1 => 0 ];

		$this->assertSame( 1, TermRules::nearest( 3, $rules, $parent, true ) );
		$this->assertNull( TermRules::nearest( 3, $rules, $parent, false ) );
	}

	public function test_own_rule_beats_ancestor(): void {
		$rules = [ 1 => [ 'kind' => 'requires' ], 3 => [ 'kind' => 'opens' ] ];

		$this->assertSame( 3, TermRules::nearest( 3, $rules, [ 3 => 1 ], true ) );
	}

	public function test_parent_loop_ends(): void {
		$this->assertNull( TermRules::nearest( 1, [], [ 1 => 2, 2 => 1 ], true ) );
	}
}

