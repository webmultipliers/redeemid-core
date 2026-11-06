<?php

namespace RedeemID\Shopify;

use RedeemID\Config;

/**
 * The addresses members see: the storefront's primary domain and the
 * customer account domain. A store with custom domains (shop.example.com,
 * account.example.com) has them here; one without has its myshopify.com
 * defaults. REDEEMID_STORE_DOMAIN itself stays the myshopify.com domain,
 * because that is the one the Admin API and webhooks use.
 *
 * Shopify reports both from the Storefront API's shop query, which needs no
 * token. Cached for a day with a last-good copy, so a page view never waits
 * on it and an outage keeps the last addresses Shopify gave.
 */
final class Domains {
	private const CACHE     = 'redeemid_store_domains';
	private const LAST_GOOD = 'redeemid_store_domains_last_good';

	private const QUERY = '{ shop { primaryDomain { url } customerAccountUrl } }';

	/**
	 * @return array{storefront: string, account: string} Both https URLs with no trailing slash, or '' with no store set.
	 */
	public static function urls(): array {
		$store = Config::store_domain();

		if ( '' === $store ) {
			return [ 'storefront' => '', 'account' => '' ];
		}

		$cached = get_transient( self::CACHE );

		if ( is_array( $cached ) && ( $cached['store'] ?? '' ) === $store ) {
			return self::shape( $cached, $store );
		}

		$headers = Storefront::available() ? Storefront::headers() : [];
		$data    = Http::graphql( Storefront::endpoint(), $headers, self::QUERY, [], 'storefront.domains' );

		if ( is_wp_error( $data ) ) {
			$last = get_option( self::LAST_GOOD );
			$last = is_array( $last ) && ( $last['store'] ?? '' ) === $store ? $last : [ 'store' => $store ];

			// Short negative cache so an outage doesn't cost every page view a call.
			set_transient( self::CACHE, $last, 5 * MINUTE_IN_SECONDS );

			return self::shape( $last, $store );
		}

		$found = [
			'store'      => $store,
			'storefront' => (string) ( $data['shop']['primaryDomain']['url'] ?? '' ),
			'account'    => (string) ( $data['shop']['customerAccountUrl'] ?? '' ),
		];

		set_transient( self::CACHE, $found, DAY_IN_SECONDS );
		update_option( self::LAST_GOOD, $found, false );

		return self::shape( $found, $store );
	}

	/**
	 * The storefront's address with a path on it, for links and forms that
	 * go to the store (a cart/add form, a product page).
	 */
	public static function storefront( string $path = '' ): string {
		$base = self::urls()['storefront'];

		return '' === $base || '' === $path ? $base : $base . '/' . ltrim( $path, '/' );
	}

	/**
	 * Where a member manages orders and addresses.
	 */
	public static function account(): string {
		return self::urls()['account'];
	}

	/**
	 * Only https URLs Shopify gave are used; anything else falls back to the
	 * myshopify.com addresses, which redirect to the custom ones. Pure.
	 *
	 * @return array{storefront: string, account: string}
	 */
	public static function shape( array $found, string $store ): array {
		$clean = static function ( mixed $url ): string {
			$url = is_string( $url ) ? rtrim( trim( $url ), '/' ) : '';

			return preg_match( '#^https://[a-z0-9.-]+(?:/[^\s?\#]*)?$#i', $url ) ? $url : '';
		};

		$storefront = $clean( $found['storefront'] ?? '' );
		$account    = $clean( $found['account'] ?? '' );

		return [
			'storefront' => '' !== $storefront ? $storefront : 'https://' . $store,
			'account'    => '' !== $account ? $account : 'https://' . $store . '/account',
		];
	}

	/**
	 * The myshopify.com domain behind a storefront's custom domain, read from
	 * the store's public /meta.json. '' when the host isn't a Shopify
	 * storefront. Used when an owner enters the address they know.
	 */
	public static function myshopify_for( string $host ): string {
		if ( ! preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host ) ) {
			return '';
		}

		$meta = Http::request( 'GET', 'https://' . $host . '/meta.json', [ 'Accept' => 'application/json' ], null, 'storefront.meta', 10 );
		$shop = is_array( $meta ) ? strtolower( (string) ( $meta['myshopify_domain'] ?? '' ) ) : '';

		return preg_match( '/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop ) ? $shop : '';
	}

	/**
	 * The store changed: what was found belongs to the old one.
	 */
	public static function forget(): void {
		delete_transient( self::CACHE );
		delete_option( self::LAST_GOOD );
	}
}
