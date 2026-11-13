<?php

namespace RedeemID\Commerce;

use RedeemID\Auth\Flow;
use RedeemID\Shopify\Storefront;
use RedeemID\Support\Logger;

/**
 * Visitors sent over from the store's own pages.
 *
 * A store that keeps its Online Store channel usually publishes a redirect
 * theme (such as Shopify's Sell on WordPress theme),
 * which sends its pages to the site with two extras:
 *
 * - ?discount=CODE: Shopify's discount links (/discount/CODE) leave the code
 *   in a store cookie, and the theme passes it on. The code goes on the
 *   visitor's RedeemID cart now, or waits in a short HttpOnly cookie for the
 *   first cart made for them (Add to cart or buy-now).
 * - ?logged_in=true: the customer is signed in to the store. A visitor who
 *   isn't signed in here is signed in without a form (prompt=none). If the
 *   store session has ended, they come back signed out, with no notice.
 *
 * Either way the visitor then lands on the same page without those
 * parameters, so the page never renders (or gets cached) with them.
 */
final class Arrivals {
	public const COOKIE   = 'redeemid_discount';
	public const LIFETIME = DAY_IN_SECONDS;

	/** Parameters the redirect theme adds, taken off before the page renders. */
	public const PARAMS = [ 'discount', 'logged_in', 'company_location_id' ];

	public static function register(): void {
		// After the sign-in callback (priority 0), before any page renders.
		add_action( 'template_redirect', [ self::class, 'maybe_arrive' ], 1 );
	}

	/**
	 * A discount code as a link carries it, or '' for anything that can't be
	 * one. Pure.
	 */
	public static function code( string $raw ): string {
		$code = trim( $raw );

		return '' !== $code && strlen( $code ) <= 255 && ! preg_match( '/[\x00-\x1f\x7f]/', $code ) ? $code : '';
	}

	/**
	 * The URL without the theme's parameters. Pure apart from remove_query_arg.
	 */
	public static function clean_url( string $url ): string {
		return remove_query_arg( self::PARAMS, $url );
	}

	/**
	 * Whether the request carries any of the theme's parameters. Pure.
	 *
	 * @param array<string, mixed> $query
	 */
	public static function carries( array $query ): bool {
		return (bool) array_intersect( self::PARAMS, array_keys( $query ) );
	}

	public static function maybe_arrive(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only landing parameters from a link; nothing is changed but the visitor's own cart.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';

		if ( is_admin() || ! in_array( $method, [ 'get', 'head' ], true ) || ! self::carries( $_GET ) ) {
			return;
		}

		$code      = self::code( isset( $_GET['discount'] ) ? sanitize_text_field( wp_unslash( $_GET['discount'] ) ) : '' );
		$logged_in = isset( $_GET['logged_in'] ) && 'true' === sanitize_key( wp_unslash( $_GET['logged_in'] ) );
		// phpcs:enable

		nocache_headers();

		if ( '' !== $code && Storefront::available() ) {
			self::arrive_with( $code );
		}

		$request = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		// A path on this site; "//host" falls back to the home page.
		$here = Flow::safe_redirect( self::clean_url( $request ) );

		if ( $logged_in && ! is_user_logged_in() ) {
			$url = Flow::start( $here, null, true );

			if ( ! is_wp_error( $url ) ) {
				wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- Shopify's authorize endpoint from discovery.
				exit;
			}
		}

		wp_safe_redirect( $here );
		exit;
	}

	/**
	 * Put the code on the visitor's cart, or keep it for the first one.
	 */
	private static function arrive_with( string $code ): void {
		$applied = Cart::apply_discount( $code );

		if ( null === $applied ) {
			self::cookie( $code, self::LIFETIME );
		} elseif ( is_wp_error( $applied ) ) {
			Logger::log( 'commerce.discount_link_failed', [ 'error' => $applied->get_error_code() ] );
		}
	}

	/**
	 * The code a discount link left for the visitor's next cart, or ''.
	 */
	public static function pending(): string {
		return self::code( isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '' ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- read only by uncached cart and buy-now actions.
	}

	/**
	 * The pending code has gone on a cart: forget it.
	 */
	public static function used(): void {
		if ( isset( $_COOKIE[ self::COOKIE ] ) ) { // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- see pending().
			self::cookie( '', -1 );
		}
	}

	private static function cookie( string $value, int $lifetime ): void {
		if ( headers_sent() ) {
			return;
		}

		setcookie( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- set only on the uncached landing redirect and cart actions.
			self::COOKIE,
			$value,
			[
				'expires'  => $lifetime > 0 ? time() + $lifetime : 1,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
	}
}
