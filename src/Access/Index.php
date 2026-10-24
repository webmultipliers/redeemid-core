<?php

namespace WP2_Shopify\Access;

use WP2_Shopify\Blocks\Gate;

/**
 * What queries need to know about each item's access, kept in post meta so
 * search, sitemaps and comment feeds can filter in SQL instead of loading
 * every item:
 *
 * - _wp2_access_search: the text anyone may read, for items whose rule is
 *   not open, may change on a date, or that hold a membership gate or a
 *   block shown only to readers. Search matches this instead of
 *   post_content (Surfaces), so a locked phrase never finds its item, for
 *   anyone.
 * - _wp2_access_locked: set while the item's rule is not open. Sitemaps and
 *   comment feeds leave such items out where the post type asks.
 * - _wp2_access_next: the next time a date could change the rule (embargo
 *   end, free window end, archive window, a term's free-until). An hourly
 *   pass refreshes items whose time has come.
 *
 * An item is refreshed when it is saved or its rule or terms change. When
 * something can change many items at once (the rules version is bumped), a
 * background pass refreshes every gated item in batches. Until it reaches an
 * item, search still filters each result in PHP (Surfaces), so nothing leaks
 * in the meantime; only sitemaps and comment feeds can lag behind.
 */
final class Index {
	public const SEARCH = '_wp2_access_search';
	public const LOCKED = '_wp2_access_locked';
	public const NEXT   = '_wp2_access_next';

	public const REINDEX = 'wp2_shopify_access_reindex';
	public const TIMED   = 'wp2_shopify_access_timed';
	public const CURSOR  = 'wp2_shopify_access_reindex_cursor';

	/** Bump to rebuild every item's index after an upgrade that changes what it holds. */
	private const VERSION     = 2;
	private const VERSION_KEY = 'wp2_shopify_access_index_version';

	private const BATCH = 200;

	/** @var array<int, true> */
	private static array $queue = [];

	public static function register(): void {
		add_action( 'save_post', [ self::class, 'enqueue' ], 99 );
		add_action( 'wp2_shopify_access_changed', [ self::class, 'changed' ] );
		add_action( 'shutdown', [ self::class, 'drain' ] );
		add_action( self::REINDEX, [ self::class, 'run_batch' ] );
		add_action( self::TIMED, [ self::class, 'run_timed' ] );
		add_action( 'admin_init', [ self::class, 'maintain' ] );
	}

	/**
	 * Keep the hourly pass scheduled, and build the index once on sites that
	 * had items before it existed.
	 */
	public static function maintain(): void {
		if ( ! wp_next_scheduled( self::TIMED ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::TIMED );
		}

		if ( (int) get_option( self::VERSION_KEY, 0 ) < self::VERSION ) {
			update_option( self::VERSION_KEY, self::VERSION, false );
			self::schedule_reindex();
		}
	}

	public static function enqueue( mixed $post_id ): void {
		if ( (int) $post_id > 0 ) {
			self::$queue[ (int) $post_id ] = true;
		}
	}

	/**
	 * @param int[] $post_ids Empty means "possibly every item".
	 */
	public static function changed( mixed $post_ids ): void {
		if ( ! is_array( $post_ids ) || [] === $post_ids ) {
			self::schedule_reindex();
			return;
		}

		array_map( [ self::class, 'enqueue' ], $post_ids );
	}

	/**
	 * Refresh items changed during this request, once each, after everything
	 * about them (content, meta, terms) has been written.
	 */
	public static function drain(): void {
		$ids         = array_keys( self::$queue );
		self::$queue = [];

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( $post instanceof \WP_Post ) {
				self::refresh( $post );
			}
		}
	}

	public static function schedule_reindex(): void {
		update_option( self::CURSOR, 0, false );

		if ( ! wp_next_scheduled( self::REINDEX ) ) {
			wp_schedule_single_event( time(), self::REINDEX );
		}
	}

	public static function pending(): bool {
		return false !== get_option( self::CURSOR, false );
	}

	public static function run_batch(): void {
		global $wpdb;

		$cursor = get_option( self::CURSOR, false );
		$types  = ItemRule::post_types();

		if ( false === $cursor || ! $types ) {
			delete_option( self::CURSOR );
			return;
		}

		$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ($in) AND post_status NOT IN ('auto-draft', 'trash', 'inherit') ORDER BY ID LIMIT %d", array_merge( [ (int) $cursor ], $types, [ self::BATCH ] ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders

		foreach ( $ids as $id ) {
			self::refresh( get_post( $id ) );
		}

		if ( count( $ids ) === self::BATCH ) {
			update_option( self::CURSOR, end( $ids ), false );
			wp_schedule_single_event( time() + 5, self::REINDEX );
		} else {
			delete_option( self::CURSOR );
		}
	}

	public static function run_timed(): void {
		$ids = get_posts(
			[
				'post_type'        => 'any',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => 500,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_query'       => [ [ 'key' => self::NEXT, 'value' => time(), 'compare' => '<=', 'type' => 'NUMERIC' ] ],
			]
		);

		foreach ( $ids as $id ) {
			self::refresh( get_post( $id ) );
		}
	}

	public static function refresh( mixed $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! ItemRule::gated( $post ) || in_array( $post->post_status, [ 'auto-draft', 'trash', 'inherit' ], true ) ) {
			self::set( $post->ID, self::SEARCH, null );
			self::set( $post->ID, self::LOCKED, null );
			self::set( $post->ID, self::NEXT, null );
			return;
		}

		Gatekeeper::flush();
		$now    = time();
		$locked = ! Gatekeeper::rule( $post )->open();
		$next   = self::next_change( Gatekeeper::context( $post ), $now );
		$search = null !== $next || Gatekeeper::protected( $post );

		if ( self::set( $post->ID, self::LOCKED, $locked ? '1' : null ) ) {
			/**
			 * An item became locked or open. Sitemap caches listen to this.
			 *
			 * @param int  $post_id
			 * @param bool $locked
			 */
			do_action( 'wp2_shopify_access_locked_changed', $post->ID, $locked );
		}
		self::set( $post->ID, self::NEXT, null === $next ? null : (string) $next );
		self::set( $post->ID, self::SEARCH, $search ? self::text( $post, $locked ) : null );
	}

	/**
	 * The soonest future time any date in the item's layers could change its
	 * rule, or null. Pure.
	 */
	public static function next_change( Context $c, int $now ): ?int {
		$times = [ $c->locked_until, $c->public_until ];

		if ( null !== $c->type_public_after && null !== $c->published ) {
			$times[] = $c->published + $c->type_public_after;
		}

		foreach ( $c->terms as $term ) {
			if ( TermRule::OPENS === $term->kind ) {
				$times[] = $term->opens_until;
			}
		}

		$future = array_filter( $times, static fn( $t ) => null !== $t && $t > $now );

		return $future ? min( $future ) : null;
	}

	/**
	 * The text anyone may read: the teaser of a locked item, or an open item
	 * with its membership gates' members-only areas taken out.
	 */
	public static function text( \WP_Post $post, bool $locked ): string {
		if ( $locked ) {
			return Gatekeeper::public_text( $post );
		}

		$blocks = Teaser::strip( parse_blocks( $post->post_content ), static fn( array $b ) => Gate::MEMBERS === ( $b['blockName'] ?? null ) || BlockAccess::members_only( $b ) );

		return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( serialize_blocks( $blocks ) ) ) ) );
	}

	/**
	 * @return bool Whether the stored value changed.
	 */
	private static function set( int $post_id, string $key, ?string $value ): bool {
		$current = get_post_meta( $post_id, $key, true );

		if ( null === $value ) {
			return '' !== $current && delete_post_meta( $post_id, $key );
		}

		return $current !== $value && (bool) update_post_meta( $post_id, $key, wp_slash( $value ) );
	}
}
