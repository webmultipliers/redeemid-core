<?php

namespace RedeemID\Auth;

use RedeemID\Settings;
use RedeemID\Support\Logger;
use RedeemID\Sync\Mirror;

/**
 * An optional cap on how many browsers a linked member can be signed in
 * on at once, to slow down one paid account shared by many people. Off by
 * default (0). When a sign-in goes over the cap, the member's oldest
 * WordPress sessions end; their Shopify account and tags are untouched.
 *
 * It only ever signs people out, so it can't grant anything. Staff (anyone
 * who can edit posts) and users who aren't linked to Shopify are never
 * capped, so it can't lock an editor out.
 */
final class SessionCap {
	public const MAX = 20;

	public static function register(): void {
		add_action( 'set_logged_in_cookie', [ self::class, 'on_cookie' ], 20, 6 );
	}

	public static function limit(): int {
		return max( 0, min( self::MAX, (int) Settings::get( 'session_limit' ) ) );
	}

	/**
	 * Runs once WordPress has stored the new session, before the response.
	 */
	public static function on_cookie( mixed $cookie, mixed $expire, mixed $expiration, mixed $user_id, mixed $scheme = 'logged_in', mixed $token = '' ): void {
		$user_id = (int) $user_id;
		$limit   = self::limit();

		if ( $limit <= 0 || $user_id <= 0 || null === Mirror::customer_id( $user_id ) || user_can( $user_id, 'edit_posts' ) ) {
			return;
		}

		$manager = \WP_Session_Tokens::get_instance( $user_id );

		// Other session stores (a Redis one, say) keep their own shape: leave them be.
		if ( ! $manager instanceof \WP_User_Meta_Session_Tokens ) {
			return;
		}

		$sessions = get_user_meta( $user_id, 'session_tokens', true );
		$sessions = is_array( $sessions ) ? $sessions : [];
		$keep     = self::keep( $sessions, $limit, is_string( $token ) && '' !== $token ? hash( 'sha256', $token ) : '' );
		$ended    = count( $sessions ) - count( $keep );

		if ( $ended <= 0 ) {
			return;
		}

		update_user_meta( $user_id, 'session_tokens', $keep );
		Logger::log( 'auth.sessions_capped', [ 'user' => $user_id, 'ended' => $ended, 'limit' => $limit ] );
	}

	/**
	 * The newest $limit live sessions, always including $current (the hashed
	 * token of the sign-in that just happened). Expired ones go first. Pure.
	 *
	 * @param array<string, array{expiration?: int, login?: int}> $sessions verifier => session.
	 * @return array<string, array>
	 */
	public static function keep( array $sessions, int $limit, string $current = '', ?int $now = null ): array {
		$now      = $now ?? time();
		$sessions = array_filter( $sessions, static fn( $s ) => is_array( $s ) && (int) ( $s['expiration'] ?? 0 ) >= $now );

		if ( $limit <= 0 || count( $sessions ) <= $limit ) {
			return $sessions;
		}

		uksort(
			$sessions,
			static function ( $a, $b ) use ( $sessions, $current ) {
				if ( $a === $current || $b === $current ) {
					return $a === $current ? -1 : 1;
				}

				return (int) ( $sessions[ $b ]['login'] ?? 0 ) <=> (int) ( $sessions[ $a ]['login'] ?? 0 );
			}
		);

		return array_slice( $sessions, 0, $limit, true );
	}
}
