<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Access\Levels;
use RedeemID\Admin\Activation;
use RedeemID\Admin\Reports;
use RedeemID\Admin\Setup;
use RedeemID\Support\Stats;

final class OwnerToolsTest extends TestCase {
	private function levels(): Levels {
		return Levels::from_config(
			[
				'silver' => [ 'label' => 'Silver', 'groups' => [ [ 'tags' => [ 'silver', 'silver-comp' ] ] ] ],
				'gold'   => [ 'label' => 'Gold', 'groups' => [ [ 'tags' => [ 'gold', 'paid' ], 'match' => 'all' ] ], 'includes' => [ 'silver' ] ],
				'quote'  => [ 'label' => 'Quote', 'groups' => [ [ 'tags' => [ 'say "hi"' ] ] ] ],
			]
		);
	}

	public function test_search_query_ors_every_level_that_grants(): void {
		$this->assertSame( '(tag:"silver" OR tag:"silver-comp") OR (tag:"gold" AND tag:"paid")', Activation::search_query( $this->levels(), 'silver' ) );
		$this->assertSame( '(tag:"gold" AND tag:"paid")', Activation::search_query( $this->levels(), 'gold' ) );
	}

	public function test_search_query_escapes_quotes(): void {
		$this->assertSame( '(tag:"say \"hi\"")', Activation::search_query( $this->levels(), 'quote' ) );
	}

	public function test_search_query_is_null_without_tags(): void {
		$this->assertNull( Activation::search_query( $this->levels(), 'signed-in' ) );
	}

	public function test_percent(): void {
		$this->assertSame( 25, Activation::percent( 1, 4 ) );
		$this->assertSame( 100, Activation::percent( 12, 10 ) );
		$this->assertNull( Activation::percent( 3, 0 ) );
		$this->assertNull( Activation::percent( 3, null ) );
	}

	public function test_missing_tags_compare_like_shopify(): void {
		$this->assertSame( [ 'Glod' ], Setup::missing_tags( [ 'Gold', ' silver ', 'Glod', 'glod' ], [ 'gold', 'Silver' ] ) );
	}

	private function facts( array $over = [] ): array {
		return $over + [
			'config'     => true,
			'test'       => [ 'at' => 1, 'results' => [ 'sign_in' => true, 'admin' => true ], 'errors' => [] ],
			'permalinks' => true,
			'callback'   => true,
			'webhooks'   => true,
			'levels'     => 2,
			'unknown'    => [],
			'members'    => 3,
		];
	}

	private function done( array $facts ): array {
		return array_column( Setup::steps( $facts ), 'done', 'key' );
	}

	public function test_all_steps_done(): void {
		$this->assertNotContains( false, $this->done( $this->facts() ) );
	}

	public function test_failed_or_missing_test_leaves_step_open(): void {
		$this->assertFalse( $this->done( $this->facts( [ 'test' => null ] ) )['test'] );

		$steps = Setup::steps( $this->facts( [ 'test' => [ 'at' => 1, 'results' => [ 'sign_in' => true, 'admin' => false ], 'errors' => [] ] ] ) );
		$this->assertFalse( $steps[1]['done'] );
		$this->assertSame( 'admin', $steps[1]['detail'] );
	}

	public function test_unknown_level_tags_leave_levels_open(): void {
		$steps = array_column( Setup::steps( $this->facts( [ 'unknown' => [ 'glod' ] ] ) ), null, 'key' );
		$this->assertFalse( $steps['levels']['done'] );
		$this->assertSame( 'glod', $steps['levels']['detail'] );
	}

	public function test_unchecked_tags_still_count_a_defined_level(): void {
		$this->assertTrue( $this->done( $this->facts( [ 'unknown' => null ] ) )['levels'] );
		$this->assertFalse( $this->done( $this->facts( [ 'levels' => 0, 'unknown' => null ] ) )['levels'] );
	}

	public function test_flow_step_waits_for_a_member(): void {
		$this->assertFalse( $this->done( $this->facts( [ 'members' => 0 ] ) )['flow'] );
	}

	public function test_stats_keys_for_events(): void {
		$this->assertSame( [ 'join:gold', 'join:vip', 'leave:silver' ], Stats::keys_for( 'level.changed', [ 'added' => 'gold, vip', 'removed' => 'silver' ] ) );
		$this->assertSame( [ 'gain:ad-free', 'lose:forums' ], Stats::keys_for( 'feature.changed', [ 'user' => 7, 'added' => 'ad-free', 'removed' => 'forums' ] ) );
		$this->assertSame( [ 'sign_in' ], Stats::keys_for( 'auth.signed_in', [] ) );
		$this->assertSame( [], Stats::keys_for( 'shopify.http', [] ) );
	}

	public function test_stats_totals_and_weeks(): void {
		$now   = gmmktime( 12, 0, 0, 10, 25, 2026 );
		$stats = [];
		$stats = Stats::bump( $stats, '2026-10-25', 'join:gold', 2 );
		$stats = Stats::bump( $stats, '2026-10-19', 'join:silver' );
		$stats = Stats::bump( $stats, '2026-10-18', 'join:gold' );
		$stats = Stats::bump( $stats, '2026-10-26', 'join:gold', 9 );

		$this->assertSame( 4, Stats::total( $stats, 'join:', 30, $now ) );
		$this->assertSame( 3, Stats::total( $stats, 'join:gold', 30, $now ) );
		$this->assertSame( 3, Stats::total( $stats, 'join:', 7, $now ) );
		$this->assertSame( [ [ 'end' => '2026-10-18', 'value' => 1 ], [ 'end' => '2026-10-25', 'value' => 3 ] ], Stats::weekly( $stats, 'join:', 2, $now ) );
	}

	public function test_stats_prune_keeps_400_days(): void {
		$now   = gmmktime( 0, 0, 0, 10, 25, 2026 );
		$stats = [ '2025-09-19' => [ 'a' => 1 ], '2025-09-20' => [ 'a' => 1 ], '2026-10-25' => [ 'a' => 1 ] ];
		$this->assertSame( [ '2025-09-20', '2026-10-25' ], array_keys( Stats::prune( $stats, $now ) ) );
	}

	public function test_nice_step(): void {
		$this->assertSame( 1, Reports::nice_step( 1 ) );
		$this->assertSame( 1, Reports::nice_step( 4 ) );
		$this->assertSame( 2, Reports::nice_step( 5 ) );
		$this->assertSame( 20, Reports::nice_step( 41 ) );
		$this->assertSame( 50, Reports::nice_step( 180 ) );
	}
}
