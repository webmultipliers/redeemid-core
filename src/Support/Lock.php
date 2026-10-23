<?php

namespace WP2_Shopify\Support;

/**
 * A small cross-request mutex. add_option() is an INSERT on a unique key, so
 * only one request can create the row; expired locks are taken over.
 */
final class Lock {
	public static function acquire( string $name, int $ttl = 30 ): bool {
		$option = 'wp2_shopify_lock_' . md5( $name );
		$now    = time();

		if ( add_option( $option, $now + $ttl, '', false ) ) {
			return true;
		}

		$expires = (int) get_option( $option, 0 );

		if ( $expires > $now ) {
			return false;
		}

		// Stale: replace only if nobody else replaced it first.
		global $wpdb;

		$taken = (bool) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) ( $now + $ttl ),
				$option,
				(string) $expires
			)
		);

		wp_cache_delete( $option, 'options' );

		return $taken;
	}

	public static function release( string $name ): void {
		delete_option( 'wp2_shopify_lock_' . md5( $name ) );
	}
}
