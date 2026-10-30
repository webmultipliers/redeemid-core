<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Webhooks\Watchdog;

final class WatchdogTest extends TestCase {
	private const NOW = 1_800_000_000;

	private function facts( array $over = [] ): array {
		return $over + [
			'now'           => self::NOW,
			'linked'        => 40,
			'last_received' => self::NOW - 3600,
			'quiet_days'    => 7,
			'oldest_queued' => 0,
			'dead_recent'   => 0,
			'rejected_at'   => 0,
			'registration'  => [ 'created' => [], 'failed' => [], 'error' => '', 'existing' => 14 ],
			'cron_late'     => 0,
		];
	}

	public function test_an_uninstalled_app_is_a_failure(): void {
		$problems = Watchdog::problems( $this->facts( [ 'uninstalled_at' => self::NOW - 60 ] ) );

		$this->assertSame( Watchdog::FAIL, $problems['uninstalled']['state'] );
		$this->assertArrayNotHasKey( 'uninstalled', Watchdog::problems( $this->facts( [ 'uninstalled_at' => 0 ] ) ) );
	}

	public function test_a_healthy_chain_has_no_problems(): void {
		$this->assertSame( [], Watchdog::problems( $this->facts() ) );
		$this->assertSame( '', Watchdog::worst( [] ) );
	}

	public function test_resubscribed_topics_are_a_warning_but_a_first_registration_is_not(): void {
		$dropped = Watchdog::problems( $this->facts( [ 'registration' => [ 'created' => [ 'customer.tags_added', 'customer.tags_removed' ], 'failed' => [], 'error' => '', 'existing' => 12 ] ] ) );
		$first   = Watchdog::problems( $this->facts( [ 'registration' => [ 'created' => [ 'customers/create' ], 'failed' => [], 'error' => '', 'existing' => 0 ] ] ) );

		$this->assertSame( [ 'resubscribed' => [ 'state' => Watchdog::WARN, 'n' => 2 ] ], $dropped );
		$this->assertSame( [], $first );
	}

	public function test_failures_to_check_or_subscribe_fail(): void {
		$problems = Watchdog::problems( $this->facts( [ 'registration' => [ 'created' => [], 'failed' => [ 'customers/merge' => 'denied' ], 'error' => '', 'existing' => 13 ] ] ) );
		$this->assertSame( Watchdog::FAIL, $problems['subscribe_failed']['state'] );

		$problems = Watchdog::problems( $this->facts( [ 'registration' => [ 'created' => [], 'failed' => [], 'error' => 'Shopify could not be reached.', 'existing' => 0 ] ] ) );
		$this->assertArrayHasKey( 'check_failed', $problems );
		$this->assertSame( Watchdog::FAIL, Watchdog::worst( $problems ) );
	}

	public function test_a_stuck_queue_and_a_stopped_cron_fail(): void {
		$problems = Watchdog::problems( $this->facts( [ 'oldest_queued' => self::NOW - 1800, 'cron_late' => 3 * 3600 ] ) );

		$this->assertSame( [ 'state' => Watchdog::FAIL, 'n' => 30 ], $problems['backlog'] );
		$this->assertSame( [ 'state' => Watchdog::FAIL, 'n' => 3 ], $problems['cron'] );
	}

	public function test_a_freshly_queued_delivery_and_a_slightly_late_cron_are_fine(): void {
		$this->assertSame( [], Watchdog::problems( $this->facts( [ 'oldest_queued' => self::NOW - 120, 'cron_late' => 600 ] ) ) );
	}

	public function test_recent_rejections_fail_and_old_ones_are_forgotten(): void {
		$this->assertArrayHasKey( 'rejected', Watchdog::problems( $this->facts( [ 'rejected_at' => self::NOW - 60 ] ) ) );
		$this->assertArrayNotHasKey( 'rejected', Watchdog::problems( $this->facts( [ 'rejected_at' => self::NOW - 3 * 86400 ] ) ) );
	}

	public function test_silence_warns_only_with_linked_customers_and_the_setting_on(): void {
		$quiet = $this->facts( [ 'last_received' => self::NOW - 9 * 86400 ] );

		$this->assertSame( [ 'quiet' => [ 'state' => Watchdog::WARN, 'n' => 9 ] ], Watchdog::problems( $quiet ) );
		$this->assertSame( [], Watchdog::problems( [ 'quiet_days' => 0 ] + $quiet ) );
		$this->assertSame( [], Watchdog::problems( [ 'linked' => 0 ] + $quiet ) );
		$this->assertSame( [ 'state' => Watchdog::WARN, 'n' => 0 ], Watchdog::problems( [ 'last_received' => 0 ] + $quiet )['quiet'] );
	}

	public function test_dead_letters_warn(): void {
		$problems = Watchdog::problems( $this->facts( [ 'dead_recent' => 3 ] ) );

		$this->assertSame( [ 'dead' => [ 'state' => Watchdog::WARN, 'n' => 3 ] ], $problems );
		$this->assertSame( Watchdog::WARN, Watchdog::worst( $problems ) );
	}
}
