<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Access\Levels;
use WP2_Shopify\Access\LevelStore;
use WP2_Shopify\Entitlement\Rule;
use WP2_Shopify\Shopify\Admin as AdminApi;
use WP2_Shopify\Sync\BulkResync;

/**
 * How many members have activated their WordPress access: for each level, the
 * customers Shopify tags for it against the ones who have signed in here.
 *
 * Shopify's side is one customersCount call per level, all in one request,
 * cached for an hour. WordPress's side is counted from the mirror.
 */
final class Activation {
	private const CACHE = 'wp2_shopify_activation';
	private const TTL   = 3600;

	/**
	 * Shopify customer search syntax for "holds $slug": each group of each
	 * level that grants it, OR'd. Null when no group names a tag. Pure.
	 */
	public static function search_query( Levels $levels, string $slug ): ?string {
		$groups = [];

		foreach ( $levels->grantors( $slug ) as $grantor ) {
			foreach ( $levels->get( $grantor )?->groups ?? [] as $group ) {
				$terms = array_map( static fn( string $tag ): string => 'tag:"' . addcslashes( $tag, '"\\' ) . '"', array_values( array_unique( $group->required_tags ) ) );

				if ( $terms ) {
					$groups[] = '(' . implode( Rule::MATCH_ALL === $group->match_mode ? ' AND ' : ' OR ', $terms ) . ')';
				}
			}
		}

		return $groups ? implode( ' OR ', array_unique( $groups ) ) : null;
	}

	/**
	 * @return array{at: int, shopify_total: ?int, linked_total: int, levels: array<string, array{label: string, shopify: ?int, linked: int}>, error: string}
	 */
	public static function stats( bool $refresh = false ): array {
		$cached = $refresh ? false : get_transient( self::CACHE );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$levels  = LevelStore::levels();
		$queries = [ 'total' => '' ];

		foreach ( $levels->all() as $slug => $level ) {
			$query = $level->builtin() ? null : self::search_query( $levels, (string) $slug );

			if ( null !== $query ) {
				$queries[ (string) $slug ] = $query;
			}
		}

		$counts = AdminApi::available() ? AdminApi::count_customers( $queries ) : new \WP_Error( 'shopify_admin_unconfigured', '' );
		$stats  = [
			'at'            => time(),
			'shopify_total' => is_array( $counts ) ? ( $counts['total'] ?? null ) : null,
			'linked_total'  => BulkResync::linked_count(),
			'levels'        => [],
			'error'         => is_wp_error( $counts ) ? $counts->get_error_code() : '',
		];

		foreach ( $queries as $slug => $query ) {
			if ( 'total' !== $slug ) {
				$stats['levels'][ $slug ] = [
					'label'   => $levels->get( $slug )?->label ?? $slug,
					'shopify' => is_array( $counts ) ? ( $counts[ $slug ] ?? null ) : null,
					'linked'  => count( CustomerQuery::level_ids( $slug ) ),
				];
			}
		}

		// Don't keep a failed call for an hour.
		set_transient( self::CACHE, $stats, $stats['error'] ? 60 : self::TTL );

		return $stats;
	}

	public static function forget(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * Share of $part in $whole, as a whole percentage, or null when there is no whole. Pure.
	 */
	public static function percent( int $part, ?int $whole ): ?int {
		return $whole ? (int) min( 100, round( 100 * $part / $whole ) ) : null;
	}
}
