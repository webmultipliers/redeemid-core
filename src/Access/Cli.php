<?php

namespace RedeemID\Access;

use RedeemID\Sync\LevelHistory;

/**
 * Access rules from the command line.
 *
 *     wp redeemid access explain <post> [--as=<viewer>] [--tags=<tags>] [--user=<id>] [--format=<format>]
 *     wp redeemid access features [--as=<viewer>] [--tags=<tags>] [--user=<id>] [--format=<format>]
 *     wp redeemid access recompute
 *     wp redeemid access changes [--since=<date>] [--level=<slug>] [--format=<format>]
 *     wp redeemid access export [--post_type=<type>] [--format=<format>]
 */
final class Cli {
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( \WP_CLI::class ) ) {
			\WP_CLI::add_command( 'redeemid access', self::class );
		}
	}

	/**
	 * Explain how an item resolves for one viewer.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Post ID.
	 *
	 * [--as=<viewer>]
	 * : guest, signed-in, a level slug, tags or user.
	 * ---
	 * default: guest
	 * ---
	 *
	 * [--tags=<tags>]
	 * : Comma-separated tags, with --as=tags.
	 *
	 * [--user=<id>]
	 * : A user ID, with --as=user. Uses their synced record.
	 *
	 * [--format=<format>]
	 * : text or json.
	 * ---
	 * default: text
	 * ---
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function explain( array $args, array $assoc ): void {
		$post = get_post( (int) ( $args[0] ?? 0 ) );

		if ( ! $post instanceof \WP_Post ) {
			\WP_CLI::error( 'No such post.' );
		}

		$as    = (string) ( $assoc['as'] ?? Explain::AS_GUEST );
		$user  = (int) ( $assoc['user'] ?? 0 );
		$trace = Explain::trace( $post, Explain::member( $as, explode( ',', (string) ( $assoc['tags'] ?? '' ) ), $user ) );

		if ( 'json' === ( $assoc['format'] ?? 'text' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		foreach ( self::lines( $trace ) as $line ) {
			\WP_CLI::line( $line );
		}
	}

	/**
	 * Which features a viewer holds, and which route grants each one.
	 *
	 * ## OPTIONS
	 *
	 * [--as=<viewer>]
	 * : guest, signed-in, a level slug, tags or user.
	 * ---
	 * default: guest
	 * ---
	 *
	 * [--tags=<tags>]
	 * : Comma-separated tags, with --as=tags.
	 *
	 * [--user=<id>]
	 * : A user ID, with --as=user. Uses their synced record.
	 *
	 * [--format=<format>]
	 * : text or json.
	 * ---
	 * default: text
	 * ---
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function features( array $args, array $assoc ): void {
		$as   = (string) ( $assoc['as'] ?? Explain::AS_GUEST );
		$user = (int) ( $assoc['user'] ?? 0 );
		$rows = Explain::features( Explain::member( $as, explode( ',', (string) ( $assoc['tags'] ?? '' ) ), $user ), Explain::AS_USER === $as ? $user : 0 );

		if ( 'json' === ( $assoc['format'] ?? 'text' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		foreach ( self::feature_lines( $rows ) as $line ) {
			\WP_CLI::line( $line );
		}
	}

	/**
	 * Feature rows as plain lines. Pure.
	 *
	 * @return string[]
	 */
	public static function feature_lines( array $rows ): array {
		$lines = [];

		foreach ( $rows as $row ) {
			$lines[] = sprintf( '%s (%s): %s', $row['label'], $row['slug'], $row['granted'] ? 'granted, from ' . implode( ' and ', $row['routes'] ) : 'not granted (' . $row['reason'] . ')' );

			foreach ( $row['trace'] as $i => $route ) {
				$lines[] = sprintf( '  %d. %s: %s [%s]', $i + 1, $route['when'] ?: $route['label'], $route['passed'] ? 'passes' : 'fails', implode( '; ', $route['tests'] ) );
			}
		}

		return $lines ?: [ 'No features are registered.' ];
	}

	/**
	 * Rebuild every item's cached layers and public-text index in the background.
	 */
	public function recompute(): void {
		RuleStore::bump();
		Index::schedule_reindex();
		\WP_CLI::success( 'Rules version bumped and a reindex scheduled. Run `wp cron event run ' . Index::REINDEX . '` to start it now.' );
	}

	/**
	 * Who joined or left a level since a date, from each member's level history.
	 *
	 * History is display-only and is cleared when a customer is unlinked, so
	 * unlinked customers drop out of this list.
	 *
	 * ## OPTIONS
	 *
	 * [--since=<date>]
	 * : Anything strtotime() reads, such as 2026-10-01 or "7 days ago".
	 * ---
	 * default: 7 days ago
	 * ---
	 *
	 * [--level=<slug>]
	 * : Only this level.
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function changes( array $args, array $assoc ): void {
		$cutoff = strtotime( (string) ( $assoc['since'] ?? '7 days ago' ) );

		if ( false === $cutoff ) {
			\WP_CLI::error( 'Could not read --since.' );
		}

		$rows = array_map(
			static fn( array $row ) => [
				'date'   => wp_date( 'Y-m-d H:i', $row['at'] ),
				'change' => $row['change'],
				'level'  => $row['label'],
				'user'   => $row['user'],
				'name'   => $row['name'],
				'email'  => $row['email'],
			],
			LevelHistory::report( (int) $cutoff, (string) ( $assoc['level'] ?? '' ) )
		);

		if ( ! $rows && 'table' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::line( 'Nobody joined or left a level since ' . wp_date( 'Y-m-d H:i', (int) $cutoff ) . '.' );
			return;
		}

		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $rows, [ 'date', 'change', 'level', 'user', 'name', 'email' ] );
	}

	/**
	 * Every gated item's effective rule, for an audit or a spreadsheet.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<type>]
	 * : Only this post type.
	 *
	 * [--format=<format>]
	 * : csv, json or table.
	 * ---
	 * default: csv
	 * ---
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function export( array $args, array $assoc ): void {
		$types = ItemRule::post_types();

		if ( isset( $assoc['post_type'] ) ) {
			if ( ! in_array( $assoc['post_type'], $types, true ) ) {
				\WP_CLI::error( 'That post type is not gated.' );
			}

			$types = [ (string) $assoc['post_type'] ];
		}

		$levels = LevelStore::levels();
		$rows   = [];
		$page   = 1;

		do {
			$ids = get_posts(
				[
					'post_type'      => $types,
					'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private' ],
					'posts_per_page' => 100,
					'paged'          => $page++,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
				]
			);

			foreach ( $ids as $id ) {
				$post = get_post( $id );

				if ( ! $post instanceof \WP_Post ) {
					continue;
				}

				$rule = Gatekeeper::rule( $post );
				$item = ItemRule::get( $post );

				$rows[] = [
					'id'           => $post->ID,
					'type'         => $post->post_type,
					'status'       => $post->post_status,
					'title'        => get_the_title( $post ),
					'readers'      => $rule->open() ? 'everyone' : $rule->readers( $levels ),
					'set_by'       => $rule->source,
					'item_mode'    => $item['mode'],
					'item_levels'  => implode( ',', $item['levels'] ),
					'locked_until' => $item['locked_until'] ? wp_date( 'Y-m-d H:i', (int) $item['locked_until'] ) : '',
					'public_until' => $item['public_until'] ? wp_date( 'Y-m-d H:i', (int) $item['public_until'] ) : '',
					'drip_days'    => (int) $item['drip_days'],
				];
			}

			wp_cache_flush_runtime();
		} while ( count( $ids ) === 100 );

		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'csv' ), $rows, [ 'id', 'type', 'status', 'title', 'readers', 'set_by', 'item_mode', 'item_levels', 'locked_until', 'public_until', 'drip_days' ] );
	}

	/**
	 * The trace as plain lines. Pure.
	 *
	 * @return string[]
	 */
	public static function lines( array $t ): array {
		$lines = [ sprintf( '%s (#%d, %s, %s)%s', $t['post']['title'], $t['post']['id'], $t['post']['type'], $t['post']['status'], $t['gated'] ? '' : ' - post type not gated' ), '' ];

		foreach ( $t['layers'] as $i => $layer ) {
			$lines[] = sprintf( '%d. %s', $i + 1, $layer );
		}

		$lines[] = '';
		$lines[] = 'Rule:    ' . $t['rule'] . ( $t['meter_eligible'] ? ' [meter-eligible]' : '' );
		$lines[] = 'Next:    ' . ( $t['next_change'] ? gmdate( 'Y-m-d H:i', (int) $t['next_change'] ) . ' UTC' : 'no date changes it' );
		$lines[] = 'Viewer:  ' . ( $t['granted'] ? 'reads it' : 'locked (' . $t['reason'] . ( $t['offer'] ? ', wall offers ' . $t['offer'] : '' ) . ')' );
		$lines[] = 'Sees:    ' . $t['sees'];

		foreach ( $t['blocks'] as $b ) {
			$lines[] = sprintf( 'Block:   %s, %s%s%s: %s', $b['name'], $b['show'], $b['levels'] ? ' ' . implode( ',', $b['levels'] ) : '', '' !== ( $b['feature'] ?? '' ) ? ', feature ' . $b['feature'] . ( BlockAccess::LACKS === $b['when'] ? ' (not held)' : ' (held)' ) : '', $b['visible'] ? 'shown' : 'hidden' );
		}

		foreach ( $t['surfaces'] as $s ) {
			$lines[] = 'Surface: ' . $s;
		}

		return $lines;
	}
}
