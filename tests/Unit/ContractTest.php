<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RedeemID\Config;
use RedeemID\Shopify\Admin;
use RedeemID\Shopify\CustomerAccount;
use RedeemID\Webhooks\Extractor;
use RedeemID\Webhooks\Topics;

/**
 * The fields the mirror reads, checked against Shopify's own sample payloads
 * for the pinned API version (tests/fixtures/shopify-2026-10/README.md). When
 * the version moves, refresh the fixtures; a failure here means Shopify
 * changed something the mirror depends on.
 */
final class ContractTest extends TestCase {
	private const DIR = __DIR__ . '/../fixtures/shopify-2026-10/';

	private static function fixture( string $file ): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/shopify-2026-10/' . $file ), true );
	}

	private static function load( string $topic ): array {
		return self::fixture( str_replace( [ '/', '.' ], '-', $topic ) . '.json' );
	}

	private static function extract( string $topic, ?array $payload = null ): array {
		$kind = Topics::kind( $topic );

		self::assertNotNull( $kind, "$topic has no handler." );

		return Extractor::extract( $kind, $topic, $payload ?? self::load( $topic ) );
	}

	public function test_fixtures_match_the_pinned_version(): void {
		$this->assertSame( '2026-10', Config::API_VERSION, 'API_VERSION moved: refresh tests/fixtures/shopify-2026-10 and rename it.' );
	}

	/**
	 * Every subscribed topic has a recorded sample.
	 */
	public function test_every_subscribed_topic_has_a_fixture(): void {
		foreach ( Topics::subscribable() as $topic ) {
			$this->assertFileExists( self::DIR . str_replace( [ '/', '.' ], '-', $topic ) . '.json', $topic );
		}
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function customer_topics(): array {
		return [
			'create'         => [ 'customers/create', '706405506930370084' ],
			'update'         => [ 'customers/update', '706405506930370084' ],
			'delete'         => [ 'customers/delete', '706405506930370084' ],
			'disable'        => [ 'customers/disable', '706405506930370084' ],
			'enable'         => [ 'customers/enable', '706405506930370084' ],
			'tags added'     => [ 'customer.tags_added', '1' ],
			'tags removed'   => [ 'customer.tags_removed', '1' ],
			'email consent'  => [ 'customers_email_marketing_consent/update', '706405506930370084' ],
			'sms consent'    => [ 'customers_marketing_consent/update', '706405506930370084' ],
			'summary'        => [ 'customers/purchasing_summary', '1' ],
			'data request'   => [ 'customers/data_request', '191167' ],
			'customer redact' => [ 'customers/redact', '191167' ],
		];
	}

	#[DataProvider( 'customer_topics' )]
	public function test_customer_is_found( string $topic, string $customer ): void {
		$this->assertSame( $customer, self::extract( $topic )['customer'] ?? null );
	}

	public function test_profile_fields(): void {
		$data = self::extract( 'customers/update' );

		$this->assertSame( 'Bob', $data['first_name'] );
		$this->assertSame( 'Biller', $data['last_name'] );
		$this->assertSame( 'bob@biller.com', $data['email'] );
		$this->assertTrue( $data['verified_email'] );
		// Tags left customers/* payloads in 2025-01; their absence must not read as "no tags".
		$this->assertArrayNotHasKey( 'tags', $data );
		// Shopify's own sample says "state": "disabled" for a customer in good standing.
		$this->assertArrayNotHasKey( 'state', $data );
		$this->assertArrayNotHasKey( 'state', self::extract( 'customers/create' ) );
	}

	/**
	 * Profile and status webhooks carry Customer.updated_at so an older one
	 * arriving late is ignored.
	 */
	public function test_update_order_guard(): void {
		$stamp = strtotime( '2021-12-31T19:00:00-05:00' );

		$this->assertSame( $stamp, self::extract( 'customers/update' )['updated_at'] );
		$this->assertSame( $stamp, self::extract( 'customers/disable' )['updated_at'] );
		$this->assertTrue( \RedeemID\Sync\Mirror::is_older( $stamp - 1, $stamp ) );
		$this->assertFalse( \RedeemID\Sync\Mirror::is_older( $stamp, $stamp ) );
		$this->assertFalse( \RedeemID\Sync\Mirror::is_older( 0, $stamp ) );
		$this->assertFalse( \RedeemID\Sync\Mirror::is_older( $stamp, 0 ) );
	}

	/**
	 * A resync keeps a disable from Shopify's webhook and lifts any other.
	 */
	public function test_status_after_resync(): void {
		$this->assertSame( 'disabled', \RedeemID\Sync\Mirror::status_after_resync( 'disabled', 'webhook' ) );
		$this->assertSame( 'enabled', \RedeemID\Sync\Mirror::status_after_resync( 'disabled', 'missing' ) );
		$this->assertSame( 'enabled', \RedeemID\Sync\Mirror::status_after_resync( 'disabled', '' ) );
		$this->assertSame( 'enabled', \RedeemID\Sync\Mirror::status_after_resync( 'enabled', 'webhook' ) );
		$this->assertSame( 'enabled', \RedeemID\Sync\Mirror::status_after_resync( '', '' ) );
	}

	public function test_status_topics(): void {
		$this->assertSame( 'disabled', self::extract( 'customers/disable' )['status'] );
		$this->assertSame( 'enabled', self::extract( 'customers/enable' )['status'] );
	}

	public function test_tag_topics(): void {
		$added   = self::extract( 'customer.tags_added' );
		$removed = self::extract( 'customer.tags_removed' );

		$this->assertSame( [ 'tag1', 'tag2' ], $added['tags'] );
		$this->assertSame( 'added', $added['change'] );
		$this->assertSame( 'removed', $removed['change'] );
	}

	public function test_consent_topics(): void {
		$email = self::extract( 'customers_email_marketing_consent/update' );

		$this->assertSame( 'email', $email['channel'] );
		$this->assertSame( 'not_subscribed', $email['state'] );
		$this->assertSame( 'sms', self::extract( 'customers_marketing_consent/update' )['channel'] );
	}

	public function test_purchasing_summary(): void {
		$data = self::extract( 'customers/purchasing_summary' );

		$this->assertSame( 1, $data['orders_count'] );
		$this->assertSame( '100.00', $data['amount_spent'] );
		$this->assertSame( 'USD', $data['currency'] );
	}

	public function test_merge(): void {
		// Shopify's sample is a failed merge, which changes nothing.
		$failed = self::load( 'customers/merge' );
		$this->assertSame( [], self::extract( 'customers/merge', $failed ) );

		$done = self::extract( 'customers/merge', [ 'status' => 'completed' ] + $failed );
		$this->assertSame( '1', $done['survivor'] );
		$this->assertSame( '2', $done['merged'] );
	}

	public function test_compliance_topics(): void {
		$this->assertSame( '{shop}.myshopify.com', self::extract( 'customers/redact' )['shop'] );
		$this->assertSame( '{shop}.myshopify.com', self::extract( 'shop/redact' )['shop'] );
	}

	public function test_admin_reconcile_response(): void {
		foreach ( [ 'id', 'tags', 'member_since', 'renews_on' ] as $field ) {
			$this->assertStringContainsString( $field, Admin::RECONCILE_QUERY );
		}

		// Customer.state only means something under classic accounts; it must not drive access.
		$this->assertDoesNotMatchRegularExpression( '/\bstate\b/', Admin::RECONCILE_QUERY );

		$out = Admin::parse_customers( self::fixture( 'admin-reconcile.json' )['data'] );

		$this->assertSame( [ '706405506930370084', '706405506930370085' ], array_map( 'strval', array_keys( $out ) ) );
		$this->assertSame( [ 'gold', 'newsletter' ], $out['706405506930370084']['tags'] );
		$this->assertArrayNotHasKey( 'state', $out['706405506930370084'] );
		$this->assertSame( '2026-01-15', $out['706405506930370084']['since'] );
		$this->assertNull( $out['706405506930370084']['renews'] );
	}

	public function test_customer_account_identity_response(): void {
		foreach ( [ 'firstName', 'lastName', 'emailAddress', 'tags' ] as $field ) {
			$this->assertStringContainsString( $field, CustomerAccount::IDENTITY_QUERY );
		}

		$out = CustomerAccount::parse_identity( self::fixture( 'customer-account-identity.json' )['data'] );

		$this->assertSame( 'gid://shopify/Customer/706405506930370084', $out['id'] );
		$this->assertSame( 'bob@biller.com', $out['email'] );
		$this->assertSame( [ 'gold', 'newsletter' ], $out['tags'] );
		$this->assertNull( CustomerAccount::parse_identity( [ 'customer' => null ] ) );
	}
}
