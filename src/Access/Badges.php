<?php

namespace WP2_Shopify\Access;

/**
 * What a theme can show about an item's access, for the item itself and for
 * every card in a query loop (each card is decided for its own post):
 *
 * - post classes: wp2-gated, requires-{level} for each level the rule names,
 *   and is-locked or is-unlocked for the current visitor;
 * - the wp2-shopify/access block binding source, so any paragraph, heading
 *   or button can show the level label, the visitor's state or the level's
 *   call to action;
 * - the Access Badge block (blocks/access-badge), built on the same data.
 */
final class Badges {
	public const SOURCE = 'wp2-shopify/access';

	/** Binding keys, with what each returns. */
	public const KEYS = [ 'level', 'levels', 'state', 'message', 'cta_label', 'cta_url' ];

	public static function register(): void {
		add_filter( 'post_class', [ self::class, 'post_class' ], 10, 3 );
		add_action( 'init', [ self::class, 'register_source' ] );
	}

	/**
	 * Classes for an item's rule. Pure.
	 *
	 * @return string[]
	 */
	public static function classes( EffectiveRule $rule, bool $locked ): array {
		if ( $rule->open() ) {
			return [];
		}

		$classes = [ 'wp2-gated', $locked ? 'is-locked' : 'is-unlocked' ];

		foreach ( $rule->levels() as $slug ) {
			$classes[] = 'requires-' . $slug;
		}

		return $classes;
	}

	public static function post_class( mixed $classes, mixed $extra = [], mixed $post_id = 0 ): mixed {
		$post = get_post( (int) $post_id );

		if ( ! is_array( $classes ) || ! $post instanceof \WP_Post || ! ItemRule::gated( $post ) ) {
			return $classes;
		}

		return array_merge( $classes, self::classes( Gatekeeper::rule( $post ), Gatekeeper::locked( $post ) ) );
	}

	/**
	 * The level a badge names: the one the wall would offer this visitor, or
	 * for a reader the first level the rule asks for. Null for an open item.
	 */
	public static function level( \WP_Post $post ): ?Level {
		$rule = Gatekeeper::rule( $post );

		if ( $rule->open() ) {
			return null;
		}

		$levels = LevelStore::levels();
		$slug   = Gatekeeper::locked( $post ) ? Gatekeeper::verdict( $post )->next_level : null;

		foreach ( $slug ? [ $slug ] : $rule->levels() as $candidate ) {
			if ( $levels->has( $candidate ) ) {
				return $levels->get( $candidate );
			}
		}

		return null;
	}

	/**
	 * One binding value for an item. Every value is public: labels, the
	 * visitor's own state and the level's wall copy, never item content.
	 */
	public static function value( \WP_Post $post, string $key ): string {
		if ( ! ItemRule::gated( $post ) ) {
			return '';
		}

		$rule   = Gatekeeper::rule( $post );
		$level  = self::level( $post );
		$locked = Gatekeeper::locked( $post );

		return match ( $key ) {
			'level'     => $level?->label ?? '',
			'levels'    => $rule->open() ? '' : self::need( $rule ),
			'state'     => $rule->open() ? __( 'Free to read', 'wp2-shopify' ) : ( $locked ? __( 'Locked', 'wp2-shopify' ) : __( 'Included with your membership', 'wp2-shopify' ) ),
			'message'   => $locked ? ( $level?->message ?? '' ) : '',
			'cta_label' => $locked ? ( $level?->cta_label ?? '' ) : '',
			'cta_url'   => $locked ? ( $level?->cta_url ?? '' ) : '',
			default     => '',
		};
	}

	/**
	 * "Gold" or "Silver and Gold" or "Silver or Gold".
	 */
	private static function need( EffectiveRule $rule ): string {
		$levels = LevelStore::levels();
		$label  = static fn( string $slug ) => $levels->get( $slug )?->label ?? $slug;

		return implode(
			' ' . __( 'and', 'wp2-shopify' ) . ' ',
			array_map( static fn( array $c ) => implode( ' ' . __( 'or', 'wp2-shopify' ) . ' ', array_map( $label, $c ) ), $rule->clauses )
		);
	}

	public static function register_source(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		register_block_bindings_source(
			self::SOURCE,
			[
				'label'              => __( 'Access', 'wp2-shopify' ),
				'uses_context'       => [ 'postId', 'postType' ],
				'get_value_callback' => [ self::class, 'binding' ],
			]
		);
	}

	public static function binding( mixed $args, mixed $block = null ): ?string {
		$key     = is_array( $args ) ? (string) ( $args['key'] ?? 'level' ) : 'level';
		$post_id = $block instanceof \WP_Block ? (int) ( $block->context['postId'] ?? 0 ) : 0;
		$post    = get_post( $post_id ?: null );

		if ( ! $post instanceof \WP_Post || ! in_array( $key, self::KEYS, true ) ) {
			return null;
		}

		$value = self::value( $post, $key );

		// Core escapes on the way out: wp_kses_post for rich text, the HTML API for attributes.
		return 'cta_url' === $key ? esc_url_raw( $value ) : $value;
	}
}
