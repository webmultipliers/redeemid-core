<?php

namespace WP2_Shopify\Access;

/**
 * Access rules from the command line.
 *
 *     wp wp2-shopify access explain <post> [--as=<viewer>] [--tags=<tags>] [--user=<id>] [--format=<format>]
 *     wp wp2-shopify access features [--as=<viewer>] [--tags=<tags>] [--user=<id>] [--format=<format>]
 *     wp wp2-shopify access recompute
 */
final class Cli {
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( \WP_CLI::class ) ) {
			\WP_CLI::add_command( 'wp2-shopify access', self::class );
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
