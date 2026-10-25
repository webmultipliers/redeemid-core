<?php

namespace RedeemID\Auth;

use RedeemID\Plugin;
use RedeemID\Support\Lock;
use RedeemID\Support\Logger;

/**
 * Shopify tokens for one WordPress login session.
 *
 * Access, refresh and ID tokens are encrypted and stored inside the WordPress
 * session record (WP_Session_Tokens), so they are bound to that session,
 * destroyed with it on logout, and never sent to the browser. Signing in on a
 * second device does not overwrite the first device's tokens.
 */
final class TokenVault {
	private const KEY = 'redeemid';

	/** Session token captured from the set_auth_cookie action during sign-in. */
	private static string $fresh_session = '';

	public static function capture_session_token( $cookie, $expire, $expiration, $user_id, $scheme, $token ): void {
		self::$fresh_session = (string) $token;
	}

	public static function store( int $user_id, array $tokens, ?string $session_token = null ): bool {
		$session_token = $session_token ?? ( self::$fresh_session ?: wp_get_session_token() );

		if ( '' === $session_token ) {
			return false;
		}

		$manager = \WP_Session_Tokens::get_instance( $user_id );
		$session = $manager->get( $session_token );

		if ( ! is_array( $session ) ) {
			return false;
		}

		$session[ self::KEY ] = Plugin::crypto()->encrypt(
			(string) wp_json_encode(
				[
					'access_token'  => $tokens['access_token'] ?? '',
					'refresh_token' => $tokens['refresh_token'] ?? '',
					'id_token'      => $tokens['id_token'] ?? '',
					'expires_at'    => (int) ( $tokens['expires_at'] ?? 0 ),
				]
			)
		);

		$manager->update( $session_token, $session );

		return true;
	}

	public static function get( int $user_id, ?string $session_token = null ): ?array {
		$session_token = $session_token ?? wp_get_session_token();

		if ( $user_id <= 0 || '' === $session_token ) {
			return null;
		}

		$session = \WP_Session_Tokens::get_instance( $user_id )->get( $session_token );

		if ( ! is_array( $session ) || empty( $session[ self::KEY ] ) ) {
			return null;
		}

		$json = Plugin::crypto()->decrypt( (string) $session[ self::KEY ] );
		$data = null === $json ? null : json_decode( $json, true );

		return is_array( $data ) ? $data : null;
	}

	public static function forget( int $user_id, ?string $session_token = null ): void {
		$session_token = $session_token ?? wp_get_session_token();

		if ( $user_id <= 0 || '' === $session_token ) {
			return;
		}

		$manager = \WP_Session_Tokens::get_instance( $user_id );
		$session = $manager->get( $session_token );

		if ( is_array( $session ) && isset( $session[ self::KEY ] ) ) {
			unset( $session[ self::KEY ] );
			$manager->update( $session_token, $session );
		}
	}

	/**
	 * Remove Shopify tokens from every session of a user (disconnect, erasure).
	 */
	public static function forget_all( int $user_id ): void {
		$meta = get_user_meta( $user_id, 'session_tokens', true );

		if ( ! is_array( $meta ) ) {
			return;
		}

		foreach ( $meta as $verifier => $session ) {
			if ( is_array( $session ) && isset( $session[ self::KEY ] ) ) {
				unset( $meta[ $verifier ][ self::KEY ] );
			}
		}

		update_user_meta( $user_id, 'session_tokens', $meta );
	}

	/**
	 * A usable access token for the current session, refreshing server-side
	 * when it is about to expire. Refresh is single-flight per session.
	 */
	public static function access_token( int $user_id ): ?string {
		$tokens = self::get( $user_id );

		if ( ! $tokens || '' === $tokens['access_token'] ) {
			return null;
		}

		if ( $tokens['expires_at'] > time() + 60 ) {
			return $tokens['access_token'];
		}

		$refreshed = self::refresh( $user_id );

		return is_wp_error( $refreshed ) ? null : $refreshed['access_token'];
	}

	public static function refresh( int $user_id ): array|\WP_Error {
		$session_token = wp_get_session_token();
		$tokens        = self::get( $user_id, $session_token );

		if ( ! $tokens || '' === $tokens['refresh_token'] ) {
			return new \WP_Error( 'redeemid_no_session', 'There is no Shopify session to refresh.' );
		}

		$lock = 'refresh:' . hash( 'sha256', $session_token );

		if ( ! Lock::acquire( $lock, 30 ) ) {
			// Another request is refreshing this session; it will store the rotated token.
			return new \WP_Error( 'redeemid_refresh_busy', 'Session refresh already in progress.' );
		}

		try {
			$fresh = OAuth::refresh( $tokens['refresh_token'] );

			if ( is_wp_error( $fresh ) ) {
				$data = $fresh->get_error_data();

				if ( is_array( $data ) && 'invalid_grant' === ( $data['oauth_error'] ?? '' ) ) {
					self::on_revoked( $user_id );
				}

				Logger::log( 'auth.refresh_failed', [ 'user' => $user_id, 'error' => $fresh->get_error_code() ] );

				return $fresh;
			}

			// Rotation: the old refresh token is replaced in the same write.
			$fresh['id_token'] = '' !== $fresh['id_token'] ? $fresh['id_token'] : $tokens['id_token'];
			self::store( $user_id, $fresh, $session_token );

			return $fresh;
		} finally {
			Lock::release( $lock );
		}
	}

	/**
	 * The Shopify session was revoked or expired for good.
	 *
	 * Shadow users have no other credential, so their WordPress session ends.
	 * A linked regular WordPress account keeps its WordPress session and only
	 * loses Shopify-backed features; gating still reads the synced mirror.
	 */
	private static function on_revoked( int $user_id ): void {
		self::forget( $user_id );

		if ( ShadowUsers::is_shadow( $user_id ) ) {
			wp_destroy_current_session();
			wp_clear_auth_cookie();
			wp_set_current_user( 0 );
		}
	}
}
