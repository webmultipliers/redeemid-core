<?php

namespace RedeemID\Features;

/**
 * The caching rule each feature declares, applied when a page actually
 * depends on it for a signed-in visitor. Guests are not personalised: they
 * can only hold Public features, which every guest holds alike.
 *
 * - private: the page is not stored in a shared cache (DONOTCACHEPAGE, and
 *   Cache-Control: private while headers can still be sent).
 * - vary: the redeemid_fset cookie carries a key for the visitor's granted
 *   set, for a cache layer that can vary on a cookie. Whether yours can is
 *   for you to verify on your stack. The key is bound to the member and their
 *   login session, so it can't be forged or copied from another member: a
 *   cache can't check a signature, so a key that anyone holding the same set
 *   shares would let a visitor who copies it read cached pages meant for
 *   that set. Each member gets their own cached variant instead.
 * - fragment: nothing here; the consumer renders the varying part per
 *   visitor (a dynamic island, an uncached endpoint).
 */
final class Cache {
	public const COOKIE = 'redeemid_fset';

	public static function used( ?Feature $feature ): void {
		if ( ! $feature || Feature::CACHE_PRIVATE !== $feature->cache || ! is_user_logged_in() || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}

		self::private_page();
	}

	/**
	 * Keep this response out of shared caches: DONOTCACHEPAGE for page-cache
	 * plugins, and Cache-Control: private for CDNs and proxies that never
	 * see that constant. Every personal render (granted items, gate areas,
	 * per-block Access settings, member-aware plan cards, locked panels with
	 * a nonce) calls this, not the constant alone.
	 */
	public static function private_page(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		}

		if ( ! headers_sent() ) {
			header( 'Cache-Control: private, no-store, max-age=0' );
		}
	}

	/**
	 * An opaque key for a granted set, bound to who holds it: sorted slugs and
	 * the member's binding (user ID and login session), hashed with the site's
	 * salt so the cookie names no feature and can't be made for another
	 * member or session. Pure apart from the salt.
	 *
	 * @param string[] $slugs
	 */
	public static function key( array $slugs, string $salt, string $who = '' ): string {
		sort( $slugs );

		return $slugs ? substr( hash_hmac( 'sha256', $who . '|' . implode( ',', $slugs ), $salt ), 0, 32 ) : '';
	}

	/**
	 * What binds the vary key to this visitor: the user and the session token
	 * WordPress holds for their login, so a new login gets a new key and a
	 * logged-out copy is worthless.
	 */
	private static function who(): string {
		return get_current_user_id() . ':' . wp_get_session_token();
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

		$key = is_user_logged_in() ? self::key( array_keys( array_intersect_key( Registry::granted(), $vary ) ), wp_salt( 'auth' ), self::who() ) : '';
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- the vary cookie exists for the cache; its key is bound to the member and session (key()).
		$has = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';

		if ( $key === $has ) {
			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- see above.
		setcookie( self::COOKIE, $key, [ 'expires' => '' === $key ? time() - HOUR_IN_SECONDS : 0, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ] );
	}
}
