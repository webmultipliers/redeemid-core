<?php

namespace WP2_Shopify\Shopify;

use WP2_Shopify\Config;

/**
 * Storefront API from the server (spec 3.2): public catalog reads, cart
 * creation for buy-now and buyer identity for the official plugin's cart.
 * Never personalised except for the buyer identity a signed-in customer's own
 * token adds to their cart.
 *
 * Shopify asks server-side callers to use a private token and to pass the
 * buyer's IP on requests that come from buyer traffic; without it every
 * visitor looks like the server's one IP, and checkout creation is throttled
 * per minute. So the private token is used when it is set, with the buyer IP
 * on buy-now and cart calls, and the public token is the fallback.
 */
final class Storefront {
	/** Catalog responses are display data, cached briefly (spec 15.1). */
	public const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	private const PRODUCT_QUERY = <<<'GRAPHQL'
query WP2PlanProduct($handle: String!) {
  product(handle: $handle) {
    id
    handle
    title
    description
    featuredImage { url altText width height }
    variants(first: 25) {
      nodes {
        id
        title
        availableForSale
        price { amount currencyCode }
        compareAtPrice { amount currencyCode }
      }
    }
  }
}
GRAPHQL;

	private const PRODUCT_SEARCH = <<<'GRAPHQL'
query WP2ProductSearch($query: String) {
  products(first: 20, query: $query, sortKey: TITLE) {
    nodes { handle title }
  }
}
GRAPHQL;

	private const CART_CREATE = <<<'GRAPHQL'
mutation WP2BuyNow($input: CartInput!) {
  cartCreate(input: $input) {
    cart { id checkoutUrl }
    userErrors { field message }
  }
}
GRAPHQL;

	private const CART_BUYER = <<<'GRAPHQL'
mutation WP2CartBuyer($cartId: ID!, $buyerIdentity: CartBuyerIdentityInput!) {
  cartBuyerIdentityUpdate(cartId: $cartId, buyerIdentity: $buyerIdentity) {
    cart { id }
    userErrors { field message }
  }
}
GRAPHQL;

	public static function available(): bool {
		return '' !== Config::store_domain() && ( Config::has( 'WP2_SHOPIFY_STOREFRONT_PRIVATE_TOKEN' ) || Config::has( 'WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN' ) );
	}

	public static function endpoint(): string {
		return 'https://' . Config::store_domain() . '/api/' . Config::api_version() . '/graphql.json';
	}

	/**
	 * Products whose title matches a search, for the Plan Card's product
	 * picker in the editor. Cached briefly per search like every catalog read.
	 *
	 * @return array<int, array{handle: string, title: string}>|\WP_Error
	 */
	public static function search_products( string $search ): array|\WP_Error {
		$search = trim( preg_replace( '/[^\p{L}\p{N} _-]+/u', ' ', $search ) ?? '' );
		$key    = 'wp2_shopify_sf_search_' . md5( Config::store_domain() . '|' . strtolower( $search ) );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! self::available() ) {
			return new \WP_Error( 'shopify_unconfigured', 'Storefront access is not configured.' );
		}

		$data = Http::graphql( self::endpoint(), self::headers(), self::PRODUCT_SEARCH, [ 'query' => self::title_query( $search ) ], 'storefront.product_search' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$products = [];

		foreach ( (array) ( $data['products']['nodes'] ?? [] ) as $node ) {
			if ( is_array( $node ) && ! empty( $node['handle'] ) ) {
				$products[] = [ 'handle' => (string) $node['handle'], 'title' => (string) ( $node['title'] ?? $node['handle'] ) ];
			}
		}

		set_transient( $key, $products, self::CACHE_TTL );

		return $products;
	}

	/**
	 * Shopify search syntax for "title has words starting with each of these".
	 * Shopify's `*` only matches as a suffix (a prefix query), so `*gold*`
	 * finds nothing for a word in the middle of a title; each word becomes
	 * its own `title:word*` term instead. Null (every product) when empty.
	 */
	public static function title_query( string $search ): ?string {
		$words = preg_split( '/\s+/', trim( $search ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];

		return $words ? implode( ' ', array_map( static fn( string $word ) => 'title:' . $word . '*', $words ) ) : null;
	}

	/**
	 * A product with its variants, from cache when fresh. Null when the handle
	 * does not resolve; WP_Error when Shopify could not be asked.
	 */
	public static function product( string $handle ): array|null|\WP_Error {
		$handle = sanitize_title( $handle );

		if ( '' === $handle ) {
			return null;
		}

		$key    = 'wp2_shopify_sf_product_' . md5( Config::store_domain() . '|' . $handle );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return ! empty( $cached['error'] ) ? new \WP_Error( 'shopify_unreachable', 'Shopify was unreachable a moment ago.' ) : $cached['product'];
		}

		if ( ! self::available() ) {
			return new \WP_Error( 'shopify_unconfigured', 'Storefront access is not configured.' );
		}

		$data = Http::graphql( self::endpoint(), self::headers(), self::PRODUCT_QUERY, [ 'handle' => $handle ], 'storefront.product' );

		if ( is_wp_error( $data ) ) {
			// Back off briefly so an outage doesn't cost every page view a timeout.
			set_transient( $key, [ 'error' => true ], MINUTE_IN_SECONDS );

			return $data;
		}

		$product = is_array( $data['product'] ?? null ) ? self::shape_product( $data['product'] ) : null;

		set_transient( $key, [ 'product' => $product ], self::CACHE_TTL );

		return $product;
	}

	/**
	 * Keep only what blocks render. Prices stay as Shopify returned them.
	 */
	public static function shape_product( array $raw ): array {
		$variants = [];

		foreach ( $raw['variants']['nodes'] ?? [] as $node ) {
			if ( ! is_array( $node ) || empty( $node['id'] ) ) {
				continue;
			}

			$variants[] = [
				'id'        => (string) $node['id'],
				'title'     => (string) ( $node['title'] ?? '' ),
				'available' => ! empty( $node['availableForSale'] ),
				'price'     => is_array( $node['price'] ?? null ) ? [ 'amount' => (string) $node['price']['amount'], 'currency' => (string) $node['price']['currencyCode'] ] : null,
				'compareAt' => is_array( $node['compareAtPrice'] ?? null ) ? [ 'amount' => (string) $node['compareAtPrice']['amount'], 'currency' => (string) $node['compareAtPrice']['currencyCode'] ] : null,
			];
		}

		$image = is_array( $raw['featuredImage'] ?? null ) ? $raw['featuredImage'] : null;

		return [
			'id'          => (string) ( $raw['id'] ?? '' ),
			'handle'      => (string) ( $raw['handle'] ?? '' ),
			'title'       => (string) ( $raw['title'] ?? '' ),
			'description' => (string) ( $raw['description'] ?? '' ),
			'image'       => $image ? [ 'url' => (string) $image['url'], 'alt' => (string) ( $image['altText'] ?? '' ), 'width' => (int) ( $image['width'] ?? 0 ), 'height' => (int) ( $image['height'] ?? 0 ) ] : null,
			'variants'    => $variants,
		];
	}

	/**
	 * The variant a plan card sells: the requested one if it exists, else the
	 * first available one, else the first one (rendered as sold out).
	 */
	public static function pick_variant( array $product, string $variant_id = '' ): ?array {
		$variants = $product['variants'] ?? [];

		foreach ( $variants as $variant ) {
			if ( '' !== $variant_id && ( $variant['id'] === $variant_id || str_ends_with( $variant['id'], '/' . $variant_id ) ) ) {
				return $variant;
			}
		}

		foreach ( $variants as $variant ) {
			if ( $variant['available'] ) {
				return $variant;
			}
		}

		return $variants[0] ?? null;
	}

	/**
	 * Create a one-line cart and return its checkout URL, requested fresh
	 * (spec 10.2 buy-now, 12.3). A signed-in customer's token attaches buyer
	 * identity so checkout opens signed in.
	 */
	public static function checkout_url( string $variant_gid, int $quantity, ?string $customer_token = null ): string|\WP_Error {
		if ( ! self::available() ) {
			return new \WP_Error( 'shopify_unconfigured', 'Storefront access is not configured.' );
		}

		$input = [ 'lines' => [ [ 'merchandiseId' => $variant_gid, 'quantity' => max( 1, min( 10, $quantity ) ) ] ] ];

		if ( $customer_token ) {
			$input['buyerIdentity'] = [ 'customerAccessToken' => $customer_token ];
		}

		$data = Http::graphql( self::endpoint(), self::headers( true ), self::CART_CREATE, [ 'input' => $input ], 'storefront.cart_create' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$errors = $data['cartCreate']['userErrors'] ?? [];

		if ( $errors ) {
			return new \WP_Error( 'shopify_cart', (string) ( $errors[0]['message'] ?? 'Shopify refused the cart.' ) );
		}

		$url = (string) ( $data['cartCreate']['cart']['checkoutUrl'] ?? '' );

		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ? $url : new \WP_Error( 'shopify_cart', 'Shopify returned no checkout URL.' );
	}

	/**
	 * Attach a signed-in customer to an existing cart, such as the one the
	 * official plugin's cart component keeps, so its checkout opens signed in.
	 * The token goes from server to Shopify only.
	 */
	public static function attach_buyer( string $cart_gid, string $customer_token ): true|\WP_Error {
		if ( ! self::available() ) {
			return new \WP_Error( 'shopify_unconfigured', 'Storefront access is not configured.' );
		}

		$data = Http::graphql( self::endpoint(), self::headers( true ), self::CART_BUYER, [ 'cartId' => $cart_gid, 'buyerIdentity' => [ 'customerAccessToken' => $customer_token ] ], 'storefront.cart_buyer' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$errors = $data['cartBuyerIdentityUpdate']['userErrors'] ?? [];

		if ( $errors ) {
			return new \WP_Error( 'shopify_cart', (string) ( $errors[0]['message'] ?? 'Shopify refused the buyer identity.' ) );
		}

		return empty( $data['cartBuyerIdentityUpdate']['cart']['id'] ) ? new \WP_Error( 'shopify_cart_missing', 'Shopify has no such cart.' ) : true;
	}

	/**
	 * Money as Shopify returned it, in its own currency (spec 10.1: never converted).
	 */
	public static function format_money( ?array $money ): string {
		if ( ! $money || '' === $money['amount'] ) {
			return '';
		}

		if ( class_exists( \NumberFormatter::class ) ) {
			$formatter = new \NumberFormatter( get_locale(), \NumberFormatter::CURRENCY );
			$formatted = $formatter->formatCurrency( (float) $money['amount'], $money['currency'] );

			if ( false !== $formatted ) {
				return $formatted;
			}
		}

		return $money['amount'] . ' ' . $money['currency'];
	}

	/**
	 * @param bool $buyer The request comes from a buyer's action (buy-now, cart), not a cached catalog read.
	 */
	private static function headers( bool $buyer = false ): array {
		$private = Config::get( 'WP2_SHOPIFY_STOREFRONT_PRIVATE_TOKEN' );

		if ( '' === $private ) {
			return [ 'X-Shopify-Storefront-Access-Token' => Config::get( 'WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN' ) ];
		}

		$ip = $buyer ? self::buyer_ip() : '';

		return [ 'Shopify-Storefront-Private-Token' => $private ] + ( '' !== $ip ? [ 'Shopify-Storefront-Buyer-IP' => $ip ] : [] );
	}

	/**
	 * The visitor's IP as the web server saw it. Behind a proxy or CDN that is
	 * the proxy's address; the wp2_shopify_buyer_ip filter can return the real
	 * one from a header the site trusts. Forwarded headers are not read here,
	 * because a visitor can set them.
	 */
	private static function buyer_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'wp2_shopify_buyer_ip', $ip );

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
