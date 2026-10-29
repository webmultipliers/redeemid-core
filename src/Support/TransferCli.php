<?php

namespace RedeemID\Support;

/**
 * Settings export and import from the command line.
 *
 *     wp redeemid settings export [--file=<path>]
 *     wp redeemid settings import <file> [--sections=<list>] [--dry-run]
 */
final class TransferCli {
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( \WP_CLI::class ) ) {
			\WP_CLI::add_command( 'redeemid settings', self::class );
		}
	}

	/**
	 * Export levels, content rules, term rules, feature mappings and
	 * membership settings as JSON. Never customers, credentials or tokens.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Write to this file instead of standard output.
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function export( array $args, array $assoc ): void {
		$json = (string) wp_json_encode( Transfer::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( isset( $assoc['file'] ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents, WordPress.WP.AlternativeFunctions -- a CLI export the operator asked for.
			if ( false === file_put_contents( (string) $assoc['file'], $json . "\n" ) ) {
				\WP_CLI::error( 'Could not write the file.' );
			}

			\WP_CLI::success( 'Settings written to ' . $assoc['file'] . '.' );
			return;
		}

		\WP_CLI::line( $json );
	}

	/**
	 * Import a settings file. Each imported section replaces this site's.
	 * Run with --dry-run first to see what would change.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : A file from `wp redeemid settings export` or the Access screen.
	 *
	 * [--sections=<list>]
	 * : Comma-separated: levels, rules, term_rules, features, settings. Default: every section in the file.
	 *
	 * [--dry-run]
	 * : Show what would change, and change nothing.
	 *
	 * @param string[]             $args
	 * @param array<string, mixed> $assoc
	 */
	public function import( array $args, array $assoc ): void {
		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown, WordPress.WP.AlternativeFunctions -- a local file the operator named.
		$raw      = is_readable( (string) ( $args[0] ?? '' ) ) ? (string) file_get_contents( (string) $args[0] ) : '';
		$data     = json_decode( $raw, true );
		$problems = Transfer::validate( $data );

		if ( $problems ) {
			\WP_CLI::error( 'Cannot import: ' . implode( ', ', $problems ) . '.' );
		}

		$sections = Transfer::rebase( Transfer::normalize( $data ), (string) ( $data['site'] ?? '' ), home_url( '/' ) );
		$only     = isset( $assoc['sections'] ) ? array_map( 'trim', explode( ',', (string) $assoc['sections'] ) ) : array_keys( $sections );
		$unknown  = array_diff( $only, Transfer::SECTIONS );

		if ( $unknown ) {
			\WP_CLI::error( 'Unknown section: ' . implode( ', ', $unknown ) . '.' );
		}

		$rows = [];

		foreach ( Transfer::diff( Transfer::current(), array_intersect_key( $sections, array_flip( $only ) ) ) as $section => $change ) {
			$rows[] = [
				'section' => $section,
				'added'   => implode( ',', $change['added'] ),
				'changed' => implode( ',', $change['changed'] ),
				'removed' => implode( ',', $change['removed'] ),
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'section', 'added', 'changed', 'removed' ] );

		if ( ! empty( $assoc['dry-run'] ) ) {
			\WP_CLI::success( 'Dry run: nothing changed.' );
			return;
		}

		$report = Transfer::apply( $sections, $only );

		foreach ( $report['skipped_terms'] as $term ) {
			\WP_CLI::warning( 'Skipped term rule, no such term here: ' . $term );
		}

		foreach ( $report['managed'] as $section ) {
			\WP_CLI::warning( 'Imported ' . $section . ', but code on this site manages it, so the code still wins.' );
		}

		\WP_CLI::success( 'Imported: ' . ( implode( ', ', $report['written'] ) ?: 'nothing' ) . '.' );
	}
}
