<?php

namespace RedeemID\Commerce;

use RedeemID\Features\Cache;
use RedeemID\Settings;
use RedeemID\Shopify\Storefront;

/**
 * Storefront pages: a product page, a collection page and the cart page,
 * at paths the site chooses (Settings: product_path, collection_path,
 * cart_path; /products/{handle}, /collections/{handle} and /cart by default).
 *
 * Each page is a block template (block themes, editable in the Site Editor)
 * or a small PHP template (classic themes) holding RedeemID's Shopify
 * Product, Shopify Collection or Shopify Cart block, which read the handle
 * from the URL. Pages render on the server like the blocks.
 *
 * Shopify's own WordPress plugin claims /cart, /products/* and
 * /collections/* by prepending rewrite rules. By default RedeemID stands down
 * on a path that plugin owns and serves the others. With "RedeemID takes
 * precedence" (storefront_first), RedeemID adds its rules after that plugin
 * has added its own, so RedeemID's win on shared paths. A path a published
 * WordPress page already uses is never taken.
 */
final class Pages {
	public const PRODUCT    = 'product';
	public const COLLECTION = 'collection';
	public const CART       = 'cart';

	/** Query var per page. */
	public const VARS = [
		self::PRODUCT    => 'redeemid_product',
		self::COLLECTION => 'redeemid_collection',
		self::CART       => 'redeemid_cart',
	];

	/** Bump when the rules change, so existing sites rebuild them once. */
	private const RULES_VERSION = '1';

	/** @var array{kind: string, handle: string, entity: array|null}|null */
	private static ?array $request = null;

	public static function register(): void {
		add_filter( 'query_vars', [ self::class, 'query_vars' ] );
		// After Shopify's plugin (priority 10) when RedeemID takes precedence, so ours are prepended last and win; before it otherwise.
		add_filter( 'rewrite_rules_array', [ self::class, 'rules' ], self::first() ? 20 : 5 );
		add_action( 'init', [ self::class, 'maybe_flush' ], 99 );
		add_action( 'init', [ self::class, 'register_templates' ] );
		add_action( 'update_option_' . Settings::OPTION, [ self::class, 'settings_changed' ], 10, 2 );
		add_filter( 'pre_handle_404', [ self::class, 'resolve' ], 10, 2 );
		add_filter( 'template_include', [ self::class, 'template' ], 99 );
		add_filter( 'body_class', [ self::class, 'body_class' ] );
		add_filter( 'redeemid_product_url', [ self::class, 'product_url' ], 5, 2 );
		add_filter( 'wp_robots', [ self::class, 'robots' ] );
		Seo::register();
	}

	public static function enabled(): bool {
		return (bool) Settings::get( 'storefront_pages' );
	}

	public static function first(): bool {
		return (bool) Settings::get( 'storefront_first' );
	}

	/**
	 * A path as typed in Settings, cleaned to up to three lower-case
	 * segments of letters, digits, hyphens and underscores. '' when nothing
	 * usable is left. Pure.
	 */
	public static function clean_path( string $raw ): string {
		$segments = array_filter( explode( '/', strtolower( trim( $raw ) ) ), static fn( string $s ) => '' !== $s );
		$segments = array_map( static fn( string $s ) => (string) preg_replace( '/[^a-z0-9_-]/', '', $s ), $segments );
		$segments = array_values( array_filter( $segments, static fn( string $s ) => '' !== $s ) );

		return implode( '/', array_slice( $segments, 0, 3 ) );
	}

	/**
	 * The path for a page kind, from Settings or the redeemid_storefront_path filter.
	 */
	public static function path( string $kind ): string {
		$default = [ self::PRODUCT => 'products', self::COLLECTION => 'collections', self::CART => 'cart' ][ $kind ] ?? '';
		$path    = self::clean_path( (string) apply_filters( 'redeemid_storefront_path', (string) Settings::get( $kind . '_path' ), $kind ) );

		return '' !== $path ? $path : $default;
	}

	/**
	 * The rewrite rules RedeemID wants, keyed like WordPress's. Pure apart from
	 * the paths passed in.
	 *
	 * @param array<string, string> $paths Kind => path.
	 * @return array<string, string>
	 */
	public static function rules_for( array $paths ): array {
		$rules = [];

		foreach ( $paths as $kind => $path ) {
			$base = '^' . preg_quote( $path, '#' );

			$rules[ self::CART === $kind ? $base . '/?$' : $base . '/([^/]+)/?$' ] = 'index.php?' . self::VARS[ $kind ] . '=' . ( self::CART === $kind ? '1' : '$matches[1]' );
		}

		return $rules;
	}

	/**
	 * rewrite_rules_array: add RedeemID's rules on top, skipping a path a
	 * published page uses and, unless RedeemID takes precedence, a path
	 * Shopify's plugin has already claimed.
	 *
	 * @param array<string, string> $rules
	 * @return array<string, string>
	 */
	public static function rules( mixed $rules ): array {
		$rules = is_array( $rules ) ? $rules : [];

		if ( ! self::enabled() ) {
			return $rules;
		}

		$paths = [];

		foreach ( array_keys( self::VARS ) as $kind ) {
			$path = self::path( $kind );

			if ( get_page_by_path( $path ) ) {
				continue;
			}

			$paths[ $kind ] = $path;
		}

		$ours = self::rules_for( $paths );

		if ( ! self::first() ) {
			$ours = array_diff_key( $ours, $rules );
		}

		return $ours + $rules;
	}

	/**
	 * Whether RedeemID's rule for a page kind is live in the stored rules,
	 * which is what decides who answers that URL.
	 */
	public static function serves( string $kind ): bool {
		if ( ! isset( self::VARS[ $kind ] ) || ! self::enabled() ) {
			return false;
		}

		$rules = get_option( 'rewrite_rules' );
		$key   = array_key_first( self::rules_for( [ $kind => self::path( $kind ) ] ) );

		return is_array( $rules ) && isset( $rules[ $key ] ) && str_contains( (string) $rules[ $key ], self::VARS[ $kind ] . '=' );
	}

	/**
	 * Who answers a page kind's URL, for Health: 'redeemid', 'shopify' (its
	 * own WordPress plugin) or '' (nobody: a WordPress page has the path, or
	 * rewrite rules need rebuilding).
	 */
	public static function owner( string $kind ): string {
		if ( self::serves( $kind ) ) {
			return 'redeemid';
		}

		$rules = get_option( 'rewrite_rules' );
		$key   = isset( self::VARS[ $kind ] ) ? array_key_first( self::rules_for( [ $kind => self::path( $kind ) ] ) ) : '';

		return is_array( $rules ) && isset( $rules[ $key ] ) && str_contains( (string) $rules[ $key ], 'shopify_' ) ? 'shopify' : '';
	}

	public static function url( string $kind, string $handle = '' ): string {
		$path = self::path( $kind );

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( self::VARS[ $kind ], '' !== $handle ? $handle : '1', home_url( '/' ) );
		}

		return home_url( user_trailingslashit( '/' . $path . ( '' !== $handle ? '/' . $handle : '' ) ) );
	}

	/**
	 * redeemid_product_url: product links go to RedeemID's product page when it serves one.
	 */
	public static function product_url( mixed $url, mixed $handle ): string {
		return is_string( $handle ) && '' !== $handle && self::serves( self::PRODUCT ) ? self::url( self::PRODUCT, sanitize_title( $handle ) ) : (string) $url;
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public static function query_vars( mixed $vars ): array {
		return array_merge( is_array( $vars ) ? $vars : [], array_values( self::VARS ) );
	}

	/**
	 * Rebuild rewrite rules once after an update changes them, the same as
	 * saving Settings → Permalinks.
	 */
	public static function maybe_flush(): void {
		$version = self::RULES_VERSION . '|' . ( self::enabled() ? 1 : 0 ) . '|' . ( self::first() ? 1 : 0 ) . '|' . implode( ',', array_map( [ self::class, 'path' ], array_keys( self::VARS ) ) );

		if ( get_option( 'redeemid_storefront_rules' ) !== $version ) {
			update_option( 'redeemid_storefront_rules', $version, true );
			flush_rewrite_rules( false ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- once per change of paths or switches.
		}
	}

	/**
	 * Paths and switches live in Settings; maybe_flush() notices a change on the next request.
	 */
	public static function settings_changed( mixed $old, mixed $new ): void {
		delete_option( 'redeemid_storefront_rules' );
	}

	/**
	 * The storefront page this request asks for, with its Shopify data. Null
	 * when the request is not one.
	 *
	 * @return array{kind: string, handle: string, entity: array|null}|null
	 */
	public static function request(): ?array {
		if ( null !== self::$request ) {
			return '' === self::$request['kind'] ? null : self::$request;
		}

		self::$request = [ 'kind' => '', 'handle' => '', 'entity' => null ];

		foreach ( self::VARS as $kind => $var ) {
			$value = (string) get_query_var( $var );

			if ( '' === $value || ! self::serves( $kind ) ) {
				continue;
			}

			$handle = self::CART === $kind ? '' : sanitize_title( $value );
			$entity = match ( $kind ) {
				self::PRODUCT    => Storefront::product( $handle ),
				self::COLLECTION => Storefront::collection( $handle, 1 ),
				default          => [],
			};

			self::$request = [ 'kind' => $kind, 'handle' => $handle, 'entity' => is_array( $entity ) ? $entity : null ];
			break;
		}

		return '' === self::$request['kind'] ? null : self::$request;
	}

	/**
	 * pre_handle_404: a product or collection Shopify has is a page; one it
	 * doesn't have is a 404. While Shopify is unreachable the page still
	 * renders, and its block says so.
	 */
	public static function resolve( mixed $preempt, mixed $query ): mixed {
		$request = self::request();

		if ( ! $request || ! ( $query instanceof \WP_Query ) || ! $query->is_main_query() ) {
			return $preempt;
		}

		if ( self::CART !== $request['kind'] && null === $request['entity'] && ! self::unreachable( $request ) ) {
			$query->set_404();
			status_header( 404 );
			nocache_headers();

			return true;
		}

		status_header( 200 );

		return true;
	}

	private static function unreachable( array $request ): bool {
		$check = self::PRODUCT === $request['kind'] ? Storefront::product( $request['handle'] ) : Storefront::collection( $request['handle'], 1 );

		return is_wp_error( $check );
	}

	/**
	 * Block templates for block themes, editable in the Site Editor.
	 */
	public static function register_templates(): void {
		if ( ! function_exists( 'register_block_template' ) ) {
			return;
		}

		foreach ( self::templates() as $slug => $template ) {
			register_block_template(
				'redeemid//' . $slug,
				[
					'title'       => $template['title'],
					'description' => $template['description'],
					'content'     => self::template_content( $template['block'] ),
				]
			);
		}
	}

	/**
	 * @return array<string, array{title: string, description: string, block: string}>
	 */
	private static function templates(): array {
		return [
			'redeemid-product'    => [
				'title'       => __( 'Shopify product', 'redeemid' ),
				'description' => __( 'RedeemID’s product page, at the product path in RedeemID → Settings.', 'redeemid' ),
				'block'       => 'redeemid/product',
			],
			'redeemid-collection' => [
				'title'       => __( 'Shopify collection', 'redeemid' ),
				'description' => __( 'RedeemID’s collection page, at the collection path in RedeemID → Settings.', 'redeemid' ),
				'block'       => 'redeemid/collection',
			],
			'redeemid-cart'       => [
				'title'       => __( 'Shopify cart', 'redeemid' ),
				'description' => __( 'RedeemID’s cart page, at the cart path in RedeemID → Settings.', 'redeemid' ),
				'block'       => 'redeemid/cart',
			],
		];
	}

	private static function template_content( string $block ): string {
		return '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->'
			. '<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><main class="wp-block-group">'
			. '<!-- wp:' . $block . ' /-->'
			. '</main><!-- /wp:group -->'
			. '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
	}

	/**
	 * template_include: the storefront page's template.
	 */
	public static function template( mixed $template ): mixed {
		$request = self::request();

		if ( ! $request || is_404() ) {
			return $template;
		}

		if ( self::CART === $request['kind'] ) {
			Cache::private_page();
		}

		$slug = 'redeemid-' . $request['kind'];

		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() && function_exists( 'locate_block_template' ) ) {
			return locate_block_template( (string) $template, $slug, [ $slug . '.php' ] );
		}

		$theme = locate_template( [ $slug . '.php' ] );

		return '' !== $theme ? $theme : REDEEMID_DIR . 'templates/storefront.php';
	}

	/**
	 * The block a classic theme's storefront page renders (templates/storefront.php).
	 */
	public static function block_markup(): string {
		$request = self::request();

		return $request ? '<!-- wp:' . self::templates()[ 'redeemid-' . $request['kind'] ]['block'] . ' /-->' : '';
	}

	/**
	 * @param string[] $classes
	 * @return string[]
	 */
	public static function body_class( mixed $classes ): array {
		$classes = is_array( $classes ) ? $classes : [];
		$request = self::request();

		if ( $request ) {
			$classes   = array_values( array_diff( $classes, [ 'home', 'blog' ] ) );
			$classes[] = 'redeemid-storefront';
			$classes[] = 'redeemid-storefront-' . $request['kind'];
		}

		return $classes;
	}

	/**
	 * The cart is personal: keep it out of search engines.
	 */
	public static function robots( mixed $robots ): array {
		$robots  = is_array( $robots ) ? $robots : [];
		$request = self::request();

		if ( $request && self::CART === $request['kind'] ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}

		return $robots;
	}

	/**
	 * The handle a block should show: its own attribute, else the URL's.
	 */
	public static function handle_for( string $kind, string $attribute ): string {
		if ( '' !== $attribute ) {
			return $attribute;
		}

		$request = self::request();

		return $request && $kind === $request['kind'] ? $request['handle'] : '';
	}

	/**
	 * Whether the block is the main content of a storefront page (it then
	 * uses a page heading, h1).
	 */
	public static function is_page_block( string $kind, string $attribute ): bool {
		$request = self::request();

		return '' === $attribute && $request && $kind === $request['kind'];
	}
}
