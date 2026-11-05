<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Admin\Setup;

final class SetupFollowUpTest extends TestCase {
	public function test_a_passing_test_publishes_the_callback_page_and_registers_webhooks(): void {
		$this->assertSame( [ 'callback', 'webhooks' ], Setup::follow_ups( [ 'sign_in' => true, 'admin' => true ], false, false ) );
	}

	public function test_nothing_is_done_twice(): void {
		$this->assertSame( [], Setup::follow_ups( [ 'sign_in' => true, 'admin' => true ], true, true ) );
	}

	public function test_a_page_someone_unpublished_is_left_alone(): void {
		$this->assertNotContains( 'callback', Setup::follow_ups( [ 'sign_in' => true ], true, false ) );
	}

	public function test_failing_or_untested_apis_do_nothing(): void {
		$this->assertSame( [], Setup::follow_ups( [ 'sign_in' => false, 'admin' => false ], false, false ) );
		$this->assertSame( [], Setup::follow_ups( [], false, false ) );
		$this->assertSame( [ 'webhooks' ], Setup::follow_ups( [ 'sign_in' => false, 'admin' => true ], false, false ) );
	}

	public function test_only_a_clean_registration_counts(): void {
		$this->assertTrue( Setup::webhooks_registered( [ 'error' => '', 'failed' => [] ] ) );
		$this->assertFalse( Setup::webhooks_registered( [ 'error' => 'Throttled', 'failed' => [] ] ) );
		$this->assertFalse( Setup::webhooks_registered( [ 'error' => '', 'failed' => [ 'customers/update' => 'x' ] ] ) );
		$this->assertFalse( Setup::webhooks_registered( false ) );
	}
}
