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
	 * Accounts that never link to, or sign in through, a Shopify customer:
	 * anyone who can edit posts. A Shopify sign-in is an emailed code, so it
	 * would skip the WordPress password and any two-factor plugin; staff use
	 * the WordPress login.
	 *
	 * On multisite a user and their login cookie belong to the whole network,
	 * so staff on any site of the network, and super admins, count as staff
	 * here too: otherwise an editor of one site could be linked on another and
	 * reach their editor account through an emailed code.
	 */
	public static function privileged( \WP_User $user ): bool {
		if ( self::staff_on_site( $user ) ) {
			return true;
		}

		if ( ! is_multisite() ) {
			return false;
		}

		if ( is_super_admin( $user->ID ) ) {
			return true;
		}

		foreach ( self::other_sites( array_keys( get_blogs_of_user( $user->ID ) ), get_current_blog_id() ) as $site_id ) {
			if ( self::staff_on_site( new \WP_User( $user->ID, '', $site_id ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The sites to check besides this one. Pure.
	 *
	 * @param array<int|string> $sites Site IDs the user belongs to.
	 * @return int[]
	 */
	public static function other_sites( array $sites, int $current ): array {
		return array_values( array_filter( array_unique( array_map( 'intval', $sites ) ), static fn( int $site ) => $site > 0 && $site !== $current ) );
	}

	/**
	 * Staff on the site the user object was loaded for.
	 */
	private static function staff_on_site( \WP_User $user ): bool {
		return user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_options' ) || in_array( 'administrator', (array) $user->roles, true );
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

	public static function log_refusal( int $user_id, string $reason ): void {
		Logger::log( 'link.refused', [ 'user' => $user_id, 'reason' => $reason ] );
	}
}
