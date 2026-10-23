<?php

namespace WP2_Shopify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2_Shopify\Shopify\AdminToken;
use WP2_Shopify\Webhooks\Registrar;

final class RegistrarTest extends TestCase {
	private const URI = 'https://example.test/wp-json/wp2-shopify/v1/webhooks/events';

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
			[ 'topic' => 'ORDERS_PAID', 'uri' => self::URI . '/' ],
			// Same topic, someone else's endpoint: does not count.
			[ 'topic' => 'CUSTOMERS_UPDATE', 'uri' => 'https://elsewhere.test/hook' ],
		];

		$missing = Registrar::missing( $existing, self::URI );

		$this->assertNotContains( 'orders/paid', $missing );
		$this->assertContains( 'customers/update', $missing );
		$this->assertCount( count( Registrar::topics() ) - 1, $missing );
	}

	public function test_token_cache_lifetime_leaves_a_margin(): void {
		$this->assertSame( 86099, AdminToken::ttl( [ 'expires_in' => 86399 ] ) );
		$this->assertSame( 3300, AdminToken::ttl( [] ) );
		$this->assertSame( 60, AdminToken::ttl( [ 'expires_in' => 10 ] ) );
	}
}
