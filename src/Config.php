<?php

namespace WP2_Shopify;

/**
 * Configuration-as-code (spec 14.1). Secrets are read only from wp-config.php
 * constants; nothing secret is ever written to the database by this plugin.
 */
final class Config {
	/** Pinned Shopify API version for every surface (Storefront, Customer Account, Admin, webhooks). */
	public const API_VERSION = '2026-10';

	/** Customer Account API scopes requested at sign-in. */
	public const SCOPES = 'openid email customer-account-api:full';

	/**
	 * Every constant the plugin reads, for the Debug page. This is the whole
	 * configuration surface: anything not listed here is not read.
	 *
	 * @return array<string, array{label: string, secret: bool, required: bool, help: string}>
	 */
	public static function definitions(): array {
		return [
			'WP2_SHOPIFY_STORE_DOMAIN'        => [
				'label'    => 'Store domain',
				'secret'   => false,
				'required' => true,
				'help'     => 'The store\'s *.myshopify.com domain.',
			],
			'WP2_SHOPIFY_CLIENT_ID'           => [
				'label'    => 'Customer Account API client ID',
				'secret'   => false,
				'required' => true,
				'help'     => 'From the Headless (or custom) sales channel\'s Customer Account API settings. Powers sign-in.',
			],
			'WP2_SHOPIFY_CLIENT_SECRET'       => [
				'label'    => 'Customer Account API client secret',
				'secret'   => true,
				'required' => false,
				'help'     => 'Only for confidential clients; leave unset for a public client.',
			],
			'WP2_SHOPIFY_ADMIN_CLIENT_ID'     => [
				'label'    => 'Admin app client ID',
				'secret'   => false,
				'required' => true,
				'help'     => 'The Dev Dashboard app installed on the store. The plugin requests 24-hour Admin tokens with the client credentials grant and renews them itself. Scopes: read_customers, write_customers, read_orders. Powers purchase-to-tag, reconciliation, resync and webhook registration.',
			],
			'WP2_SHOPIFY_ADMIN_CLIENT_SECRET' => [
				'label'    => 'Admin app client secret',
				'secret'   => true,
				'required' => true,
				'help'     => 'The Dev Dashboard app\'s client secret. Also verifies the webhooks the plugin registers.',
			],
			'WP2_SHOPIFY_ENCRYPTION_KEY'      => [
				'label'    => 'Token encryption key',
				'secret'   => true,
				'required' => false,
				'help'     => 'Optional 32-byte key, base64 encoded. When missing, a key is derived from the WordPress salts; set it if the salts get rotated.',
			],
		];
	}

	/**
	 * Constants earlier builds read and this one ignores, with what replaced them.
	 * The Debug page warns when one is still defined.
	 *
	 * @return array<string, string>
	 */
	public static function retired(): array {
		return [
			'WP2_SHOPIFY_ADMIN_ACCESS_TOKEN'       => 'Legacy permanent Admin tokens are no longer used. Set WP2_SHOPIFY_ADMIN_CLIENT_ID and WP2_SHOPIFY_ADMIN_CLIENT_SECRET for a Dev Dashboard app.',
			'WP2_SHOPIFY_WEBHOOK_SIGNING_SECRET'   => 'Webhooks are registered by the plugin through the app and verified with WP2_SHOPIFY_ADMIN_CLIENT_SECRET. Delete webhooks created in Settings → Notifications.',
			'WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN'      => 'Not read yet. It comes back with the Storefront catalog blocks.',
			'WP2_SHOPIFY_STOREFRONT_PRIVATE_TOKEN' => 'Not read yet. It comes back with the server-side cart.',
			'WP2_SHOPIFY_API_VERSION'              => 'The API version is pinned to ' . self::API_VERSION . ' by the plugin.',
			'WP2_SHOPIFY_COOKIE_DOMAIN'            => 'The plugin uses WordPress\' COOKIE_DOMAIN.',
		];
	}

	public static function get( string $name ): string {
		if ( defined( $name ) ) {
			return trim( (string) constant( $name ) );
		}

		if ( 'WP2_SHOPIFY_CLIENT_SECRET' === $name && defined( 'WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN' ) ) {
			return trim( (string) constant( 'WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN' ) );
		}

		return '';
	}

	/**
	 * The key Shopify signs webhooks with: webhooks registered by a Dev Dashboard
	 * app are signed with that app's client secret.
	 */
	public static function webhook_secret(): string {
		return self::get( 'WP2_SHOPIFY_ADMIN_CLIENT_SECRET' );
	}

	public static function has( string $name ): bool {
		return '' !== self::get( $name );
	}

	public static function uses_deprecated_secret_name(): bool {
		return ! defined( 'WP2_SHOPIFY_CLIENT_SECRET' ) && defined( 'WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN' );
	}

	public static function api_version(): string {
		return self::API_VERSION;
	}

	/**
	 * The store's myshopify host, normalised (no scheme, no path, lower case).
	 */
	public static function store_domain(): string {
		$domain = strtolower( self::get( 'WP2_SHOPIFY_STORE_DOMAIN' ) );
		$domain = preg_replace( '#^https?://#', '', $domain );

		return rtrim( (string) $domain, '/' );
	}

	/**
	 * Cookie domain for the plugin's own cookies. Never derived from the
	 * request's Host header (POC defect: HTTP_HOST is client-controlled).
	 */
	public static function cookie_domain(): string {
		return defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '';
	}

	/**
	 * A page in the store's Shopify admin, e.g. admin_url( 'customers/123' ).
	 */
	public static function admin_url( string $path = '' ): string {
		$handle = preg_replace( '/\.myshopify\.com$/', '', self::store_domain() );

		return '' === $handle ? '' : 'https://admin.shopify.com/store/' . rawurlencode( (string) $handle ) . '/' . ltrim( $path, '/' );
	}

	public static function callback_slug(): string {
		$slug = trim( (string) apply_filters( 'wp2_shopify_auth_callback_slug', 'auth-callback' ), '/' );

		return '' === $slug ? 'auth-callback' : $slug;
	}

	public static function callback_url(): string {
		return home_url( '/' . self::callback_slug() . '/' );
	}
}
