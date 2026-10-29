<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Access\Level;
use RedeemID\Access\Levels;

final class LevelsTest extends TestCase {
	private function levels( array $config ): Levels {
		return Levels::from_config( $config );
	}

	public function test_builtins_always_exist_and_cannot_be_replaced(): void {
		$levels = $this->levels( [ 'public' => [ 'label' => 'Hijack', 'groups' => [ [ 'tags' => [ 'x' ] ] ] ] ] );

		$this->assertSame( 'Public', $levels->get( Level::PUBLIC )->label );
		$this->assertTrue( $levels->has( Level::SIGNED_IN ) );
	}

	public function test_inclusion_is_transitive(): void {
		$levels = $this->levels(
			[
				'members' => [ 'groups' => [ [ 'tags' => [ 'm' ] ] ] ],
				'silver'  => [ 'groups' => [ [ 'tags' => [ 's' ] ] ], 'includes' => [ 'members' ] ],
				'gold'    => [ 'groups' => [ [ 'tags' => [ 'g' ] ] ], 'includes' => [ 'silver' ] ],
			]
		);

		$this->assertSame( [ 'gold', 'silver', 'members' ], $levels->closure( 'gold' ) );
		$this->assertSame( [ 'members', 'silver', 'gold' ], $levels->grantors( 'members' ) );
	}

	public function test_inclusion_cycles_stop(): void {
		$levels = $this->levels(
			[
				'a' => [ 'groups' => [ [ 'tags' => [ 'a' ] ] ], 'includes' => [ 'b' ] ],
				'b' => [ 'groups' => [ [ 'tags' => [ 'b' ] ] ], 'includes' => [ 'a', 'missing' ] ],
			]
		);

		$this->assertSame( [ 'a', 'b' ], $levels->closure( 'a' ) );
		$this->assertSame( [ [ 'a' ] ], $levels->reduce( [ [ 'a' ], [ 'b' ] ] ), 'Levels that include each other are one requirement' );
	}

	public function test_slugs_and_tags_are_cleaned(): void {
		$levels = $this->levels( [ ' Gold Tier ' => [ 'groups' => [ [ 'tags' => 'gold-member, vip ,' ], [ 'tags' => [] ] ] ] ] );
		$gold   = $levels->get( 'gold-tier' );

		$this->assertNotNull( $gold );
		$this->assertSame( [ 'gold-member', 'vip' ], $gold->tags() );
		$this->assertCount( 1, $gold->groups, 'An empty group would grant every signed-in visitor, so it is dropped' );
		$this->assertSame( 'gold-tier', $gold->label, 'No label falls back to the slug' );
	}

	public function test_health_flags_missing_tags_and_empty_levels(): void {
		$levels = $this->levels(
			[
				'gold'  => [ 'groups' => [ [ 'tags' => [ 'Gold-Member', 'renamed-tag' ] ] ], 'includes' => [ 'nope' ] ],
				'empty' => [ 'label' => 'Empty' ],
			]
		);

		$this->assertSame( [ 'renamed-tag' ], $levels->missing_tags( 'gold', [ 'gold-member', 'other' ] ) );
		$this->assertSame( [ 'missing_tags', 'unknown_include' ], $levels->health( 'gold', [ 'gold-member' ] ) );
		$this->assertSame( [ 'no_condition' ], $levels->health( 'empty', [] ) );
		$this->assertSame( [], $levels->health( Level::PUBLIC, [] ) );
	}

	public function test_held_by_tags_counts_inclusion(): void {
		$levels = $this->levels(
			[
				'silver' => [ 'groups' => [ [ 'tags' => [ 'silver-member' ] ] ] ],
				'gold'   => [ 'groups' => [ [ 'tags' => [ 'gold-member' ] ] ], 'includes' => [ 'silver' ] ],
			]
		);

		$this->assertTrue( $levels->held_by_tags( 'silver', [ 'GOLD-MEMBER' ] ) );
		$this->assertFalse( $levels->held_by_tags( 'gold', [ 'silver-member' ] ) );
	}

	public function test_round_trip_through_array(): void {
		$config = [ 'label' => 'Gold', 'groups' => [ [ 'tags' => [ 'gold' ], 'match' => 'all' ] ], 'includes' => [ 'silver' ], 'badge' => '#c9a227', 'message' => 'For Gold', 'cta_label' => 'Join', 'cta_url' => '/join/', 'home_url' => '/members/gold/', 'products' => [ 'gold-membership' ] ];

		$this->assertSame( $config, Level::from_array( 'gold', $config )->to_array() );
	}
}
