<?php

namespace WP2_Shopify\Commerce;

use WP2_Shopify\Auth\TokenVault;
use WP2_Shopify\Integrations\OfficialPlugin;
use WP2_Shopify\Rest\Routes;
use WP2_Shopify\Rest\Status;
use WP2_Shopify\Settings;
use WP2_Shopify\Shopify\Storefront;
use WP2_Shopify\Support\Logger;
use WP2_Shopify\Sync\Mirror;
use WP2_Shopify\Sync\PostCheckout;

/**
 * Signs the official plugin's cart in. Its cart component keeps the cart ID in
 * the browser; when a linked customer is signed in, a small script sends that
 * ID here and WP2 attaches the customer's identity with
 * cartBuyerIdentityUpdate, so checkout opens signed in. The customer token
 * goes from this server to Shopify only, never to the browser.
 *
 * On sign-out the script drops a cart it attached, so the next person on a
 * shared device can't check out as the previous customer. With the
 * clear_cart_on_logout setting it drops any cart.
 *
 * Only loaded when the official plugin serves the same store
 * (OfficialPlugin::serves_storefront()). Without it, plan cards buy through
 * BuyNow and there is no browser cart to bridge.
 */
final class CartBridge {
	public const ROUTE       = '/cart/identity';
	public const STATE       = 'wp2_shopify_cart_bridge';
	private const HANDLE     = 'wp2-shopify-cart-bridge';
	private const PER_MINUTE = 6;

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enabled(): bool {
		return OfficialPlugin::serves_storefront() && Storefront::available();
	}

	public static function enqueue(): void {
		if ( ! self::enabled() ) {
			return;
		}

		wp_enqueue_script( self::HANDLE, WP2_SHOPIFY_URL . 'assets/cart-bridge.js', [], WP2_SHOPIFY_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );

		// Static per site, so cached pages can carry it.
		$config = [
			'statusUrl'     => add_query_arg( 'action', Status::AJAX_ACTION, admin_url( 'admin-ajax.php' ) ),
			'identityUrl'   => rest_url( Routes::NS . self::ROUTE ),
			'clearOnLogout' => (bool) Settings::get( 'clear_cart_on_logout' ),
		];

		wp_add_inline_script( self::HANDLE, 'window.wp2ShopifyCartBridge = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * A Storefront cart GID, optionally with its ?key= suffix, or ''.
	 */
	public static function cart_gid( string $raw ): string {
		$raw = trim( $raw );

		return preg_match( '#^gid://shopify/Cart/[A-Za-z0-9_-]{1,200}(\?key=[A-Za-z0-9]{1,100})?$#', $raw ) ? $raw : '';
	}

	public static function can_attach(): bool {
		return is_user_logged_in() && null !== Mirror::customer_id( get_current_user_id() );
	}

	public static function attach( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$cart = self::cart_gid( (string) $request->get_param( 'cartId' ) );

		if ( '' === $cart ) {
			return new \WP_Error( 'invalid_cart', 'That is not a Shopify cart ID.', [ 'status' => 400 ] );
		}

		if ( ! self::enabled() ) {
			return new \WP_Error( 'unavailable', 'The cart bridge is not active on this site.', [ 'status' => 409 ] );
		}

		$user_id = get_current_user_id();

		if ( ! self::within_rate_limit( $user_id ) ) {
			return new \WP_Error( 'busy', 'Too many cart updates. Try again in a minute.', [ 'status' => 429 ] );
		}

		$token  = TokenVault::access_token( $user_id );
		$result = $token ? Storefront::attach_buyer( $cart, $token ) : new \WP_Error( 'no_token', 'No Shopify session for this user.' );

		if ( is_wp_error( $result ) ) {
			self::record( false, $result->get_error_code() );
			Logger::log( 'commerce.cart_identity_failed', [ 'user_id' => $user_id, 'error' => $result->get_error_code() ] );

			return new \WP_Error( $result->get_error_code(), 'Shopify did not accept the sign-in for this cart.', [ 'status' => 409 ] );
		}

		self::record( true );
		Logger::log( 'commerce.cart_identity', [ 'user_id' => $user_id ] );
		// The cart is the customer's, so the next stop may be checkout.
		PostCheckout::mark( $user_id, (string) $request->get_header( 'referer' ) );

		$response = new \WP_REST_Response( [ 'attached' => true ] );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * Last success and last failure, for Debug. Never the cart ID or the user.
	 */
	public static function last(): array {
		$state = get_option( self::STATE, [] );

		return is_array( $state ) ? $state + [ 'ok_at' => 0, 'error' => '', 'error_at' => 0 ] : [ 'ok_at' => 0, 'error' => '', 'error_at' => 0 ];
	}

	private static function record( bool $ok, string $error = '' ): void {
		$state = self::last();

		if ( $ok ) {
			$state['ok_at'] = time();
		} else {
			$state['error']    = $error;
			$state['error_at'] = time();
		}

		update_option( self::STATE, $state, false );
	}

	private static function within_rate_limit( int $user_id ): bool {
		$key = 'wp2_shopify_cartid_' . $user_id;
		$n   = (int) get_transient( $key );

		if ( $n >= self::PER_MINUTE ) {
			return false;
		}

		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );

		return true;
	}
}
