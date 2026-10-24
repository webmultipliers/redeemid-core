<?php

namespace WP2_Shopify\Access;

/**
 * The minimal leak hardening that ships before any rule can gate many items
 * at once:
 *
 * - Search (interim): core search runs LIKE on raw post_content, so a locked
 *   phrase would find its post. Until a public-text index exists, items the
 *   visitor is locked out of are removed from every search query's results:
 *   the site search page, REST ?search= and /wp/v2/search.
 * - Schema: locked items say isAccessibleForFree false, pointing at the
 *   paywall container, so search engines see an honest paywall. Crawlers get
 *   the same locked view as any visitor.
 * - Headers: X-WP2-Access on singular items lets a CDN vary or bypass
 *   without parsing HTML; entitled views are Cache-Control: private.
 */
final class Surfaces {
	public static function register(): void {
		add_filter( 'the_posts', [ self::class, 'filter_search_results' ], 10, 2 );
		add_action( 'template_redirect', [ self::class, 'send_headers' ], 1 );
		add_action( 'wp_head', [ self::class, 'schema' ], 30 );
		add_action( 'wp2_shopify_access_levels_changed', static fn() => do_action( 'wp2_shopify_access_changed', [] ) );
	}

	/**
	 * @param \WP_Post[]|int[] $posts
	 */
	public static function filter_search_results( mixed $posts, mixed $query ): mixed {
		// wp-admin list screens are for editors managing content, not a public surface.
		if ( ! is_array( $posts ) || ! $query instanceof \WP_Query || '' === (string) $query->get( 's' ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $posts;
		}

		$kept = [];

		foreach ( $posts as $item ) {
			$post = get_post( $item );

			if ( $post instanceof \WP_Post && Gatekeeper::locked( $post ) ) {
				continue;
			}

			$kept[] = $item;
		}

		$removed = count( $posts ) - count( $kept );

		if ( $removed > 0 ) {
			// Counts must not confirm the hidden matches either.
			$query->found_posts   = max( 0, (int) $query->found_posts - $removed );
			$query->max_num_pages = $query->get( 'posts_per_page' ) > 0 ? (int) ceil( $query->found_posts / (int) $query->get( 'posts_per_page' ) ) : 1;
		}

		return $kept;
	}

	/**
	 * open: no rule applies. locked: the visitor sees the teaser. granted: the
	 * visitor is entitled, so the response must not be shared.
	 */
	public static function state( \WP_Post $post ): string {
		if ( Gatekeeper::rule( $post )->open() ) {
			return 'open';
		}

		return Gatekeeper::verdict( $post )->granted() ? 'granted' : 'locked';
	}

	public static function send_headers(): void {
		if ( ! is_singular() || headers_sent() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return;
		}

		$state = self::state( $post );
		header( 'X-WP2-Access: ' . $state );

		if ( 'granted' === $state ) {
			header( 'Cache-Control: private, no-store, max-age=0' );
		}
	}

	/**
	 * Google's paywalled-content markup on locked items. Return false from the
	 * wp2_shopify_paywall_schema filter when an SEO plugin already adds it.
	 */
	public static function schema(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post || Gatekeeper::rule( $post )->open() ) {
			return;
		}

		$data = [
			'@context'            => 'https://schema.org',
			'@type'               => 'post' === $post->post_type ? 'Article' : 'CreativeWork',
			'headline'            => wp_strip_all_tags( get_the_title( $post ) ),
			'url'                 => get_permalink( $post ),
			'isAccessibleForFree' => false,
			'hasPart'             => [
				'@type'               => 'WebPageElement',
				'isAccessibleForFree' => false,
				'cssSelector'         => '.wp2-shopify-paywall',
			],
		];

		/**
		 * Filter the paywall JSON-LD for a locked item. Return false to print none.
		 */
		$data = apply_filters( 'wp2_shopify_paywall_schema', $data, $post );

		if ( is_array( $data ) ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . "</script>\n";
		}
	}
}
