<?php

namespace RedeemID\Support;

use RedeemID\Lifecycle;

/**
 * WordPress multisite, one Shopify store per site.
 *
 * Options, posts, tables and cron are per site already. Users are not: one
 * user can belong to several sites, each connected to its own store, and
 * user meta is shared by the whole network. So every `_redeemid_` user meta
 * key (the Shopify link, the mirror, the meter, tokens bookkeeping) is
 * scoped to the site: on any site but the main one it is stored under the
 * site's table prefix, as WordPress does for roles (`wp_2__redeemid_tags`).
 * The main site keeps the plain keys, so a single site that becomes a
 * network keeps its data where it is.
 *
 * get/add/update/delete_user_meta() calls are scoped here, by filter, so
 * code calls them with the plain key. SQL and user queries that name a key
 * go through meta_key().
 */
final class Multisite {
	private const PREFIX = '_redeemid_';

	public static function register(): void {
		add_action( 'wp_initialize_site', [ self::class, 'new_site' ], 20 );

		if ( ! self::scoped() ) {
			return;
		}

		add_filter( 'get_user_metadata', [ self::class, 'get' ], 1, 4 );
		add_filter( 'add_user_metadata', [ self::class, 'add' ], 1, 5 );
		add_filter( 'update_user_metadata', [ self::class, 'update' ], 1, 5 );
		add_filter( 'delete_user_metadata', [ self::class, 'delete' ], 1, 5 );
	}

	/**
	 * Whether this request's site stores its user meta under its own prefix.
	 */
	public static function scoped(): bool {
		return is_multisite() && ! is_main_site();
	}

	/**
	 * The user meta key as stored on this site.
	 */
	public static function meta_key( string $key ): string {
		global $wpdb;

		return self::scope( $key, self::scoped() ? $wpdb->get_blog_prefix() : '' );
	}

	/**
	 * $key under $prefix when it is one of RedeemID's keys. Pure.
	 */
	public static function scope( string $key, string $prefix ): string {
		return '' !== $prefix && str_starts_with( $key, self::PREFIX ) ? $prefix . $key : $key;
	}

	public static function get( mixed $check, mixed $user_id, mixed $key, mixed $single ): mixed {
		if ( ! is_string( $key ) || ! str_starts_with( $key, self::PREFIX ) ) {
			return $check;
		}

		$values = get_metadata_raw( 'user', (int) $user_id, self::meta_key( $key ), false );

		// A short-circuit is read as a list of values; get_metadata() takes [0] for a single value.
		return $values ? $values : ( $single ? [ '' ] : [] );
	}

	public static function add( mixed $check, mixed $user_id, mixed $key, mixed $value, mixed $unique ): mixed {
		return is_string( $key ) && str_starts_with( $key, self::PREFIX ) ? add_metadata( 'user', (int) $user_id, self::meta_key( $key ), $value, (bool) $unique ) : $check;
	}

	public static function update( mixed $check, mixed $user_id, mixed $key, mixed $value, mixed $prev ): mixed {
		return is_string( $key ) && str_starts_with( $key, self::PREFIX ) ? update_metadata( 'user', (int) $user_id, self::meta_key( $key ), $value, $prev ) : $check;
	}

	public static function delete( mixed $check, mixed $user_id, mixed $key, mixed $value, mixed $all ): mixed {
		return is_string( $key ) && str_starts_with( $key, self::PREFIX ) ? delete_metadata( 'user', (int) $user_id, self::meta_key( $key ), $value, (bool) $all ) : $check;
	}

	/**
	 * Network activation runs the activation hook once, on the main site:
	 * set up every site. Large networks (over 100 sites) set the rest up as
	 * each site is first visited (Lifecycle::maintain).
	 */
	public static function activate_network(): void {
		foreach ( get_sites( [ 'fields' => 'ids', 'number' => 100 ] ) as $site ) {
			switch_to_blog( (int) $site );
			Lifecycle::activate_site();
			restore_current_blog();
		}
	}

	/**
	 * A site added to a network where RedeemID is network-active.
	 */
	public static function new_site( mixed $site ): void {
		if ( ! $site instanceof \WP_Site || ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( plugin_basename( REDEEMID_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		Lifecycle::activate_site();
		restore_current_blog();
	}
}
