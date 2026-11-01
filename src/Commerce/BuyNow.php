<?php

namespace RedeemID\Commerce;

use RedeemID\Api\Events;
use RedeemID\Auth\Flow;
use RedeemID\Auth\TokenVault;
use RedeemID\Shopify\Storefront;
use RedeemID\Support\Logger;
use RedeemID\Sync\PostCheckout;

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
	public const ACTION = 'redeemid_buy_now';
	private const PER_MINUTE = 10;

	public static function message( string $code ): string {
		return match ( $code ) {
			'buy_invalid'     => __( 'That option could not be found. Please choose again.', 'redeemid' ),
			'buy_busy'        => __( 'Too many checkout attempts. Please wait a minute and try again.', 'redeemid' ),
			'buy_unavailable' => __( 'Checkout is temporarily unavailable. Please try again shortly.', 'redeemid' ),
			default           => '',
		};
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

	/**
	 * Accepts a full SellingPlan GID or its numeric ID; '' for none.
	 */
	public static function selling_plan_gid( string $raw ): string {
		$raw = trim( $raw );

		if ( preg_match( '#^gid://shopify/SellingPlan/(\d+)$#', $raw, $m ) || preg_match( '#^(\d+)$#', $raw, $m ) ) {
			return 'gid://shopify/SellingPlan/' . $m[1];
		}

		return '';
	}

	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see class comment.
		$variant  = self::variant_gid( isset( $_POST['variant'] ) ? sanitize_text_field( wp_unslash( $_POST['variant'] ) ) : '' );
		$quantity = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1;
		$plan     = self::selling_plan_gid( isset( $_POST['selling_plan'] ) ? sanitize_text_field( wp_unslash( $_POST['selling_plan'] ) ) : '' );
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
		$source  = Attribution::for_url( $back );
		$url     = Storefront::checkout_url( $variant, $quantity, $token, $plan, $source );

		// A refused customer token must not block the purchase: retry as a guest.
		if ( is_wp_error( $url ) && $token ) {
			Logger::log( 'commerce.buyer_identity_refused', [ 'user_id' => $user_id, 'error' => $url->get_error_code() ] );
			$url = Storefront::checkout_url( $variant, $quantity, null, $plan, $source );
		}

		if ( is_wp_error( $url ) ) {
			Logger::log( 'commerce.buy_now_failed', [ 'error' => $url->get_error_code() ] );
			self::back( $back, 'buy_unavailable' );
		}

		Logger::log( 'commerce.buy_now', [ 'user_id' => $user_id, 'signed_in' => (bool) $token, 'selling_plan' => '' !== $plan ] );
		PostCheckout::mark( $user_id, $back );
		Events::emit(
			Events::CHECKOUT_STARTED,
			[
				'variant'      => $variant,
				'quantity'     => max( 1, $quantity ),
				'subscription' => '' !== $plan,
				'signed_in'    => (bool) $token,
				'user_id'      => $user_id,
				'post_id'      => Attribution::post_id( $back ),
			]
		);

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- checkoutUrl just returned by Shopify, https only.
		exit;
	}

	/**
	 * The buyer's IP as Storefront::buyer_ip() sees it, so a site behind a
	 * proxy or CDN that sets the redeemid_buyer_ip filter gets a limit per
	 * visitor, not one shared by everyone behind the proxy's address.
	 */
	private static function within_rate_limit(): bool {
		$key = self::rate_key( Storefront::buyer_ip() );
		$n   = (int) get_transient( $key );

		if ( $n >= self::PER_MINUTE ) {
			return false;
		}

		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * The transient key for one buyer's rate limit. Pure.
	 */
	public static function rate_key( string $ip ): string {
		return 'redeemid_buy_' . md5( $ip );
	}

	private static function back( string $url, string $code ): never {
		wp_safe_redirect( add_query_arg( 'redeemid_notice', $code, $url ) );
		exit;
	}
}
