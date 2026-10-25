<?php

namespace WP2_Shopify\Commerce;

use WP2_Shopify\Auth\Flow;
use WP2_Shopify\Auth\TokenVault;
use WP2_Shopify\Shopify\Storefront;
use WP2_Shopify\Support\Logger;
use WP2_Shopify\Sync\PostCheckout;

/**
 * Buy-now without JavaScript: a plain form POST creates a
 * one-line cart, attaches the signed-in customer's identity, and redirects to
 * a fresh checkoutUrl. This is the purchase path for plan cards whether or not
 * Shopify's own plugin is installed, and it never touches that plugin's cart.
 *
 * No nonce: the form sits in cacheable HTML (no nonces in cached pages),
 * and the action only creates a cart for public merchandise and sends the
 * visitor to Shopify. A per-IP rate limit stops it being used to spam carts.
 */
final class BuyNow {
	public const ACTION = 'wp2_shopify_buy_now';
	private const PER_MINUTE = 10;

	private const MESSAGES = [
		'buy_invalid'     => 'That option could not be found. Please choose again.',
		'buy_busy'        => 'Too many checkout attempts. Please wait a minute and try again.',
		'buy_unavailable' => 'Checkout is temporarily unavailable. Please try again shortly.',
	];

	public static function message( string $code ): string {
		return isset( self::MESSAGES[ $code ] ) ? __( self::MESSAGES[ $code ], 'wp2-shopify' ) : ''; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_post_nopriv_' . self::ACTION, [ self::class, 'handle' ] );
	}

	public static function form_url(): string {
		return admin_url( 'admin-post.php' );
	}

	/**
	 * Accepts a full ProductVariant GID or its numeric ID.
	 */
	public static function variant_gid( string $raw ): string {
		$raw = trim( $raw );

		if ( preg_match( '#^gid://shopify/ProductVariant/(\d+)$#', $raw, $m ) || preg_match( '#^(\d+)$#', $raw, $m ) ) {
			return 'gid://shopify/ProductVariant/' . $m[1];
		}

		return '';
	}

	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see class comment.
		$variant  = self::variant_gid( isset( $_POST['variant'] ) ? sanitize_text_field( wp_unslash( $_POST['variant'] ) ) : '' );
		$quantity = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1;
		$back     = Flow::safe_redirect( isset( $_POST['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) : (string) wp_get_referer() );
		// phpcs:enable

		nocache_headers();

		if ( '' === $variant ) {
			self::back( $back, 'buy_invalid' );
		}

		if ( ! self::within_rate_limit() ) {
			self::back( $back, 'buy_busy' );
		}

		$user_id = get_current_user_id();
		$token   = $user_id ? TokenVault::access_token( $user_id ) : null;
		$url     = Storefront::checkout_url( $variant, $quantity, $token );

		// A refused customer token must not block the purchase: retry as a guest.
		if ( is_wp_error( $url ) && $token ) {
			Logger::log( 'commerce.buyer_identity_refused', [ 'user_id' => $user_id, 'error' => $url->get_error_code() ] );
			$url = Storefront::checkout_url( $variant, $quantity );
		}

		if ( is_wp_error( $url ) ) {
			Logger::log( 'commerce.buy_now_failed', [ 'error' => $url->get_error_code() ] );
			self::back( $back, 'buy_unavailable' );
		}

		Logger::log( 'commerce.buy_now', [ 'user_id' => $user_id, 'signed_in' => (bool) $token ] );
		PostCheckout::mark( $user_id, $back );

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- checkoutUrl just returned by Shopify, https only.
		exit;
	}

	private static function within_rate_limit(): bool {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- only hashed into a rate-limit key on an uncached POST.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'wp2_shopify_buy_' . md5( $ip );
		$n   = (int) get_transient( $key );

		if ( $n >= self::PER_MINUTE ) {
			return false;
		}

		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );

		return true;
	}

	private static function back( string $url, string $code ): never {
		wp_safe_redirect( add_query_arg( 'wp2s_notice', $code, $url ) );
		exit;
	}
}
