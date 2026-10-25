<?php

namespace RedeemID\Admin;

use RedeemID\Access\Checker;
use RedeemID\Access\LevelStore;
use RedeemID\Settings;
use RedeemID\Sync\Mirror;

/**
 * The Customers list's filters, shared by the table and the CSV export so an
 * export holds exactly the customers on screen.
 *
 * Search matches WordPress's own fields and the name and email Shopify sent,
 * since a customer's Shopify email can differ from their WordPress one. The
 * level filter checks candidates against the level's real rule, so included
 * levels and "all of these tags" rules count the way gates count them.
 */
final class CustomerQuery {
	/** Most user IDs a search or level filter collects before handing over to WP_User_Query. */
	private const CAP = 5000;

	/**
	 * @return array{s: string, state: string, level: string, orderby: string, order: string}
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification -- list display and export filters only.
		return [
			's'       => sanitize_text_field( wp_unslash( $_REQUEST['s'] ?? '' ) ),
			'state'   => sanitize_key( $_REQUEST['state'] ?? '' ),
			'level'   => sanitize_key( $_REQUEST['level'] ?? '' ),
			'orderby' => sanitize_key( $_REQUEST['orderby'] ?? '' ),
			'order'   => 'asc' === sanitize_key( wp_unslash( $_REQUEST['order'] ?? '' ) ) ? 'ASC' : 'DESC',
		];
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * WP_User_Query arguments for $f, without paging.
	 *
	 * @param array<string, string> $f From filters().
	 * @return array<string, mixed>
	 */
	public static function args( array $f ): array {
		$meta = [ [ 'key' => Mirror::NUMERIC, 'compare' => 'EXISTS' ] ];

		if ( 'disabled' === $f['state'] ) {
			$meta[] = [ 'key' => Mirror::STATUS, 'value' => 'disabled' ];
		} elseif ( 'failed' === $f['state'] ) {
			$meta[] = [ 'key' => Mirror::SYNC_FAILED, 'compare' => 'EXISTS' ];
		}

		$args = [
			'meta_query' => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'    => 'ID',
			'order'      => $f['order'],
		];

		if ( 'display_name' === $f['orderby'] ) {
			$args['orderby'] = 'display_name';
		} elseif ( 'last_sync' === $f['orderby'] || 'linked' === $f['orderby'] ) {
			$args['meta_key'] = 'last_sync' === $f['orderby'] ? Mirror::LAST_SYNC : Mirror::LINKED_AT; // phpcs:ignore WordPress.DB.SlowDBQuery
			$args['orderby']  = 'meta_value_num';
		}

		$include = null;

		if ( '' !== $f['s'] ) {
			if ( ctype_digit( $f['s'] ) && strlen( $f['s'] ) > 6 ) {
				// A Shopify customer ID.
				$args['meta_query'][] = [ 'key' => Mirror::NUMERIC, 'value' => $f['s'] ];
			} else {
				$include = self::search_ids( $f['s'] );
			}
		}

		if ( '' !== $f['level'] && LevelStore::levels()->has( $f['level'] ) ) {
			$ids     = self::level_ids( $f['level'] );
			$include = null === $include ? $ids : array_values( array_intersect( $include, $ids ) );
		}

		if ( null !== $include ) {
			// An empty include means "everyone" to WP_User_Query; 0 matches no one.
			$args['include'] = $include ?: [ 0 ];
		}

		return $args;
	}

	/**
	 * Users whose WordPress login, email or name, or Shopify email or name, contain $search.
	 *
	 * @return int[]
	 */
	private static function search_ids( string $search ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$ids  = ( new \WP_User_Query(
			[
				'search'         => '*' . $search . '*',
				'search_columns' => [ 'user_login', 'user_email', 'display_name', 'user_nicename' ],
				'fields'         => 'ID',
				'number'         => self::CAP,
				'count_total'    => false,
			]
		) )->get_results();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin search over three mirror keys.
		$meta = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s, %s) AND meta_value LIKE %s LIMIT %d", Mirror::EMAIL, Mirror::FIRST_NAME, Mirror::LAST_NAME, $like, self::CAP ) );

		return array_values( array_unique( array_map( 'intval', array_merge( (array) $ids, (array) $meta ) ) ) );
	}

	/**
	 * Linked users who hold $slug, directly or through a level that includes it.
	 *
	 * @return int[]
	 */
	public static function level_ids( string $slug ): array {
		global $wpdb;

		$levels = LevelStore::levels();
		$tags   = [];

		foreach ( $levels->grantors( $slug ) as $grantor ) {
			$tags = array_merge( $tags, $levels->get( $grantor )?->tags() ?? [] );
		}

		$tags = array_values( array_unique( array_filter( array_map( 'strval', $tags ), 'strlen' ) ) );

		if ( ! $tags ) {
			return [];
		}

		// Narrow by the serialized tag list first, then decide each candidate properly.
		$where = implode( ' OR ', array_fill( 0, count( $tags ), 'meta_value LIKE %s' ) );
		$likes = array_map( static fn( string $tag ): string => '%"' . $wpdb->esc_like( $tag ) . '"%', $tags );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where holds only placeholders.
		$candidates = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND ({$where}) LIMIT %d", Mirror::TAGS, ...array_merge( $likes, [ self::CAP ] ) ) );
		$checker    = new Checker( $levels, Settings::evaluator() );
		$now        = time();

		return array_values(
			array_filter(
				array_map( 'intval', (array) $candidates ),
				static fn( int $id ): bool => $checker->holds( $slug, Mirror::member( $id ), $now )->granted
			)
		);
	}
}
