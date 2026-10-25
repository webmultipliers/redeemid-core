<?php

namespace RedeemID\Features;

use RedeemID\Access\Level;
use RedeemID\Access\LevelStore;
use RedeemID\Access\Viewer;
use RedeemID\Entitlement\Member;
use RedeemID\Settings;
use RedeemID\Support\Logger;
use RedeemID\Sync\Mirror;

/**
 * The feature registry: what is registered, who gets it, and the one
 * canonical server-side check (redeemid_has_feature()).
 *
 * - Registration is code: the redeemid_features filter.
 * - Mapping is configuration: one stored condition per feature
 *   (redeemid_feature_conditions), edited on RedeemID → Access → Features
 *   or from a level's screen. A registration that sets its own condition is
 *   managed in code and read-only there.
 * - Grants are derived, never stored: the mirror plus the condition,
 *   resolved once per request for every feature and memoized.
 * - Grant and revoke happen only through the mirror (webhooks,
 *   resyncs, sign-in, the Customers tag editor, which writes to
 *   Shopify). redeemid_features_changed fires when a mirror write changes
 *   a customer's set.
 */
final class Registry {
	public const OPTION = 'redeemid_feature_conditions';

	/** Mirror keys whose change can change a customer's features. */
	private const WATCHED = [ Mirror::TAGS, Mirror::STATUS, Mirror::LAST_SYNC, Mirror::SYNC_FAILED, Mirror::GID, Mirror::NUMERIC ];

	/** @var array<string, Feature>|null */
	private static ?array $features = null;

	/** @var array<string, array<string, Grant>> Viewer key => grants. */
	private static array $memo = [];

	/** @var array<int, string[]> User ID => granted slugs before this request's mirror writes. */
	private static array $before = [];

	public static function register(): void {
		foreach ( [ 'add', 'update', 'delete' ] as $op ) {
			add_filter( "{$op}_user_metadata", [ self::class, 'before_write' ], 10, 3 );
		}

		add_action( 'redeemid_access_levels_changed', [ self::class, 'flush' ] );
		add_action( 'shutdown', [ self::class, 'after_writes' ] );
	}

	public static function flush(): void {
		self::$features = null;
		self::$memo     = [];
	}

	/**
	 * Registrations, from code.
	 *
	 * @return array<string, array>
	 */
	public static function definitions(): array {
		/**
		 * Register features. Keys are stable slugs (lower case, dashes, a
		 * vendor/ prefix for third parties). Values take:
		 *
		 * - label, description: shown in admin and on the account card;
		 * - enforcement: any of php, block, body, rest, shortcode;
		 * - sensitive: true when the thing it guards must be withheld server-side;
		 * - cache: private, vary or fragment (required; private if missing);
		 * - primary: list it in the member menu (at most three are shown);
		 * - js: print a presentation-only boolean for it in window.redeemidFeatures;
		 * - condition: set who gets it in code (managed in code, read-only in admin).
		 */
		$defs = apply_filters( 'redeemid_features', [] );

		return is_array( $defs ) ? $defs : [];
	}

	/**
	 * Custom condition tests, by name.
	 *
	 * @return array<string, array{label?: string, callback: callable}>
	 */
	public static function tests(): array {
		/**
		 * Register custom tests for feature conditions (test:<name> or
		 * test:<name>=<arg>). The callback gets (Member $member, string $arg,
		 * int $user_id, int $now) and grants only by returning true; anything
		 * else, or an exception, fails the test. Guests and disabled or never-synced
		 * customers never reach it.
		 */
		$tests = apply_filters( 'redeemid_feature_tests', [] );
		$out   = [];

		foreach ( is_array( $tests ) ? $tests : [] as $name => $test ) {
			$name = Level::slug( (string) $name );

			if ( '' !== $name && is_array( $test ) && is_callable( $test['callback'] ?? null ) ) {
				$out[ $name ] = $test;
			}
		}

		return $out;
	}

	/**
	 * @return array<string, array> slug => [ 'routes' => [...] ].
	 */
	public static function stored(): array {
		$stored = get_option( self::OPTION, [] );

		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * @return array<string, Feature>
	 */
	public static function features(): array {
		if ( null !== self::$features ) {
			return self::$features;
		}

		$stored   = self::stored();
		$features = [];

		foreach ( self::definitions() as $slug => $def ) {
			$slug = Feature::slug( (string) $slug );

			if ( '' !== $slug && is_array( $def ) ) {
				$features[ $slug ] = Feature::from_array( $slug, $def, isset( $stored[ $slug ]['routes'] ) ? (array) $stored[ $slug ]['routes'] : null );
			}
		}

		return self::$features = $features;
	}

	public static function get( string $slug ): ?Feature {
		return self::features()[ $slug ] ?? null;
	}

	/**
	 * Stored conditions whose feature is no longer registered: inert, shown in admin.
	 *
	 * @return string[]
	 */
	public static function orphans(): array {
		return array_values( array_diff( array_keys( self::stored() ), array_keys( self::features() ) ) );
	}

	public static function resolver(): Resolver {
		return new Resolver( LevelStore::levels(), Settings::evaluator(), self::tests() );
	}

	/**
	 * Every feature decision for a member, after the decision filter.
	 *
	 * @return array<string, Grant>
	 */
	public static function resolve( Member $member, int $user_id = 0, ?int $now = null ): array {
		$now     ??= time();
		$features  = self::features();

		if ( ! $features ) {
			return [];
		}

		$grants = self::resolver()->resolve( array_values( $features ), $member, $now, $user_id );

		if ( ! has_filter( 'redeemid_feature_decision' ) ) {
			return $grants;
		}

		$healthy = $member->signed_in && $member->linked && null === Settings::evaluator()->account_problem( $member, $now );

		foreach ( $grants as $slug => $grant ) {
			$grants[ $slug ] = self::filter( $grant, $member, $user_id, $healthy );
		}

		return $grants;
	}

	/**
	 * Let code alter one decision. A filter can withhold any feature, but can
	 * only grant to a signed-in, linked, enabled customer with a fresh
	 * mirror; one that throws or returns a non-boolean leaves the feature not
	 * granted. Changes are logged, once an hour per feature, user and change.
	 */
	private static function filter( Grant $grant, Member $member, int $user_id, bool $healthy ): Grant {
		try {
			/**
			 * Alter one feature decision.
			 *
			 * @param bool   $granted
			 * @param string $slug
			 * @param int    $user_id 0 for a guest or a test member.
			 * @param Grant  $grant   The decision with its routes and trace.
			 * @param Member $member
			 */
			$result = apply_filters( 'redeemid_feature_decision', $grant->granted, $grant->slug, $user_id, $grant, $member );
		} catch ( \Throwable ) {
			$result = null;
		}

		$after = true === $result && ( $grant->granted || $healthy );

		if ( $after === $grant->granted ) {
			return $grant;
		}

		$key = 'redeemid_feat_' . md5( $grant->slug . '|' . $user_id . '|' . (int) $after );

		if ( ! get_transient( $key ) ) {
			set_transient( $key, 1, HOUR_IN_SECONDS );
			Logger::log( 'feature.decision_filtered', [ 'feature' => $grant->slug, 'user' => $user_id, 'before' => $grant->granted ? 'granted' : 'not_granted', 'after' => $after ? 'granted' : 'not_granted', 'note' => is_bool( $result ) ? '' : 'filter error or non-boolean' ] );
		}

		return $grant->with( $after, Grant::FILTERED );
	}

	/**
	 * The current visitor's decisions (preview-as honoured), or one user's
	 * from their synced mirror. Memoized for the request.
	 *
	 * @param int|\WP_User|null $user
	 * @return array<string, Grant>
	 */
	public static function grants( int|\WP_User|null $user = null ): array {
		if ( null === $user ) {
			$key = 'v:' . Viewer::key();

			return self::$memo[ $key ] ??= self::resolve( Viewer::member(), get_current_user_id() );
		}

		$id = $user instanceof \WP_User ? (int) $user->ID : $user;

		return self::$memo[ 'u:' . $id ] ??= self::resolve( Mirror::member( $id ), $id );
	}

	/**
	 * The canonical check. Unknown slug: not granted, no exception.
	 *
	 * @param int|\WP_User|null $user
	 */
	public static function has( string $slug, int|\WP_User|null $user = null ): bool {
		$grant = self::grants( $user )[ Feature::slug( $slug ) ] ?? null;

		if ( null === $user && $grant ) {
			Cache::used( self::get( $grant->slug ) );
		}

		return (bool) $grant?->granted;
	}

	/**
	 * Granted features: slug => label, provenance, every granting route. No
	 * tags and no customer record.
	 *
	 * @param int|\WP_User|null $user
	 * @return array<string, array{label: string, provenance: string, routes: string[], primary: bool}>
	 */
	public static function granted( int|\WP_User|null $user = null ): array {
		$out = [];

		foreach ( self::grants( $user ) as $slug => $grant ) {
			$feature = self::get( $slug );

			if ( $grant->granted && $feature ) {
				if ( null === $user ) {
					Cache::used( $feature );
				}

				$out[ $slug ] = [ 'label' => $feature->label, 'provenance' => $grant->provenance(), 'routes' => $grant->routes, 'primary' => $feature->primary ];
			}
		}

		return $out;
	}

	/**
	 * Granted primary features for a member menu, at most Feature::MENU_CAP.
	 *
	 * @param int|\WP_User|null $user
	 * @return array<string, array{label: string, provenance: string, routes: string[], primary: bool}>
	 */
	public static function menu( int|\WP_User|null $user = null ): array {
		return array_slice( array_filter( self::granted( $user ), static fn( $f ) => $f['primary'] ), 0, Feature::MENU_CAP, true );
	}

	/**
	 * Save a feature's mapped condition. Ignored for features managed in code.
	 *
	 * @param Route[] $routes
	 */
	public static function save( string $slug, array $routes ): bool {
		$feature = self::get( $slug );

		if ( ! $feature || $feature->managed ) {
			return false;
		}

		$stored          = self::stored();
		$stored[ $slug ] = [ 'routes' => array_map( static fn( Route $r ) => $r->to_array(), $routes ) ];
		ksort( $stored );
		self::write( $stored, [ $slug ] );

		return true;
	}

	public static function forget( string $slug ): void {
		$stored = self::stored();
		unset( $stored[ $slug ] );
		self::write( $stored, [ $slug ] );
	}

	/**
	 * The level screen's shortcut: these features (and only these, among the
	 * ones mapped in admin) have a route that is exactly this level. Edits the
	 * same store as the Features tab.
	 *
	 * @param string[] $slugs
	 */
	public static function assign_level( string $level, array $slugs ): void {
		$stored  = self::stored();
		$changed = [];

		foreach ( self::features() as $slug => $feature ) {
			if ( $feature->managed ) {
				continue;
			}

			$routes = $feature->routes;
			$has    = (bool) array_filter( $routes, static fn( Route $r ) => $r->is_level_only( $level ) );
			$want   = in_array( $slug, $slugs, true );

			if ( $want === $has ) {
				continue;
			}

			$routes = $want
				? array_merge( $routes, [ new Route( [ [ 'type' => Route::LEVEL, 'value' => $level, 'arg' => '' ] ] ) ] )
				: array_values( array_filter( $routes, static fn( Route $r ) => ! $r->is_level_only( $level ) ) );

			$stored[ $slug ] = [ 'routes' => array_map( static fn( Route $r ) => $r->to_array(), $routes ) ];
			$changed[]       = $slug;
		}

		if ( $changed ) {
			ksort( $stored );
			self::write( $stored, $changed );
		}
	}

	private static function write( array $stored, array $slugs ): void {
		update_option( self::OPTION, $stored, true );
		self::flush();

		/**
		 * Who gets these features changed. Features are site-wide, so a cache
		 * layer should purge the whole site, not a list of URLs.
		 *
		 * @param string[] $slugs
		 */
		do_action( 'redeemid_features_mapping_changed', $slugs );
	}

	/**
	 * Before the first mirror write for a user in this request, note the
	 * features they hold, so after_writes() can tell what changed.
	 */
	public static function before_write( mixed $check, mixed $user_id, mixed $key ): mixed {
		$user_id = (int) $user_id;

		if ( $user_id > 0 && in_array( $key, self::WATCHED, true ) && self::features() ) {
			if ( ! isset( self::$before[ $user_id ] ) ) {
				self::$before[ $user_id ] = self::slugs( $user_id );
			}

			self::$memo = [];
		}

		return $check;
	}

	public static function after_writes(): void {
		foreach ( self::$before as $user_id => $before ) {
			$after   = self::slugs( $user_id );
			$added   = array_values( array_diff( $after, $before ) );
			$removed = array_values( array_diff( $before, $after ) );

			if ( $added || $removed ) {
				Logger::log( 'feature.changed', [ 'user' => $user_id, 'added' => implode( ',', $added ), 'removed' => implode( ',', $removed ) ] );

				/**
				 * A mirror write changed a customer's granted features. For cache
				 * purging, logging or a downstream integration; the grant itself
				 * is never decided here.
				 *
				 * @param int      $user_id
				 * @param string[] $added
				 * @param string[] $removed
				 */
				do_action( 'redeemid_features_changed', $user_id, $added, $removed );
			}
		}

		self::$before = [];
	}

	/**
	 * @return string[]
	 */
	private static function slugs( int $user_id ): array {
		$grants = self::resolve( Mirror::member( $user_id ), $user_id );

		return array_keys( array_filter( $grants, static fn( Grant $g ) => $g->granted ) );
	}
}
