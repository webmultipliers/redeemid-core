<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Webhooks\Inbox;
use RedeemID\Webhooks\InboxStore;

final class InboxTest extends TestCase {
	private function payload( string $name ): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/' . $name ), true );
	}

	public function test_replayed_webhook_is_a_duplicate(): void {
		$store = new MemoryInboxStore();
		$inbox = new Inbox( $store );
		$raw   = (string) file_get_contents( __DIR__ . '/../fixtures/customers-update.json' );

		$first  = $inbox->accept( 'customers/update', 'wh-1', 'ev-1', $raw, $this->payload( 'customers-update.json' ), 1000 );
		$replay = $inbox->accept( 'customers/update', 'wh-1', 'ev-1', $raw, $this->payload( 'customers-update.json' ), 2000 );

		$this->assertSame( InboxStore::QUEUED, $first['status'] );
		$this->assertSame( 'duplicate', $replay['status'] );
		$this->assertCount( 1, $store->rows );
	}

	public function test_same_webhook_id_on_another_topic_is_new(): void {
		$store = new MemoryInboxStore();
		$inbox = new Inbox( $store );

		$inbox->accept( 'customers/update', 'wh-1', '', '{}', [ 'id' => 1 ], 1 );
		$other = $inbox->accept( 'customers/enable', 'wh-1', '', '{}', [ 'id' => 1 ], 1 );

		$this->assertSame( InboxStore::QUEUED, $other['status'] );
		$this->assertCount( 2, $store->rows );
	}

	public function test_unknown_topic_is_dead_lettered_not_success(): void {
		$store  = new MemoryInboxStore();
		$result = ( new Inbox( $store ) )->accept( 'customers/add_tags', 'wh-9', '', '{}', [ 'id' => 1 ], 1 );

		$this->assertSame( InboxStore::DEAD, $result['status'] );
		$this->assertSame( 'unknown_topic', $store->rows[ $result['id'] ]['error'] );
		$this->assertSame( [], $store->rows[ $result['id'] ]['data'] );
	}

	public function test_only_extracted_fields_are_stored(): void {
		$store  = new MemoryInboxStore();
		$result = ( new Inbox( $store ) )->accept( 'customers/update', 'wh-2', '', '{}', $this->payload( 'customers-update.json' ), 1 );
		$data   = $store->rows[ $result['id'] ]['data'];

		$this->assertSame( '706405506930370084', $data['customer'] );
		// Customer.state is not kept: it only means something under classic accounts.
		$this->assertArrayNotHasKey( 'state', $data );
		$this->assertArrayNotHasKey( 'addresses', $data );
		$this->assertArrayNotHasKey( 'note', $data );
		// No tags in the payload must not become "tags removed".
		$this->assertArrayNotHasKey( 'tags', $data );
	}

	public function test_missing_webhook_id_falls_back_to_event_id_then_body(): void {
		$this->assertNotSame( Inbox::dedupe_key( 't', '', 'e1', 'b' ), Inbox::dedupe_key( 't', '', 'e2', 'b' ) );
		$this->assertSame( Inbox::dedupe_key( 't', '', '', 'body' ), Inbox::dedupe_key( 'T', '', '', 'body' ) );
		$this->assertNotSame( Inbox::dedupe_key( 't', '', '', 'a' ), Inbox::dedupe_key( 't', '', '', 'b' ) );
	}

	public function test_tags_event_extracts_change(): void {
		$store  = new MemoryInboxStore();
		$result = ( new Inbox( $store ) )->accept( 'customer.tags_added', 'wh-3', '', '{}', $this->payload( 'customer-tags-added.json' ), 1 );
		$data   = $store->rows[ $result['id'] ]['data'];

		$this->assertSame( [ 'vip', 'gold' ], $data['tags'] );
		$this->assertSame( 'added', $data['change'] );
	}

	public function test_customer_groups_are_ignored_without_storing_payload(): void {
		$store  = new MemoryInboxStore();
		$result = ( new Inbox( $store ) )->accept( 'customer_groups/update', 'wh-4', '', '{}', [ 'id' => 5, 'name' => 'x' ], 1 );

		$this->assertSame( InboxStore::IGNORED, $result['status'] );
		$this->assertSame( [], $store->rows[ $result['id'] ]['data'] );
	}
}
