<?php

namespace WP2_Shopify\Access;

use WP2_Shopify\Blocks\Gate;
use WP2_Shopify\Settings;

/**
 * Per-block visibility: an Access setting on every block, decided on the
 * server in pre_render_block, so a hidden block never renders and is never
 * sent.
 *
 * A block can only narrow what the item allows, never widen it:
 *
 * - everyone (default): no condition of its own.
 * - readers: visitors who can read this item in full.
 * - levels: readers who also hold one of these levels (a Gold box inside a
 *   Members story).
 * - locked: visitors this item locks out, for upgrade prompts. Such a block
 *   must sit before the paywall break, or the cut removes it first; the
 *   editor warns when it does not.
 * - without: visitors who hold none of these levels ("Upgrade to Gold" for
 *   everyone below Gold).
 *
 * The last two are shown to people who can't read the item, so they count
 * as public text. The first two never do: search, SEO descriptions and auto
 * excerpts are built with them taken out (members_only()).
 *
 * In a query loop each block is decided for the loop's post, not the page.
 * Outside any item (a template part, a widget), "readers" means signed-in
 * visitors. An editor bypassing on an item's own page sees it as a reader
 * holding every level.
 */
final class BlockAccess {
	public const ATTR = 'wp2Access';

	public const EVERYONE = 'everyone';
	public const READERS  = 'readers';
	public const LEVELS   = 'levels';
	public const LOCKED   = 'locked';
	public const WITHOUT  = 'without';

	public const SHOWS = [ self::EVERYONE, self::READERS, self::LEVELS, self::LOCKED, self::WITHOUT ];

	/** Blocks whose visibility is already the point of the block. */
	public const SKIP = [ Teaser::BREAK_BLOCK, Gate::MEMBERS, Gate::TEASER ];

	public static function register(): void {
		add_filter( 'register_block_type_args', [ self::class, 'add_attribute' ], 10, 2 );
		add_action( 'init', [ self::class, 'backfill' ], 999 );
		// Late: core's _wp_add_block_level_preset_styles (priority 10) returns null whatever it is given.
		add_filter( 'pre_render_block', [ self::class, 'pre_render' ], 100, 2 );
		add_action( 'enqueue_block_editor_assets', [ self::class, 'editor_assets' ] );
		add_action( 'enqueue_block_assets', [ self::class, 'canvas_styles' ] );
	}

	/**
	 * Declare the attribute on every block type, so the server-side renderer
	 * and REST validation accept it.
	 */
	public static function add_attribute( mixed $args, mixed $name ): mixed {
		if ( is_array( $args ) && ! in_array( $name, self::SKIP, true ) ) {
			$args['attributes']                     = (array) ( $args['attributes'] ?? [] );
			$args['attributes'][ self::ATTR ] ??= [ 'type' => 'object' ];
		}

		return $args;
	}

	/**
	 * Block types registered before this plugin's filter was added.
	 */
	public static function backfill(): void {
		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			if ( ! in_array( $name, self::SKIP, true ) && is_array( $type->attributes ) && ! isset( $type->attributes[ self::ATTR ] ) ) {
				$type->attributes[ self::ATTR ] = [ 'type' => 'object' ];
			}
		}
	}

	/**
	 * A block's setting, cleaned. Pure.
	 *
	 * @return array{show: string, levels: string[]}
	 */
	public static function setting( array $block ): array {
		$raw  = $block['attrs'][ self::ATTR ] ?? null;
		$raw  = is_array( $raw ) ? $raw : [];
		$show = in_array( $raw['show'] ?? '', self::SHOWS, true ) && ! in_array( $block['blockName'] ?? '', self::SKIP, true ) ? $raw['show'] : self::EVERYONE;

		return [ 'show' => $show, 'levels' => Rules::levels( $raw['levels'] ?? [] ) ];
	}

	/**
	 * Could this block be hidden from someone who may see the public text? Pure.
	 */
	public static function members_only( array $block ): bool {
		return in_array( self::setting( $block )['show'], [ self::READERS, self::LEVELS ], true );
	}

	/**
	 * Does this content hold any block with a condition of its own?
	 */
	public static function in_content( string $content ): bool {
		return str_contains( $content, '"' . self::ATTR . '"' );
	}

	/**
	 * Is the block shown? Pure.
	 *
	 * @param array{show: string, levels: string[]} $setting
	 * @param bool                                   $reads Can the visitor read the item in full?
	 * @param callable(string): bool                 $holds Does the visitor hold this level?
	 */
	public static function visible( array $setting, bool $reads, callable $holds ): bool {
		$any = static function () use ( $setting, $holds ): bool {
			foreach ( $setting['levels'] as $slug ) {
				if ( $holds( $slug ) ) {
					return true;
				}
			}

			return false;
		};

		return match ( $setting['show'] ) {
			self::READERS => $reads,
			// No level chosen is a half-made setting: fail closed.
			self::LEVELS  => $reads && $any(),
			self::LOCKED  => ! $reads,
			self::WITHOUT => ! $any(),
			default       => true,
		};
	}

	public static function pre_render( mixed $pre, mixed $parsed ): mixed {
		if ( null !== $pre || ! is_array( $parsed ) ) {
			return $pre;
		}

		$setting = self::setting( $parsed );

		if ( self::EVERYONE === $setting['show'] ) {
			return $pre;
		}

		if ( is_user_logged_in() && ! defined( 'DONOTCACHEPAGE' ) ) {
			// What a signed-in visitor sees here depends on who they are.
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		}

		return self::visible( $setting, ...self::viewer() ) ? null : '';
	}

	/**
	 * The current visitor, for the current post: whether they read it, and
	 * which levels they hold.
	 *
	 * @return array{0: bool, 1: callable(string): bool}
	 */
	private static function viewer(): array {
		$post = get_post();

		if ( $post instanceof \WP_Post && Viewer::bypassing( $post ) ) {
			return [ true, static fn() => true ];
		}

		$checker = new Checker( LevelStore::levels(), Settings::evaluator() );
		$member  = Viewer::member();
		$now     = time();
		$holds   = static fn( string $slug ): bool => $checker->holds( $slug, $member, $now )->granted;

		if ( ! $post instanceof \WP_Post ) {
			return [ $holds( Level::SIGNED_IN ), $holds ];
		}

		return [ Gatekeeper::rule( $post )->open() || Gatekeeper::verdict( $post )->granted(), $holds ];
	}

	/**
	 * Every block with a condition of its own, at any depth, for the Test tab.
	 *
	 * @return array<int, array{name: string, show: string, levels: string[]}>
	 */
	public static function conditions( array $blocks ): array {
		$found = [];

		foreach ( $blocks as $block ) {
			$setting = self::setting( $block );

			if ( self::EVERYONE !== $setting['show'] ) {
				$found[] = [ 'name' => (string) ( $block['blockName'] ?? '' ) ] + $setting;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				array_push( $found, ...self::conditions( $block['innerBlocks'] ) );
			}
		}

		return $found;
	}

	public static function editor_assets(): void {
		$handle = 'wp2-shopify-block-access';

		wp_enqueue_script(
			$handle,
			WP2_SHOPIFY_URL . 'assets/block-access.js',
			[ 'wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-i18n', 'wp-blocks' ],
			WP2_SHOPIFY_VERSION,
			// In the head, so the attribute filter runs before any block type registers.
			false
		);

		$levels = [];

		foreach ( LevelStore::levels()->all() as $slug => $level ) {
			if ( Level::PUBLIC !== $slug ) {
				$levels[] = [ 'slug' => $slug, 'label' => $level->label ];
			}
		}

		wp_add_inline_script(
			$handle,
			'window.wp2ShopifyBlockAccess = ' . wp_json_encode(
				[
					'attr'   => self::ATTR,
					'skip'   => self::SKIP,
					'break'  => Teaser::BREAK_BLOCK,
					'source' => Badges::SOURCE,
					'levels' => $levels,
				]
			) . ';',
			'before'
		);
	}

	/**
	 * In the editor canvas, outline blocks that not everyone sees.
	 */
	public static function canvas_styles(): void {
		if ( ! is_admin() ) {
			return;
		}

		wp_register_style( 'wp2-shopify-block-access', false, [], WP2_SHOPIFY_VERSION );
		wp_add_inline_style( 'wp2-shopify-block-access', '.wp2-access-limited{outline:1px dashed #b26200;outline-offset:3px}.wp2-access-locked,.wp2-access-without{outline-color:#2271b1}' );
		wp_enqueue_style( 'wp2-shopify-block-access' );
	}
}
