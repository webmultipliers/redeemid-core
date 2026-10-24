<?php

namespace WP2_Shopify\Access;

use WP2_Shopify\Entitlement\Member;
use WP2_Shopify\Settings;
use WP2_Shopify\Sync\Mirror;

/**
 * The resolution trace for one item and one viewer: every layer, the rule
 * they resolve to and where it came from, what this viewer gets, which of
 * the item's blocks they see, and how search, comments and search engines
 * treat it. Shown on Shopify → Access → Test and by
 * `wp wp2-shopify access explain`.
 *
 * Never uses the editor bypass: it answers for the viewer described, as an
 * automated check would.
 */
final class Explain {
	public const AS_GUEST     = 'guest';
	public const AS_SIGNED_IN = 'signed-in';
	public const AS_TAGS      = 'tags';
	public const AS_USER      = 'user';

	/**
	 * The member to test as: a guest, a signed-in customer with no tags, a
	 * holder of one level, a synced customer with these tags, or a real
	 * user's synced mirror.
	 *
	 * @param string[] $tags
	 */
	public static function member( string $as, array $tags = [], int $user_id = 0, ?int $now = null ): Member {
		$now ??= time();

		return match ( true ) {
			self::AS_GUEST === $as                   => Member::guest(),
			self::AS_SIGNED_IN === $as               => new Member( signed_in: true, linked: true, last_sync: $now ),
			self::AS_TAGS === $as                    => new Member( signed_in: true, linked: true, tags: array_values( array_filter( array_map( 'trim', $tags ), 'strlen' ) ), last_sync: $now ),
			self::AS_USER === $as                    => $user_id > 0 ? Mirror::member( $user_id ) : Member::guest(),
			LevelStore::levels()->has( $as )         => Viewer::member_for( LevelStore::levels()->get( $as ), $now ),
			default                                  => Member::guest(),
		};
	}

	/**
	 * @return array{
	 *   post: array{id: int, title: string, type: string, status: string},
	 *   gated: bool,
	 *   layers: string[],
	 *   rule: string,
	 *   meter_eligible: bool,
	 *   next_change: ?int,
	 *   granted: bool,
	 *   reason: string,
	 *   offer: string,
	 *   sees: string,
	 *   blocks: array<int, array{name: string, show: string, levels: string[], visible: bool}>,
	 *   surfaces: string[]
	 * }
	 */
	public static function trace( \WP_Post $post, Member $member, ?int $now = null ): array {
		$now     ??= time();
		$levels    = LevelStore::levels();
		$checker   = new Checker( $levels, Settings::evaluator() );
		$gated     = ItemRule::gated( $post );
		$context   = Gatekeeper::context( $post );
		$rule      = ( new Resolver( $levels ) )->resolve( $context, $now );
		$filtered  = apply_filters( 'wp2_shopify_effective_rule', $rule, $post );
		$rule      = $filtered instanceof EffectiveRule ? $filtered : new EffectiveRule( [ [ '__invalid_rule' ] ], 'Filter' );
		$verdict   = $checker->check( $rule, $member, $now );
		$reads     = $rule->open() || $verdict->granted();
		$holds     = static fn( string $slug ): bool => $checker->holds( $slug, $member, $now )->granted;
		$blocks    = parse_blocks( $post->post_content );
		$type_row  = RuleStore::rules()->type( $post->post_type );
		$label     = static fn( string $slug ) => $levels->get( $slug )?->label ?? $slug;
		$sees      = __( 'The whole item.', 'wp2-shopify' );

		if ( ! $reads ) {
			$cut  = Gatekeeper::teaser( $post )->cut( Teaser::strip( $blocks, [ BlockAccess::class, 'members_only' ] ), (string) $post->post_excerpt );
			$sees = match ( $cut['source'] ) {
				Teaser::SOURCE_BREAK   => __( 'The content above the paywall break, then the wall.', 'wp2-shopify' ),
				Teaser::SOURCE_SETTING => __( 'The post type\'s teaser, then the wall.', 'wp2-shopify' ),
				Teaser::SOURCE_EXCERPT => __( 'The hand-written excerpt, then the wall.', 'wp2-shopify' ),
				default                => __( 'Only the wall: no teaser.', 'wp2-shopify' ),
			};
		}

		$conditions = array_map(
			static fn( array $c ) => $c + [ 'visible' => BlockAccess::visible( $c, $reads, $holds ) ],
			BlockAccess::conditions( $blocks )
		);

		return [
			'post'           => [ 'id' => $post->ID, 'title' => get_the_title( $post ), 'type' => $post->post_type, 'status' => $post->post_status ],
			'gated'          => $gated,
			'layers'         => self::layers( $context, $levels, $now ),
			'rule'           => $rule->explain( $levels ),
			'meter_eligible' => $rule->meter_eligible,
			'next_change'    => Index::next_change( $context, $now ),
			'granted'        => $reads,
			'reason'         => $verdict->decision->reason,
			'offer'          => $verdict->next_level ? $label( $verdict->next_level ) : '',
			'sees'           => $sees,
			'blocks'         => $conditions,
			'surfaces'       => [
				Gatekeeper::protected( $post )
					? __( 'Search matches its public text only: title, excerpt, teaser and blocks shown to locked visitors.', 'wp2-shopify' )
					: __( 'Search matches its full text: nothing in it is restricted.', 'wp2-shopify' ),
				Rules::COMMENTS_EVERYONE === $type_row['comments']
					? __( 'Comments: shown to everyone.', 'wp2-shopify' )
					: __( 'Comments: on a locked item, shown only to visitors who can read it.', 'wp2-shopify' ),
				$type_row['noindex'] && ! $rule->open()
					? __( 'Search engines: noindex, and left out of sitemaps.', 'wp2-shopify' )
					: __( 'Search engines: indexed and in sitemaps. Locked items show crawlers the teaser only.', 'wp2-shopify' ),
			],
		];
	}

	/**
	 * Each layer in words, in the order the Resolver reads them.
	 *
	 * @return string[]
	 */
	public static function layers( Context $c, Levels $levels, int $now ): array {
		$names = static fn( array $slugs ) => $slugs ? implode( ' or ', array_map( static fn( $s ) => $levels->get( $s )?->label ?? $s . ' (unknown level)', $slugs ) ) : '';
		$date  = static fn( int $t ) => function_exists( 'wp_date' ) ? (string) wp_date( 'j M Y H:i', $t ) : gmdate( 'j M Y H:i', $t );
		$lines = [];

		$lines[] = match ( $c->item_mode ) {
			Context::ITEM_PUBLIC => 'Item: Public, so the item is open whatever the layers below say.',
			Context::ITEM_LEVEL  => 'Item: requires ' . ( $names( $c->item_levels ) ?: 'Signed in (no level saved)' ) . ', instead of the layers below.',
			Context::ITEM_ADD    => 'Item: the inherited rule, plus ' . ( $names( $c->item_levels ) ?: 'nothing (no level saved)' ) . '.',
			default              => 'Item: inherits.',
		};

		if ( $c->locked_until ) {
			$lines[] = 'Item window: locked until ' . $date( $c->locked_until ) . ( $c->locked_until <= $now ? ' (passed, so open)' : '' ) . '.';
		}

		if ( $c->public_until ) {
			$lines[] = 'Item window: free until ' . $date( $c->public_until ) . ( $c->public_until <= $now ? ' (passed)' : ' (free now)' ) . '.';
		}

		foreach ( $c->terms as $term ) {
			$lines[] = TermRule::OPENS === $term->kind
				? $term->label() . ': opens the item until ' . ( $term->opens_until ? $date( $term->opens_until ) : '(no expiry, ignored)' ) . ( $term->opens_at( $now ) ? ' (in force).' : ' (not in force).' )
				: $term->label() . ': requires ' . ( $names( $term->levels ) ?: 'nothing' ) . ( $term->priority ? ', priority ' . $term->priority : '' ) . '.';
		}

		if ( ! $c->terms ) {
			$lines[] = 'Terms: none with a rule.';
		}

		$lines[] = $c->type_label . ': ' . ( $c->type_levels ? 'requires ' . $names( $c->type_levels ) : 'no rule' ) . ( $c->type_public_after ? ', open after ' . (int) round( $c->type_public_after / 86400 ) . ' days' : '' ) . '.';
		$lines[] = 'Site default: ' . ( $c->site_levels ? 'requires ' . $names( $c->site_levels ) : 'none' ) . '. Several Requires terms combine as: ' . ( [ Context::CONFLICT_MOST => 'most restrictive', Context::CONFLICT_LEAST => 'least restrictive', Context::CONFLICT_PRIORITY => 'highest priority' ][ $c->conflict ] ?? $c->conflict ) . '.';

		return $lines;
	}
}
