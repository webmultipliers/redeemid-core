<?php

namespace RedeemID\Abilities;

use RedeemID\Access\Checker;
use RedeemID\Access\Drip;
use RedeemID\Access\Explain;
use RedeemID\Access\Gatekeeper;
use RedeemID\Access\Level;
use RedeemID\Access\Levels;
use RedeemID\Access\LevelStore;
use RedeemID\Access\Membership;
use RedeemID\Access\Reader;
use RedeemID\Access\RuleStore;
use RedeemID\Access\Verdict;
use RedeemID\Access\Viewer;
use RedeemID\Admin\Menu;
use RedeemID\Blocks\Account;
use RedeemID\Entitlement\Decision;
use RedeemID\Entitlement\Member;
use RedeemID\Features\Registry;
use RedeemID\Settings;
use RedeemID\Support\Health;
use RedeemID\Support\Transfer;
use RedeemID\Sync\BulkResync;
use RedeemID\Sync\LevelHistory;
use RedeemID\Sync\Mirror;
use RedeemID\Webhooks\Monitor;

/**
 * RedeemID's abilities for the WordPress Abilities API (WordPress 6.9+), so
 * AI agents (through the MCP Adapter plugin), the abilities REST routes and
 * the JS client can ask what RedeemID knows. On older WordPress the hooks
 * never fire and nothing registers.
 *
 * Every ability is read-only. None grants access, changes a level or calls
 * Shopify: Shopify stays the record. Staff abilities need the RedeemID admin
 * capability; member abilities answer only for the signed-in caller. Unknown
 * posts, users or viewers are errors, never "allowed". No ability returns a
 * token, a secret or a Shopify payload.
 *
 * All are marked public for MCP. A site that wants them off MCP sets
 * meta.mcp.public to false with core's wp_register_ability_args filter.
 */
final class Abilities {
	public const CATEGORY = 'redeemid';

	public const STAFF  = 'staff';
	public const MEMBER = 'member';

	/** Ability name => who may run it. */
	public const AUDIENCE = [
		'redeemid/explain-access'   => self::STAFF,
		'redeemid/explain-features' => self::STAFF,
		'redeemid/list-levels'      => self::STAFF,
		'redeemid/get-rules'        => self::STAFF,
		'redeemid/get-member'       => self::STAFF,
		'redeemid/site-health'      => self::STAFF,
		'redeemid/level-changes'    => self::STAFF,
		'redeemid/my-membership'    => self::MEMBER,
		'redeemid/can-i-read'       => self::MEMBER,
	];

	public static function register(): void {
		add_action( 'wp_abilities_api_categories_init', [ self::class, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ self::class, 'register_abilities' ] );
	}

	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'RedeemID', 'redeemid' ),
				'description' => __( 'Memberships from Shopify customer tags: who can read what, and why.', 'redeemid' ),
			]
		);
	}

	public static function register_abilities(): void {
		foreach ( self::definitions() as $name => $args ) {
			wp_register_ability(
				$name,
				$args + [
					'category'            => self::CATEGORY,
					'permission_callback' => static fn() => self::allowed( self::AUDIENCE[ $name ], is_user_logged_in(), current_user_can( Menu::CAP ) ),
					'meta'                => self::meta(),
				]
			);
		}
	}

	/**
	 * May this caller run an ability for $audience? Pure.
	 */
	public static function allowed( string $audience, bool $logged_in, bool $can_manage ): bool {
		return match ( $audience ) {
			self::STAFF  => $logged_in && $can_manage,
			self::MEMBER => $logged_in,
			default      => false,
		};
	}

	/**
	 * Read-only everywhere; listed in REST and on the MCP Adapter's default server. Pure.
	 */
	public static function meta(): array {
		return [
			'annotations'  => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
			'show_in_rest' => true,
			'mcp'          => [ 'public' => true ],
		];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function definitions(): array {
		$viewer = [
			'as'      => [
				'type'        => 'string',
				'default'     => Explain::AS_GUEST,
				'description' => 'Who to answer for: "guest", "signed-in" (a synced customer with no tags), a level slug, "tags" (with tags) or "user" (with user_id).',
			],
			'tags'    => [
				'type'        => 'array',
				'items'       => [ 'type' => 'string' ],
				'default'     => [],
				'description' => 'Shopify customer tags, with as = "tags".',
			],
			'user_id' => [
				'type'        => 'integer',
				'minimum'     => 0,
				'default'     => 0,
				'description' => 'A WordPress user ID, with as = "user". Uses their synced record.',
			],
		];
		$item   = [
			'post_id' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'The item\'s post ID. Give this or url.' ],
			'url'     => [ 'type' => 'string', 'default' => '', 'description' => 'The item\'s URL on this site. Give this or post_id.' ],
		];

		return [
			'redeemid/explain-access'   => [
				'label'            => __( 'Explain access to an item', 'redeemid' ),
				'description'      => __( 'Whether a viewer can read a post or page and why: every rule layer, the rule it resolves to, the level a locked viewer is offered, what they see instead, which blocks show, and how search, comments and search engines treat it. Answers for the viewer described, never with the editor bypass.', 'redeemid' ),
				'input_schema'     => self::input( $item + $viewer ),
				'output_schema'    => self::output(
					[
						'post'        => [ 'type' => 'object' ],
						'gated'       => [ 'type' => 'boolean' ],
						'layers'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'rule'        => [ 'type' => 'string' ],
						'granted'     => [ 'type' => 'boolean' ],
						'reason'      => [ 'type' => 'string' ],
						'offer'       => [ 'type' => 'string' ],
						'sees'        => [ 'type' => 'string' ],
						'next_change' => [ 'type' => [ 'integer', 'null' ] ],
						'blocks'      => [ 'type' => 'array' ],
						'surfaces'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					]
				),
				'execute_callback' => [ self::class, 'explain_access' ],
			],
			'redeemid/explain-features' => [
				'label'            => __( 'Explain features for a viewer', 'redeemid' ),
				'description'      => __( 'Every registered membership feature, whether the viewer holds it, and which route and test grants or refuses it.', 'redeemid' ),
				'input_schema'     => self::input( $viewer ),
				'output_schema'    => self::output( [ 'features' => [ 'type' => 'array' ] ] ),
				'execute_callback' => [ self::class, 'explain_features' ],
			],
			'redeemid/list-levels'      => [
				'label'            => __( 'List access levels', 'redeemid' ),
				'description'      => __( 'The access levels: each one\'s Shopify tag condition, the levels it includes, its badge and the products that lead to it. Includes the built-in Public and Signed in levels.', 'redeemid' ),
				'input_schema'     => self::input( [] ),
				'output_schema'    => self::output( [ 'levels' => [ 'type' => 'array' ], 'managed_in_code' => [ 'type' => 'boolean' ] ] ),
				'execute_callback' => [ self::class, 'list_levels' ],
			],
			'redeemid/get-rules'        => [
				'label'            => __( 'Get access rules', 'redeemid' ),
				'description'      => __( 'The site default, how several term rules combine, the rule for each post type and taxonomy, the meter, and every rule saved on a term.', 'redeemid' ),
				'input_schema'     => self::input( [] ),
				'output_schema'    => self::output( [ 'rules' => [ 'type' => 'object' ], 'term_rules' => [ 'type' => 'object' ], 'managed_in_code' => [ 'type' => 'boolean' ] ] ),
				'execute_callback' => [ self::class, 'get_rules' ],
			],
			'redeemid/get-member'       => [
				'label'            => __( 'Get a member\'s synced record', 'redeemid' ),
				'description'      => __( 'One WordPress user\'s synced Shopify customer record: linked or not, status, tags, the levels and features they hold, when and how they last synced, and their level history. Read from WordPress; Shopify is not called.', 'redeemid' ),
				'input_schema'     => self::input(
					[
						'user_id' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'A WordPress user ID. Give this or email.' ],
						'email'   => [ 'type' => 'string', 'default' => '', 'description' => 'The user\'s WordPress or Shopify email address. Give this or user_id.' ],
					]
				),
				'output_schema'    => self::output(
					[
						'user'      => [ 'type' => 'object' ],
						'linked'    => [ 'type' => 'boolean' ],
						'status'    => [ 'type' => 'string' ],
						'tags'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'levels'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'features'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'last_sync' => [ 'type' => [ 'integer', 'null' ] ],
					]
				),
				'execute_callback' => [ self::class, 'get_member' ],
			],
			'redeemid/site-health'      => [
				'label'            => __( 'Check RedeemID health', 'redeemid' ),
				'description'      => __( 'The setup checklist (configuration, sign-in, blocks), current webhook problems, and the state of the last bulk resync. Configuration values are never shown, only whether each is set.', 'redeemid' ),
				'input_schema'     => self::input( [] ),
				'output_schema'    => self::output( [ 'checks' => [ 'type' => 'array' ], 'webhooks' => [ 'type' => 'array' ], 'bulk_resync' => [ 'type' => [ 'object', 'null' ] ] ] ),
				'execute_callback' => [ self::class, 'site_health' ],
			],
			'redeemid/level-changes'    => [
				'label'            => __( 'List level changes', 'redeemid' ),
				'description'      => __( 'Who joined or left a level since a date, from each member\'s level history, oldest first. Unlinked customers drop out of the history.', 'redeemid' ),
				'input_schema'     => self::input(
					[
						'since' => [ 'type' => 'string', 'default' => '7 days ago', 'description' => 'Anything PHP\'s strtotime() reads, such as 2026-10-01 or "30 days ago".' ],
						'level' => [ 'type' => 'string', 'default' => '', 'description' => 'Only this level slug.' ],
					]
				),
				'output_schema'    => self::output( [ 'since' => [ 'type' => 'integer' ], 'changes' => [ 'type' => 'array' ] ] ),
				'execute_callback' => [ self::class, 'level_changes' ],
			],
			'redeemid/my-membership'    => [
				'label'            => __( 'Get my membership', 'redeemid' ),
				'description'      => __( 'The signed-in caller\'s own membership: their levels by label, when they joined and renew, levels that ended, the features they hold and when Shopify last synced. Never another user\'s.', 'redeemid' ),
				'input_schema'     => self::input( [] ),
				'output_schema'    => self::output(
					[
						'state'    => [ 'type' => 'string' ],
						'levels'   => [ 'type' => 'array' ],
						'features' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'synced'   => [ 'type' => 'integer' ],
					]
				),
				'execute_callback' => [ self::class, 'my_membership' ],
			],
			'redeemid/can-i-read'       => [
				'label'            => __( 'Can I read this?', 'redeemid' ),
				'description'      => __( 'Whether the signed-in caller can read a post or page, and if not, the level that would unlock it or when it opens for them. Free metered reads are not counted.', 'redeemid' ),
				'input_schema'     => self::input( $item ),
				'output_schema'    => self::output(
					[
						'post_id'  => [ 'type' => 'integer' ],
						'title'    => [ 'type' => 'string' ],
						'can_read' => [ 'type' => 'boolean' ],
						'reason'   => [ 'type' => 'string' ],
						'offer'    => [ 'type' => [ 'object', 'null' ] ],
						'opens_at' => [ 'type' => [ 'integer', 'null' ] ],
					]
				),
				'execute_callback' => [ self::class, 'can_i_read' ],
			],
		];
	}

	/**
	 * An object input whose every property is optional. An empty object is
	 * the default, so a client may send nothing. Pure.
	 */
	public static function input( array $properties ): array {
		$schema = [ 'type' => 'object', 'additionalProperties' => false, 'default' => new \stdClass() ];

		return $properties ? $schema + [ 'properties' => $properties ] : $schema;
	}

	private static function output( array $properties ): array {
		return [ 'type' => 'object', 'properties' => $properties ];
	}

	public static function explain_access( mixed $input ): array|\WP_Error {
		$input = (array) $input;
		$post  = self::post( $input );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$member = self::member( $input );

		return is_wp_error( $member ) ? $member : Explain::trace( $post, $member );
	}

	public static function explain_features( mixed $input ): array|\WP_Error {
		$input  = (array) $input;
		$member = self::member( $input );

		if ( is_wp_error( $member ) ) {
			return $member;
		}

		$user_id = Explain::AS_USER === ( $input['as'] ?? '' ) ? (int) $input['user_id'] : 0;

		return [ 'features' => Explain::features( $member, $user_id ) ];
	}

	public static function list_levels(): array {
		$levels = LevelStore::levels();

		return [
			'levels'          => array_values( array_map( static fn( Level $l ) => self::level_row( $l, $levels ), $levels->all() ) ),
			'managed_in_code' => LevelStore::managed_in_code(),
		];
	}

	/**
	 * One level for an agent: its condition in tags, never a member's tags. Pure.
	 */
	public static function level_row( Level $level, Levels $levels ): array {
		return [
			'slug'      => $level->slug,
			'label'     => $level->label,
			'builtin'   => $level->builtin(),
			'condition' => array_map( static fn( $g ) => [ 'tags' => $g->required_tags, 'match' => $g->match_mode ], $level->groups ),
			'includes'  => $level->includes,
			'opens'     => array_values( array_diff( $levels->closure( $level->slug ), [ $level->slug ] ) ),
			'badge'     => $level->badge,
			'products'  => $level->products,
			'cta_url'   => $level->cta_url,
		];
	}

	public static function get_rules(): array {
		return [
			'rules'           => RuleStore::rules()->to_array(),
			'term_rules'      => (object) Transfer::term_rules(),
			'managed_in_code' => RuleStore::managed_in_code(),
		];
	}

	public static function get_member( mixed $input ): array|\WP_Error {
		$input = (array) $input;
		$user  = self::user( (int) ( $input['user_id'] ?? 0 ), (string) ( $input['email'] ?? '' ) );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$id       = (int) $user->ID;
		$member   = Mirror::member( $id );
		$customer = Mirror::customer_id( $id );

		return [
			'user'          => [ 'id' => $id, 'name' => $user->display_name, 'email' => $user->user_email ],
			'linked'        => null !== $customer,
			'customer_id'   => $customer ? (string) $customer->numeric : '',
			'shopify_email' => (string) get_user_meta( $id, Mirror::EMAIL, true ),
			'status'        => $customer ? $member->status : '',
			'tags'          => $member->tags,
			'levels'        => Membership::held( LevelStore::levels(), $member, time() ),
			'features'      => array_keys( Registry::granted( $id ) ),
			'last_sync'     => $member->last_sync,
			'sync_source'   => (string) get_user_meta( $id, Mirror::SYNC_SOURCE, true ),
			'sync_failed'   => $member->last_sync_failed,
			'test_fixture'  => Mirror::is_fixture( $id ),
			'history'       => (object) LevelHistory::get( $id ),
		];
	}

	public static function site_health(): array {
		$webhooks = [];

		foreach ( Monitor::problems() as $code => $problem ) {
			$webhooks[] = [ 'code' => (string) $code, 'state' => $problem['state'], 'message' => Monitor::message( (string) $code, (int) $problem['n'] ) ];
		}

		return [
			'checks'      => Health::checks(),
			'webhooks'    => $webhooks,
			'bulk_resync' => BulkResync::job(),
		];
	}

	public static function level_changes( mixed $input ): array|\WP_Error {
		$input  = (array) $input;
		$cutoff = strtotime( (string) ( $input['since'] ?? '7 days ago' ) );

		if ( false === $cutoff ) {
			return new \WP_Error( 'redeemid_bad_date', __( 'Could not read since as a date.', 'redeemid' ), [ 'status' => 400 ] );
		}

		return [ 'since' => $cutoff, 'changes' => LevelHistory::report( $cutoff, (string) ( $input['level'] ?? '' ) ) ];
	}

	public static function my_membership(): array {
		return Account::view( get_current_user_id() );
	}

	public static function can_i_read( mixed $input ): array|\WP_Error {
		$post = self::post( (array) $input );

		// Drafts and private items the caller can't see are "no such item", not "locked".
		if ( is_wp_error( $post ) || ! Reader::visible( $post, get_current_user_id() ) ) {
			return is_wp_error( $post ) ? $post : self::not_found();
		}

		$levels  = LevelStore::levels();
		$verdict = ( new Checker( $levels, Settings::evaluator() ) )->check( Gatekeeper::rule( $post ), Viewer::member(), time() );
		$opens   = $verdict->granted() ? Drip::opens_for( $post, get_current_user_id() ) : 0;

		return [ 'post_id' => $post->ID, 'title' => get_the_title( $post ) ] + self::read_answer( Reader::can( $post ), $verdict, $opens, time(), $levels );
	}

	/**
	 * What can-i-read tells a member. Reasons are Decision codes. Pure.
	 *
	 * @return array{can_read: bool, reason: string, offer: ?array{slug: string, label: string}, opens_at: ?int}
	 */
	public static function read_answer( bool $can, Verdict $verdict, int $opens_at, int $now, Levels $levels ): array {
		if ( $can ) {
			return [ 'can_read' => true, 'reason' => Decision::GRANTED, 'offer' => null, 'opens_at' => null ];
		}

		if ( $verdict->granted() ) {
			return [ 'can_read' => false, 'reason' => Decision::DRIP, 'offer' => null, 'opens_at' => $opens_at > $now ? $opens_at : null ];
		}

		$offer = $verdict->next_level ? $levels->get( $verdict->next_level ) : null;

		return [
			'can_read' => false,
			'reason'   => $verdict->decision->reason,
			'offer'    => $offer ? [ 'slug' => $offer->slug, 'label' => $offer->label ] : null,
			'opens_at' => null,
		];
	}

	/**
	 * The viewer an explain call asked for, or why it can't be built. Pure.
	 *
	 * @param callable(string): bool $is_level
	 */
	public static function viewer_problem( string $as, array $tags, int $user_id, callable $is_level ): ?string {
		return match ( true ) {
			in_array( $as, [ Explain::AS_GUEST, Explain::AS_SIGNED_IN ], true ) => null,
			Explain::AS_TAGS === $as => [] === array_filter( array_map( 'trim', array_map( 'strval', $tags ) ), 'strlen' ) ? 'redeemid_no_tags' : null,
			Explain::AS_USER === $as => $user_id > 0 ? null : 'redeemid_no_user',
			default                  => $is_level( $as ) ? null : 'redeemid_unknown_viewer',
		};
	}

	private static function member( array $input ): Member|\WP_Error {
		$as      = (string) ( $input['as'] ?? Explain::AS_GUEST );
		$tags    = (array) ( $input['tags'] ?? [] );
		$user_id = (int) ( $input['user_id'] ?? 0 );
		$problem = self::viewer_problem( $as, $tags, $user_id, [ LevelStore::levels(), 'has' ] );

		if ( null === $problem && Explain::AS_USER === $as && ! get_userdata( $user_id ) ) {
			$problem = 'redeemid_no_user';
		}

		if ( null !== $problem ) {
			$messages = [
				'redeemid_no_tags'        => __( 'as is "tags" but no tags were given.', 'redeemid' ),
				'redeemid_no_user'        => __( 'as is "user" but user_id is not an existing user.', 'redeemid' ),
				'redeemid_unknown_viewer' => __( 'as must be guest, signed-in, tags, user or a level slug.', 'redeemid' ),
			];

			return new \WP_Error( $problem, $messages[ $problem ], [ 'status' => 400 ] );
		}

		return Explain::member( $as, array_map( 'strval', $tags ), $user_id );
	}

	private static function post( array $input ): \WP_Post|\WP_Error {
		$id   = (int) ( $input['post_id'] ?? 0 );
		$url  = (string) ( $input['url'] ?? '' );
		$id   = $id > 0 ? $id : ( '' !== $url ? url_to_postid( $url ) : 0 ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.url_to_postid_url_to_postid -- one lookup per call, not a page view.
		$post = $id > 0 ? get_post( $id ) : null;

		return $post instanceof \WP_Post ? $post : self::not_found();
	}

	private static function not_found(): \WP_Error {
		return new \WP_Error( 'redeemid_no_item', __( 'No such item. Give an existing post_id or a URL on this site.', 'redeemid' ), [ 'status' => 404 ] );
	}

	private static function user( int $user_id, string $email ): \WP_User|\WP_Error {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		if ( ! $user && is_email( $email ) ) {
			$user = get_user_by( 'email', $email );

			if ( ! $user ) {
				$found = get_users(
					[
						'meta_key'    => Mirror::EMAIL, // phpcs:ignore WordPress.DB.SlowDBQuery -- staff lookup, not a page view.
						'meta_value'  => $email, // phpcs:ignore WordPress.DB.SlowDBQuery
						'number'      => 1,
						'count_total' => false,
					]
				);
				$user  = $found[0] ?? false;
			}
		}

		return $user instanceof \WP_User ? $user : new \WP_Error( 'redeemid_no_user', __( 'No such user. Give an existing user_id or email.', 'redeemid' ), [ 'status' => 404 ] );
	}
}
