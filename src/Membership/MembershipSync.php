<?php

namespace WP2_Shopify\Membership;

use WP2_Shopify\Settings;
use WP2_Shopify\Shopify\Admin;
use WP2_Shopify\Support\CustomerId;
use WP2_Shopify\Support\Lock;
use WP2_Shopify\Support\Logger;
use WP2_Shopify\Sync\Mirror;

/**
 * The plugin-owned purchase-to-tag loop. Every membership event (paid,
 * cancelled, refunded) recomputes the customer's managed tags from their
 * orders, so out-of-order or replayed deliveries converge on the same answer.
 */
final class MembershipSync {
	/**
	 * @return true|\WP_Error|null Null when there is nothing to do (no map, no Admin token).
	 */
	public static function recompute( CustomerId $id ): true|\WP_Error|null {
		$plan = Settings::tag_plan();

		if ( $plan->is_empty() || ! Admin::available() ) {
			return null;
		}

		$lock = 'membership:' . $id->numeric;

		if ( ! Lock::acquire( $lock, 60 ) ) {
			// Another request is recomputing this customer; ask the queue to retry.
			return new \WP_Error( 'wp2_shopify_busy', 'Membership recompute already running.' );
		}

		try {
			$state = Admin::membership_orders( $id );

			if ( is_wp_error( $state ) ) {
				return $state;
			}

			// Without read_all_orders Shopify returns only the last 60 days of orders, so an
			// older membership purchase is invisible. Remove a tag only for a refund or
			// cancellation the plugin can see, never because an order fell out of the window.
			// The same holds when the customer has more orders than one page returns.
			$removable = Admin::full_order_history() && $state['complete'] ? null : $plan->revoked( $state['orders'] );
			$diff      = $plan->diff( $state['tags'], $plan->desired( $state['orders'] ), $removable );

			if ( $diff['add'] ) {
				$result = Admin::add_tags( $id, $diff['add'] );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			if ( $diff['remove'] ) {
				$result = Admin::remove_tags( $id, $diff['remove'] );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			// Update the mirror now instead of waiting for customer.tags_added to arrive.
			$user = Mirror::find_user( $id );

			if ( $user ) {
				$tags = array_values( array_diff( array_unique( array_merge( $state['tags'], $diff['add'] ) ), $diff['remove'] ) );
				Mirror::set_tags( $user->ID, $tags );
				Mirror::stamp( $user->ID, 'membership' );
			}

			Logger::log( 'membership.recomputed', [ 'customer' => $id->numeric, 'added' => count( $diff['add'] ), 'removed' => count( $diff['remove'] ) ] );

			return true;
		} finally {
			Lock::release( $lock );
		}
	}
}
