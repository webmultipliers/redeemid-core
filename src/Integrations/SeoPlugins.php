<?php

namespace WP2_Shopify\Integrations;

use WP2_Shopify\Access\Gatekeeper;
use WP2_Shopify\Access\Index;
use WP2_Shopify\Access\Surfaces;
use WP2_Shopify\Blocks\Gate;

/**
 * Keeps SEO plugins from describing a gated item with its locked text.
 *
 * Both plugins build descriptions from raw post_content, outside the_content,
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
	public static function register(): void {
		add_filter( 'wpseo_replacements', [ self::class, 'yoast_replacements' ], 10, 2 );
		add_filter( 'rank_math/frontend/description', [ self::class, 'rank_math_description' ] );

		// Where a post type keeps locked items out of search engines (Surfaces), these plugins agree.
		add_filter( 'wpseo_robots_array', [ self::class, 'robots' ] );
		add_filter( 'rank_math/frontend/robots', [ self::class, 'robots' ] );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', [ self::class, 'yoast_sitemap_exclude' ] );
		add_filter( 'rank_math/sitemap/entry', [ self::class, 'rank_math_sitemap_entry' ], 10, 3 );
		add_action( 'wp2_shopify_access_locked_changed', [ self::class, 'clear_sitemap_caches' ] );
		add_action( 'wp2_shopify_access_changed', [ self::class, 'clear_sitemap_caches' ] );
	}

	/**
	 * Both plugins cache sitemaps, and neither knows an item locked or opened.
	 */
	public static function clear_sitemap_caches( mixed $post_id = null ): void {
		if ( ! Surfaces::noindex_types() ) {
			return;
		}

		if ( class_exists( 'WPSEO_Sitemaps_Cache' ) ) {
			is_int( $post_id ) ? \WPSEO_Sitemaps_Cache::invalidate_post( $post_id ) : \WPSEO_Sitemaps_Cache::clear();
		}

		if ( class_exists( 'RankMath\\Sitemap\\Cache' ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage();
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
	public static function yoast_sitemap_exclude( mixed $ids ): mixed {
		$types = Surfaces::noindex_types();

		if ( ! $types ) {
			return $ids;
		}

		$locked = get_posts(
			[
				'post_type'        => $types,
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => Index::LOCKED, // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);

		return array_values( array_unique( array_merge( is_array( $ids ) ? $ids : [], $locked ) ) );
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

	private static function gated( mixed $post ): bool {
		return $post instanceof \WP_Post && ! post_password_required( $post ) && ( ! Gatekeeper::rule( $post )->open() || has_block( Gate::PARENT, $post ) );
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
