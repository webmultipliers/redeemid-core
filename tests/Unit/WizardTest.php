<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Admin\Wizard;

final class WizardTest extends TestCase {
	private function facts( array $over = [] ): array {
		return $over + [
			'config'          => true,
			'test'            => [ 'at' => 1, 'results' => [ 'sign_in' => true, 'admin' => true, 'storefront' => true ], 'errors' => [] ],
			'permalinks'      => true,
			'callback'        => true,
			'webhooks'        => true,
			'levels'          => 1,
			'unknown'         => [],
			'members'         => 1,
			'https'           => true,
			'cron'            => true,
			'files'           => null,
			'sign_in_config'  => true,
			'admin_config'    => true,
			'storefront_conf' => true,
			'scopes'          => 'read_customers,write_customers,read_customer_merge',
			'locked'          => true,
			'tagged'          => 4,
		];
	}

	private function done( array $facts ): array {
		return array_column( Wizard::steps( $facts ), 'done', 'key' );
	}

	public function test_steps_follow_the_wizard_order(): void {
		$this->assertSame( Wizard::KEYS, array_keys( Wizard::steps( $this->facts() ) ) );
		$this->assertNotContains( false, $this->done( $this->facts() ) );
	}

	public function test_site_needs_https_and_pretty_permalinks(): void {
		$this->assertFalse( $this->done( $this->facts( [ 'https' => false ] ) )['site'] );
		$this->assertFalse( $this->done( $this->facts( [ 'permalinks' => false ] ) )['site'] );
		// Cron is advice, not a blocker.
		$this->assertTrue( $this->done( $this->facts( [ 'cron' => false ] ) )['site'] );
	}

	public function test_a_saved_value_is_not_enough_without_a_passing_test(): void {
		$this->assertFalse( $this->done( $this->facts( [ 'test' => null ] ) )['sign_in'] );

		$failed = $this->done( $this->facts( [ 'test' => [ 'at' => 1, 'results' => [ 'sign_in' => true, 'admin' => false ], 'errors' => [] ] ] ) );
		$this->assertTrue( $failed['sign_in'] );
		$this->assertFalse( $failed['admin'] );
		$this->assertFalse( $failed['storefront'] );
	}

	public function test_admin_step_needs_every_scope(): void {
		$this->assertFalse( $this->done( $this->facts( [ 'scopes' => 'read_customers,write_customers' ] ) )['admin'] );
		// Not known until a token was requested: the test result decides alone.
		$this->assertTrue( $this->done( $this->facts( [ 'scopes' => '' ] ) )['admin'] );
	}

	public function test_missing_scopes(): void {
		$this->assertSame( [ 'read_customer_merge' ], Wizard::missing_scopes( 'read_customers, write_customers' ) );
		$this->assertSame( [], Wizard::missing_scopes( '' ) );
		$this->assertSame( [], Wizard::missing_scopes( 'write_customers,read_customer_merge,read_customers,read_orders' ) );
	}

	public function test_lock_waits_for_sign_in_admin_webhooks_and_a_level(): void {
		$this->assertTrue( Wizard::steps( $this->facts() )['lock']['ready'] );

		foreach ( [ [ 'webhooks' => false ], [ 'sign_in_config' => false ], [ 'admin_config' => false ], [ 'levels' => 0 ] ] as $gap ) {
			$this->assertFalse( Wizard::steps( $this->facts( $gap ) )['lock']['ready'], (string) key( $gap ) );
		}
	}

	public function test_webhooks_wait_for_the_admin_app(): void {
		$this->assertFalse( Wizard::steps( $this->facts( [ 'admin_config' => false ] ) )['webhooks']['ready'] );
	}

	public function test_flow_counts_shopify_tags_or_linked_members(): void {
		$this->assertTrue( $this->done( $this->facts( [ 'members' => 0 ] ) )['flow'] );
		$this->assertTrue( $this->done( $this->facts( [ 'tagged' => null ] ) )['flow'] );
		$this->assertFalse( $this->done( $this->facts( [ 'tagged' => null, 'members' => 0 ] ) )['flow'] );
		$this->assertFalse( $this->done( $this->facts( [ 'members' => 0 ] ) )['try'] );
	}

	public function test_current_resumes_at_the_first_required_gap(): void {
		$steps = Wizard::steps( $this->facts( [ 'storefront_conf' => false, 'locked' => false, 'callback' => false ] ) );

		$this->assertSame( 'callback', Wizard::current( $steps, '' ) );
		$this->assertSame( 'storefront', Wizard::current( $steps, 'storefront' ) );
		$this->assertSame( 'callback', Wizard::current( $steps, 'done' ) );
		$this->assertSame( 'callback', Wizard::current( $steps, 'nonsense' ) );
	}

	public function test_current_is_done_when_only_optional_steps_are_left(): void {
		$steps = Wizard::steps( $this->facts( [ 'storefront_conf' => false, 'locked' => false ] ) );

		$this->assertSame( 'done', Wizard::current( $steps, '' ) );
		$this->assertSame( 'done', Wizard::current( $steps, 'done' ) );
	}

	public function test_snippet_never_prints_a_secret(): void {
		$snippet = Wizard::snippet(
			[
				'REDEEMID_STORE_DOMAIN'  => [ 'value' => "shop's.myshopify.com", 'secret' => false ],
				'REDEEMID_CLIENT_ID'     => [ 'value' => '', 'secret' => false ],
				'REDEEMID_CLIENT_SECRET' => [ 'value' => 'hunter2', 'secret' => true ],
			]
		);

		$this->assertSame(
			"define( 'REDEEMID_STORE_DOMAIN', 'shop\\'s.myshopify.com' );\n"
			. "define( 'REDEEMID_CLIENT_ID', 'paste-client-id-here' );\n"
			. "define( 'REDEEMID_CLIENT_SECRET', 'paste-client-secret-here' );",
			$snippet
		);
	}
}
