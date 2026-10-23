<?php

namespace WP2_Shopify\Sync;

use WP2_Shopify\Membership\MembershipSync;
use WP2_Shopify\Shopify\Admin;

/**
 * Closes the gap between paying and access (review point 1): a buyer who
 * returns from checkout should not see a page that is still locked while the
 * orders/paid webhook is in flight. The checkout handoff (Phase 2) calls
 * mark(); the next signed-in page views re-run the membership computation,
 * at most once a minute, for up to 30 minutes.
 */
final class PostCheckout {
	public const META       = '_wp2_shopify_checkout_started';
	private const ATTEMPT   = '_wp2_shopify_post_checkout_attempt';
	private const WINDOW    = 30 * MINUTE_IN_SECONDS;
	private const INTERVAL  = MINUTE_IN_SECONDS;

	public static function register(): void {
		add_action( 'template_redirect', [ self::class, 'maybe_sync' ], 1 );
	}

	public static function mark( int $user_id ): void {
		update_user_meta( $user_id, self::META, time() );
	}

	public static function maybe_sync(): void {
		$user_id = get_current_user_id();
		$started = $user_id ? (int) get_user_meta( $user_id, self::META, true ) : 0;

		if ( ! $started ) {
			return;
		}

		if ( time() - $started > self::WINDOW ) {
			delete_user_meta( $user_id, self::META );
			delete_user_meta( $user_id, self::ATTEMPT );
			return;
		}

		if ( time() - (int) get_user_meta( $user_id, self::ATTEMPT, true ) < self::INTERVAL ) {
			return;
		}

		update_user_meta( $user_id, self::ATTEMPT, time() );

		$id = Mirror::customer_id( $user_id );

		if ( ! $id || ! Admin::available() ) {
			return;
		}

		$before = Mirror::tags( $user_id );
		$result = MembershipSync::recompute( $id );

		if ( null === $result ) {
			Reconciler::sync_user( $user_id, 'post_checkout' );
		}

		if ( Mirror::tags( $user_id ) !== $before ) {
			// Access changed: stop polling.
			delete_user_meta( $user_id, self::META );
		}
	}
}
