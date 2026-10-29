<?php

namespace RedeemID\Files;

use RedeemID\Admin\Menu;
use RedeemID\Support\Health;

/**
 * Is the protected folder really closed? A small probe file with a random
 * token sits in it; the check fetches it straight from the web server. If
 * the token comes back, anyone with a file's address can download it.
 *
 * The result is kept for twelve hours. Only Site Health runs the check; the
 * Health block shows the last result and never makes a request.
 */
final class Probe {
	private const FILE   = 'probe.txt';
	private const OPTION = 'redeemid_files_probe';
	private const RESULT = 'redeemid_files_probe_result';

	public const CLOSED  = 'closed';
	public const OPEN    = 'open';
	public const UNKNOWN = 'unknown';

	public static function ensure_file( string $dir ): void {
		$token = (string) get_option( self::OPTION, '' );

		if ( '' === $token ) {
			$token = wp_generate_password( 32, false );
			update_option( self::OPTION, $token, false );
		}

		if ( ! file_exists( $dir . '/' . self::FILE ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents, WordPress.WP.AlternativeFunctions -- a fixed probe file in the plugin's own folder.
			file_put_contents( $dir . '/' . self::FILE, $token );
		}
	}

	/**
	 * Read a probe response. Pure.
	 */
	public static function verdict( int $status, string $body, string $token ): string {
		if ( 0 === $status ) {
			return self::UNKNOWN;
		}

		return 200 === $status && '' !== $token && str_contains( $body, $token ) ? self::OPEN : self::CLOSED;
	}

	/**
	 * Run the check (at most every twelve hours unless $fresh), or null
	 * when no file has been protected yet.
	 */
	public static function check( bool $fresh = false ): ?string {
		if ( ! is_dir( ProtectedFiles::dir() ) ) {
			return null;
		}

		$cached = get_transient( self::RESULT );

		if ( ! $fresh && is_string( $cached ) ) {
			return $cached;
		}

		$uploads = wp_upload_dir( null, false );
		$url     = trailingslashit( (string) $uploads['baseurl'] ) . ProtectedFiles::DIR . '/' . self::FILE;
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- one loopback request, at most twice a day.
		$response = wp_remote_get( $url, [ 'timeout' => 3, 'redirection' => 0, 'sslverify' => false ] );
		$result   = is_wp_error( $response )
			? self::UNKNOWN
			: self::verdict( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ), (string) get_option( self::OPTION, '' ) );

		set_transient( self::RESULT, $result, 12 * HOUR_IN_SECONDS );

		return $result;
	}

	public static function site_health( array $tests ): array {
		$tests['direct']['redeemid_protected_files'] = [
			'label' => __( 'Protected files can\'t be downloaded directly', 'redeemid' ),
			'test'  => [ self::class, 'site_health_result' ],
		];

		return $tests;
	}

	public static function site_health_result(): array {
		$result = self::check( true );
		$base   = [
			'badge' => [ 'label' => __( 'RedeemID', 'redeemid' ), 'color' => 'blue' ],
			'test'  => 'redeemid_protected_files',
		];

		return $base + match ( $result ) {
			self::OPEN    => [
				'label'       => __( 'Protected files can be downloaded by anyone with the address', 'redeemid' ),
				'status'      => 'critical',
				'description' => '<p>' . esc_html__( 'The web server serves files in uploads/redeemid-protected/ directly, so the membership check is skipped. On nginx, add the location block from the RedeemID protected files guide to the site\'s server block and reload nginx. On Apache, allow the folder\'s .htaccess to apply (AllowOverride).', 'redeemid' ) . '</p>',
				'actions'     => sprintf( '<p><a href="%s" target="_blank" rel="noopener">%s</a></p>', esc_url( 'https://github.com/webmultipliers/redeemid-core/blob/main/docs/protected-files.md' ), esc_html__( 'Read the protected files guide', 'redeemid' ) ),
			],
			self::UNKNOWN => [
				'label'       => __( 'Couldn\'t check whether protected files are closed', 'redeemid' ),
				'status'      => 'recommended',
				'description' => '<p>' . esc_html__( 'The site couldn\'t request its own uploads folder. Open the probe file from uploads/redeemid-protected/ in a private window: you should get an error, not a line of text.', 'redeemid' ) . '</p>',
				'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( Menu::url( Menu::SLUG ) ), esc_html__( 'Open RedeemID', 'redeemid' ) ),
			],
			default       => [
				'label'       => __( 'Protected files can\'t be downloaded directly', 'redeemid' ),
				'status'      => 'good',
				'description' => '<p>' . esc_html( null === $result ? __( 'No file is protected yet.', 'redeemid' ) : __( 'The web server refuses direct requests to the protected folder, so every download goes through the membership check.', 'redeemid' ) ) . '</p>',
			],
		};
	}

	/**
	 * @param array<int, array<string, string>> $checks
	 */
	public static function health_row( mixed $checks ): array {
		$checks = is_array( $checks ) ? $checks : [];
		$result = is_dir( ProtectedFiles::dir() ) ? get_transient( self::RESULT ) : null;

		if ( null === $result ) {
			return $checks;
		}

		$checks[] = [
			'label'  => __( 'Protected files', 'redeemid' ),
			'state'  => match ( $result ) {
				self::OPEN   => Health::FAIL,
				self::CLOSED => Health::OK,
				default      => Health::WARN,
			},
			'detail' => match ( $result ) {
				self::OPEN   => __( 'served directly by the web server: add the server rule', 'redeemid' ),
				self::CLOSED => __( 'direct requests refused', 'redeemid' ),
				default      => __( 'not checked yet: open Site Health', 'redeemid' ),
			},
		];

		return $checks;
	}
}
