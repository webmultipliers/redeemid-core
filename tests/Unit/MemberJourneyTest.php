<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Access\Drip;
use RedeemID\Access\Levels;
use RedeemID\Access\Membership;
use RedeemID\Blocks\PanelKind;
use RedeemID\Entitlement\Decision;
use RedeemID\Entitlement\Member;
use RedeemID\Sync\LevelHistory;
use RedeemID\Sync\Mirror;

final class MemberJourneyTest extends TestCase {
	private const NOW = 1_800_000_000;

	private function levels(): Levels {
		return Levels::from_config(
			[
				'silver' => [ 'label' => 'Silver', 'groups' => [ [ 'tags' => [ 'silver' ] ] ] ],
				'gold'   => [ 'label' => 'Gold', 'groups' => [ [ 'tags' => [ 'gold' ] ] ], 'includes' => [ 'silver' ] ],
				'extra'  => [ 'label' => 'Extra', 'groups' => [ [ 'tags' => [ 'extra' ] ] ] ],
			]
		);
	}

	private function member( array $tags, string $status = 'enabled' ): Member {
		return new Member( true, true, $tags, $status, self::NOW - 60 );
	}

	public function test_held_levels_include_what_a_level_opens_and_never_builtins(): void {
		$this->assertSame( [ 'silver', 'gold' ], Membership::held( $this->levels(), $this->member( [ 'gold' ] ), self::NOW ) );
		$this->assertSame( [ 'silver' ], Membership::held( $this->levels(), $this->member( [ 'silver' ] ), self::NOW ) );
		$this->assertSame( [], Membership::held( $this->levels(), Member::guest(), self::NOW ) );
		$this->assertSame( [], Membership::held( $this->levels(), $this->member( [ 'gold' ], 'disabled' ), self::NOW ) );
	}

	public function test_top_levels_drop_the_ones_a_held_level_includes(): void {
		$levels = $this->levels();

		$this->assertSame( [ 'gold' ], Membership::top( $levels, [ 'silver', 'gold' ] ) );
		$this->assertSame( [ 'gold', 'extra' ], Membership::top( $levels, [ 'silver', 'gold', 'extra' ] ) );
	}

	public function test_history_records_joins_departures_and_rejoins(): void {
		$h = LevelHistory::apply( [], [ 'gold' ], [], self::NOW );
		$this->assertSame( [ 'gold' => [ 'since' => self::NOW, 'until' => 0 ] ], $h );

		$h = LevelHistory::apply( $h, [], [ 'gold' ], self::NOW + 100 );
		$this->assertSame( [ 'gold' => [ 'since' => self::NOW, 'until' => self::NOW + 100 ] ], $h );

		// Coming back starts a new stint.
		$h = LevelHistory::apply( $h, [ 'gold' ], [], self::NOW + 200 );
		$this->assertSame( [ 'gold' => [ 'since' => self::NOW + 200, 'until' => 0 ] ], $h );
	}

	public function test_the_locked_panel_offers_what_fits_the_reason(): void {
		$this->assertSame( PanelKind::SIGN_IN, PanelKind::for( Decision::GUEST ) );
		$this->assertSame( PanelKind::CONNECT, PanelKind::for( Decision::NOT_LINKED ) );
		$this->assertSame( PanelKind::DISABLED, PanelKind::for( Decision::DISABLED ) );
		$this->assertSame( PanelKind::FINISHING, PanelKind::for( Decision::NO_MIRROR ) );
		$this->assertSame( PanelKind::NONE, PanelKind::for( Decision::BAD_RULE ) );
	}

	public function test_a_missing_level_is_finishing_renew_upgrade_or_join(): void {
		$this->assertSame( PanelKind::FINISHING, PanelKind::for( Decision::MISSING_TAGS, true, true, true ) );
		$this->assertSame( PanelKind::RENEW, PanelKind::for( Decision::MISSING_TAGS, false, true, true ) );
		$this->assertSame( PanelKind::UPGRADE, PanelKind::for( Decision::MISSING_TAGS, false, false, true ) );
		$this->assertSame( PanelKind::JOIN, PanelKind::for( Decision::MISSING_TAGS ) );
	}

	public function test_metafield_dates(): void {
		$this->assertSame( '2026-03-01', Mirror::date( '2026-03-01' ) );
		$this->assertSame( '2026-03-01', Mirror::date( '2026-03-01T10:00:00Z' ) );
		$this->assertSame( '', Mirror::date( '2026-02-30' ) );
		$this->assertSame( '', Mirror::date( 'next week' ) );
		$this->assertSame( '', Mirror::date( null ) );
	}

	public function test_member_home_is_the_first_top_level_with_one(): void {
		$levels = Levels::from_config(
			[
				'silver' => [ 'label' => 'Silver', 'groups' => [ [ 'tags' => [ 'silver' ] ] ], 'home_url' => '/silver/' ],
				'gold'   => [ 'label' => 'Gold', 'groups' => [ [ 'tags' => [ 'gold' ] ] ] ],
				'vip'    => [ 'label' => 'VIP', 'groups' => [ [ 'tags' => [ 'vip' ] ] ], 'home_url' => '/vip/' ],
			]
		);

		$this->assertSame( '/vip/', Membership::home( $levels, [ 'gold', 'vip' ] ) );
		$this->assertSame( '/silver/', Membership::home( $levels, [ 'silver' ] ) );
		$this->assertSame( '', Membership::home( $levels, [ 'gold' ] ) );
	}

	public function test_drip_start_prefers_shopify_then_history_then_link(): void {
		$history = [ 'gold' => [ 'since' => 500, 'until' => 0 ], 'silver' => [ 'since' => 100, 'until' => 300 ], 'vip' => [ 'since' => 700, 'until' => 0 ] ];

		$this->assertSame( gmmktime( 0, 0, 0, 1, 2, 2026 ), Drip::joined( '2026-01-02', $history, [ 'gold' ], 50, self::NOW ) );
		$this->assertSame( 500, Drip::joined( '', $history, [ 'gold', 'vip' ], 50, self::NOW ), 'earliest open stint of a held level' );
		$this->assertSame( 50, Drip::joined( '', $history, [ 'silver' ], 50, self::NOW ), 'an ended stint does not count' );
		$this->assertSame( self::NOW, Drip::joined( '', [], [], 0, self::NOW ) );
		$this->assertSame( 500 + 3 * 86400, Drip::unlocks_at( 500, 3 ) );
	}

	public function test_drip_shows_its_own_panel(): void {
		$this->assertSame( PanelKind::DRIP, PanelKind::for( Decision::DRIP ) );
	}

	public function test_level_changes_since_a_date(): void {
		$history = [
			'gold'   => [ 'since' => 100, 'until' => 500 ],
			'silver' => [ 'since' => 400, 'until' => 0 ],
			'bronze' => [ 'since' => 50, 'until' => 0 ],
		];

		$this->assertSame(
			[
				[ 'level' => 'silver', 'change' => 'joined', 'at' => 400 ],
				[ 'level' => 'gold', 'change' => 'left', 'at' => 500 ],
			],
			LevelHistory::changes( $history, 300 )
		);
	}
}
