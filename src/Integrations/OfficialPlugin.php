<?php

namespace RedeemID\Integrations;

use RedeemID\Config;

/**
 * Shopify's own WordPress plugin ("Sell on WordPress" channel, 1.x).
 *
 * It is an anonymous storefront: product, collection and cart-toggle blocks,
 * /products, /collections and /cart routes, and Storefront Web Components.
 * RedeemID runs beside it and never reads its credentials. Only its store domain is
 * compared with REDEEMID_STORE_DOMAIN, because both plugins must point at
 * the same store for a member's purchase to unlock anything.
 *
 * Verified against 1.0.0 (2026-03-20): it defines SHOPIFY_WP_VERSION and
 * SHOPIFY_PLUGIN_DIR, keeps its domain in the shopify_store_url option and
 * writes shopify_shop_info once it has connected.
 */
final class OfficialPlugin {
	public const ABSENT      = 'absent';
	public const UNCONNECTED = 'unconnected';
	public const SAME_STORE  = 'same_store';
	public const OTHER_STORE = 'other_store';

	/**
	 * Published security advisories for Shopify's plugin, each affecting every
	 * version up to and including `through`. Shown on Overview and Debug so a
	 * site still running it knows, since Shopify has shipped no fix.
	 */
	public const ADVISORIES = [
		[ 'id' => 'CVE-2026-57748', 'through' => '1.0.0', 'kind' => 'local file inclusion' ],
	];

	/** @var array{state: string, version: string, store_domain: string, owns_cart: bool, product_routes: bool}|null */
	private static ?array $info = null;

	/**
	 * Pure classification, so it can be tested without WordPress.
	 *
	 * @param string $version      The official plugin's version, '' when it is not loaded.
	 * @param string $their_domain Its connected store domain, '' when not connected.
	 * @param string $our_domain   RedeemID's normalised store domain.
	 */
	public static function classify( string $version, string $their_domain, string $our_domain ): string {
		if ( '' === $version ) {
			return self::ABSENT;
		}

		$their_domain = self::normalise( $their_domain );

		if ( '' === $their_domain ) {
			return self::UNCONNECTED;
		}

		// RedeemID not configured yet: nothing to disagree with.
		if ( '' === $our_domain || $their_domain === self::normalise( $our_domain ) ) {
			return self::SAME_STORE;
		}

		return self::OTHER_STORE;
	}

	public static function normalise( string $domain ): string {
		$domain = strtolower( trim( $domain ) );
		$domain = (string) preg_replace( '#^https?://#', '', $domain );

		return rtrim( (string) strtok( $domain, '/' ), '/' );
	}

	/**
	 * @return array{state: string, version: string, store_domain: string, owns_cart: bool, product_routes: bool}
	 */
	public static function info(): array {
		if ( null !== self::$info ) {
			return self::$info;
		}

		$version = defined( 'SHOPIFY_WP_VERSION' ) && defined( 'SHOPIFY_PLUGIN_DIR' ) ? (string) constant( 'SHOPIFY_WP_VERSION' ) : '';
		$domain  = '';

		if ( '' !== $version && get_option( 'shopify_shop_info' ) ) {
			$domain = (string) get_option( 'shopify_store_url', '' );
		}

		$state = (string) apply_filters( 'redeemid_official_state', self::classify( $version, $domain, Config::store_domain() ), $version, $domain );

		self::$info = [
			'state'          => in_array( $state, [ self::ABSENT, self::UNCONNECTED, self::SAME_STORE, self::OTHER_STORE ], true ) ? $state : self::ABSENT,
			'version'        => $version,
			'store_domain'   => self::normalise( $domain ),
			'owns_cart'      => '' !== $version && self::rewrite_owner( 'cart' ),
			'product_routes' => '' !== $version && self::rewrite_owner( 'products' ),
		];

		return self::$info;
	}

	/**
	 * The advisories that cover $version. Pure.
	 *
	 * @return array<int, array{id: string, through: string, kind: string}>
	 */
	public static function advisories( string $version ): array {
		if ( '' === $version ) {
			return [];
		}

		return array_values( array_filter( self::ADVISORIES, static fn( array $advisory ) => version_compare( $version, $advisory['through'], '<=' ) ) );
	}

	public static function state(): string {
		return self::info()['state'];
	}

	/**
	 * Active and connected to the store RedeemID is configured for.
	 */
	public static function serves_storefront(): bool {
		return self::SAME_STORE === self::state();
	}

	/**
	 * Whether the stored rewrite rules send /cart or /products/* to the official plugin.
	 * Its rules are prepended, so when present they win over pages and other plugins.
	 */
	private static function rewrite_owner( string $route ): bool {
		$rules = get_option( 'rewrite_rules' );
		$key   = 'cart' === $route ? '^cart/?$' : '^products/([^/]+)/?$';
		$var   = 'cart' === $route ? 'shopify_cart_page' : 'shopify_product_handle';

		return is_array( $rules ) && isset( $rules[ $key ] ) && str_contains( (string) $rules[ $key ], $var );
	}

	/**
	 * One-line summary for admin screens.
	 */
	public static function summary(): string {
		$info = self::info();

		return match ( $info['state'] ) {
			self::ABSENT      => __( 'Not installed. RedeemID runs on its own; its product and collection blocks replace Shopify’s catalog blocks.', 'redeemid' ),
			self::UNCONNECTED => sprintf( /* translators: %s: version */ __( 'Version %s is active but not connected to a store yet.', 'redeemid' ), $info['version'] ),
			self::SAME_STORE  => sprintf( /* translators: 1: version 2: store domain */ __( 'Version %1$s, connected to %2$s, the same store as RedeemID.', 'redeemid' ), $info['version'], $info['store_domain'] ?: Config::store_domain() ),
			default           => sprintf( /* translators: 1: version 2: their store 3: our store */ __( 'Version %1$s is connected to %2$s, but RedeemID is set up for %3$s. Purchases there will not unlock content here.', 'redeemid' ), $info['version'], $info['store_domain'], Config::store_domain() ),
		};
	}

	/** For tests. */
	public static function reset(): void {
		self::$info = null;
	}
}
