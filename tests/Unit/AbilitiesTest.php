<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Abilities\Abilities;
use RedeemID\Access\Levels;
use RedeemID\Access\Verdict;
use RedeemID\Entitlement\Decision;

final class AbilitiesTest extends TestCase {
	private function levels(): Levels {
		return Levels::from_config(
			[
				'members' => [ 'label' => 'Members', 'groups' => [ [ 'tags' => [ 'member' ] ] ] ],
				'gold'    => [ 'label' => 'Gold', 'groups' => [ [ 'tags' => [ 'gold', 'vip' ], 'match' => 'any' ] ], 'includes' => [ 'members' ], 'products' => [ 'gold-plan' ] ],
			]
		);
	}

	public function test_guests_run_nothing(): void {
		foreach ( Abilities::AUDIENCE as $name => $audience ) {
			$this->assertFalse( Abilities::allowed( $audience, false, false ), $name );
			$this->assertFalse( Abilities::allowed( $audience, false, true ), $name );
		}
	}

	public function test_staff_abilities_need_the_admin_capability(): void {
		$this->assertFalse( Abilities::allowed( Abilities::STAFF, true, false ) );
		$this->assertTrue( Abilities::allowed( Abilities::STAFF, true, true ) );
		$this->assertTrue( Abilities::allowed( Abilities::MEMBER, true, false ) );
	}

	public function test_an_unknown_audience_fails_closed(): void {
		$this->assertFalse( Abilities::allowed( 'everyone', true, true ) );
	}

	public function test_every_ability_is_namespaced_and_read_only(): void {
		foreach ( array_keys( Abilities::AUDIENCE ) as $name ) {
			$this->assertMatchesRegularExpression( '#^redeemid/[a-z0-9-]+$#', $name );
		}

		$meta = Abilities::meta();
		$this->assertTrue( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
		$this->assertTrue( $meta['mcp']['public'] );
	}

	public function test_staff_actions_are_staff_only_and_off_mcp_by_default(): void {
		foreach ( Abilities::ACTIONS as $name => $destructive ) {
			$this->assertSame( Abilities::STAFF, Abilities::AUDIENCE[ $name ] );

			$meta = Abilities::action_meta( $destructive );
			$this->assertFalse( $meta['annotations']['readonly'] );
			$this->assertSame( $destructive, $meta['annotations']['destructive'] );
			$this->assertFalse( $meta['mcp']['public'] );
		}

		$this->assertTrue( Abilities::ACTIONS['redeemid/edit-member-tags'] );
	}

	public function test_input_schemas_accept_an_empty_call(): void {
		$empty = Abilities::input( [] );
		$this->assertSame( 'object', $empty['type'] );
		$this->assertArrayNotHasKey( 'properties', $empty );
		$this->assertSame( '{}', json_encode( $empty['default'] ) );

		$item = Abilities::input( [ 'post_id' => [ 'type' => 'integer' ] ] );
		$this->assertFalse( $item['additionalProperties'] );
		$this->assertArrayHasKey( 'post_id', $item['properties'] );
	}

	public function test_viewer_input_must_describe_a_real_viewer(): void {
		$is_level = static fn( string $slug ): bool => 'gold' === $slug;

		$this->assertNull( Abilities::viewer_problem( 'guest', [], 0, $is_level ) );
		$this->assertNull( Abilities::viewer_problem( 'signed-in', [], 0, $is_level ) );
		$this->assertNull( Abilities::viewer_problem( 'gold', [], 0, $is_level ) );
		$this->assertNull( Abilities::viewer_problem( 'tags', [ 'vip' ], 0, $is_level ) );
		$this->assertNull( Abilities::viewer_problem( 'user', [], 12, $is_level ) );

		$this->assertSame( 'redeemid_no_tags', Abilities::viewer_problem( 'tags', [ ' ', '' ], 0, $is_level ) );
		$this->assertSame( 'redeemid_no_user', Abilities::viewer_problem( 'user', [], 0, $is_level ) );
		// A typo is an error, not a silent guest answer.
		$this->assertSame( 'redeemid_unknown_viewer', Abilities::viewer_problem( 'glod', [], 0, $is_level ) );
	}

	public function test_level_rows_show_conditions_and_what_a_level_opens(): void {
		$levels = $this->levels();
		$row    = Abilities::level_row( $levels->get( 'gold' ), $levels );

		$this->assertSame( 'gold', $row['slug'] );
		$this->assertFalse( $row['builtin'] );
		$this->assertSame( [ [ 'tags' => [ 'gold', 'vip' ], 'match' => 'any' ] ], $row['condition'] );
		$this->assertSame( [ 'members' ], $row['opens'] );
		$this->assertSame( [ 'gold-plan' ], $row['products'] );
		$this->assertTrue( Abilities::level_row( $levels->get( 'public' ), $levels )['builtin'] );
	}

	public function test_can_i_read_offers_the_next_level(): void {
		$answer = Abilities::read_answer( false, new Verdict( Decision::deny( Decision::MISSING_TAGS ), 'gold' ), 0, 1000, $this->levels() );

		$this->assertFalse( $answer['can_read'] );
		$this->assertSame( Decision::MISSING_TAGS, $answer['reason'] );
		$this->assertSame( [ 'slug' => 'gold', 'label' => 'Gold' ], $answer['offer'] );
	}

	public function test_can_i_read_reports_drip_not_a_level(): void {
		$answer = Abilities::read_answer( false, new Verdict( Decision::grant(), null ), 5000, 1000, $this->levels() );

		$this->assertSame( Decision::DRIP, $answer['reason'] );
		$this->assertNull( $answer['offer'] );
		$this->assertSame( 5000, $answer['opens_at'] );
	}

	public function test_can_i_read_never_offers_an_unknown_level(): void {
		$answer = Abilities::read_answer( false, new Verdict( Decision::deny( Decision::BAD_RULE ), 'gone' ), 0, 1000, $this->levels() );

		$this->assertFalse( $answer['can_read'] );
		$this->assertNull( $answer['offer'] );
	}

	public function test_can_i_read_yes(): void {
		$answer = Abilities::read_answer( true, new Verdict( Decision::grant(), null ), 0, 1000, $this->levels() );

		$this->assertTrue( $answer['can_read'] );
		$this->assertSame( Decision::GRANTED, $answer['reason'] );
	}
}
