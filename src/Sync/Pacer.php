<?php

namespace RedeemID\Sync;

/**
 * How fast a bulk resync may call the Admin API. Pure, so it is unit tested.
 *
 * Shopify meters GraphQL by query cost against a bucket that refills at a
 * fixed rate, and reports both after every call (extensions.cost). A bulk
 * resync only spends what is above a reserve, so sign-ins, member refreshes
 * and webhook syncs always find budget left, whatever the store's plan.
 */
final class Pacer {
	/** Share of the bucket a bulk resync never touches. */
	public const RESERVE = 0.5;

	/** Consecutive failures before the resync stops and waits for staff. */
	public const MAX_FAILURES = 6;

	/** Longest wait between retries, in seconds. */
	public const MAX_BACKOFF = 900;

	/**
	 * Seconds to wait before the next batch.
	 *
	 * @param array{requested: int, actual: int, available: float, maximum: float, restore: float}|null $cost
	 *        Http::last_cost() after the previous batch; null when no call was made.
	 */
	public static function wait( ?array $cost ): float {
		if ( ! $cost ) {
			return 0.0;
		}

		$next = max( 1, $cost['requested'], $cost['actual'] );
		$need = $next + self::RESERVE * $cost['maximum'];

		if ( $cost['available'] >= $need ) {
			return 0.0;
		}

		return round( ( $need - $cost['available'] ) / max( 1.0, $cost['restore'] ), 2 );
	}

	/**
	 * Seconds to wait after the nth consecutive failure: 30, 60, 120… up to 15 minutes.
	 */
	public static function backoff( int $failures ): int {
		return (int) min( self::MAX_BACKOFF, 30 * ( 2 ** max( 0, min( 10, $failures - 1 ) ) ) );
	}

	/**
	 * Whether a failed call is worth repeating: Shopify was busy, slow or out
	 * of reach. Anything else (no credentials, a revoked app) needs a person.
	 */
	public static function transient( string $code ): bool {
		return in_array( $code, [ 'shopify_unreachable', 'shopify_throttled', 'shopify_bad_json', 'shopify_http_429' ], true )
			|| (bool) preg_match( '/^shopify_http_5\d\d$/', $code );
	}
}
