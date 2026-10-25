<?php

namespace WP2_Shopify\Blocks;

use WP2_Shopify\Access\Checker;
use WP2_Shopify\Access\Gatekeeper;
use WP2_Shopify\Access\Level;
use WP2_Shopify\Access\LevelStore;
use WP2_Shopify\Access\Membership;
use WP2_Shopify\Access\Viewer;
use WP2_Shopify\Auth\Flow;
use WP2_Shopify\Entitlement\Decision;
use WP2_Shopify\Entitlement\Rule;
use WP2_Shopify\Settings;
use WP2_Shopify\Sync\LevelHistory;
use WP2_Shopify\Sync\PostCheckout;

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
	 * Blockstudio component so themes can restyle or override it. What it
	 * offers depends on why the visitor was turned away (PanelKind).
	 *
	 * @param string $level The level the content needs, when known: the item's
	 *                      next level, or a level gate's level.
	 */
	public static function fallback_panel( array $a, Decision $decision, string $level = '' ): string {
		$user_id = get_current_user_id();
		$levels  = LevelStore::levels();
		$level   = '' !== $level ? $level : self::wanted_level( $a );
		$wanted  = '' !== $level ? $levels->get( $level ) : null;
		$label   = $wanted && ! $wanted->builtin() ? $wanted->label : '';
		$held    = $user_id ? Membership::held( $levels, Viewer::member(), time() ) : [];
		$kind    = PanelKind::for(
			$decision->reason,
			PostCheckout::active( $user_id ),
			'' !== $level && $user_id && LevelHistory::lapsed( $user_id, $level ),
			(bool) $held
		);
		$here    = (string) get_permalink() ?: self::current_url();
		$cta     = (string) ( $a['fallbackCtaLabel'] ?? '' );
		$data    = [
			'message'    => (string) ( ( $a['fallbackMessage'] ?? '' ) ?: __( 'This content is for members.', 'wp2-shopify' ) ),
			'reason'     => '',
			'kind'       => $kind,
			'level'      => $level,
			'ctaLabel'   => $cta,
			'ctaUrl'     => self::url( $a['fallbackCtaUrl'] ?? '' ),
			'signInUrl'  => '',
			'hint'       => '',
			'formAction' => '',
			'formLabel'  => '',
			'formNonce'  => '',
			'redirectTo' => $here,
		];

		switch ( $kind ) {
			case PanelKind::SIGN_IN:
				if ( false !== ( $a['showSignIn'] ?? true ) ) {
					$data['signInUrl'] = Flow::login_url( $here );
					$data['hint']      = __( 'Already a member, or just bought a membership? Log in with Shopify using the email address you checked out with.', 'wp2-shopify' );
				}
				break;
			case PanelKind::CONNECT:
				$data['reason']     = __( 'Your account here isn\'t connected to a Shopify customer yet. Connect it to use a membership you have.', 'wp2-shopify' );
				$data['formAction'] = 'wp2_shopify_connect';
				$data['formLabel']  = __( 'Connect your Shopify account', 'wp2-shopify' );
				break;
			case PanelKind::FINISHING:
				$data['reason']     = __( 'We\'re finishing setting up your membership. This usually takes under a minute after checkout.', 'wp2-shopify' );
				$data['formAction'] = 'wp2_shopify_sync';
				$data['formLabel']  = __( 'Check again', 'wp2-shopify' );
				$data['ctaLabel']   = '';
				break;
			case PanelKind::RENEW:
				/* translators: %s: access level label, such as Gold */
				$data['reason']     = $label ? sprintf( __( 'Your %s membership has ended.', 'wp2-shopify' ), $label ) : __( 'Your membership has ended.', 'wp2-shopify' );
				/* translators: %s: access level label */
				$data['ctaLabel']   = $label ? sprintf( __( 'Renew %s', 'wp2-shopify' ), $label ) : ( $cta ?: __( 'Renew', 'wp2-shopify' ) );
				$data['formAction'] = 'wp2_shopify_sync';
				$data['formLabel']  = __( 'Already renewed? Refresh', 'wp2-shopify' );
				break;
			case PanelKind::UPGRADE:
			case PanelKind::JOIN:
				// The level's own button label is written for newcomers; an existing member is upgrading.
				$data['ctaLabel']   = PanelKind::UPGRADE === $kind && $label
					/* translators: %s: access level label */
					? sprintf( __( 'Upgrade to %s', 'wp2-shopify' ), $label )
					/* translators: %s: access level label */
					: ( $cta ?: ( $label ? sprintf( __( 'Join %s', 'wp2-shopify' ), $label ) : '' ) );
				$data['formAction'] = 'wp2_shopify_sync';
				$data['formLabel']  = __( 'Already bought it? Refresh', 'wp2-shopify' );
				break;
			case PanelKind::DISABLED:
				$data['reason']   = __( 'Your account is disabled. Contact the store for help.', 'wp2-shopify' );
				$data['ctaLabel'] = '';
				break;
			case PanelKind::DRIP:
				$data['message']  = __( 'This is part of your membership and opens soon.', 'wp2-shopify' );
				$data['reason']   = (int) ( $a['unlocksAt'] ?? 0 ) > 0
					/* translators: %s: a date */
					? sprintf( __( 'It opens for you on %s.', 'wp2-shopify' ), wp_date( (string) get_option( 'date_format' ), (int) $a['unlocksAt'] ) )
					: '';
				$data['ctaLabel'] = '';
				// Personal: the date is this member's own.
				if ( ! defined( 'DONOTCACHEPAGE' ) ) {
					define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
				}
				break;
		}

		if ( '' !== $data['formAction'] ) {
			$data['formNonce'] = wp_create_nonce( $data['formAction'] );

			// A nonce is per visitor: this page must not be cached.
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
			}
		}

		/**
		 * The locked panel's content before it renders: message, reason, kind,
		 * level, ctaLabel, ctaUrl, signInUrl, hint, formAction, formLabel.
		 *
		 * @param array<string, string> $data
		 * @param Decision              $decision
		 */
		$data = (array) apply_filters( 'wp2_shopify_locked_panel', $data, $decision );

		return function_exists( 'bs_block' ) ? (string) bs_block( [ 'name' => self::PANEL, 'data' => $data ] ) : '';
	}

	/**
	 * The level a gate asks for: its own for a level gate, the item's next
	 * level for an item gate, none for custom tags.
	 */
	private static function wanted_level( array $a ): string {
		$source = self::option( $a['ruleSource'] ?? '', self::RULE_CUSTOM );
		$post   = get_post();

		if ( self::RULE_LEVEL === $source ) {
			return self::option( $a['level'] ?? '', '' );
		}

		return self::RULE_ITEM === $source && $post instanceof \WP_Post ? (string) Gatekeeper::verdict( $post )->next_level : '';
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
