<?php

namespace RedeemID\Files;

use RedeemID\Access\Checker;
use RedeemID\Access\Level;
use RedeemID\Access\LevelStore;
use RedeemID\Access\Reader;
use RedeemID\Features\Feature;
use RedeemID\Features\Registry;
use RedeemID\Settings;
use RedeemID\Support\Logger;
use RedeemID\Sync\Mirror;

/**
 * Protected files: media that only entitled members can download.
 *
 * Protecting an attachment moves its file (and every image size) into
 * uploads/redeemid-protected/, which the web server must refuse to serve
 * (an .htaccess does it on Apache; nginx needs the snippet in
 * docs/protected-files.md, and Site Health checks it). From then on every
 * URL WordPress builds for the file points at ?redeemid_file=<id>, which
 * checks the visitor's access on every request before streaming the file.
 * A link copied out of a page is no use to anyone who isn't entitled when
 * they open it.
 *
 * Who may download, per file:
 * - inherit: whoever can read the item the file is attached to (every layer
 *   of its rule, as redeemid_can_read() answers);
 * - levels: members holding any of these levels;
 * - feature: members holding this feature.
 * People who can edit the attachment can always download it.
 *
 * Old direct links to a file protected after it was linked land on a 404
 * under uploads; those are redirected to the checked URL.
 *
 * allowed(), sanitize() and relocate() are pure.
 */
final class ProtectedFiles {
	public const META       = '_redeemid_file';
	public const MOVED_FROM = '_redeemid_file_moved_from';
	public const DIR        = 'redeemid-protected';
	public const PARAM      = 'redeemid_file';

	public const INHERIT = 'inherit';
	public const LEVELS  = 'levels';
	public const FEATURE = 'feature';

	public static function register(): void {
		add_filter( 'wp_get_attachment_url', [ self::class, 'filter_url' ], 20, 2 );
		add_filter( 'image_downsize', [ self::class, 'filter_downsize' ], 10, 3 );
		add_filter( 'wp_calculate_image_srcset', [ self::class, 'filter_srcset' ], 10, 5 );
		add_action( 'init', [ Download::class, 'maybe_serve' ], 20 );
		add_action( 'template_redirect', [ Download::class, 'redirect_moved' ], 1 );
		add_filter( 'site_status_tests', [ Probe::class, 'site_health' ] );
		add_filter( 'redeemid_health_checks', [ Probe::class, 'health_row' ] );
	}

	/**
	 * A stored rule, cleaned; null means the file is public.
	 *
	 * @return array{mode: string, levels: string[], feature: string}|null
	 */
	public static function sanitize( mixed $value ): ?array {
		if ( ! is_array( $value ) || ! in_array( $value['mode'] ?? '', [ self::INHERIT, self::LEVELS, self::FEATURE ], true ) ) {
			return null;
		}

		$levels = array_values( array_unique( array_filter( array_map( [ Level::class, 'slug' ], (array) ( $value['levels'] ?? [] ) ) ) ) );

		return [
			'mode'    => $value['mode'],
			'levels'  => self::LEVELS === $value['mode'] ? $levels : [],
			'feature' => self::FEATURE === $value['mode'] ? Feature::slug( $value['feature'] ?? '' ) : '',
		];
	}

	/**
	 * Does this rule let the visitor download? Fails closed: a rule with
	 * nothing to check, or an inherited rule with no item, grants nobody.
	 *
	 * @param array{mode: string, levels: string[], feature: string} $rule
	 * @param callable(): bool       $reads_parent Can they read the item the file is attached to? False when there is none.
	 * @param callable(string): bool $holds        Do they hold this level?
	 * @param callable(string): bool $has_feature  Do they hold this feature?
	 */
	public static function allowed( array $rule, callable $reads_parent, callable $holds, callable $has_feature ): bool {
		return match ( $rule['mode'] ) {
			self::INHERIT => $reads_parent(),
			self::LEVELS  => (bool) array_filter( $rule['levels'], $holds ),
			self::FEATURE => '' !== $rule['feature'] && $has_feature( $rule['feature'] ),
			default       => false,
		};
	}

	/**
	 * @return array{mode: string, levels: string[], feature: string}|null
	 */
	public static function rule( int $attachment_id ): ?array {
		return self::sanitize( get_post_meta( $attachment_id, self::META, true ) );
	}

	/**
	 * Protected and actually stored in the protected folder.
	 */
	public static function is_protected( int $attachment_id ): bool {
		return null !== self::rule( $attachment_id ) && str_starts_with( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ), self::DIR . '/' );
	}

	public static function can_download( \WP_Post $attachment, int $user_id ): bool {
		$rule = self::rule( $attachment->ID );

		if ( null === $rule ) {
			return true;
		}

		if ( $user_id > 0 && user_can( $user_id, 'edit_post', $attachment->ID ) ) {
			return true;
		}

		$now     = time();
		$member  = Mirror::member( $user_id );
		$checker = new Checker( LevelStore::levels(), Settings::evaluator() );

		return self::allowed(
			$rule,
			static function () use ( $attachment, $user_id ): bool {
				$parent = $attachment->post_parent ? get_post( $attachment->post_parent ) : null;

				return $parent instanceof \WP_Post && Reader::can( $parent, $user_id );
			},
			static fn( string $slug ): bool => $checker->holds( $slug, $member, $now )->granted,
			static fn( string $slug ): bool => Registry::has( $slug, $user_id )
		);
	}

	/**
	 * The checked URL for a protected file, or one of its image sizes.
	 */
	public static function url( int $attachment_id, string $size = '' ): string {
		return add_query_arg( array_filter( [ self::PARAM => $attachment_id, 'size' => $size ] ), home_url( '/' ) );
	}

	public static function filter_url( mixed $url, mixed $attachment_id ): mixed {
		return self::is_protected( (int) $attachment_id ) ? self::url( (int) $attachment_id ) : $url;
	}

	/**
	 * Image sizes of a protected image go through the checked URL too.
	 *
	 * @param false|array  $out
	 * @param string|int[] $size
	 */
	public static function filter_downsize( mixed $out, mixed $attachment_id, mixed $size ): mixed {
		$id = (int) $attachment_id;

		if ( false !== $out || ! self::is_protected( $id ) ) {
			return $out;
		}

		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : [];

		if ( is_string( $size ) && isset( $meta['sizes'][ $size ] ) ) {
			return [ self::url( $id, $size ), (int) $meta['sizes'][ $size ]['width'], (int) $meta['sizes'][ $size ]['height'], true ];
		}

		return [ self::url( $id ), (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ), false ];
	}

	/**
	 * No srcset for a protected image: its candidates would be direct file URLs.
	 */
	public static function filter_srcset( mixed $sources, mixed $size_array, mixed $image_src, mixed $meta, mixed $attachment_id ): mixed {
		return self::is_protected( (int) $attachment_id ) ? false : $sources;
	}

	/**
	 * Protect an attachment with $rule, or change the rule of one already
	 * protected. Moves its files into the protected folder first; on failure
	 * nothing is changed.
	 *
	 * @param array{mode: string, levels: string[], feature: string} $rule
	 */
	public static function protect( int $attachment_id, array $rule ): true|\WP_Error {
		$rule = self::sanitize( $rule );

		if ( null === $rule ) {
			return self::unprotect( $attachment_id );
		}

		if ( ! self::is_protected( $attachment_id ) ) {
			$moved = self::move( $attachment_id, true );

			if ( is_wp_error( $moved ) ) {
				return $moved;
			}
		}

		update_post_meta( $attachment_id, self::META, $rule );
		Logger::log( 'file.protected', [ 'attachment' => $attachment_id, 'mode' => $rule['mode'], 'user' => get_current_user_id() ] );

		return true;
	}

	/**
	 * Make a protected attachment a normal public file again.
	 */
	public static function unprotect( int $attachment_id ): true|\WP_Error {
		if ( self::is_protected( $attachment_id ) ) {
			$moved = self::move( $attachment_id, false );

			if ( is_wp_error( $moved ) ) {
				return $moved;
			}

			Logger::log( 'file.unprotected', [ 'attachment' => $attachment_id, 'user' => get_current_user_id() ] );
		}

		delete_post_meta( $attachment_id, self::META );

		return true;
	}

	/**
	 * A path relative to uploads, moved into or out of the protected folder.
	 */
	public static function relocate( string $relative, bool $into ): string {
		$relative = ltrim( $relative, '/' );
		$prefix   = self::DIR . '/';

		if ( $into ) {
			return str_starts_with( $relative, $prefix ) ? $relative : $prefix . $relative;
		}

		return str_starts_with( $relative, $prefix ) ? substr( $relative, strlen( $prefix ) ) : $relative;
	}

	/**
	 * The protected folder's absolute path, created with its server guards
	 * when $create is true.
	 */
	public static function dir( bool $create = false ): string {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( (string) $uploads['basedir'] ) . self::DIR;

		if ( $create && ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( $create ) {
			$fs = self::filesystem();

			if ( $fs && ! $fs->exists( $dir . '/.htaccess' ) ) {
				// Apache 2.4 and 2.2. nginx ignores this file: see docs/protected-files.md.
				$fs->put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n", FS_CHMOD_FILE );
			}

			if ( $fs && ! $fs->exists( $dir . '/index.php' ) ) {
				$fs->put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
			}

			Probe::ensure_file( $dir );
		}

		return $dir;
	}

	/**
	 * Move an attachment's file, its image sizes and any original image, then
	 * point WordPress at the new place.
	 */
	private static function move( int $attachment_id, bool $into ): true|\WP_Error {
		$relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$fs       = self::filesystem();

		if ( '' === $relative || ! $fs ) {
			return new \WP_Error( 'redeemid_file_missing', __( 'This file can\'t be moved: WordPress has no file for it, or the file system isn\'t writable.', 'redeemid' ) );
		}

		$base   = trailingslashit( (string) wp_upload_dir( null, false )['basedir'] );
		$target = self::relocate( $relative, $into );

		if ( $target === $relative ) {
			return true;
		}

		if ( $into ) {
			self::dir( true );
		}

		$meta  = wp_get_attachment_metadata( $attachment_id );
		$meta  = is_array( $meta ) ? $meta : [];
		$names = [ basename( $relative ) ];

		foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$names[] = (string) $size['file'];
			}
		}

		if ( ! empty( $meta['original_image'] ) ) {
			$names[] = (string) $meta['original_image'];
		}

		$from_dir = dirname( $base . $relative );
		$to_dir   = dirname( $base . $target );

		if ( ! is_dir( $to_dir ) && ! wp_mkdir_p( $to_dir ) ) {
			return new \WP_Error( 'redeemid_file_mkdir', __( 'The folder for this file couldn\'t be created.', 'redeemid' ) );
		}

		$done = [];

		foreach ( array_unique( $names ) as $name ) {
			$from = $from_dir . '/' . $name;

			if ( ! $fs->exists( $from ) ) {
				continue;
			}

			if ( ! $fs->move( $from, $to_dir . '/' . $name, true ) ) {
				// Put back what already moved, so the attachment is never half in each place.
				foreach ( $done as $moved ) {
					$fs->move( $to_dir . '/' . $moved, $from_dir . '/' . $moved, true );
				}

				return new \WP_Error( 'redeemid_file_move', __( 'The file couldn\'t be moved. Check that the uploads folder is writable.', 'redeemid' ) );
			}

			$done[] = $name;
		}

		update_attached_file( $attachment_id, $target );

		if ( isset( $meta['file'] ) ) {
			$meta['file'] = $target;
			wp_update_attachment_metadata( $attachment_id, $meta );
		}

		if ( $into ) {
			update_post_meta( $attachment_id, self::MOVED_FROM, $relative );
		} else {
			delete_post_meta( $attachment_id, self::MOVED_FROM );
		}

		return true;
	}

	private static function filesystem(): ?\WP_Filesystem_Base {
		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
	}
}
