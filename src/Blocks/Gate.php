<?php

namespace WP2_Shopify\Blocks;

use WP2_Shopify\Access\Checker;
use WP2_Shopify\Access\Gatekeeper;
use WP2_Shopify\Access\Level;
use WP2_Shopify\Access\LevelStore;
use WP2_Shopify\Access\Viewer;
use WP2_Shopify\Auth\Flow;
use WP2_Shopify\Entitlement\Decision;
use WP2_Shopify\Entitlement\Rule;
use WP2_Shopify\Settings;

/**
 * Server-side gating for wp2-shopify/membership-gate.
 *
 * The decision is applied to the parsed block tree before anything renders:
 * render_block_data removes the Members only child for visitors who are not
 * entitled, so its inner blocks are never rendered on any surface that goes
 * through the block renderer (pages, REST content.rendered, feeds, oEmbed).
 * pre_render_block is a second lock on the child itself.
 */
final class Gate {
	public const PARENT  = 'wp2-shopify/membership-gate';
	public const MEMBERS = 'wp2-shopify/membership-gate-content';
	public const TEASER  = 'wp2-shopify/membership-gate-fallback';

	public const RULE_ITEM   = 'item';
	public const RULE_LEVEL  = 'level';
	public const RULE_CUSTOM = 'custom';

	/** The Blockstudio component that renders the locked-visitor panel (blocks/locked-panel). */
	public const PANEL = 'wp2-shopify/locked-panel';

	public static function register(): void {
		add_filter( 'render_block_data', [ self::class, 'filter_children' ], 10, 1 );
		add_filter( 'pre_render_block', [ self::class, 'guard_members_area' ], 10, 3 );
		add_action( 'template_redirect', [ self::class, 'maybe_redirect' ], 20 );
	}

	public static function values( array $attrs ): array {
		$attrs  = Upgrade::block( [ 'blockName' => self::PARENT, 'attrs' => $attrs ] )['attrs'];
		$values = function_exists( 'bs_block_values' ) ? bs_block_values( self::PARENT, $attrs ) : null;

		// Fail closed if Blockstudio cannot resolve the attributes: an empty rule still requires sign-in.
		return is_array( $values ) ? $values : ( $attrs['blockstudio']['attributes'] ?? $attrs );
	}

	/**
	 * The gate's decision for the current visitor. A gate set to "This item's
	 * rule" uses the item's verdict, a level gate asks whether the visitor
	 * holds that access level, and a custom-tags gate uses its own tags. Every
	 * kind only narrows: inside an item the visitor is locked out of, nothing a
	 * gate holds is released.
	 */
	public static function decide( array $a ): Decision {
		$post = get_post();

		// An editor reading an item on its own page sees every gate in it open (Viewer).
		if ( $post instanceof \WP_Post && Viewer::bypassing( $post ) ) {
			return Decision::grant();
		}

		$item = $post instanceof \WP_Post ? Gatekeeper::verdict( $post ) : null;

		$source = self::option( $a['ruleSource'] ?? '', self::RULE_CUSTOM );

		if ( self::RULE_ITEM === $source ) {
			// No item to inherit from (a template, a widget): fail closed to signed-in members only.
			return $item ? $item->decision : Settings::evaluator()->decide( new Rule(), Viewer::member(), time() );
		}

		if ( $item && ! $item->granted() && ! Gatekeeper::rule( $post )->open() ) {
			return $item->decision;
		}

		if ( self::RULE_LEVEL === $source ) {
			// An unknown or unchosen level is a BAD_RULE denial: fail closed.
			return ( new Checker( LevelStore::levels(), Settings::evaluator() ) )->holds( self::option( $a['level'] ?? '', '' ), Viewer::member(), time() );
		}

		return Settings::evaluator()->decide( Rule::from_attributes( $a ), Viewer::member(), time() );
	}

	/**
	 * A select value as a string. Blockstudio stores selects as {value, label};
	 * content written by hand or by older versions may hold a bare string.
	 */
	public static function option( mixed $value, string $fallback ): string {
		return is_array( $value ) ? (string) ( $value['value'] ?? $fallback ) : ( $value ? (string) $value : $fallback );
	}

	/**
	 * A Blockstudio link field value ({url, title, ...}) as a URL. Plain strings,
	 * from content saved before these fields became link fields, still work.
	 */
	public static function url( mixed $value ): string {
		return trim( (string) ( is_array( $value ) ? ( $value['url'] ?? '' ) : ( is_string( $value ) ? $value : '' ) ) );
	}

	/**
	 * The URL of the page being rendered, for "come back here" redirects.
	 * Built from the request rather than home_url( add_query_arg( [] ) ), which
	 * doubles the path on sites installed in a subdirectory.
	 */
	public static function current_url(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

		return wp_validate_redirect( set_url_scheme( 'http://' . $host . $uri ), home_url( '/' ) );
	}

	/**
	 * Options for the gate's Level picker, filled by Blockstudio's populate
	 * (blocks/membership-gate/block.json). Built-in Public is left out: a gate
	 * that lets everyone in is not a gate.
	 *
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function level_options(): array {
		$options = [];

		foreach ( LevelStore::levels()->all() as $slug => $level ) {
			if ( Level::PUBLIC !== $slug ) {
				$options[] = [ 'value' => (string) $slug, 'label' => $level->label ];
			}
		}

		return $options;
	}

	public static function filter_children( array $parsed ): array {
		if ( self::PARENT !== ( $parsed['blockName'] ?? '' ) || empty( $parsed['innerBlocks'] ) ) {
			return $parsed;
		}

		$a        = self::values( (array) ( $parsed['attrs'] ?? [] ) );
		$decision = self::decide( $a );
		$teaser   = 'teaser-blocks' === self::option( $a['fallbackMode'] ?? '', 'message' );

		if ( $decision->granted && ! defined( 'DONOTCACHEPAGE' ) ) {
			// Member-only output must never land in a shared page cache.
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		}

		$keep_blocks  = [];
		$keep_content = [];
		$index        = 0;

		foreach ( (array) $parsed['innerContent'] as $chunk ) {
			if ( null !== $chunk ) {
				$keep_content[] = $chunk;
				continue;
			}

			$child = $parsed['innerBlocks'][ $index++ ] ?? null;
			$name  = $child['blockName'] ?? '';

			$keep = match ( $name ) {
				self::MEMBERS => $decision->granted,
				self::TEASER  => ! $decision->granted && $teaser,
				default       => false, // Locked template: anything else is unexpected, drop it.
			};

			if ( $keep ) {
				$keep_blocks[]  = $child;
				$keep_content[] = null;
			}
		}

		$parsed['innerBlocks']  = $keep_blocks;
		$parsed['innerContent'] = $keep_content;

		return $parsed;
	}

	/**
	 * A Members only area renders only inside a gate that granted access.
	 */
	public static function guard_members_area( $pre, array $parsed, $parent ) {
		if ( self::MEMBERS !== ( $parsed['blockName'] ?? '' ) ) {
			return $pre;
		}

		if ( ! $parent instanceof \WP_Block || self::PARENT !== $parent->name ) {
			return '';
		}

		return self::decide( self::values( (array) ( $parent->parsed_block['attrs'] ?? [] ) ) )->granted ? $pre : '';
	}

	/**
	 * Redirect mode, applied before output when the gate is in the queried post.
	 */
	public static function maybe_redirect(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post || ! has_block( self::PARENT, $post ) ) {
			return;
		}

		foreach ( self::find( parse_blocks( $post->post_content ) ) as $block ) {
			$a = self::values( (array) $block['attrs'] );

			if ( 'redirect' !== self::option( $a['fallbackMode'] ?? '', 'message' ) || self::decide( $a )->granted ) {
				continue;
			}

			$target = wp_validate_redirect( self::url( $a['redirectUrl'] ?? '' ), '' );

			if ( '' !== $target && untrailingslashit( $target ) !== untrailingslashit( (string) get_permalink( $post ) ) ) {
				nocache_headers();
				wp_safe_redirect( $target );
				exit;
			}
		}
	}

	/**
	 * The locked-visitor panel, rendered by the wp2-shopify/locked-panel
	 * Blockstudio component so themes can restyle or override it.
	 */
	public static function fallback_panel( array $a, Decision $decision ): string {
		$guest = Decision::GUEST === $decision->reason && false !== ( $a['showSignIn'] ?? true );
		$data  = [
			'message'   => (string) ( ( $a['fallbackMessage'] ?? '' ) ?: __( 'This content is for members.', 'wp2-shopify' ) ),
			'reason'    => match ( $decision->reason ) {
				Decision::DISABLED  => __( 'Your account is disabled. Contact the store for help.', 'wp2-shopify' ),
				Decision::NO_MIRROR => __( 'Your membership details are syncing. Try again shortly.', 'wp2-shopify' ),
				default             => '',
			},
			'ctaLabel'  => (string) ( $a['fallbackCtaLabel'] ?? '' ),
			'ctaUrl'    => self::url( $a['fallbackCtaUrl'] ?? '' ),
			'signInUrl' => $guest ? Flow::login_url( (string) get_permalink() ) : '',
		];

		return function_exists( 'bs_block' ) ? (string) bs_block( [ 'name' => self::PANEL, 'data' => $data ] ) : '';
	}

	/**
	 * @return array<int, array> Every gate block in a parsed tree.
	 */
	private static function find( array $blocks ): array {
		$found = [];

		foreach ( $blocks as $block ) {
			if ( self::PARENT === ( $block['blockName'] ?? '' ) ) {
				$found[] = $block;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = array_merge( $found, self::find( $block['innerBlocks'] ) );
			}
		}

		return $found;
	}
}
