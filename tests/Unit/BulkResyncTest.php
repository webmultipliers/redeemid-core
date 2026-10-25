<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Shopify\Http;
use RedeemID\Sync\BulkJob;
use RedeemID\Sync\Pacer;

final class BulkResyncTest extends TestCase {
	private const NOW = 1_800_000_000;

	private function cost( float $available, float $maximum = 2000, float $restore = 100, int $requested = 52 ): array {
		return [ 'requested' => $requested, 'actual' => 12, 'available' => $available, 'maximum' => $maximum, 'restore' => $restore ];
	}

	public function test_goes_straight_on_while_the_bucket_is_above_the_reserve(): void {
		$this->assertSame( 0.0, Pacer::wait( $this->cost( 1900 ) ) );
		$this->assertSame( 0.0, Pacer::wait( $this->cost( 1052 ) ) );
	}

	public function test_waits_for_the_reserve_plus_the_next_batch_to_refill(): void {
		// Needs 1000 (half of 2000) + 52, has 800: 252 points at 100 a second.
		$this->assertSame( 2.52, Pacer::wait( $this->cost( 800 ) ) );
		// A small plan refills slower, so waits longer.
		$this->assertSame( 5.04, Pacer::wait( $this->cost( 300, 1000, 50, 52 ) ) );
	}

	public function test_no_cost_report_means_no_wait(): void {
		$this->assertSame( 0.0, Pacer::wait( null ) );
	}

	public function test_backoff_doubles_and_is_capped(): void {
		$this->assertSame( 30, Pacer::backoff( 1 ) );
		$this->assertSame( 60, Pacer::backoff( 2 ) );
		$this->assertSame( 240, Pacer::backoff( 4 ) );
		$this->assertSame( Pacer::MAX_BACKOFF, Pacer::backoff( 9 ) );
	}

	public function test_only_busy_or_unreachable_shopify_is_retried(): void {
		foreach ( [ 'shopify_unreachable', 'shopify_throttled', 'shopify_http_429', 'shopify_http_502', 'shopify_bad_json' ] as $code ) {
			$this->assertTrue( Pacer::transient( $code ), $code );
		}

		foreach ( [ 'shopify_admin_unconfigured', 'shopify_http_401', 'shopify_http_403', 'shopify_graphql' ] as $code ) {
			$this->assertFalse( Pacer::transient( $code ), $code );
		}
	}

	public function test_a_job_walks_forward_and_finishes(): void {
		$job = BulkJob::start( 120, 1, self::NOW );

		$this->assertSame( BulkJob::RUNNING, $job['status'] );
		$this->assertTrue( BulkJob::is_open( $job ) );

		$job = BulkJob::advance( $job, range( 3, 52 ), [ 'changed' => 4, 'missing' => 1 ], self::NOW + 2, 2.0 );
		$job = BulkJob::advance( $job, range( 60, 109 ), [ 'changed' => 1, 'missing' => 0 ], self::NOW + 4, 2.0 );

		$this->assertSame( 100, $job['done'] );
		$this->assertSame( 109, $job['cursor'] );
		$this->assertSame( 5, $job['changed'] );
		$this->assertSame( 1, $job['missing'] );
		$this->assertSame( 83, BulkJob::percent( $job ) );
		// 20 left at 0.04 s each.
		$this->assertSame( 1, BulkJob::eta( $job ) );

		$job = BulkJob::finish( $job, self::NOW + 6 );

		$this->assertSame( BulkJob::DONE, $job['status'] );
		$this->assertSame( 100, $job['total'] );
		$this->assertSame( 100, BulkJob::percent( $job ) );
		$this->assertFalse( BulkJob::is_open( $job ) );
	}

	public function test_customers_linked_after_the_start_raise_the_total(): void {
		$job = BulkJob::advance( BulkJob::start( 10, 1, self::NOW ), range( 1, 12 ), [ 'changed' => 0, 'missing' => 0 ], self::NOW );

		$this->assertSame( 12, $job['total'] );
	}

	public function test_transient_failures_back_off_then_stop(): void {
		$job = BulkJob::start( 10, 1, self::NOW );
		$job = BulkJob::fail( $job, 'shopify_throttled', 'Throttled', self::NOW );

		$this->assertSame( BulkJob::RUNNING, $job['status'] );
		$this->assertSame( self::NOW + 30, $job['next_at'] );

		for ( $i = 2; $i <= Pacer::MAX_FAILURES; $i++ ) {
			$job = BulkJob::fail( $job, 'shopify_unreachable', 'Down', self::NOW );
		}

		$this->assertSame( BulkJob::FAILED, $job['status'] );
		$this->assertSame( 'Down', $job['error'] );
	}

	public function test_a_throttled_batch_waits_as_long_as_shopify_says(): void {
		$job = BulkJob::fail( BulkJob::start( 10, 1, self::NOW ), 'shopify_throttled', 'Throttled', self::NOW, 11 );

		$this->assertSame( self::NOW + 11, $job['next_at'] );
		$this->assertSame( BulkJob::RUNNING, $job['status'] );
	}

	public function test_a_success_clears_the_failure_count(): void {
		$job = BulkJob::fail( BulkJob::start( 10, 1, self::NOW ), 'shopify_unreachable', 'Down', self::NOW );
		$job = BulkJob::advance( $job, [ 1 ], [ 'changed' => 0, 'missing' => 0 ], self::NOW );

		$this->assertSame( 0, $job['failures'] );
		$this->assertSame( '', $job['error'] );
	}

	public function test_a_failure_staff_must_fix_stops_at_once_and_can_resume(): void {
		$job = BulkJob::fail( BulkJob::start( 10, 1, self::NOW ), 'shopify_http_401', 'Unauthorized', self::NOW );

		$this->assertSame( BulkJob::FAILED, $job['status'] );

		$job = BulkJob::resume( $job, self::NOW + 60 );

		$this->assertSame( BulkJob::RUNNING, $job['status'] );
		$this->assertSame( 0, $job['failures'] );
		$this->assertSame( self::NOW + 60, $job['next_at'] );
	}

	public function test_pause_resume_and_cancel(): void {
		$job = BulkJob::pause( BulkJob::start( 10, 1, self::NOW ), self::NOW );

		$this->assertSame( BulkJob::PAUSED, $job['status'] );
		$this->assertNull( BulkJob::eta( $job ) );
		$this->assertSame( BulkJob::RUNNING, BulkJob::resume( $job, self::NOW )['status'] );
		$this->assertSame( BulkJob::CANCELLED, BulkJob::cancel( $job, self::NOW )['status'] );
		// A finished job can't be cancelled or resumed.
		$done = BulkJob::finish( BulkJob::start( 0, 1, self::NOW ), self::NOW );
		$this->assertSame( BulkJob::DONE, BulkJob::cancel( $done, self::NOW )['status'] );
		$this->assertSame( BulkJob::DONE, BulkJob::resume( $done, self::NOW )['status'] );
	}

	public function test_reads_shopifys_cost_report(): void {
		$cost = Http::cost(
			[
				'requestedQueryCost' => 52,
				'actualQueryCost'    => 12,
				'throttleStatus'     => [ 'maximumAvailable' => 2000.0, 'currentlyAvailable' => 1988, 'restoreRate' => 100.0 ],
			]
		);

		$this->assertSame( [ 'requested' => 52, 'actual' => 12, 'available' => 1988.0, 'maximum' => 2000.0, 'restore' => 100.0 ], $cost );
		$this->assertNull( Http::cost( null ) );
		$this->assertNull( Http::cost( [ 'requestedQueryCost' => 1 ] ) );
	}
}
