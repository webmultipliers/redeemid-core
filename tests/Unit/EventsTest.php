<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Admin\Customers;
use RedeemID\Api\Client;
use RedeemID\Api\Events;
use RedeemID\Support\Stats;

final class EventsTest extends TestCase {
	public function test_an_envelope_has_one_shape(): void {
		$event = Events::envelope( Events::WALL_SHOWN, [ 'post_id' => 42, 'reason' => 'guest', 'level' => 'gold', 'user_id' => 0, 'post_type' => 'post' ], 1700000000 );

		$this->assertSame( [ 'name', 'version', 'time', 'props' ], array_keys( $event ) );
		$this->assertSame( Events::VERSION, $event['version'] );
		$this->assertSame( 1700000000, $event['time'] );
		$this->assertSame( [ 'post_id' => 42, 'post_type' => 'post', 'reason' => 'guest', 'level' => 'gold', 'user_id' => 0 ], $event['props'] );
	}

	public function test_unknown_events_are_refused(): void {
		$this->assertNull( Events::envelope( 'something_else', [], 1 ) );
	}

	public function test_props_are_limited_to_declared_scalar_keys(): void {
		$event = Events::envelope( Events::LEVEL_GAINED, [ 'level' => 'gold', 'user_id' => 7, 'email' => 'jane@example.com', 'tags' => [ 'vip' ] ], 1 );

		$this->assertSame( [ 'level' => 'gold', 'user_id' => 7 ], $event['props'] );

		$odd = Events::envelope( Events::METER_USED, [ 'post_id' => [ 1 ], 'used' => 2 ], 1 );
		$this->assertNull( $odd['props']['post_id'] );
		$this->assertNull( $odd['props']['limit'] );
		$this->assertSame( 2, $odd['props']['used'] );
	}

	public function test_no_event_carries_personal_fields(): void {
		foreach ( Events::CATALOG as $event ) {
			$this->assertEmpty( array_intersect( $event['props'], [ 'email', 'tags', 'customer_id', 'name', 'token' ] ) );
		}
	}

	public function test_meter_events_are_counted_for_reports(): void {
		$this->assertSame( [ 'meter' ], Stats::keys_for( Events::METER_USED, [] ) );
		$this->assertSame( [ 'meter_out' ], Stats::keys_for( Events::METER_EXHAUSTED, [] ) );
		$this->assertSame( [], Stats::keys_for( Events::WALL_SHOWN, [] ) );
	}

	public function test_client_state_for_a_guest_hides_everything_personal(): void {
		$state = Client::shape( false, true, [ 'gold' ], [ 'ad-free' ], [ 'on' => true, 'used' => 3, 'limit' => 5, 'period' => 'month' ], 'abc' );

		$this->assertFalse( $state['signedIn'] );
		$this->assertFalse( $state['linked'] );
		$this->assertSame( [], $state['levels'] );
		$this->assertSame( [], $state['features'] );
		$this->assertSame( '', $state['nonce'] );
		$this->assertSame( [ 'used' => 0, 'limit' => 5, 'period' => 'month' ], $state['meter'] );
	}

	public function test_client_state_for_a_member(): void {
		$state = Client::shape( true, true, [ 'gold', 'silver' ], [ 'ad-free' ], null, 'abc' );

		$this->assertTrue( $state['linked'] );
		$this->assertSame( [ 'gold', 'silver' ], $state['levels'] );
		$this->assertNull( $state['meter'] );
		$this->assertSame( 'abc', $state['nonce'] );
	}

	public function test_tag_plan_only_removes_held_tags_and_skips_held_adds(): void {
		$plan = Customers::tag_plan( [ 'gold', 'VIP' ], [ ' vip ', 'silver', '' ], [ 'gold', 'never-held', 'silver' ] );

		$this->assertSame( [ 'silver' ], $plan['add'] );
		$this->assertSame( [ 'gold' ], $plan['remove'] );
	}
}
