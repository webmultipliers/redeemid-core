<?php

namespace WP2_Shopify;

/**
 * Backwards compatibility with the proof of concept.
 *
 * [wp2_shopify_identity] stays as a deprecated alias. Until the account-card
 * block lands it renders the sign-in control.
 */
final class Compat {
	public static function register(): void {
		add_shortcode( 'wp2_shopify_identity', [ self::class, 'identity_shortcode' ] );
	}

	public static function identity_shortcode(): string {
		_deprecated_function( '[wp2_shopify_identity]', '2.0.0', 'the wp2-shopify/auth-action block' );

		return function_exists( 'bs_block' ) ? (string) bs_block( [ 'id' => 'wp2-shopify/auth-action', 'data' => [] ] ) : '';
	}
}
