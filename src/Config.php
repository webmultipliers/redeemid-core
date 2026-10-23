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
	 * Every constant the plugin reads, for the diagnostics page.
	 *
	 * @return array<string, array{label: string, secret: bool, required: bool, help: string}>
	 */
	public static function definitions(): array {
		return [
			'WP2_SHOPIFY_STORE_DOMAIN'             => [
				'label'    => 'Store domain',
				'secret'   => false,
				'required' => true,
				'help'     => 'The store\'s *.myshopify.com domain.',
			],
			'WP2_SHOPIFY_CLIENT_ID'                => [
				'label'    => 'Customer Account API client ID',
				'secret'   => false,
				'required' => true,
				'help'     => 'From the Headless (or custom) sales channel\'s Customer Account API settings.',
			],
			'WP2_SHOPIFY_CLIENT_SECRET'            => [
				'label'    => 'Customer Account API client secret',
				'secret'   => true,
				'required' => false,
				'help'     => 'Only for confidential clients. Replaces WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN, which still works as a deprecated alias.',
			],
			'WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN'      => [
				'label'    => 'Storefront public access token',
				'secret'   => false,
				'required' => false,
				'help'     => 'Public by design. Used for catalog reads (Phase 1).',
			],
			'WP2_SHOPIFY_STOREFRONT_PRIVATE_TOKEN' => [
				'label'    => 'Storefront private access token',
				'secret'   => true,
				'required' => false,
				'help'     => 'Server-side Storefront calls that send the buyer IP header (cart proxy, Phase 2).',
			],
			'WP2_SHOPIFY_ADMIN_ACCESS_TOKEN'       => [
				'label'    => 'Admin API access token',
				'secret'   => true,
				'required' => false,
				'help'     => 'Scopes: read_customers, write_customers, read_orders (plus read_all_orders if memberships last longer than 60 days). Powers purchase-to-tag and reconciliation.',
			],
			'WP2_SHOPIFY_WEBHOOK_SIGNING_SECRET'   => [
				'label'    => 'Webhook signing secret',
				'secret'   => true,
				'required' => true,
				'help'     => 'The app\'s client secret (or the secret shown in Notifications for admin-created webhooks).',
			],
			'WP2_SHOPIFY_ENCRYPTION_KEY'           => [
				'label'    => 'Token encryption key',
				'secret'   => true,
				'required' => false,
				'help'     => 'Optional 32-byte key, base64 encoded. When missing, a key is derived from the WordPress salts.',
			],
			'WP2_SHOPIFY_API_VERSION'              => [
				'label'    => 'Shopify API version override',
				'secret'   => false,
				'required' => false,
				'help'     => 'Leave undefined to use the pinned version ' . self::API_VERSION . '.',
			],
			'WP2_SHOPIFY_COOKIE_DOMAIN'            => [
				'label'    => 'Cookie domain override',
				'secret'   => false,
				'required' => false,
				'help'     => 'Leave undefined to use WordPress\' COOKIE_DOMAIN.',
			],
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

	public static function has( string $name ): bool {
		return '' !== self::get( $name );
	}

	public static function uses_deprecated_secret_name(): bool {
		return ! defined( 'WP2_SHOPIFY_CLIENT_SECRET' ) && defined( 'WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN' );
	}

	public static function api_version(): string {
		$override = self::get( 'WP2_SHOPIFY_API_VERSION' );

		return preg_match( '/^\d{4}-\d{2}$/', $override ) ? $override : self::API_VERSION;
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
		if ( self::has( 'WP2_SHOPIFY_COOKIE_DOMAIN' ) ) {
			return self::get( 'WP2_SHOPIFY_COOKIE_DOMAIN' );
		}

		return defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? (string) COOKIE_DOMAIN : '';
	}

	public static function callback_slug(): string {
		$slug = trim( (string) apply_filters( 'wp2_shopify_auth_callback_slug', 'auth-callback' ), '/' );

		return '' === $slug ? 'auth-callback' : $slug;
	}

	public static function callback_url(): string {
		return home_url( '/' . self::callback_slug() . '/' );
	}
}
