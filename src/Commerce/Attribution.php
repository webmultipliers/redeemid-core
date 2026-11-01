<?php

namespace RedeemID\Commerce;

/**
 * Which WordPress page sold it: the page a buy-now or add-to-cart form sat
 * on, written onto the Shopify cart so it travels to the order.
 *
 * Each cart line carries the page that added it, and a buy-now cart also
 * carries it on the cart itself:
 *
 * - _redeemid_source: the page's path, such as /reports/harbor-fees/
 * - _redeemid_post:   the post ID, when the page is a post
 *
 * Shopify hides keys that start with an underscore from the buyer at checkout
 * and shows them on the order in the admin, in order exports and to Shopify
 * Flow, so sales can be reported by page in Shopify. WordPress stores nothing:
 * no orders, no revenue.
 *
 * The redeemid_cart_attribution filter changes the list or, returning [],
 * turns it off.
 */
final class Attribution {
	public const SOURCE = '_redeemid_source';
	public const POST   = '_redeemid_post';

	/** Shopify's limit on an attribute value is generous; keep ours short. */
	private const MAX = 255;

	/**
	 * The attributes for a form that sat on $url.
	 *
	 * @return array<int, array{key: string, value: string}>
	 */
	public static function for_url( string $url ): array {
		$post_id    = self::post_id( $url );
		$attributes = self::attributes( (string) wp_parse_url( $url, PHP_URL_PATH ), $post_id );

		/**
		 * Filters the attributes written onto the Shopify cart for a purchase
		 * that started on $url. Return [] to write none.
		 *
		 * @param array  $attributes List of ['key' => string, 'value' => string].
		 * @param string $url        The page the form sat on.
		 * @param int    $post_id    Its post ID, or 0.
		 */
		$filtered = apply_filters( 'redeemid_cart_attribution', $attributes, $url, $post_id );

		return self::clean( is_array( $filtered ) ? $filtered : [] );
	}

	/** Post IDs already looked up in this request, by URL. */
	private static array $posts = [];

	/**
	 * The post a front-end URL shows, or 0. Runs once per purchase, so the
	 * uncached lookup is fine; VIP's cached one is used where it exists.
	 */
	public static function post_id( string $url ): int {
		if ( '' === $url ) {
			return 0;
		}

		if ( ! isset( self::$posts[ $url ] ) ) {
			self::$posts[ $url ] = (int) ( function_exists( 'wpcom_vip_url_to_postid' ) ? wpcom_vip_url_to_postid( $url ) : url_to_postid( $url ) ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid -- fallback off VIP.
		}

		return self::$posts[ $url ];
	}

	/**
	 * The attributes for a page, from its path and post ID. Pure. Only the
	 * path is passed in: a query string can carry notices, tracking IDs or
	 * worse.
	 *
	 * @return array<int, array{key: string, value: string}>
	 */
	public static function attributes( string $path, int $post_id ): array {
		$out = [];

		if ( '' !== $path ) {
			$out[] = [ 'key' => self::SOURCE, 'value' => $path ];
		}

		if ( $post_id > 0 ) {
			$out[] = [ 'key' => self::POST, 'value' => (string) $post_id ];
		}

		return $out;
	}

	/**
	 * Keeps well-formed pairs with non-empty string keys and values, cut to
	 * length, one per key. Pure.
	 *
	 * @return array<int, array{key: string, value: string}>
	 */
	public static function clean( array $attributes ): array {
		$out = [];

		foreach ( $attributes as $attribute ) {
			if ( ! is_array( $attribute ) || ! is_scalar( $attribute['key'] ?? null ) || ! is_scalar( $attribute['value'] ?? null ) ) {
				continue;
			}

			$key   = substr( trim( (string) $attribute['key'] ), 0, self::MAX );
			$value = substr( trim( (string) $attribute['value'] ), 0, self::MAX );

			if ( '' !== $key && '' !== $value ) {
				$out[ $key ] = [ 'key' => $key, 'value' => $value ];
			}
		}

		return array_values( $out );
	}
}
