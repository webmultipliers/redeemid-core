<?php

namespace RedeemID\Access;

/**
 * Every surface besides the body that could show, match or confirm locked
 * text, and what it does:
 *
 * - Search: items whose rule is not open, may change on a date, or hold a
 *   membership gate are matched on their public text (Index), not on
 *   post_content, for every visitor. That covers the site search page, REST
 *   ?search= and /wp/v2/search, and keeps result counts honest. Each result
 *   is checked again in PHP, so an item the index has not reached yet still
 *   cannot be found by its locked text.
 * - Comments: where a post type keeps comments on locked items for readers
 *   (the default), visitors who cannot read the item see no comments, no
 *   count and no form, in templates, REST and comment feeds.
 * - Robots and sitemaps: where a post type keeps locked items out of search
 *   engines, they get noindex and leave the core, Yoast and Rank Math
 *   sitemaps together, so the two never disagree. Otherwise they stay listed.
 * - Schema: locked items say isAccessibleForFree false, pointing at the
 *   paywall container. Crawlers get the same locked view as any visitor.
 * - Headers: X-RedeemID-Access on singular items lets a CDN vary or bypass
 *   without parsing HTML; entitled and bypass views are Cache-Control: private.
 */
final class Surfaces {
	private const JOIN = 'redeemid';

	/** @var array<int, true> Queries whose search SQL was rewritten, by object ID. */
	private static array $rewritten = [];

	public static function register(): void {
		add_filter( 'posts_search', [ self::class, 'search_sql' ], 10, 2 );
		add_filter( 'posts_search_orderby', [ self::class, 'search_orderby' ], 10, 2 );
		add_filter( 'posts_join', [ self::class, 'search_join' ], 10, 2 );
		add_filter( 'the_posts', [ self::class, 'filter_search_results' ], 10, 2 );

		add_filter( 'comments_open', [ self::class, 'comments_open' ], 10, 2 );
		add_filter( 'pings_open', [ self::class, 'comments_open' ], 10, 2 );
		add_filter( 'get_comments_number', [ self::class, 'comments_number' ], 10, 2 );
		add_filter( 'the_comments', [ self::class, 'filter_comments' ] );
		add_filter( 'comment_feed_where', [ self::class, 'comment_feed_where' ] );

		add_filter( 'wp_robots', [ self::class, 'robots' ] );
		add_filter( 'wp_sitemaps_posts_query_args', [ self::class, 'sitemap_args' ], 10, 2 );

		add_action( 'template_redirect', [ self::class, 'send_headers' ], 1 );
		add_action( 'wp_head', [ self::class, 'schema' ], 30 );
		add_action( 'redeemid_access_levels_changed', static fn() => do_action( 'redeemid_access_changed', [] ) );
	}

	/**
	 * Front-end and REST searches. wp-admin list screens are for editors
	 * managing content, not a public surface.
	 */
	private static function public_search( mixed $query ): bool {
		return $query instanceof \WP_Query && '' !== (string) $query->get( 's' ) && ! $query->get( 'suppress_filters' ) && ! ( is_admin() && ! wp_doing_ajax() );
	}

	public static function search_sql( mixed $search, mixed $query ): mixed {
		global $wpdb;

		if ( ! is_string( $search ) || '' === $search || ! self::public_search( $query ) ) {
			return $search;
		}

		self::$rewritten[ spl_object_id( $query ) ] = true;

		return self::public_column( $search, $wpdb->posts );
	}

	public static function search_orderby( mixed $orderby, mixed $query ): mixed {
		global $wpdb;

		return is_string( $orderby ) && isset( self::$rewritten[ spl_object_id( $query ) ] ) ? self::public_column( $orderby, $wpdb->posts ) : $orderby;
	}

	public static function search_join( mixed $join, mixed $query ): mixed {
		global $wpdb;

		if ( ! is_string( $join ) || ! isset( self::$rewritten[ spl_object_id( $query ) ] ) ) {
			return $join;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- self::JOIN is a constant alias.
		return $join . $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS " . self::JOIN . " ON ( " . self::JOIN . ".post_id = {$wpdb->posts}.ID AND " . self::JOIN . '.meta_key = %s )', Index::SEARCH );
	}

	/**
	 * Point every post_content comparison in a search clause at the item's
	 * public text when it has one. Pure.
	 */
	public static function public_column( string $sql, string $posts_table ): string {
		return str_replace( "{$posts_table}.post_content", 'COALESCE(' . self::JOIN . ".meta_value, {$posts_table}.post_content)", $sql );
	}

	/**
	 * The PHP check behind the SQL: a protected result stays only when every
	 * search term is in its title, excerpt or public text.
	 *
	 * @param \WP_Post[]|int[] $posts
	 */
	public static function filter_search_results( mixed $posts, mixed $query ): mixed {
		if ( is_object( $query ) ) {
			unset( self::$rewritten[ spl_object_id( $query ) ] );
		}

		if ( ! is_array( $posts ) || ! self::public_search( $query ) ) {
			return $posts;
		}

		$terms = array_values( array_filter( (array) $query->get( 'search_terms' ), static fn( $t ) => is_string( $t ) && '' !== $t && ! str_starts_with( $t, '-' ) ) );
		$kept  = [];

		foreach ( $posts as $item ) {
			$post = get_post( $item );

			if ( $post instanceof \WP_Post && self::protected( $post ) && ! self::matches( self::public_haystack( $post ), $terms ) ) {
				continue;
			}

			$kept[] = $item;
		}

		$removed = count( $posts ) - count( $kept );

		if ( $removed > 0 ) {
			// Counts must not confirm the hidden matches either.
			$query->found_posts   = max( 0, (int) $query->found_posts - $removed );
			$query->max_num_pages = $query->get( 'posts_per_page' ) > 0 ? (int) ceil( $query->found_posts / (int) $query->get( 'posts_per_page' ) ) : 1;
		}

		return $kept;
	}

	private static function protected( \WP_Post $post ): bool {
		return Gatekeeper::protected( $post );
	}

	private static function public_haystack( \WP_Post $post ): string {
		$text = get_post_meta( $post->ID, Index::SEARCH, true );
		$text = is_string( $text ) && '' !== $text ? $text : Index::text( $post, ! Gatekeeper::rule( $post )->open() );

		return $post->post_title . ' ' . $post->post_excerpt . ' ' . $text;
	}

	/**
	 * @param string[] $terms Every term must appear, as in core search. Pure.
	 */
	public static function matches( string $haystack, array $terms ): bool {
		foreach ( $terms as $term ) {
			if ( false === mb_stripos( $haystack, $term ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Are this item's comments hidden from the current visitor?
	 */
	public static function comments_hidden( mixed $post ): bool {
		$post = get_post( $post );

		return $post instanceof \WP_Post
			&& ItemRule::gated( $post )
			&& Rules::COMMENTS_MEMBERS === RuleStore::rules()->type( $post->post_type )['comments']
			&& Gatekeeper::locked( $post )
			&& ! Viewer::bypassing( $post );
	}

	public static function comments_open( mixed $open, mixed $post_id ): mixed {
		return $open && self::comments_hidden( $post_id ) ? false : $open;
	}

	public static function comments_number( mixed $count, mixed $post_id ): mixed {
		return self::comments_hidden( $post_id ) ? 0 : $count;
	}

	/**
	 * Comment queries: templates, the Comments and Latest Comments blocks,
	 * widgets and REST /wp/v2/comments.
	 *
	 * @param \WP_Comment[]|int[] $comments
	 */
	public static function filter_comments( mixed $comments ): mixed {
		if ( ! is_array( $comments ) || is_admin() && ! wp_doing_ajax() ) {
			return $comments;
		}

		return array_values(
			array_filter(
				$comments,
				static function ( $comment ) {
					$comment = get_comment( $comment );

					return ! $comment instanceof \WP_Comment || ! self::comments_hidden( (int) $comment->comment_post_ID );
				}
			)
		);
	}

	/**
	 * Comment feeds are shared and cached, so they leave out comments on every
	 * locked item where the post type keeps them for readers, whoever asks.
	 */
	public static function comment_feed_where( mixed $where ): mixed {
		global $wpdb;

		$types = array_values( array_filter( ItemRule::post_types(), static fn( $t ) => Rules::COMMENTS_MEMBERS === RuleStore::rules()->type( $t )['comments'] ) );

		if ( ! is_string( $where ) || ! $types ) {
			return $where;
		}

		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of %s placeholders.
		return $where . $wpdb->prepare( " AND {$wpdb->comments}.comment_post_ID NOT IN ( SELECT ridl.post_id FROM {$wpdb->postmeta} AS ridl INNER JOIN {$wpdb->posts} AS ridp ON ridp.ID = ridl.post_id WHERE ridl.meta_key = %s AND ridp.post_type IN ($in) )", array_merge( [ Index::LOCKED ], $types ) );
	}

	/**
	 * Should search engines leave this item out? Decided by the rule alone, not
	 * the visitor: crawlers are locked visitors.
	 */
	public static function noindex( mixed $post ): bool {
		$post = get_post( $post );

		return $post instanceof \WP_Post && ItemRule::gated( $post ) && RuleStore::rules()->type( $post->post_type )['noindex'] && ! Gatekeeper::rule( $post )->open();
	}

	/**
	 * @param array<string, bool|string> $robots
	 */
	public static function robots( mixed $robots ): mixed {
		if ( is_array( $robots ) && is_singular() && self::noindex( get_queried_object() ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}

		return $robots;
	}

	/**
	 * @return string[] Post types whose locked items leave sitemaps.
	 */
	public static function noindex_types(): array {
		return array_values( array_filter( ItemRule::post_types(), static fn( $t ) => RuleStore::rules()->type( $t )['noindex'] ) );
	}

	public static function sitemap_args( mixed $args, mixed $post_type ): mixed {
		if ( ! is_array( $args ) || ! in_array( $post_type, self::noindex_types(), true ) ) {
			return $args;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery
		$args['meta_query']   = (array) ( $args['meta_query'] ?? [] );
		$args['meta_query'][] = [ 'key' => Index::LOCKED, 'compare' => 'NOT EXISTS' ];

		return $args;
	}

	/**
	 * open: no rule applies. locked: the visitor sees the teaser. granted: the
	 * visitor is entitled. bypass: an editor reading the whole item. The last
	 * two must not be shared.
	 */
	public static function state( \WP_Post $post ): string {
		if ( Gatekeeper::rule( $post )->open() ) {
			return 'open';
		}

		if ( Gatekeeper::verdict( $post )->granted() ) {
			return 'granted';
		}

		return Viewer::bypassing( $post ) ? 'bypass' : 'locked';
	}

	public static function send_headers(): void {
		if ( ! is_singular() || headers_sent() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post || post_password_required( $post ) ) {
			return;
		}

		$state = self::state( $post );
		header( 'X-RedeemID-Access: ' . $state );

		if ( 'granted' === $state || 'bypass' === $state || null !== Viewer::preview_as() ) {
			header( 'Cache-Control: private, no-store, max-age=0' );
		}
	}

	/**
	 * Google's paywalled-content markup on locked items. Return false from the
	 * redeemid_paywall_schema filter when an SEO plugin already adds it.
	 */
	public static function schema(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post || Gatekeeper::rule( $post )->open() ) {
			return;
		}

		$data = [
			'@context'            => 'https://schema.org',
			'@type'               => 'post' === $post->post_type ? 'Article' : 'CreativeWork',
			'headline'            => wp_strip_all_tags( get_the_title( $post ) ),
			'url'                 => get_permalink( $post ),
			'isAccessibleForFree' => false,
			'hasPart'             => [
				'@type'               => 'WebPageElement',
				'isAccessibleForFree' => false,
				'cssSelector'         => '.redeemid-paywall',
			],
		];

		/**
		 * Filter the paywall JSON-LD for a locked item. Return false to print none.
		 */
		$data = apply_filters( 'redeemid_paywall_schema', $data, $post );

		if ( is_array( $data ) ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . "</script>\n";
		}
	}
}
