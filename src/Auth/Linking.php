<?php

namespace RedeemID\Auth;

use RedeemID\Support\Logger;

/**
 * Linking an existing WordPress account to a Shopify customer.
 *
 * The server issues a short-lived, single-use token, hashed at rest and
 * bound to the user and their WordPress session; the OAuth state carries only
 * that token, never a raw user ID.
 */
final class Linking {
	public const META = '_redeemid_link_token';
	private const TTL  = 10 * MINUTE_IN_SECONDS;

	/** Pending confirmations after a verified email match. */
	private const PENDING = 'redeemid_link_pending_';

	/** Binds held sign-ins to the browser that started them. */
	public const COOKIE = 'redeemid_pending';

	public static function refusal( int $user_id ): ?string {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return 'no_user';
		}

		if ( self::privileged( $user ) ) {
			return 'admin_refused';
		}

		if ( ShadowUsers::is_shadow( $user_id ) ) {
			return 'already_shadow';
		}

		return null;
	}

	/**
	 * Accounts that never link to, or sign in through, a Shopify customer.
	 */
	public static function privileged( \WP_User $user ): bool {
		return user_can( $user, 'manage_options' ) || in_array( 'administrator', (array) $user->roles, true );
	}

	public static function issue( int $user_id ): string {
		$token = Pkce::random( 32 );

		update_user_meta(
			$user_id,
			self::META,
			[
				'hash'    => hash( 'sha256', $token ),
				'session' => hash( 'sha256', wp_get_session_token() ),
				'expires' => time() + self::TTL,
			]
		);

		return $token;
	}

	/**
	 * Verify and burn a link token. Returns null on success, else an error code.
	 */
	public static function redeem( int $user_id, string $token ): ?string {
		$record = get_user_meta( $user_id, self::META, true );
		delete_user_meta( $user_id, self::META );

		if ( ! is_array( $record ) || '' === $token ) {
			return 'link_token_missing';
		}

		if ( (int) $record['expires'] < time() ) {
			return 'link_token_expired';
		}

		if ( ! hash_equals( (string) $record['hash'], hash( 'sha256', $token ) ) ) {
			return 'link_token_mismatch';
		}

		if ( ! hash_equals( (string) $record['session'], hash( 'sha256', wp_get_session_token() ) ) ) {
			return 'link_session_mismatch';
		}

		return null;
	}

	/**
	 * Require a verified Shopify email equal to the WordPress account email.
	 * The email and its verification come from the ID token claims; when Shopify
	 * does not assert email_verified, linking is refused (fail closed).
	 */
	public static function email_check( int $user_id, array $claims, string $api_email ): ?string {
		$user  = get_userdata( $user_id );
		$email = strtolower( trim( (string) ( $claims['email'] ?? $api_email ) ) );

		if ( true !== ( $claims['email_verified'] ?? null ) ) {
			return 'email_unverified';
		}

		if ( ! $user || '' === $email || strtolower( $user->user_email ) !== $email ) {
			return 'email_mismatch';
		}

		return null;
	}

	/**
	 * Park a sign-in that needs one more answer from the visitor. The ref
	 * travels in the callback URL, where analytics, logs and history can see
	 * it, so it is bound to this browser too: the record keeps a hash of a
	 * random HttpOnly cookie, and a ref opened in any other browser is
	 * worthless.
	 */
	public static function hold( array $pending ): string {
		$ref = Pkce::random( 24 );

		set_transient( self::PENDING . hash( 'sha256', $ref ), $pending + [ 'browser' => hash( 'sha256', self::browser_secret() ) ], self::TTL );

		return $ref;
	}

	/**
	 * Read and burn a held sign-in. A ref from another browser is refused and
	 * left in place, so a leaked link can't cancel the real visitor's step.
	 */
	public static function take( string $ref ): ?array {
		$key     = self::PENDING . hash( 'sha256', $ref );
		$pending = get_transient( $key );

		if ( ! is_array( $pending ) || ! self::bound( $pending, self::browser_cookie() ) ) {
			return null;
		}

		delete_transient( $key );

		return $pending;
	}

	public static function peek( string $ref ): ?array {
		$pending = get_transient( self::PENDING . hash( 'sha256', $ref ) );

		return is_array( $pending ) && self::bound( $pending, self::browser_cookie() ) ? $pending : null;
	}

	/**
	 * Whether a held record belongs to the browser holding $cookie. Pure.
	 */
	public static function bound( array $pending, string $cookie ): bool {
		$stored = $pending['browser'] ?? '';

		return is_string( $stored ) && '' !== $stored && '' !== $cookie && hash_equals( $stored, hash( 'sha256', $cookie ) );
	}

	/**
	 * This browser's secret for held sign-ins, set on first use.
	 */
	private static function browser_secret(): string {
		$secret = self::browser_cookie();

		if ( '' !== $secret ) {
			return $secret;
		}

		$secret = Pkce::random( 32 );

		if ( ! headers_sent() ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- set only on the uncached sign-in callback.
			setcookie(
				self::COOKIE,
				$secret,
				[
					'expires'  => time() + self::TTL,
					'path'     => COOKIEPATH ?: '/',
					'domain'   => \RedeemID\Config::cookie_domain(),
					'secure'   => is_ssl(),
					'httponly' => true,
					// Lax: the callback that sets it is a top-level navigation back from Shopify.
					'samesite' => 'Lax',
				]
			);
		}

		$_COOKIE[ self::COOKIE ] = $secret; // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- so a second hold in this request reuses the secret.

		return $secret;
	}

	private static function browser_cookie(): string {
		// phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- read only on the uncached callback and its form posts.
		$value = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';

		return preg_match( '/^[A-Za-z0-9_-]{43}$/', $value ) ? $value : '';
	}

	public static function log_refusal( int $user_id, string $reason ): void {
		Logger::log( 'link.refused', [ 'user' => $user_id, 'reason' => $reason ] );
	}
}
