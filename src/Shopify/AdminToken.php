<?php

namespace WP2_Shopify\Shopify;

use WP2_Shopify\Config;
use WP2_Shopify\Plugin;
use WP2_Shopify\Support\Lock;
use WP2_Shopify\Support\Logger;

/**
 * The Admin API access token for the Dev Dashboard app named by
 * WP2_SHOPIFY_ADMIN_CLIENT_ID + WP2_SHOPIFY_ADMIN_CLIENT_SECRET. Its tokens come
 * from the client credentials grant and last about 24 hours, so they are
 * fetched on demand and cached, encrypted, until shortly before expiry.
 */
final class AdminToken {
	private const CACHE = 'wp2_shopify_admin_token';

	/** Renew this many seconds before Shopify's stated expiry. */
	private const MARGIN = 300;

	public static function configured(): bool {
		return Config::has( 'WP2_SHOPIFY_ADMIN_CLIENT_ID' ) && Config::has( 'WP2_SHOPIFY_ADMIN_CLIENT_SECRET' );
	}

	public static function get(): string|\WP_Error {
		if ( ! self::configured() ) {
			return new \WP_Error( 'shopify_admin_unconfigured', 'No Admin API credentials are defined.' );
		}

		$cached = self::cached();

		if ( null !== $cached ) {
			return $cached;
		}

		// Single-flight: one request fetches, the others reuse the result or fetch on their own if it is slow.
		$locked = Lock::acquire( self::CACHE, 20 );

		try {
			$cached = self::cached();

			return $cached ?? self::fetch();
		} finally {
			if ( $locked ) {
				Lock::release( self::CACHE );
			}
		}
	}

	/**
	 * Drop the cached token, for example after Shopify answers 401.
	 */
	public static function forget(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * Lifetime to cache a token for, from the grant response.
	 */
	public static function ttl( array $response ): int {
		$expires = (int) ( $response['expires_in'] ?? 0 );

		return max( 60, ( $expires > 0 ? $expires : 3600 ) - self::MARGIN );
	}

	private static function cached(): ?string {
		$stored = get_transient( self::CACHE );

		return is_string( $stored ) ? Plugin::crypto()->decrypt( $stored ) : null;
	}

	private static function fetch(): string|\WP_Error {
		$response = Http::request(
			'POST',
			'https://' . Config::store_domain() . '/admin/oauth/access_token',
			[ 'Content-Type' => 'application/x-www-form-urlencoded' ],
			http_build_query(
				[
					'grant_type'    => 'client_credentials',
					'client_id'     => Config::get( 'WP2_SHOPIFY_ADMIN_CLIENT_ID' ),
					'client_secret' => Config::get( 'WP2_SHOPIFY_ADMIN_CLIENT_SECRET' ),
				]
			),
			'admin.client_credentials'
		);

		if ( is_wp_error( $response ) ) {
			// shop_not_permitted: the app and the store are not in the same Shopify organization.
			Logger::log( 'shopify.token_failed', [ 'error' => $response->get_error_message() ] );
			update_option( 'wp2_shopify_admin_token_error', [ 'error' => $response->get_error_message(), 'at' => time() ], false );

			return $response;
		}

		$token = (string) ( $response['access_token'] ?? '' );

		if ( '' === $token ) {
			return new \WP_Error( 'shopify_admin_token', 'Shopify returned no access token.' );
		}

		set_transient( self::CACHE, Plugin::crypto()->encrypt( $token ), self::ttl( $response ) );
		update_option( 'wp2_shopify_admin_token_scopes', (string) ( $response['scope'] ?? '' ), false );
		delete_option( 'wp2_shopify_admin_token_error' );

		return $token;
	}
}
