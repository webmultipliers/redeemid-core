<?php

namespace RedeemID\Commerce;

use RedeemID\Auth\Flow;
use RedeemID\Auth\TokenVault;
use RedeemID\Settings;
use RedeemID\Shopify\Storefront;
use RedeemID\Support\Logger;
use RedeemID\Sync\PostCheckout;

/**
 * RedeemID's own cart: a Shopify Storefront cart held on the server.
 *
 * The browser gets a random key in an HttpOnly cookie (KEY_COOKIE); the
 * Shopify cart ID lives only in a transient under that key, so no cart ID
 * reaches browser storage. A second, readable cookie (COUNT_COOKIE) holds
 * only the number of items, so the Cart Link block can show a count on a
 * cached page without a request.
 *
 * Every change is a plain form POST to admin-post.php, so the cart works
 * without JavaScript. Like buy-now, there is no nonce (product forms sit in
 * cacheable HTML): requests a browser marks as cross-site are refused, and a
 * per-IP rate limit stops cart spam. A signed-in customer's identity is
 * attached on the server when they check out, so checkout opens signed in
 * and the purchase can unlock content. A cart signed in that way is dropped
 * at sign-out, so the next person on a shared device can't use it.
 *
 * While Shopify's own plugin owns the cart page, RedeemID's cart stands down
 * (one cart per site) unless the site gives RedeemID precedence.
 */
final class Cart {
	public const ACTION       = 'redeemid_cart';
	public const KEY_COOKIE   = 'redeemid_cart';
	public const COUNT_COOKIE = 'redeemid_cart_count';
	public const LIFETIME     = 14 * DAY_IN_SECONDS;
	/** User meta: the cart key of a signed-in visitor's cart, so privacy export and erasure can find it. */
	public const USER_META    = '_redeemid_cart_key';
	public const MAX_QUANTITY = 99;
	private const PER_MINUTE  = 30;

	/** @var array|null|false The current visitor's cart for this request; false = not read yet. */
	private static array|null|false $current = false;

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'admin_post_nopriv_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'wp_logout', [ self::class, 'on_logout' ] );
	}

	/**
	 * Storefront access is set up and the cart page is RedeemID's.
	 */
	public static function enabled(): bool {
		return (bool) apply_filters( 'redeemid_cart_enabled', Storefront::available() && Pages::serves( Pages::CART ) );
	}

	public static function url(): string {
		return Pages::url( Pages::CART );
	}

	public static function form_url(): string {
		return admin_url( 'admin-post.php' );
	}

	public static function message( string $code ): string {
		return match ( $code ) {
			'cart_added'       => __( 'Added to your cart.', 'redeemid' ),
			'cart_updated'     => __( 'Your cart is updated.', 'redeemid' ),
			'cart_invalid'     => __( 'That option could not be found. Please choose again.', 'redeemid' ),
			'cart_busy'        => __( 'Too many cart changes. Please wait a minute and try again.', 'redeemid' ),
			'cart_unavailable' => __( 'The cart is temporarily unavailable. Please try again shortly.', 'redeemid' ),
			'cart_refused'     => __( 'Shopify couldn’t make that change. The item may be sold out, or the code may not apply.', 'redeemid' ),
			'cart_empty'       => __( 'Your cart is empty.', 'redeemid' ),
			default            => '',
		};
	}

	/**
	 * A notice for ?redeemid_notice, from buy-now or the cart, as escaped
	 * HTML: "Added to your cart" links to the cart.
	 */
	public static function notice_html( string $code ): string {
		$text = BuyNow::message( $code ) ?: self::message( $code );

		if ( '' === $text ) {
			return '';
		}

		$html = esc_html( $text );

		if ( 'cart_added' === $code && self::enabled() ) {
			$html .= ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'View cart', 'redeemid' ) . '</a>';
		}

		return $html;
	}

	/**
	 * A cart key from the cookie, or '' when it isn't one. Pure.
	 */
	public static function clean_key( string $raw ): string {
		return preg_match( '/^[a-f0-9]{32}$/', $raw ) ? $raw : '';
	}

	/**
	 * Clamp a quantity from a form. 0 is allowed (it removes a line). Pure.
	 */
	public static function clean_quantity( mixed $raw, int $min = 1 ): int {
		return max( $min, min( self::MAX_QUANTITY, (int) $raw ) );
	}

	/**
	 * Accepts a full CartLine GID; '' for anything else. Pure.
	 */
	public static function line_gid( string $raw ): string {
		return preg_match( '#^gid://shopify/CartLine/[A-Za-z0-9-]+(\?cart=[A-Za-z0-9_-]+)?$#', trim( $raw ) ) ? trim( $raw ) : '';
	}

	/**
	 * Whether a request the browser labelled with Sec-Fetch-Site may change
	 * the cart. Browsers that don't send the header are allowed. Pure.
	 */
	public static function same_site( string $sec_fetch_site ): bool {
		return '' === $sec_fetch_site || in_array( $sec_fetch_site, [ 'same-origin', 'same-site', 'none' ], true );
	}

	private static function key(): string {
		return self::clean_key( isset( $_COOKIE[ self::KEY_COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ self::KEY_COOKIE ] ) ) : '' ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- the cart page and cart actions are never cached.
	}

	private static function store_key( string $key ): string {
		return 'redeemid_cart_' . $key;
	}

	/**
	 * @return array{id: string, buyer: int}|null
	 */
	private static function stored( string $key ): ?array {
		$stored = '' !== $key ? get_transient( self::store_key( $key ) ) : false;

		return is_array( $stored ) && ! empty( $stored['id'] ) ? [ 'id' => (string) $stored['id'], 'buyer' => (int) ( $stored['buyer'] ?? 0 ) ] : null;
	}

	/**
	 * The visitor's cart, read from Shopify once per request. Null when they
	 * have none (or Shopify has let it expire).
	 */
	public static function current(): ?array {
		if ( false !== self::$current ) {
			return self::$current;
		}

		$stored        = self::stored( self::key() );
		$cart          = $stored ? Storefront::cart( $stored['id'] ) : null;
		self::$current = is_array( $cart ) ? $cart : null;

		if ( $stored && null === $cart ) {
			self::forget();
		}

		return self::$current;
	}

	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see class comment.
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$back = Flow::safe_redirect( isset( $_POST['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_POST['redirect_to'] ) ) : (string) wp_get_referer() );
		// phpcs:enable
		$site = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';

		nocache_headers();

		if ( ! self::enabled() || ! self::same_site( $site ) ) {
			self::back( $back, 'cart_unavailable' );
		}

		if ( ! self::within_rate_limit() ) {
			self::back( $back, 'cart_busy' );
		}

		match ( $op ) {
			'add'      => self::add( $back ),
			'update'   => self::update( $back ),
			'discount' => self::discount( $back ),
			'checkout' => self::checkout( $back ),
			default    => self::back( $back, 'cart_invalid' ),
		};
	}

	private static function add( string $back ): never {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see class comment.
		$variant  = BuyNow::variant_gid( isset( $_POST['variant'] ) ? sanitize_text_field( wp_unslash( $_POST['variant'] ) ) : '' );
		$plan     = BuyNow::selling_plan_gid( isset( $_POST['selling_plan'] ) ? sanitize_text_field( wp_unslash( $_POST['selling_plan'] ) ) : '' );
		$quantity = self::clean_quantity( isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1 );
		// phpcs:enable

		if ( '' === $variant ) {
			self::back( $back, 'cart_invalid' );
		}

		$line   = [ 'merchandiseId' => $variant, 'quantity' => $quantity ] + ( '' !== $plan ? [ 'sellingPlanId' => $plan ] : [] );
		$stored = self::stored( self::key() );
		$cart   = $stored ? Storefront::cart_mutate( 'add', [ 'cartId' => $stored['id'], 'lines' => [ $line ] ] ) : null;

		// No cart yet, or Shopify let it expire: start one.
		if ( null === $cart || ( is_wp_error( $cart ) && 'shopify_cart_missing' === $cart->get_error_code() ) ) {
			$country = Storefront::country();
			$input   = [ 'lines' => [ $line ] ] + ( '' !== $country ? [ 'buyerIdentity' => [ 'countryCode' => $country ] ] : [] );
			$cart    = Storefront::cart_mutate( 'create', [ 'input' => $input ] );
			$stored  = null;
		}

		if ( is_wp_error( $cart ) ) {
			Logger::log( 'commerce.cart_failed', [ 'op' => 'add', 'error' => $cart->get_error_code() ] );
			self::back( $back, 'shopify_cart' === $cart->get_error_code() ? 'cart_refused' : 'cart_unavailable' );
		}

		self::remember( $cart, $stored['buyer'] ?? 0 );
		self::back( $back, 'cart_added' );
	}

	private static function update( string $back ): never {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see class comment.
		$line     = self::line_gid( isset( $_POST['line'] ) ? sanitize_text_field( wp_unslash( $_POST['line'] ) ) : '' );
		$quantity = isset( $_POST['remove'] ) ? 0 : self::clean_quantity( isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1, 0 );
		// phpcs:enable
		$stored = self::stored( self::key() );

		if ( '' === $line || ! $stored ) {
			self::back( $back, 'cart_invalid' );
		}

		$cart = 0 === $quantity
			? Storefront::cart_mutate( 'remove', [ 'cartId' => $stored['id'], 'lineIds' => [ $line ] ] )
			: Storefront::cart_mutate( 'update', [ 'cartId' => $stored['id'], 'lines' => [ [ 'id' => $line, 'quantity' => $quantity ] ] ] );

		self::finish( $cart, $stored, $back );
	}

	private static function discount( string $back ): never {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- see class comment.
		$code = isset( $_POST['code'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['code'] ) ), 0, 64 ) : '';
		// phpcs:enable
		$stored = self::stored( self::key() );

		if ( ! $stored ) {
			self::back( $back, 'cart_empty' );
		}

		$cart = Storefront::cart_mutate( 'discounts', [ 'cartId' => $stored['id'], 'discountCodes' => '' !== $code ? [ $code ] : [] ] );

		self::finish( $cart, $stored, $back );
	}

	/**
	 * Send the visitor to Shopify checkout, signed in when they are.
	 */
	private static function checkout( string $back ): never {
		$stored = self::stored( self::key() );
		$cart   = $stored ? Storefront::cart( $stored['id'] ) : null;

		if ( ! is_array( $cart ) || ! $cart['lines'] || '' === $cart['checkout'] ) {
			if ( $stored && null === $cart ) {
				self::forget();
			}

			self::back( $back, is_wp_error( $cart ) ? 'cart_unavailable' : 'cart_empty' );
		}

		$user_id = get_current_user_id();
		$token   = $user_id ? TokenVault::access_token( $user_id ) : null;

		if ( $token && $stored['buyer'] !== $user_id ) {
			$attached = Storefront::attach_buyer( $stored['id'], $token );

			// A refused customer token must not block the purchase: check out as a guest.
			if ( is_wp_error( $attached ) ) {
				Logger::log( 'commerce.buyer_identity_refused', [ 'user_id' => $user_id, 'error' => $attached->get_error_code() ] );
			} else {
				self::remember( $cart, $user_id );
			}
		}

		Logger::log( 'commerce.cart_checkout', [ 'user_id' => $user_id, 'signed_in' => (bool) $token, 'lines' => count( $cart['lines'] ) ] );
		PostCheckout::mark( $user_id, $back );

		wp_redirect( $cart['checkout'] ); // phpcs:ignore WordPress.Security.SafeRedirect -- checkoutUrl just returned by Shopify, https only.
		exit;
	}

	/**
	 * After an update or discount: keep the changed cart, or explain.
	 */
	private static function finish( array|\WP_Error $cart, array $stored, string $back ): never {
		if ( is_wp_error( $cart ) ) {
			if ( 'shopify_cart_missing' === $cart->get_error_code() ) {
				self::forget();
				self::back( $back, 'cart_empty' );
			}

			Logger::log( 'commerce.cart_failed', [ 'error' => $cart->get_error_code() ] );
			self::back( $back, 'shopify_cart' === $cart->get_error_code() ? 'cart_refused' : 'cart_unavailable' );
		}

		self::remember( $cart, $stored['buyer'] );
		self::back( $back, 'cart_updated' );
	}

	/**
	 * Store the cart under the visitor's key (a new key for a new cart) and
	 * refresh both cookies.
	 */
	private static function remember( array $cart, int $buyer ): void {
		$key = self::key();

		if ( '' === $key || ( self::stored( $key )['id'] ?? '' ) !== $cart['id'] ) {
			$key = bin2hex( random_bytes( 16 ) );
		}

		set_transient( self::store_key( $key ), [ 'id' => $cart['id'], 'buyer' => $buyer ], self::LIFETIME );

		$owner = $buyer ?: get_current_user_id();

		if ( $owner > 0 ) {
			update_user_meta( $owner, self::USER_META, $key );
		}

		self::cookie( self::KEY_COOKIE, $key, true );
		self::cookie( self::COUNT_COOKIE, (string) $cart['count'], false );
		self::$current = $cart;
	}

	/**
	 * Drop the visitor's cart: its stored ID and both cookies.
	 */
	public static function forget(): void {
		$key = self::key();

		if ( '' !== $key ) {
			delete_transient( self::store_key( $key ) );
		}

		if ( is_user_logged_in() ) {
			delete_user_meta( get_current_user_id(), self::USER_META );
		}

		self::cookie( self::KEY_COOKIE, '', true, -1 );
		self::cookie( self::COUNT_COOKIE, '', false, -1 );
		self::$current = null;
	}

	/**
	 * The cart a member last used here, for a privacy export: its Shopify ID,
	 * or null when there is none (or it has expired).
	 *
	 * @return array{id: string, buyer: int}|null
	 */
	public static function for_user( int $user_id ): ?array {
		return self::stored( self::clean_key( (string) get_user_meta( $user_id, self::USER_META, true ) ) );
	}

	/**
	 * Drop the cart a member last used here, for privacy erasure. Their
	 * browser's cookie then points at nothing, which reads as no cart.
	 */
	public static function erase_for_user( int $user_id ): bool {
		$key = self::clean_key( (string) get_user_meta( $user_id, self::USER_META, true ) );

		delete_user_meta( $user_id, self::USER_META );

		return '' !== $key && delete_transient( self::store_key( $key ) );
	}

	/**
	 * A cart that was signed in to a customer at checkout leaves with them.
	 * With "Clear the cart on sign-out", every cart does.
	 */
	public static function on_logout( int $user_id = 0 ): void {
		$stored = self::stored( self::key() );

		if ( $stored && ( $stored['buyer'] > 0 || Settings::get( 'clear_cart_on_logout' ) ) ) {
			self::forget();
		}
	}

	private static function cookie( string $name, string $value, bool $http_only, int $lifetime = self::LIFETIME ): void {
		if ( headers_sent() ) {
			return;
		}

		setcookie( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- see class comment: the cart is per visitor and its pages are never cached.
			$name,
			$value,
			[
				'expires'  => $lifetime > 0 ? time() + $lifetime : 1,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => is_ssl(),
				'httponly' => $http_only,
				'samesite' => 'Lax',
			]
		);
	}

	private static function within_rate_limit(): bool {
		$key = 'redeemid_cart_rate_' . md5( Storefront::buyer_ip() );
		$n   = (int) get_transient( $key );

		if ( $n >= self::PER_MINUTE ) {
			return false;
		}

		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );

		return true;
	}

	private static function back( string $url, string $code ): never {
		wp_safe_redirect( add_query_arg( 'redeemid_notice', $code, $url ) );
		exit;
	}

	/** For tests. */
	public static function reset(): void {
		self::$current = false;
	}
}
