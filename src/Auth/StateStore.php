<?php

namespace WP2_Shopify\Auth;

/**
 * Server-side storage for an in-flight sign-in, keyed by the OAuth state.
 *
 * The PKCE verifier, the OIDC nonce, the post-login redirect and any link
 * token reference live here, never in browser storage. The
 * browser holds only the state value, in an HttpOnly cookie, which binds the
 * callback to the browser that started the flow.
 */
final class StateStore {
	public const COOKIE = 'wp2_shopify_oauth_state';
	private const TTL   = 10 * MINUTE_IN_SECONDS;

	public static function create( array $data ): string {
		$state = Pkce::random( 32 );

		set_transient( self::key( $state ), $data + [ 'created' => time() ], self::TTL );

		// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- OAuth state lives only on the uncached sign-in redirect and callback.
		setcookie(
			self::COOKIE,
			$state,
			[
				'expires'  => time() + self::TTL,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => \WP2_Shopify\Config::cookie_domain(),
				'secure'   => is_ssl(),
				'httponly' => true,
				// Lax, not Strict: the callback is a cross-site top-level navigation from Shopify.
				'samesite' => 'Lax',
			]
		);

		return $state;
	}

	/**
	 * Single use: the record is deleted as soon as it is read.
	 */
	public static function consume( string $state ): ?array {
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';

		self::clear_cookie();

		if ( '' === $state || '' === $cookie || ! hash_equals( $cookie, $state ) ) {
			return null;
		}

		$data = get_transient( self::key( $state ) );
		delete_transient( self::key( $state ) );

		return is_array( $data ) ? $data : null;
	}

	private static function clear_cookie(): void {
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) || headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE,
			'',
			[
				'expires'  => time() - DAY_IN_SECONDS,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => \WP2_Shopify\Config::cookie_domain(),
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
		unset( $_COOKIE[ self::COOKIE ] );
	}

	// phpcs:enable

	private static function key( string $state ): string {
		return 'wp2s_oauth_' . substr( hash( 'sha256', $state ), 0, 40 );
	}
}
