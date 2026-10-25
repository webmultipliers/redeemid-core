<?php

namespace WP2_Shopify\Support;

use WP2_Shopify\Access\LevelStore;
use WP2_Shopify\Admin\CustomerQuery;
use WP2_Shopify\Webhooks\Monitor;

/**
 * Daily counters for Shopify → Reports, kept for 400 days in one option.
 *
 * The event log keeps 30 days, so it can't answer "how many joined Gold this
 * year". Stats counts a few low-frequency events as they are logged (a level
 * gained or lost, a sign-in, a new link, a buy-now checkout, a purchase that
 * unlocked access) and, once a day, how many linked customers hold each
 * level. Nothing here is per page view, so it costs nothing on busy sites.
 *
 * Counts only, by day: no user IDs. bump(), weekly() and prune() are pure.
 */
final class Stats {
	public const OPTION = 'wp2_shopify_stats';
	public const KEEP   = 400;

	public static function register(): void {
		add_action( 'wp2_shopify_log', [ self::class, 'record' ], 20, 2 );
		add_action( Monitor::HOOK, [ self::class, 'snapshot' ], 20 );
	}

	/**
	 * @param array<string, scalar|null> $context
	 */
	public static function record( string $event, array $context ): void {
		$keys = self::keys_for( $event, $context );

		if ( ! $keys ) {
			return;
		}

		$stats = get_option( self::OPTION, [] );
		$day   = gmdate( 'Y-m-d' );

		foreach ( $keys as $key ) {
			$stats = self::bump( is_array( $stats ) ? $stats : [], $day, $key );
		}

		update_option( self::OPTION, self::prune( $stats, time() ), false );
	}

	/**
	 * The counters an event adds to. Pure.
	 *
	 * @param array<string, scalar|null> $context
	 * @return string[]
	 */
	public static function keys_for( string $event, array $context ): array {
		$split = static fn( mixed $list ): array => array_filter( array_map( 'trim', explode( ',', (string) $list ) ), 'strlen' );

		return match ( $event ) {
			'level.changed'          => [ ...array_map( static fn( $s ) => 'join:' . $s, $split( $context['added'] ?? '' ) ), ...array_map( static fn( $s ) => 'leave:' . $s, $split( $context['removed'] ?? '' ) ) ],
			'auth.signed_in'         => [ 'sign_in' ],
			'link.linked'            => [ 'linked' ],
			'commerce.buy_now'       => [ 'checkout' ],
			'checkout.access_ready'  => [ 'unlocked' ],
			default                  => [],
		};
	}

	/**
	 * Once a day: linked customers holding each level.
	 */
	public static function snapshot(): void {
		$stats = get_option( self::OPTION, [] );
		$stats = is_array( $stats ) ? $stats : [];
		$day   = gmdate( 'Y-m-d' );

		foreach ( LevelStore::levels()->all() as $slug => $level ) {
			if ( ! $level->builtin() ) {
				$stats[ $day ][ 'members:' . $slug ] = count( CustomerQuery::level_ids( (string) $slug ) );
			}
		}

		update_option( self::OPTION, self::prune( $stats, time() ), false );
	}

	/**
	 * @param array<string, array<string, int>> $stats
	 * @return array<string, array<string, int>>
	 */
	public static function bump( array $stats, string $day, string $key, int $by = 1 ): array {
		$stats[ $day ][ $key ] = (int) ( $stats[ $day ][ $key ] ?? 0 ) + $by;

		return $stats;
	}

	/**
	 * Drop days older than KEEP.
	 *
	 * @param array<string, array<string, int>> $stats
	 * @return array<string, array<string, int>>
	 */
	public static function prune( array $stats, int $now ): array {
		$cutoff = gmdate( 'Y-m-d', $now - self::KEEP * 86400 );

		return array_filter( $stats, static fn( $day ) => is_string( $day ) && $day >= $cutoff, ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Sum of $key (or of every key starting with $key when it ends in ':')
	 * over the $days days up to and including $now's day. Pure.
	 *
	 * @param array<string, array<string, int>> $stats
	 */
	public static function total( array $stats, string $key, int $days, int $now ): int {
		$from = gmdate( 'Y-m-d', $now - ( $days - 1 ) * 86400 );
		$to   = gmdate( 'Y-m-d', $now );
		$sum  = 0;

		foreach ( $stats as $day => $row ) {
			if ( (string) $day < $from || (string) $day > $to ) {
				continue;
			}

			foreach ( $row as $k => $n ) {
				if ( $k === $key || ( str_ends_with( $key, ':' ) && str_starts_with( (string) $k, $key ) ) ) {
					$sum += (int) $n;
				}
			}
		}

		return $sum;
	}

	/**
	 * Weekly sums of $key for the last $weeks weeks, oldest first, each week
	 * ending on $now's day. Pure.
	 *
	 * @param array<string, array<string, int>> $stats
	 * @return array<int, array{end: string, value: int}>
	 */
	public static function weekly( array $stats, string $key, int $weeks, int $now ): array {
		$out = [];

		for ( $w = $weeks - 1; $w >= 0; $w-- ) {
			$end   = $now - $w * 7 * 86400;
			$out[] = [ 'end' => gmdate( 'Y-m-d', $end ), 'value' => self::total( $stats, $key, 7, $end ) ];
		}

		return $out;
	}
}
