<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Shopify\AdminToken;
use RedeemID\Webhooks\Extractor;
use RedeemID\Webhooks\Registrar;
use RedeemID\Webhooks\Topics;

final class RegistrarTest extends TestCase {
	private const URI = 'https://example.test/wp-json/redeemid/v1/webhooks/events';

	public function test_topic_enum_names(): void {
		$this->assertSame( 'CUSTOMER_TAGS_ADDED', Registrar::enum( 'customer.tags_added' ) );
		$this->assertSame( 'ORDERS_PAID', Registrar::enum( 'orders/paid' ) );
		$this->assertSame( 'CUSTOMERS_EMAIL_MARKETING_CONSENT_UPDATE', Registrar::enum( 'customers_email_marketing_consent/update' ) );
	}

	public function test_compliance_topics_are_not_registered_through_the_api(): void {
		$topics = Registrar::topics();

		$this->assertContains( 'customers/merge', $topics );
		$this->assertContains( 'app/uninstalled', $topics );
		$this->assertNotContains( 'customers/redact', $topics );
		$this->assertNotContains( 'shop/redact', $topics );
	}

	public function test_only_missing_topics_for_our_uri_are_created(): void {
		$existing = [
			[ 'topic' => 'CUSTOMERS_CREATE', 'uri' => self::URI . '/' ],
			// Same topic, someone else's endpoint: does not count.
			[ 'topic' => 'CUSTOMERS_UPDATE', 'uri' => 'https://elsewhere.test/hook' ],
		];

		$missing = Registrar::missing( $existing, self::URI );

		$this->assertNotContains( 'customers/create', $missing );
		$this->assertNotContains( 'orders/paid', Registrar::topics() );
		$this->assertContains( 'customers/update', $missing );
		$this->assertCount( count( Registrar::topics() ) - 1, $missing );
	}

	public function test_merge_is_skipped_without_its_scope(): void {
		$this->assertSame( 'read_customer_merge', Registrar::missing_scope( 'customers/merge', 'read_customers,write_customers' ) );
		$this->assertSame( '', Registrar::missing_scope( 'customers/merge', 'read_customers, write_customers, read_customer_merge' ) );
		// Unknown scopes: try it and let Shopify answer.
		$this->assertSame( '', Registrar::missing_scope( 'customers/merge', '' ) );
		$this->assertSame( '', Registrar::missing_scope( 'customers/create', 'read_customers' ) );
	}

	public function test_merge_payload_uses_the_gid_fields(): void {
		$payload = [
			'admin_graphql_api_customer_kept_id'    => 'gid://shopify/Customer/1',
			'admin_graphql_api_customer_deleted_id' => 'gid://shopify/Customer/2',
			'status'                                => 'completed',
		];

		$data = Extractor::extract( Topics::MERGE, 'customers/merge', $payload );
		$this->assertSame( '1', (string) $data['survivor'] );
		$this->assertSame( '2', (string) $data['merged'] );

		$failed = Extractor::extract( Topics::MERGE, 'customers/merge', [ 'status' => 'failed' ] + $payload );
		$this->assertArrayNotHasKey( 'survivor', $failed );
	}

	public function test_token_cache_lifetime_leaves_a_margin(): void {
		$this->assertSame( 86099, AdminToken::ttl( [ 'expires_in' => 86399 ] ) );
		$this->assertSame( 3300, AdminToken::ttl( [] ) );
		$this->assertSame( 60, AdminToken::ttl( [ 'expires_in' => 10 ] ) );
	}
}
