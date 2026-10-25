<?php

namespace WP2_Shopify\Access;

use WP2_Shopify\Blocks\Gate;
use WP2_Shopify\Entitlement\Decision;
use WP2_Shopify\Settings;

/**
 * The one teaser path. Every surface that renders an item's content goes
 * through the_content: the page body, the Post Content block in a query
 * loop, REST content.rendered, feeds, oEmbed and auto excerpts. At priority
 * 8, before do_blocks, this cuts a locked item's parsed blocks down to its
 * teaser, so the locked part never renders anywhere.
 *
 * Which rule applies comes from the Resolver, fed by the item's layers
 * (Layers: item, terms, post type, site default) and the
 * wp2_shopify_access_context filter.
 */
final class Gatekeeper {
	/** @var array<string, Verdict> */
	private static array $verdicts = [];

	/** @var array<int, EffectiveRule> */
	private static array $rules = [];

	public static function register(): void {
		add_filter( 'the_content', [ self::class, 'filter_content' ], 8 );
		// Auto excerpts: core renders blocks to HTML before the_content runs, so the cut happens first, here.
		add_filter( 'get_the_excerpt', [ self::class, 'filter_excerpt' ], 9, 2 );
		add_action( 'wp2_shopify_access_levels_changed', [ self::class, 'flush' ] );
	}

	public static function flush(): void {
		self::$verdicts = [];
		self::$rules    = [];
	}

	public static function context( \WP_Post $post ): Context {
		/**
		 * Filter the rule layers for an item. Runs on every request, after the
		 * cache, so a filter here never needs a cache flush.
		 */
		$context = apply_filters( 'wp2_shopify_access_context', Layers::context( $post ), $post );

		// A broken filter must lock, not leak: an unknown level fails closed in the Checker.
		return $context instanceof Context ? $context : new Context( item_mode: Context::ITEM_LEVEL, item_levels: [ '__invalid_context' ] );
	}

	public static function rule( \WP_Post $post ): EffectiveRule {
		if ( isset( self::$rules[ $post->ID ] ) ) {
			return self::$rules[ $post->ID ];
		}

		$rule = ( new Resolver( LevelStore::levels() ) )->resolve( self::context( $post ), time() );

		/**
		 * Filter the resolved rule for an item.
		 */
		$filtered = apply_filters( 'wp2_shopify_effective_rule', $rule, $post );
		$rule     = $filtered instanceof EffectiveRule ? $filtered : new EffectiveRule( [ [ '__invalid_rule' ] ], 'Filter' );

		return self::$rules[ $post->ID ] = $rule;
	}

	public static function verdict( \WP_Post $post ): Verdict {
		$key = $post->ID . ':' . Viewer::key();

		if ( ! isset( self::$verdicts[ $key ] ) ) {
			$checker                = new Checker( LevelStore::levels(), Settings::evaluator() );
			self::$verdicts[ $key ] = $checker->check( self::rule( $post ), Viewer::member(), time() );
		}

		return self::$verdicts[ $key ];
	}

	public static function teaser( \WP_Post $post ): Teaser {
		/**
		 * Filter the teaser setting for a post type: mode (blocks, words,
		 * excerpt, none) and count. A paywall break in the content always wins.
		 */
		$setting = apply_filters( 'wp2_shopify_teaser_settings', RuleStore::rules()->type( $post->post_type )['teaser'], $post->post_type, $post );

		return Teaser::from_array( is_array( $setting ) ? $setting : [] );
	}

	public static function filter_content( mixed $content ): mixed {
		$post = get_post();

		if ( ! is_string( $content ) || ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return $content;
		}

		$rule = self::rule( $post );

		if ( $rule->open() ) {
			return $content;
		}

		$verdict = self::verdict( $post );

		if ( $verdict->granted() || Viewer::bypassing( $post ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				// Entitled output must never land in a shared page cache.
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
			}

			return $content;
		}

		$cut = self::teaser( $post )->cut( parse_blocks( $content ), (string) $post->post_excerpt );
		$out = serialize_blocks( $cut['blocks'] );

		// An auto excerpt that reaches here is already rendered HTML, cut to nothing above; the wall belongs on full renders.
		if ( ! doing_filter( 'get_the_excerpt' ) ) {
			$out .= "\n\n<!-- wp:html -->\n" . self::wall( $post, $verdict ) . "\n<!-- /wp:html -->";
		}

		return $out;
	}

	public static function wall( \WP_Post $post, Verdict $verdict ): string {
		$level = $verdict->next_level ? LevelStore::levels()->get( $verdict->next_level ) : null;
		$label = $level && ! $level->builtin() ? $level->label : '';
		$a     = [
			'fallbackMessage'  => $level && '' !== $level->message
				? $level->message
				/* translators: %s: access level label, such as Gold */
				: ( $label ? sprintf( __( 'This content is for %s members.', 'wp2-shopify' ), $label ) : __( 'This content is for members.', 'wp2-shopify' ) ),
			'fallbackCtaLabel' => $level->cta_label ?? '',
			'fallbackCtaUrl'   => $level->cta_url ?? '',
			'showSignIn'       => true,
		];

		return '<div class="wp2-shopify-paywall" data-wp2-level="' . esc_attr( (string) $verdict->next_level ) . '">'
			. Gate::fallback_panel( $a, $verdict->decision, (string) $verdict->next_level )
			. '</div>';
	}

	/**
	 * Build a locked item's auto excerpt from its teaser, before core's
	 * wp_trim_excerpt (priority 10) would build one from the whole content.
	 * A hand-written excerpt is left alone: it is public by definition.
	 */
	public static function filter_excerpt( mixed $text, mixed $post = null ): mixed {
		$post = get_post( $post );

		if ( '' !== $text || ! $post instanceof \WP_Post || post_password_required( $post ) || ! self::locked( $post ) ) {
			return $text;
		}

		$text = self::public_text( $post );

		if ( '' === $text ) {
			// Nothing public to show. A single space stops core building one from the full content.
			return ' ';
		}

		/** This filter is documented in wp-includes/formatting.php */
		$length = (int) apply_filters( 'excerpt_length', 55 );
		/** This filter is documented in wp-includes/formatting.php */
		$more = apply_filters( 'excerpt_more', ' [&hellip;]' );

		return wp_trim_words( $text, $length, $more );
	}

	/**
	 * The plain text of an item's teaser: what any visitor may read. SEO
	 * descriptions of gated items are built from this, never from raw content.
	 */
	public static function public_text( \WP_Post $post ): string {
		$cut  = self::teaser( $post )->cut( Teaser::strip( parse_blocks( $post->post_content ), [ BlockAccess::class, 'members_only' ] ) );
		$html = excerpt_remove_blocks( serialize_blocks( $cut['blocks'] ) );

		return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $html ) ) ) );
	}

	/**
	 * Could some visitor be shown less of this item than its full text? True
	 * when its rule is not open, or it holds a membership gate or a block
	 * with an Access setting of its own. Search, SEO descriptions and the
	 * public-text index treat such items as protected.
	 */
	public static function protected( \WP_Post $post ): bool {
		return ! self::rule( $post )->open() || has_block( Gate::PARENT, $post ) || BlockAccess::in_content( $post->post_content );
	}

	/**
	 * For tests and for code that needs it: is this item locked for this visitor?
	 */
	public static function locked( \WP_Post $post ): bool {
		return ! self::rule( $post )->open() && ! self::verdict( $post )->granted();
	}

	/**
	 * Reason codes a theme can use for its own copy.
	 */
	public static function reason( \WP_Post $post ): string {
		return self::locked( $post ) ? self::verdict( $post )->decision->reason : Decision::GRANTED;
	}
}
