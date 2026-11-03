<?php

namespace RedeemID\Integrations;

use RedeemID\Access\Gatekeeper;
use RedeemID\Access\Index;
use RedeemID\Access\Surfaces;

/**
 * Keeps SEO plugins from describing a gated item with its locked text.
 *
 * Yoast SEO, Rank Math and The SEO Framework. The first two build descriptions from raw post_content, outside the_content,
 * so the teaser path never sees them. Verified against the current releases
 * on 2026-10-24 (Yoast SEO and Rank Math from wordpress.org):
 *
 * - Yoast: the %%excerpt%% variable falls back to the start of raw
 *   post_content, and %%post_content%% is the whole of it. Both are replaced
 *   through the wpseo_replacements filter, which every Yoast template
 *   (meta description, Open Graph, Twitter, schema) goes through.
 * - Rank Math: with no description set, it uses the paragraph holding the
 *   focus keyword, else the first paragraph. Open Graph, Twitter and schema
 *   reuse that description, so rank_math/frontend/description covers them.
 *
 * - The SEO Framework (5.1+, checked against 5.2.0 on 2026-11-03): every
 *   generated description (meta, Open Graph, Twitter, schema) starts from
 *   Excerpt::get_excerpt(), filtered by the_seo_framework_get_excerpt. Its
 *   schema graph's WebPage entity also gets the paywall markup
 *   (isAccessibleForFree false, hasPart .redeemid-paywall), so the page has
 *   one graph instead of a second JSON-LD block beside it (Surfaces::schema
 *   stands down when the merge ran).
 *
 * Robots and sitemaps follow the post type's "keep locked items out of
 * search engines" setting, as core's do (Access\Surfaces).
 *
 * Items whose rule is not open always get teaser-only descriptions, whoever
 * is viewing: search engines see the locked view. So do open items with a
 * membership gate block in them: the gate protects its members area only
 * when it renders, and these plugins read the raw content. Hand-written
 * excerpts and hand-written SEO descriptions are public by definition and kept.
 */
final class SeoPlugins {
	/** The locked item IDs Yoast's sitemaps leave out, cached until an item locks or opens. */
	public const LOCKED_IDS = 'redeemid_sitemap_locked_ids';

	private const PAGE = 500;

	public static function register(): void {
		add_filter( 'wpseo_replacements', [ self::class, 'yoast_replacements' ], 10, 2 );
		add_filter( 'rank_math/frontend/description', [ self::class, 'rank_math_description' ] );

		// Where a post type keeps locked items out of search engines (Surfaces), these plugins agree.
		add_filter( 'wpseo_robots_array', [ self::class, 'robots' ] );
		add_filter( 'rank_math/frontend/robots', [ self::class, 'robots' ] );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', [ self::class, 'sitemap_exclude' ] );
		add_filter( 'rank_math/sitemap/entry', [ self::class, 'rank_math_sitemap_entry' ], 10, 3 );
		add_filter( 'the_seo_framework_get_excerpt', [ self::class, 'tsf_excerpt' ], 10, 2 );
		add_filter( 'the_seo_framework_robots_meta_array', [ self::class, 'tsf_robots' ], 10, 2 );
		add_filter( 'the_seo_framework_sitemap_exclude_ids', [ self::class, 'sitemap_exclude' ] );
		add_filter( 'the_seo_framework_schema_graph_data', [ self::class, 'tsf_graph' ], 10, 2 );
		add_action( 'redeemid_access_locked_changed', [ self::class, 'clear_sitemap_caches' ] );
		add_action( 'redeemid_access_changed', [ self::class, 'clear_sitemap_caches' ] );
	}

	/**
	 * Both plugins cache sitemaps, and neither knows an item locked or opened.
	 */
	public static function clear_sitemap_caches( mixed $post_id = null ): void {
		delete_transient( self::LOCKED_IDS );

		if ( ! Surfaces::noindex_types() ) {
			return;
		}

		if ( class_exists( 'WPSEO_Sitemaps_Cache' ) ) {
			is_int( $post_id ) ? \WPSEO_Sitemaps_Cache::invalidate_post( $post_id ) : \WPSEO_Sitemaps_Cache::clear();
		}

		if ( class_exists( 'RankMath\\Sitemap\\Cache' ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage();
		}

		if ( class_exists( 'The_SEO_Framework\\Sitemap\\Cache' ) && method_exists( 'The_SEO_Framework\\Sitemap\\Cache', 'clear_sitemap_caches' ) ) {
			\The_SEO_Framework\Sitemap\Cache::clear_sitemap_caches();
		}
	}

	/**
	 * @param array<string, string> $robots 'index' => 'index'|'noindex', and so on.
	 */
	public static function robots( mixed $robots ): mixed {
		if ( is_array( $robots ) && is_singular() && Surfaces::noindex( get_queried_object() ) ) {
			$robots['index'] = 'noindex';
		}

		return $robots;
	}

	/**
	 * @param int[] $ids
	 */
	public static function sitemap_exclude( mixed $ids ): mixed {
		$types = Surfaces::noindex_types();

		if ( ! $types ) {
			return $ids;
		}

		$locked = get_transient( self::LOCKED_IDS );

		if ( ! is_array( $locked ) ) {
			$locked = self::locked_ids( $types );
			set_transient( self::LOCKED_IDS, $locked, 12 * HOUR_IN_SECONDS );
		}

		return array_values( array_unique( array_merge( is_array( $ids ) ? $ids : [], $locked ) ) );
	}

	/**
	 * Every published locked item of these types, read 500 IDs at a time so a
	 * large site never loads them in one query.
	 *
	 * @param string[] $types
	 * @return int[]
	 */
	public static function locked_ids( array $types ): array {
		$ids  = [];
		$page = 1;

		do {
			$batch = get_posts(
				[
					'post_type'        => $types,
					'post_status'      => 'publish',
					'fields'           => 'ids',
					'posts_per_page'   => self::PAGE,
					'paged'            => $page,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'no_found_rows'    => true,
					'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- RedeemID's own query filters (Surfaces) must not hide the locked items this lists.
					'meta_key'         => Index::LOCKED, // phpcs:ignore WordPress.DB.SlowDBQuery
				]
			);

			$ids = array_merge( $ids, array_map( 'intval', $batch ) );
			++$page;
		} while ( count( $batch ) === self::PAGE );

		return $ids;
	}

	/**
	 * Rank Math passes a database row here, not always a WP_Post.
	 */
	public static function rank_math_sitemap_entry( mixed $url, mixed $type, mixed $object ): mixed {
		$post = 'post' === $type && is_object( $object ) && isset( $object->ID ) ? get_post( (int) $object->ID ) : null;

		return $post && in_array( $post->post_type, Surfaces::noindex_types(), true ) && '' !== get_post_meta( $post->ID, Index::LOCKED, true ) ? false : $url;
	}

	/**
	 * @param array<string, string> $replacements '%%var%%' => value.
	 */
	public static function yoast_replacements( mixed $replacements, mixed $args ): mixed {
		$post = is_object( $args ) && ! empty( $args->ID ) ? get_post( (int) $args->ID ) : null;

		if ( ! is_array( $replacements ) || ! self::gated( $post ) ) {
			return $replacements;
		}

		if ( isset( $replacements['%%excerpt%%'] ) && '' === trim( (string) $post->post_excerpt ) ) {
			$replacements['%%excerpt%%'] = self::summary( $post, 156 );
		}

		if ( isset( $replacements['%%post_content%%'] ) ) {
			$replacements['%%post_content%%'] = Gatekeeper::public_text( $post );
		}

		return $replacements;
	}

	public static function rank_math_description( mixed $description ): mixed {
		$post = is_singular() ? get_queried_object() : null;

		if ( ! is_string( $description ) || ! self::gated( $post ) ) {
			return $description;
		}

		// A description an editor wrote, with no variables to expand, is public text.
		$custom = (string) get_post_meta( $post->ID, 'rank_math_description', true );

		if ( '' !== trim( $custom ) && ! str_contains( $custom, '%' ) ) {
			return $description;
		}

		$excerpt = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );

		return '' !== $excerpt ? $excerpt : self::summary( $post, 160 );
	}

	/** Whether the paywall markup went into an SEO plugin's graph on this request. */
	private static bool $merged = false;

	public static function schema_merged(): bool {
		return self::$merged;
	}

	/**
	 * The post a TSF call is about: its args name one ('id', with no 'tax',
	 * 'pta' or 'uid'), or, when null, the current singular query.
	 *
	 * @param ?array<string, mixed> $args
	 */
	private static function tsf_post( mixed $args ): ?\WP_Post {
		if ( null === $args ) {
			$post = is_singular() ? get_queried_object() : null;
		} elseif ( is_array( $args ) && ! empty( $args['id'] ) && empty( $args['tax'] ) && empty( $args['pta'] ) && empty( $args['uid'] ) ) {
			$post = get_post( (int) $args['id'] );
		} else {
			$post = null;
		}

		return $post instanceof \WP_Post ? $post : null;
	}

	public static function tsf_excerpt( mixed $excerpt, mixed $args = null ): mixed {
		$post = self::tsf_post( $args );

		if ( ! is_string( $excerpt ) || ! self::gated( $post ) ) {
			return $excerpt;
		}

		$own = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );

		return '' !== $own ? $own : Gatekeeper::public_text( $post );
	}

	/**
	 * @param array<string, string> $meta 'noindex' => 'noindex', and so on.
	 */
	public static function tsf_robots( mixed $meta, mixed $args = null ): mixed {
		$post = self::tsf_post( $args );

		if ( is_array( $meta ) && $post && Surfaces::noindex( $post ) ) {
			$meta['noindex'] = 'noindex';
		}

		return $meta;
	}

	/**
	 * @param array<int, array<string, mixed>> $graph
	 */
	public static function tsf_graph( mixed $graph, mixed $args = null ): mixed {
		$post = self::tsf_post( $args );

		if ( ! is_array( $graph ) || ! $post || Gatekeeper::rule( $post )->open() || post_password_required( $post ) ) {
			return $graph;
		}

		$marked = self::mark_paywall( $graph );

		if ( $marked !== $graph ) {
			self::$merged = true;
		}

		return $marked;
	}

	/**
	 * Add the paywall markup to the graph's page entity (WebPage, or a type
	 * that ends in Page, such as ItemPage). Pure.
	 *
	 * @param array<int, mixed> $graph
	 * @return array<int, mixed>
	 */
	public static function mark_paywall( array $graph ): array {
		foreach ( $graph as $i => $entity ) {
			$types = is_array( $entity ) ? (array) ( $entity['@type'] ?? [] ) : [];

			foreach ( $types as $type ) {
				if ( is_string( $type ) && str_ends_with( $type, 'Page' ) ) {
					$graph[ $i ]['isAccessibleForFree'] = false;
					$graph[ $i ]['hasPart']             = [
						'@type'               => 'WebPageElement',
						'isAccessibleForFree' => false,
						'cssSelector'         => '.redeemid-paywall',
					];

					return $graph;
				}
			}
		}

		return $graph;
	}

	private static function gated( mixed $post ): bool {
		return $post instanceof \WP_Post && ! post_password_required( $post ) && Gatekeeper::protected( $post );
	}

	/**
	 * The teaser's text cut to a word boundary within $limit characters.
	 */
	private static function summary( \WP_Post $post, int $limit ): string {
		$text = Gatekeeper::public_text( $post );

		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $limit );
		$space = mb_strrpos( $cut, ' ' );

		return rtrim( false === $space ? $cut : mb_substr( $cut, 0, $space ), " ,.;:" ) . '…';
	}
}
