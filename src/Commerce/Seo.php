<?php

namespace RedeemID\Commerce;

/**
 * Titles, descriptions, canonical links, social tags and Product structured
 * data for the storefront pages (Commerce\Pages). WordPress knows nothing
 * about these products, so neither do SEO plugins: with Yoast SEO or Rank
 * Math active, RedeemID answers their title, description and canonical
 * filters and leaves the tags to them; without one, it prints its own.
 * Product structured data is always RedeemID's, since no SEO plugin has it.
 * All of it is Shopify's public catalog data, never personal.
 */
final class Seo {
	public static function register(): void {
		add_filter( 'pre_get_document_title', [ self::class, 'document_title' ], 20 );
		add_action( 'wp_head', [ self::class, 'head' ], 1 );

		add_filter( 'wpseo_title', [ self::class, 'plugin_title' ] );
		add_filter( 'wpseo_opengraph_title', [ self::class, 'plugin_title' ] );
		add_filter( 'wpseo_metadesc', [ self::class, 'plugin_description' ] );
		add_filter( 'wpseo_opengraph_desc', [ self::class, 'plugin_description' ] );
		add_filter( 'wpseo_canonical', [ self::class, 'canonical_url' ] );
		add_filter( 'wpseo_opengraph_url', [ self::class, 'canonical_url' ] );
		add_filter( 'rank_math/frontend/title', [ self::class, 'plugin_title' ] );
		add_filter( 'rank_math/frontend/description', [ self::class, 'plugin_description' ], 20 );
		add_filter( 'rank_math/frontend/canonical', [ self::class, 'canonical_url' ] );
	}

	/**
	 * What the current storefront page says about itself; null on any other page.
	 *
	 * @return array{title: string, description: string, url: string, image: ?array, product: ?array}|null
	 */
	public static function page(): ?array {
		$request = Pages::request();

		if ( ! $request || is_404() ) {
			return null;
		}

		if ( Pages::CART === $request['kind'] ) {
			return [ 'title' => __( 'Cart', 'redeemid' ), 'description' => '', 'url' => Pages::url( Pages::CART ), 'image' => null, 'product' => null ];
		}

		$entity = $request['entity'];

		if ( ! $entity ) {
			return null;
		}

		$is_product = Pages::PRODUCT === $request['kind'];

		return [
			'title'       => (string) $entity['title'],
			'description' => self::excerpt( (string) $entity['description'] ),
			'url'         => Pages::url( $request['kind'], $request['handle'] ),
			'image'       => $is_product ? $entity['image'] : null,
			'product'     => $is_product ? $entity : null,
		];
	}

	/**
	 * A meta description from Shopify's plain-text description. Pure.
	 */
	public static function excerpt( string $text, int $length = 160 ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $length - 1 );
		$space = mb_strrpos( $cut, ' ' );

		return rtrim( false !== $space && $space > $length / 2 ? mb_substr( $cut, 0, $space ) : $cut, ' ,.;:' ) . '…';
	}

	/**
	 * Product structured data (schema.org Product with one Offer per
	 * variant), from Shopify's own prices. Pure.
	 */
	public static function product_schema( array $product, string $url ): array {
		$offers = [];

		foreach ( $product['variants'] ?? [] as $variant ) {
			if ( empty( $variant['price'] ) ) {
				continue;
			}

			$offers[] = [
				'@type'         => 'Offer',
				'name'          => $variant['title'],
				'price'         => $variant['price']['amount'],
				'priceCurrency' => $variant['price']['currency'],
				'availability'  => $variant['available'] ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
				'url'           => $url,
			];
		}

		return array_filter(
			[
				'@context'    => 'https://schema.org',
				'@type'       => 'Product',
				'name'        => $product['title'] ?? '',
				'description' => self::excerpt( (string) ( $product['description'] ?? '' ), 5000 ),
				'image'       => $product['image']['url'] ?? null,
				'url'         => $url,
				'offers'      => $offers,
			],
			static fn( $value ) => null !== $value && '' !== $value && [] !== $value
		);
	}

	public static function document_title( mixed $title ): mixed {
		$page = self::page();

		return $page ? $page['title'] . ' – ' . get_bloginfo( 'name', 'display' ) : $title;
	}

	public static function plugin_title( mixed $title ): mixed {
		$page = self::page();

		return $page ? $page['title'] . ' – ' . get_bloginfo( 'name', 'display' ) : $title;
	}

	public static function plugin_description( mixed $description ): mixed {
		$page = self::page();

		return $page && '' !== $page['description'] ? $page['description'] : $description;
	}

	public static function canonical_url( mixed $url ): mixed {
		$page = self::page();

		return $page ? $page['url'] : $url;
	}

	private static function seo_plugin(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' );
	}

	public static function head(): void {
		$page = self::page();

		if ( ! $page ) {
			return;
		}

		if ( ! self::seo_plugin() ) {
			if ( '' !== $page['description'] ) {
				printf( '<meta name="description" content="%s">' . "\n", esc_attr( $page['description'] ) );
			}

			// Core prints rel=canonical only on singular pages.
			printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $page['url'] ) );
			printf( '<meta property="og:type" content="%s">' . "\n", $page['product'] ? 'product' : 'website' );
			printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $page['title'] ) );
			printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $page['url'] ) );

			if ( '' !== $page['description'] ) {
				printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $page['description'] ) );
			}

			if ( $page['image'] ) {
				printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $page['image']['url'] ) );
			}

			printf( '<meta name="twitter:card" content="%s">' . "\n", $page['image'] ? 'summary_large_image' : 'summary' );
		}

		if ( $page['product'] ) {
			echo '<script type="application/ld+json">' . wp_json_encode( self::product_schema( $page['product'], $page['url'] ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . "</script>\n";
		}
	}
}
