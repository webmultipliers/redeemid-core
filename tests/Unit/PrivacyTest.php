<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Admin\Wizard;
use RedeemID\Commerce\Cart;
use RedeemID\Privacy;
use RedeemID\Privacy\Exporter;
use RedeemID\Privacy\Requests;
use RedeemID\Privacy\SelfService;
use RedeemID\Shopify\Admin;
use RedeemID\Support\EventLog;
use RedeemID\Sync\Mirror;

final class PrivacyTest extends TestCase {
	public function test_export_covers_every_mirrored_key(): void {
		$this->assertSame( [], array_values( array_diff( Mirror::ALL_KEYS, Privacy::EXPORTED ) ) );
	}

	public function test_erase_covers_request_bookkeeping(): void {
		$this->assertContains( '_redeemid_last_sync_request', Privacy::ALSO_ERASED );
	}

	public function test_export_pages_until_both_logs_run_short(): void {
		$this->assertTrue( Exporter::done( 0, 0 ) );
		$this->assertTrue( Exporter::done( Exporter::PER_PAGE - 1, 3 ) );
		$this->assertFalse( Exporter::done( Exporter::PER_PAGE, 0 ) );
		$this->assertFalse( Exporter::done( 0, Exporter::PER_PAGE ) );
	}

	public function test_log_rows_match_by_user_or_customer(): void {
		$this->assertSame( [ '', [] ], EventLog::person_where( 0, '' ) );
		$this->assertSame( [ '(user_id = %d)', [ 7 ] ], EventLog::person_where( 7, '' ) );
		$this->assertSame( [ '(customer = %s)', [ '191167' ] ], EventLog::person_where( 0, '191167' ) );
		$this->assertSame( [ '(user_id = %d OR customer = %s)', [ 7, '191167' ] ], EventLog::person_where( 7, '191167' ) );
	}

	public function test_member_requests_map_to_wordpress_request_types(): void {
		$this->assertSame( 'export_personal_data', SelfService::action_for( 'export' ) );
		$this->assertSame( 'remove_personal_data', SelfService::action_for( 'erase' ) );
		$this->assertNull( SelfService::action_for( 'delete_everything' ) );
	}

	public function test_erasure_scope_is_optional(): void {
		$this->assertContains( Admin::ERASURE_SCOPE, Wizard::OPTIONAL_SCOPES );
		$this->assertNotContains( Admin::ERASURE_SCOPE, Wizard::SCOPES );
		$this->assertSame( [], Wizard::missing_scopes( 'read_customers,write_customers,read_customer_merge' ) );
	}

	public function test_compliance_snippet_declares_every_topic(): void {
		$snippet = Wizard::compliance_snippet( 'https://example.com/wp-json/redeemid/v1/webhooks/events' );

		foreach ( Wizard::COMPLIANCE_TOPICS as $topic ) {
			$this->assertStringContainsString( '"' . $topic . '"', $snippet );
		}

		$this->assertStringContainsString( 'uri = "https://example.com/wp-json/redeemid/v1/webhooks/events"', $snippet );
	}

	public function test_uninstall_removes_what_privacy_adds(): void {
		$uninstall = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		foreach ( [ ...Requests::META_KEYS, Cart::USER_META ] as $key ) {
			$this->assertStringContainsString( "'" . $key . "'", $uninstall, "$key is not removed on uninstall." );
		}
	}
}
