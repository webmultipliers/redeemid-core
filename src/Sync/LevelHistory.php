<?php

namespace WP2_Shopify\Sync;

use WP2_Shopify\Access\LevelStore;
use WP2_Shopify\Access\Membership;
use WP2_Shopify\Support\Logger;

/**
 * Notices when a customer joins or leaves a level, and keeps a short record
 * of it: when each level was first and last held. The record is history for
 * display ("Your Gold membership ended", "Member since"), never a grant:
 * access is always decided from the synced tags.
 *
 * Like feature changes, levels are noted before the first mirror write in a
 * request and compared at shutdown, so one sync that writes several keys
 * fires once. wp2_shopify_levels_changed is the hook for a welcome email, a
 * CRM update or an analytics event.
 */
final class LevelHistory {
	public const META = Mirror::HISTORY;

	private const WATCHED = [ Mirror::TAGS, Mirror::STATUS, Mirror::LAST_SYNC, Mirror::GID, Mirror::NUMERIC ];

	/** @var array<int, string[]> User ID => levels held before this request's mirror writes. */
	private static array $before = [];

	public static function register(): void {
		foreach ( [ 'add', 'update', 'delete' ] as $op ) {
			add_filter( "{$op}_user_metadata", [ self::class, 'before_write' ], 10, 3 );
		}

		add_action( 'shutdown', [ self::class, 'after_writes' ], 5 );
	}

	public static function before_write( mixed $check, mixed $user_id, mixed $key ): mixed {
		$user_id = (int) $user_id;

		if ( $user_id > 0 && in_array( $key, self::WATCHED, true ) && ! isset( self::$before[ $user_id ] ) ) {
			self::$before[ $user_id ] = self::levels( $user_id );
		}

		return $check;
	}

	public static function after_writes(): void {
		foreach ( self::$before as $user_id => $before ) {
			$after   = self::levels( $user_id );
			$added   = array_values( array_diff( $after, $before ) );
			$removed = array_values( array_diff( $before, $after ) );

			if ( ! $added && ! $removed ) {
				continue;
			}

			$history = self::apply( self::get( $user_id ), $added, $removed, time() );

			// Unlinking clears the mirror; the record goes with it.
			if ( null !== Mirror::customer_id( $user_id ) ) {
				update_user_meta( $user_id, self::META, $history );
			}

			Logger::log( 'level.changed', [ 'user' => $user_id, 'added' => implode( ',', $added ), 'removed' => implode( ',', $removed ) ] );

			/**
			 * A customer joined or left access levels, because Shopify changed
			 * their tags or status. History, not a grant: use it for a welcome
			 * email, a CRM update or analytics.
			 *
			 * @param int      $user_id
			 * @param string[] $added   Level slugs now held.
			 * @param string[] $removed Level slugs no longer held.
			 */
			do_action( 'wp2_shopify_levels_changed', $user_id, $added, $removed );
		}

		self::$before = [];
	}

	/**
	 * Record joins and departures. Pure.
	 *
	 * @param array<string, array{since: int, until: int}> $history
	 * @param string[]                                      $added
	 * @param string[]                                      $removed
	 * @return array<string, array{since: int, until: int}>
	 */
	public static function apply( array $history, array $added, array $removed, int $now ): array {
		foreach ( $added as $slug ) {
			$since             = (int) ( $history[ $slug ]['since'] ?? 0 );
			$history[ $slug ] = [ 'since' => $since && empty( $history[ $slug ]['until'] ) ? $since : $now, 'until' => 0 ];
		}

		foreach ( $removed as $slug ) {
			$history[ $slug ] = [ 'since' => (int) ( $history[ $slug ]['since'] ?? 0 ), 'until' => $now ];
		}

		return $history;
	}

	/**
	 * @return array<string, array{since: int, until: int}>
	 */
	public static function get( int $user_id ): array {
		$history = get_user_meta( $user_id, self::META, true );

		return is_array( $history ) ? $history : [];
	}

	/**
	 * Whether the customer held $slug before and no longer does.
	 */
	public static function lapsed( int $user_id, string $slug ): bool {
		$entry = self::get( $user_id )[ $slug ] ?? null;

		return is_array( $entry ) && ! empty( $entry['until'] );
	}

	/**
	 * @return string[]
	 */
	private static function levels( int $user_id ): array {
		return Membership::held( LevelStore::levels(), Mirror::member( $user_id ), time() );
	}
}
