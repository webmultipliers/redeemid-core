<?php

namespace WP2_Shopify\Integrations;

use WP2_Shopify\Access\Gatekeeper;

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
 * Items whose rule is not open always get teaser-only descriptions, whoever
 * is viewing: search engines see the locked view. Hand-written excerpts and
 * hand-written SEO descriptions are public by definition and kept.
 */
final class SeoPlugins {
	public static function register(): void {
		add_filter( 'wpseo_replacements', [ self::class, 'yoast_replacements' ], 10, 2 );
		add_filter( 'rank_math/frontend/description', [ self::class, 'rank_math_description' ] );
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
		return $post instanceof \WP_Post && ! post_password_required( $post ) && ! Gatekeeper::rule( $post )->open();
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
