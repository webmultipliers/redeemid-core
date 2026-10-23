<?php

namespace WP2_Shopify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP2_Shopify\Membership\TagPlan;

final class TagPlanTest extends TestCase {
	private function plan(): TagPlan {
		return new TagPlan( TagPlan::parse_map( "# membership products\n632910392: member, gold\ngid://shopify/Product/111: member\nbad line\nabc: x\n" ) );
	}

	public function test_parses_map_lines(): void {
		$this->assertSame( [ '632910392' => [ 'member', 'gold' ], '111' => [ 'member' ] ], TagPlan::parse_map( "632910392: member, gold\ngid://shopify/Product/111: member\nbad\n" ) );
	}

	public function test_paid_order_grants_tags(): void {
		$orders = [ [ 'cancelled' => false, 'financial_status' => 'PAID', 'products' => [ 'gid://shopify/Product/632910392' => 1 ] ] ];

		$this->assertSame( [ 'member', 'gold' ], $this->plan()->desired( $orders ) );
	}

	public function test_refunded_cancelled_and_pending_orders_grant_nothing(): void {
		$orders = [
			[ 'cancelled' => false, 'financial_status' => 'REFUNDED', 'products' => [ '632910392' => 1 ] ],
			[ 'cancelled' => true, 'financial_status' => 'PAID', 'products' => [ '632910392' => 1 ] ],
			[ 'cancelled' => false, 'financial_status' => 'PENDING', 'products' => [ '632910392' => 1 ] ],
			[ 'cancelled' => false, 'financial_status' => 'PAID', 'products' => [ '632910392' => 0 ] ],
		];

		$this->assertSame( [], $this->plan()->desired( $orders ) );
	}

	public function test_diff_adds_missing_and_removes_only_managed_tags(): void {
		$diff = $this->plan()->diff( [ 'Gold', 'newsletter', 'wholesale' ], [ 'member' ] );

		$this->assertSame( [ 'member' ], $diff['add'] );
		$this->assertSame( [ 'Gold' ], $diff['remove'] );
	}

	public function test_second_product_keeps_shared_tag_after_one_refund(): void {
		$orders = [
			[ 'cancelled' => false, 'financial_status' => 'REFUNDED', 'products' => [ '632910392' => 0 ] ],
			[ 'cancelled' => false, 'financial_status' => 'PAID', 'products' => [ '111' => 1 ] ],
		];
		$plan   = $this->plan();
		$diff   = $plan->diff( [ 'member', 'gold' ], $plan->desired( $orders ) );

		$this->assertSame( [], $diff['add'] );
		$this->assertSame( [ 'gold' ], $diff['remove'] );
	}

	public function test_converged_state_is_a_no_op(): void {
		$plan = $this->plan();

		$this->assertSame( [ 'add' => [], 'remove' => [] ], $plan->diff( [ 'member', 'gold' ], [ 'member', 'gold' ] ) );
	}

	public function test_partial_history_keeps_tags_whose_order_fell_out_of_the_window(): void {
		// Only a recent non-membership order is visible; the membership order is older than 60 days.
		$orders = [ [ 'cancelled' => false, 'financial_status' => 'PAID', 'products' => [ '999' => 1 ] ] ];
		$plan   = $this->plan();
		$diff   = $plan->diff( [ 'member', 'gold' ], $plan->desired( $orders ), $plan->revoked( $orders ) );

		$this->assertSame( [ 'add' => [], 'remove' => [] ], $diff );
	}

	public function test_partial_history_still_removes_tags_for_a_visible_refund(): void {
		$orders = [ [ 'cancelled' => false, 'financial_status' => 'REFUNDED', 'products' => [ '632910392' => 0 ] ] ];
		$plan   = $this->plan();
		$diff   = $plan->diff( [ 'member', 'gold', 'wholesale' ], $plan->desired( $orders ), $plan->revoked( $orders ) );

		$this->assertSame( [ 'member', 'gold' ], $diff['remove'] );
	}
}
