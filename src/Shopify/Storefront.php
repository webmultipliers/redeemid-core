<?php

namespace RedeemID\Shopify;

use RedeemID\Config;

/**
 * Storefront API from the server: public catalog reads, cart
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
	/** Catalog responses are display data, cached briefly. */
	public const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	private const PRODUCT_QUERY = <<<'GRAPHQL'
query RedeemIDPlanProduct($handle: String!) {
  product(handle: $handle) {
    id
    handle
    title
    description
    requiresSellingPlan
    featuredImage { url altText width height }
    options { name }
    variants(first: 25) {
      nodes {
        id
        title
        availableForSale
        price { amount currencyCode }
        compareAtPrice { amount currencyCode }
        sellingPlanAllocations(first: 10) {
          nodes {
            sellingPlan { id name recurringDeliveries }
            priceAdjustments { price { amount currencyCode } compareAtPrice { amount currencyCode } }
          }
        }
      }
    }
  }
}
GRAPHQL;

	private const PRODUCT_SEARCH = <<<'GRAPHQL'
query RedeemIDProductSearch($query: String) {
  products(first: 20, query: $query, sortKey: TITLE) {
    nodes { handle title }
  }
}
GRAPHQL;

	private const COLLECTION_SEARCH = <<<'GRAPHQL'
query RedeemIDCollectionSearch($query: String) {
  collections(first: 20, query: $query, sortKey: TITLE) {
    nodes { handle title }
  }
}
GRAPHQL;

	private const COLLECTION_QUERY = <<<'GRAPHQL'
query RedeemIDCollection($handle: String!, $first: Int, $after: String, $last: Int, $before: String) {
  collection(handle: $handle) {
    handle
    title
    description
    products(first: $first, after: $after, last: $last, before: $before) {
      nodes {
        handle
        title
        availableForSale
        requiresSellingPlan
        onlineStoreUrl
        featuredImage { url altText width height }
        priceRange { minVariantPrice { amount currencyCode } maxVariantPrice { amount currencyCode } }
        compareAtPriceRange { minVariantPrice { amount currencyCode } }
        variants(first: 2) { nodes { id availableForSale } }
      }
      pageInfo { hasNextPage hasPreviousPage startCursor endCursor }
    }
  }
}
GRAPHQL;

	/** Most products a collection block shows per page. */
	public const MAX_PER_PAGE = 48;

	private const CART_CREATE = <<<'GRAPHQL'
mutation RedeemIDBuyNow($input: CartInput!) {
  cartCreate(input: $input) {
    cart { id checkoutUrl }
    userErrors { field message }
  }
}
GRAPHQL;

	private const CART_BUYER = <<<'GRAPHQL'
mutation RedeemIDCartBuyer($cartId: ID!, $buyerIdentity: CartBuyerIdentityInput!) {
  cartBuyerIdentityUpdate(cartId: $cartId, buyerIdentity: $buyerIdentity) {
    cart { id }
    userErrors { field message }
  }
}
GRAPHQL;

	public static function available(): bool {
		return '' !== Config::store_domain() && ( Config::has( 'REDEEMID_STOREFRONT_PRIVATE_TOKEN' ) || Config::has( 'REDEEMID_PUBLIC_ACCESS_TOKEN' ) );
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
		$key    = 'redeemid_sf_search_' . md5( Config::store_domain() . '|' . strtolower( $search ) );
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
	 * Collections whose title matches a search, for the Collection block's
	 * picker in the editor. Cached like the product search.
	 *
	 * @return array<int, array{handle: string, title: string}>|\WP_Error
	 */
	public static function search_collections( string $search ): array|\WP_Error {
		$search = trim( preg_replace( '/[^\p{L}\p{N} _-]+/u', ' ', $search ) ?? '' );
		$key    = 'redeemid_sf_csearch_' . md5( Config::store_domain() . '|' . strtolower( $search ) );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! self::available() ) {
			return new \WP_Error( 'shopify_unconfigured', 'Storefront access is not configured.' );
		}

		$data = Http::graphql( self::endpoint(), self::headers(), self::COLLECTION_SEARCH, [ 'query' => self::title_query( $search ) ], 'storefront.collection_search' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$collections = [];

		foreach ( (array) ( $data['collections']['nodes'] ?? [] ) as $node ) {
			if ( is_array( $node ) && ! empty( $node['handle'] ) ) {
				$collections[] = [ 'handle' => (string) $node['handle'], 'title' => (string) ( $node['title'] ?? $node['handle'] ) ];
			}
		}

		set_transient( $key, $collections, self::CACHE_TTL );

		return $collections;
	}

	/**
	 * One page of a collection's products, from cache when fresh. $after and
	 * $before are cursors from an earlier page; give at most one. Null when
	 * the handle does not resolve; WP_Error when Shopify could not be asked.
	 *
	 * @return array{handle: string, title: string, description: string, products: array, next: string, previous: string}|null|\WP_Error
	 */
	public static function collection( string $handle, int $per_page, string $after = '', string $before = '' ): array|null|\WP_Error {
		$handle   = sanitize_title( $handle );
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$after    = self::cursor( $after );
		$before   = '' === $after ? self::cursor( $before ) : '';

		if ( '' === $handle ) {
			return null;
		}

		$country = self::country();
		$key     = 'redeemid_sf_collection_' . md5( implode( '|', [ Config::store_domain(), $handle, $per_page, $after, $before, $country ] ) );
		$cached  = get_transient( $key );

		if ( is_array( $cached ) ) {
			return ! empty( $cached['error'] ) ? new \WP_Error( 'shopify_unreachable', 'Shopify was unreachable a moment ago.' ) : $cached['collection'];
		}

		if ( ! self::available() ) {
			return new \WP_Error( 'shopify_unconfigured', 'Storefront access is not configured.' );
		}

		$vars = [ 'handle' => $handle ] + ( '' !== $before ? [ 'last' => $per_page, 'before' => $before ] : [ 'first' => $per_page ] + ( '' !== $after ? [ 'after' => $after ] : [] ) );
		$data = Http::graphql( self::endpoint(), self::headers(), self::in_context( self::COLLECTION_QUERY, $country ), $vars, 'storefront.collection' );

		if ( is_wp_error( $data ) ) {
			// Back off on the first page only: a bad cursor in a URL must not stand in for an outage.
			if ( '' === $after && '' === $before ) {
				set_transient( $key, [ 'error' => true ], MINUTE_IN_SECONDS );
			}

			return $data;
		}

		$collection = is_array( $data['collection'] ?? null ) ? self::shape_collection( $data['collection'] ) : null;

		set_transient( $key, [ 'collection' => $collection ], self::CACHE_TTL );

		return $collection;
	}

	/**
	 * Keep only what the collection block renders. Pure.
	 */
	public static function shape_collection( array $raw ): array {
		$products = [];

		foreach ( $raw['products']['nodes'] ?? [] as $node ) {
			if ( is_array( $node ) && ! empty( $node['handle'] ) ) {
				$products[] = self::shape_summary( $node );
			}
		}

		$page = $raw['products']['pageInfo'] ?? [];

		return [
			'handle'      => (string) ( $raw['handle'] ?? '' ),
			'title'       => (string) ( $raw['title'] ?? '' ),
			'description' => (string) ( $raw['description'] ?? '' ),
			'products'    => $products,
			'next'        => ! empty( $page['hasNextPage'] ) ? self::cursor( (string) ( $page['endCursor'] ?? '' ) ) : '',
			'previous'    => ! empty( $page['hasPreviousPage'] ) ? self::cursor( (string) ( $page['startCursor'] ?? '' ) ) : '',
		];
	}

	/**
	 * A product as a card in a list: its lowest price, and the one variant
	 * buy-now can sell straight from the card when there is no choice to make
	 * ('' when the buyer must pick options or a selling plan first). Pure.
	 */
	public static function shape_summary( array $node ): array {
		$image    = is_array( $node['featuredImage'] ?? null ) ? $node['featuredImage'] : null;
		$variants = array_values( array_filter( (array) ( $node['variants']['nodes'] ?? [] ), static fn( $v ) => is_array( $v ) && ! empty( $v['id'] ) ) );
		$min      = self::money( $node['priceRange']['minVariantPrice'] ?? null );
		$max      = self::money( $node['priceRange']['maxVariantPrice'] ?? null );
		$compare  = self::money( $node['compareAtPriceRange']['minVariantPrice'] ?? null );
		$single   = 1 === count( $variants ) && empty( $node['requiresSellingPlan'] );
		$url      = (string) ( $node['onlineStoreUrl'] ?? '' );

		return [
			'handle'    => (string) $node['handle'],
			'title'     => (string) ( $node['title'] ?? '' ),
			'available' => ! empty( $node['availableForSale'] ),
			'image'     => $image ? [ 'url' => (string) $image['url'], 'alt' => (string) ( $image['altText'] ?? '' ), 'width' => (int) ( $image['width'] ?? 0 ), 'height' => (int) ( $image['height'] ?? 0 ) ] : null,
			'price'     => $min,
			'from'      => $min && $max && (float) $max['amount'] > (float) $min['amount'],
			'compareAt' => $compare && $min && (float) $compare['amount'] > (float) $min['amount'] ? $compare : null,
			'variant'   => $single && ! empty( $variants[0]['availableForSale'] ) ? (string) $variants[0]['id'] : '',
			'url'       => str_starts_with( $url, 'https://' ) ? $url : '',
		];
	}

	/**
	 * A pagination cursor from a URL, or '' when it isn't one. Shopify's
	 * cursors are opaque base64 strings. Pure.
	 */
	public static function cursor( string $raw ): string {
		$raw = trim( $raw );

		return strlen( $raw ) <= 512 && preg_match( '#^[A-Za-z0-9+/=_-]+$#', $raw ) ? $raw : '';
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

		$country = self::country();
		$key     = 'redeemid_sf_product_' . md5( Config::store_domain() . '|' . $handle . '|' . $country );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			return ! empty( $cached['error'] ) ? new \WP_Error( 'shopify_unreachable', 'Shopify was unreachable a moment ago.' ) : $cached['product'];
		}

		if ( ! self::available() ) {
			return new \WP_Error( 'shopify_unconfigured', 'Storefront access is not configured.' );
		}

		$data = Http::graphql( self::endpoint(), self::headers(), self::in_context( self::PRODUCT_QUERY, $country ), [ 'handle' => $handle ], 'storefront.product' );

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
				'price'     => self::money( $node['price'] ?? null ),
				'compareAt' => self::money( $node['compareAtPrice'] ?? null ),
				'plans'     => self::shape_plans( $node['sellingPlanAllocations']['nodes'] ?? [] ),
			];
		}

		$image   = is_array( $raw['featuredImage'] ?? null ) ? $raw['featuredImage'] : null;
		$options = [];

		foreach ( (array) ( $raw['options'] ?? [] ) as $option ) {
			if ( is_array( $option ) && '' !== (string) ( $option['name'] ?? '' ) ) {
				$options[] = (string) $option['name'];
			}
		}

		return [
			'id'            => (string) ( $raw['id'] ?? '' ),
			'handle'        => (string) ( $raw['handle'] ?? '' ),
			'title'         => (string) ( $raw['title'] ?? '' ),
			'description'   => (string) ( $raw['description'] ?? '' ),
			'requires_plan' => ! empty( $raw['requiresSellingPlan'] ),
			'image'         => $image ? [ 'url' => (string) $image['url'], 'alt' => (string) ( $image['altText'] ?? '' ), 'width' => (int) ( $image['width'] ?? 0 ), 'height' => (int) ( $image['height'] ?? 0 ) ] : null,
			'variants'      => $variants,
			// Shopify gives a product with no options one "Title" option holding one "Default Title" value.
			'has_options'   => count( $variants ) > 1 || ( $options && [ 'Title' ] !== $options ),
		];
	}

	/**
	 * @return array{amount: string, currency: string}|null
	 */
	private static function money( mixed $raw ): ?array {
		return is_array( $raw ) && isset( $raw['amount'], $raw['currencyCode'] ) ? [ 'amount' => (string) $raw['amount'], 'currency' => (string) $raw['currencyCode'] ] : null;
	}

	/**
	 * A variant's selling plans (subscriptions, pre-orders) with the price
	 * each one charges.
	 *
	 * @return array<int, array{id: string, name: string, recurring: bool, price: ?array, compareAt: ?array}>
	 */
	private static function shape_plans( mixed $nodes ): array {
		$plans = [];

		foreach ( is_array( $nodes ) ? $nodes : [] as $node ) {
			$plan = is_array( $node ) ? ( $node['sellingPlan'] ?? null ) : null;

			if ( ! is_array( $plan ) || empty( $plan['id'] ) ) {
				continue;
			}

			$adjust  = $node['priceAdjustments'][0] ?? [];
			$plans[] = [
				'id'        => (string) $plan['id'],
				'name'      => (string) ( $plan['name'] ?? '' ),
				'recurring' => ! empty( $plan['recurringDeliveries'] ),
				'price'     => self::money( $adjust['price'] ?? null ),
				'compareAt' => self::money( $adjust['compareAtPrice'] ?? null ),
			];
		}

		return $plans;
	}

	/**
	 * The selling plan a plan card sells: the one asked for if the variant has
	 * it; otherwise the first, when the product can only be bought on a plan;
	 * otherwise none (a one-time purchase).
	 */
	public static function pick_plan( array $product, array $variant, string $plan_id = '' ): ?array {
		$plans = $variant['plans'] ?? [];

		foreach ( $plans as $plan ) {
			if ( '' !== $plan_id && ( $plan['id'] === $plan_id || str_ends_with( $plan['id'], '/' . $plan_id ) ) ) {
				return $plan;
			}
		}

		return ! empty( $product['requires_plan'] ) ? ( $plans[0] ?? null ) : null;
	}

	/**
	 * The country whose prices and currency to show, as an ISO code, or '' for
	 * the store's default. Off unless a filter returns one, for example from a
	 * CDN's country header. A page cache must then vary by that header, since
	 * plan card prices differ by country.
	 */
	public static function country(): string {
		$country = strtoupper( (string) apply_filters( 'redeemid_context_country', '' ) );

		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
	}

	/**
	 * Add Shopify's @inContext directive for $country to a query's operation. Pure.
	 */
	public static function in_context( string $query, string $country ): string {
		if ( '' === $country ) {
			return $query;
		}

		return (string) preg_replace( '/^(\s*(?:query|mutation)\s+\w+(?:\([^)]*\))?)/', '$1 @inContext(country: ' . $country . ')', $query, 1 );
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
	 * for buy-now. A signed-in customer's token attaches buyer
	 * identity so checkout opens signed in. $selling_plan buys the line on a
	 * subscription or other selling plan.
	 */
	public static function checkout_url( string $variant_gid, int $quantity, ?string $customer_token = null, string $selling_plan = '' ): string|\WP_Error {
		if ( ! self::available() ) {
			return new \WP_Error( 'shopify_unconfigured', 'Storefront access is not configured.' );
		}

		$line    = [ 'merchandiseId' => $variant_gid, 'quantity' => max( 1, min( 10, $quantity ) ) ] + ( '' !== $selling_plan ? [ 'sellingPlanId' => $selling_plan ] : [] );
		$input   = [ 'lines' => [ $line ] ];
		$country = self::country();
		$buyer   = ( $customer_token ? [ 'customerAccessToken' => $customer_token ] : [] ) + ( '' !== $country ? [ 'countryCode' => $country ] : [] );

		if ( $buyer ) {
			$input['buyerIdentity'] = $buyer;
		}

		$data = Http::graphql( self::endpoint(), self::headers( true ), self::in_context( self::CART_CREATE, $country ), [ 'input' => $input ], 'storefront.cart_create' );

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
	 * Money as Shopify returned it, in its own currency (never converted).
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
		$private = Config::get( 'REDEEMID_STOREFRONT_PRIVATE_TOKEN' );

		if ( '' === $private ) {
			return [ 'X-Shopify-Storefront-Access-Token' => Config::get( 'REDEEMID_PUBLIC_ACCESS_TOKEN' ) ];
		}

		$ip = $buyer ? self::buyer_ip() : '';

		return [ 'Shopify-Storefront-Private-Token' => $private ] + ( '' !== $ip ? [ 'Shopify-Storefront-Buyer-IP' => $ip ] : [] );
	}

	/**
	 * The visitor's IP as the web server saw it. Behind a proxy or CDN that is
	 * the proxy's address; the redeemid_buyer_ip filter can return the real
	 * one from a header the site trusts. Forwarded headers are not read here,
	 * because a visitor can set them. Buy-now's rate limit keys on this too.
	 */
	public static function buyer_ip(): string {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- validated with FILTER_VALIDATE_IP below; read on uncached checkout calls.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'redeemid_buyer_ip', $ip );

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
