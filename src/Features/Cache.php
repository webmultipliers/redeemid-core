<?php

namespace WP2_Shopify\Features;

/**
 * The caching rule each feature declares, applied when a page actually
 * depends on it for a signed-in visitor. Guests are not personalised: they
 * can only hold Public features, which every guest holds alike.
 *
 * - private: the page is not stored in a shared cache (DONOTCACHEPAGE, and
 *   Cache-Control: private while headers can still be sent).
 * - vary: the wp2_shopify_fset cookie carries a hash of the visitor's
 *   granted set, for a cache layer that can vary on a cookie. Whether yours
 *   can is for you to verify on your stack.
 * - fragment: nothing here; the consumer renders the varying part per
 *   visitor (a dynamic island, an uncached endpoint).
 */
final class Cache {
	public const COOKIE = 'wp2_shopify_fset';

	public static function used( ?Feature $feature ): void {
		if ( ! $feature || Feature::CACHE_PRIVATE !== $feature->cache || ! is_user_logged_in() || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}

		self::private_page();
	}

	public static function private_page(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		}

		if ( ! headers_sent() ) {
			header( 'Cache-Control: private, no-store, max-age=0' );
		}
	}

	/**
	 * A stable, opaque key for a granted set: sorted slugs, hashed with the
	 * site's salt so the cookie names no feature. Pure apart from the salt.
	 *
	 * @param string[] $slugs
	 */
	public static function key( array $slugs, string $salt ): string {
		sort( $slugs );

		return $slugs ? substr( hash_hmac( 'sha256', implode( ',', $slugs ), $salt ), 0, 16 ) : '';
	}

	/**
	 * Keep the vary cookie in step with the visitor's set, when any feature
	 * declares the vary option.
	 */
	public static function vary_cookie(): void {
		$vary = array_filter( Registry::features(), static fn( Feature $f ) => Feature::CACHE_VARY === $f->cache );

		if ( ! $vary || headers_sent() ) {
			return;
		}

		$key = is_user_logged_in() ? self::key( array_keys( array_intersect_key( Registry::granted(), $vary ) ), wp_salt( 'auth' ) ) : '';
		$has = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';

		if ( $key === $has ) {
			return;
		}

		setcookie( self::COOKIE, $key, [ 'expires' => '' === $key ? time() - HOUR_IN_SECONDS : 0, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ] );
	}
}
