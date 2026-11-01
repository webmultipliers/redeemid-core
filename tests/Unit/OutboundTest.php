<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Api\Events;
use RedeemID\Api\Outbound;

final class OutboundTest extends TestCase {
	public function test_the_payload_is_the_envelope_plus_an_id_and_the_site(): void {
		$event   = Events::envelope( Events::METER_EXHAUSTED, [ 'post_id' => 42, 'limit' => 3, 'period' => 'month', 'user_id' => 7 ], 1700000000 );
		$payload = Outbound::payload( $event, 'abc', 'https://example.com/' );

		$this->assertSame( [ 'id', 'site', 'name', 'version', 'time', 'props' ], array_keys( $payload ) );
		$this->assertSame( 'meter_exhausted', $payload['name'] );
		$this->assertSame( $event['props'], $payload['props'] );
		$this->assertArrayNotHasKey( 'customer', $payload );
	}

	public function test_the_signature_is_an_hmac_of_time_and_body(): void {
		$body = '{"id":"abc"}';
		$sig  = Outbound::sign( $body, 'whsec_test', 1700000000 );

		$this->assertSame( 't=1700000000,v1=' . hash_hmac( 'sha256', '1700000000.' . $body, 'whsec_test' ), $sig );
		$this->assertMatchesRegularExpression( '/^t=\d+,v1=[a-f0-9]{64}$/', $sig );
		$this->assertNotSame( $sig, Outbound::sign( $body . ' ', 'whsec_test', 1700000000 ) );
		$this->assertNotSame( $sig, Outbound::sign( $body, 'whsec_test', 1700000001 ) );
	}

	public function test_the_headers_name_the_event_and_delivery(): void {
		$headers = Outbound::headers( '{}', 's', 1, 'level_lost', 'abc', '0.0.8' );

		$this->assertSame( 'level_lost', $headers['X-RedeemID-Event'] );
		$this->assertSame( 'abc', $headers['X-RedeemID-Delivery'] );
		$this->assertSame( 'RedeemID/0.0.8', $headers['User-Agent'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertSame( Outbound::sign( '{}', 's', 1 ), $headers['X-RedeemID-Signature'] );
	}

	public function test_retries_back_off_then_give_up(): void {
		$this->assertSame( 60, Outbound::retry_delay( 1 ) );
		$this->assertSame( 43200, Outbound::retry_delay( 5 ) );
		$this->assertNull( Outbound::retry_delay( 6 ) );

		$delays = array_map( [ Outbound::class, 'retry_delay' ], range( 1, 5 ) );
		$this->assertSame( $delays, array_values( array_unique( $delays ) ) );
		$this->assertLessThan( 16 * 3600, array_sum( $delays ) );
	}

	public function test_only_a_2xx_counts(): void {
		$this->assertTrue( Outbound::succeeded( 200 ) );
		$this->assertTrue( Outbound::succeeded( 204 ) );
		$this->assertFalse( Outbound::succeeded( 301 ) );
		$this->assertFalse( Outbound::succeeded( 500 ) );
		$this->assertFalse( Outbound::succeeded( 0 ) );
	}

	public function test_events_are_limited_to_the_catalog_in_catalog_order(): void {
		$this->assertSame( [ Events::WALL_SHOWN, Events::LEVEL_LOST ], Outbound::sanitize_events( [ 'level_lost', 'nope', 'wall_shown', 'level_lost' ] ) );
		$this->assertSame( [], Outbound::sanitize_events( 'wall_shown' ) );
		$this->assertSame( [], Outbound::sanitize_events( null ) );
	}

	public function test_the_defaults_skip_per_view_events(): void {
		$this->assertSame( Outbound::DEFAULT_EVENTS, Outbound::sanitize_events( Outbound::DEFAULT_EVENTS ) );
		$this->assertNotContains( Events::WALL_SHOWN, Outbound::DEFAULT_EVENTS );
		$this->assertNotContains( Events::METER_USED, Outbound::DEFAULT_EVENTS );
	}
}
