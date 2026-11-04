<?php

namespace RedeemID\Auth;

use RedeemID\Settings;
use RedeemID\Sync\Mirror;

/**
 * Optional: linked members sign in with Shopify only (Settings → Membership →
 * "Linked members sign in with Shopify only", off by default).
 *
 * An existing WordPress user who links a Shopify customer keeps their
 * WordPress password unless this is on. When it is, a linked member's
 * password, password reset and application passwords stop working, so
 * Shopify is the only way in for them. Nothing is deleted: the stored
 * password stays, and turning the setting off, or unlinking the member,
 * gives the WordPress login back. Staff (Linking::privileged) are never
 * affected, because they can't sign in with Shopify at all.
 */
final class PasswordLogin {
	public const SETTING = 'shopify_only_login';

	public static function register(): void {
		add_filter( 'wp_authenticate_user', [ self::class, 'authenticate' ], 20 );
		add_filter( 'allow_password_reset', [ self::class, 'allow_reset' ], 20, 2 );
		add_filter( 'wp_is_application_passwords_available_for_user', [ self::class, 'allow_app_passwords' ], 20, 2 );
	}

	/**
	 * Whether this user must sign in with Shopify: the setting is on, they are
	 * linked, and they are not staff. Pure apart from its two inputs.
	 */
	public static function applies( bool $setting, bool $linked, bool $privileged ): bool {
		return $setting && $linked && ! $privileged;
	}

	public static function shopify_only( \WP_User $user ): bool {
		return self::applies( (bool) Settings::get( self::SETTING ), null !== Mirror::customer_id( $user->ID ), Linking::privileged( $user ) );
	}

	public static function authenticate( mixed $user ): mixed {
		if ( ! $user instanceof \WP_User || ! self::shopify_only( $user ) ) {
			return $user;
		}

		return new \WP_Error(
			'redeemid_shopify_only',
			sprintf(
				/* translators: %s: sign-in link */
				__( 'This account signs in with Shopify. <a href="%s">Log in with Shopify</a>.', 'redeemid' ),
				esc_url( Flow::login_url( home_url( '/' ) ) )
			)
		);
	}

	public static function allow_reset( mixed $allow, mixed $user_id ): mixed {
		$user = get_userdata( (int) $user_id );

		return $user && self::shopify_only( $user ) ? false : $allow;
	}

	public static function allow_app_passwords( mixed $available, mixed $user ): mixed {
		return $user instanceof \WP_User && self::shopify_only( $user ) ? false : $available;
	}
}
