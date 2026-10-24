<?php

namespace WP2_Shopify\Auth;

use WP2_Shopify\Support\Logger;

/**
 * Linking an existing WordPress account to a Shopify customer.
 *
 * The POC set a link-user cookie in JavaScript that the server never read.
 * Here the server issues a short-lived, single-use token, hashed at rest and
 * bound to the user and their WordPress session; the OAuth state carries only
 * that token, never a raw user ID.
 */
final class Linking {
	private const META = '_wp2_shopify_link_token';
	private const TTL  = 10 * MINUTE_IN_SECONDS;

	/** Pending confirmations after a verified email match. */
	private const PENDING = 'wp2s_link_pending_';

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

	public static function hold( array $pending ): string {
		$ref = Pkce::random( 24 );

		set_transient( self::PENDING . hash( 'sha256', $ref ), $pending, self::TTL );

		return $ref;
	}

	public static function take( string $ref ): ?array {
		$key     = self::PENDING . hash( 'sha256', $ref );
		$pending = get_transient( $key );
		delete_transient( $key );

		return is_array( $pending ) ? $pending : null;
	}

	public static function peek( string $ref ): ?array {
		$pending = get_transient( self::PENDING . hash( 'sha256', $ref ) );

		return is_array( $pending ) ? $pending : null;
	}

	public static function log_refusal( int $user_id, string $reason ): void {
		Logger::log( 'link.refused', [ 'user' => $user_id, 'reason' => $reason ] );
	}
}
