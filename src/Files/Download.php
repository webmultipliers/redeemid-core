<?php

namespace RedeemID\Files;

use RedeemID\Support\Logger;

/**
 * Serves ?redeemid_file=<id>[&size=<name>]: checks who is asking on every
 * request, then streams the file from the protected folder. The response
 * is never cacheable, so a shared cache can't hand it to someone else.
 *
 * PHP streams the file by default. On a server set up for it, the
 * redeemid_file_sendfile filter hands the transfer to the server instead:
 * return 'x-sendfile' (Apache mod_xsendfile, LiteSpeed) or
 * 'x-accel-redirect' (nginx, with the internal location in
 * docs/protected-files.md).
 */
final class Download {
	public static function maybe_serve(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- a download link; access is checked below.
		if ( ! isset( $_GET[ ProtectedFiles::PARAM ] ) ) {
			return;
		}

		$id   = absint( $_GET[ ProtectedFiles::PARAM ] );
		$size = sanitize_key( wp_unslash( $_GET['size'] ?? '' ) );
		// phpcs:enable

		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type || ! ProtectedFiles::is_protected( $id ) ) {
			self::stop( 404, __( 'File not found.', 'redeemid' ) );
		}

		$user_id = get_current_user_id();

		if ( ! ProtectedFiles::can_download( $post, $user_id ) ) {
			Logger::log( 'file.denied', [ 'attachment' => $id, 'user' => $user_id ] );
			self::deny( $post );
		}

		$path = self::path( $id, $size );

		if ( null === $path ) {
			self::stop( 404, __( 'File not found.', 'redeemid' ) );
		}

		Logger::log( 'file.downloaded', [ 'attachment' => $id, 'user' => $user_id ] );
		self::send( $path );
	}

	/**
	 * The absolute path for an attachment or one of its image sizes, if it is
	 * inside the protected folder and exists.
	 */
	public static function path( int $attachment_id, string $size = '' ): ?string {
		$file = get_attached_file( $attachment_id, true );

		if ( ! is_string( $file ) || '' === $file ) {
			return null;
		}

		if ( '' !== $size ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			$name = is_array( $meta ) ? ( $meta['sizes'][ $size ]['file'] ?? '' ) : '';
			$file = '' !== $name ? dirname( $file ) . '/' . basename( (string) $name ) : $file;
		}

		$real = realpath( $file );
		$root = realpath( ProtectedFiles::dir() );

		return false !== $real && false !== $root && str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) && is_file( $real ) ? $real : null;
	}

	/**
	 * Inline for what browsers show themselves; a download for the rest.
	 */
	public static function disposition( string $mime ): string {
		return preg_match( '#^(image|audio|video)/|^application/pdf$|^text/plain$#', $mime ) && 'image/svg+xml' !== $mime ? 'inline' : 'attachment';
	}

	private static function send( string $path ): never {
		$type = wp_check_filetype( $path );
		$mime = is_string( $type['type'] ) && '' !== $type['type'] ? $type['type'] : 'application/octet-stream';
		$name = str_replace( [ '"', "\r", "\n" ], '', basename( $path ) );

		/**
		 * How the file is sent: '' (PHP streams it), 'x-sendfile' or 'x-accel-redirect'.
		 *
		 * @param string $mode
		 * @param string $path Absolute path of the file.
		 */
		$mode = (string) apply_filters( 'redeemid_file_sendfile', '', $path );

		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: ' . self::disposition( $mime ) . '; filename="' . $name . '"' );

		if ( 'x-sendfile' === $mode ) {
			header( 'X-Sendfile: ' . $path );
			exit;
		}

		if ( 'x-accel-redirect' === $mode ) {
			$uploads = wp_upload_dir( null, false );
			$uri     = (string) wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH ) . '/' . ltrim( substr( $path, strlen( (string) realpath( (string) $uploads['basedir'] ) ) ), '/' );
			header( 'X-Accel-Redirect: ' . $uri );
			exit;
		}

		header( 'Content-Length: ' . (string) filesize( $path ) );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_readfile -- streaming a checked local file to the visitor.
		readfile( $path );
		exit;
	}

	/**
	 * Not entitled: a page that says so and points to the item, where the
	 * wall explains how to get access.
	 */
	private static function deny( \WP_Post $attachment ): never {
		$parent = $attachment->post_parent ? get_post( $attachment->post_parent ) : null;
		$link   = $parent instanceof \WP_Post && 'publish' === $parent->post_status
			? sprintf( ' <a href="%s">%s</a>', esc_url( (string) get_permalink( $parent ) ), esc_html( sprintf( /* translators: %s: item title */ __( 'Go to %s', 'redeemid' ), get_the_title( $parent ) ) ) )
			: '';
		$text   = is_user_logged_in()
			? __( 'Your membership doesn\'t include this file.', 'redeemid' )
			: __( 'This file is for members. Sign in to download it.', 'redeemid' );

		nocache_headers();
		wp_die( esc_html( $text ) . $link, esc_html__( 'Members only', 'redeemid' ), [ 'response' => is_user_logged_in() ? 403 : 401 ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}

	private static function stop( int $status, string $text ): never {
		nocache_headers();
		wp_die( esc_html( $text ), '', [ 'response' => $status ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- an HTTP status code, not output.
	}

	/**
	 * A direct link to a file that was protected after it was linked lands
	 * on a 404 under uploads: send it to the checked URL instead.
	 */
	public static function redirect_moved(): void {
		if ( ! is_404() ) {
			return;
		}

		$uploads = wp_upload_dir( null, false );
		$prefix  = (string) wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH ) . '/';
		$path    = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- parsed for its path only.

		if ( '/' === $prefix || ! str_starts_with( $path, $prefix ) ) {
			return;
		}

		$relative = rawurldecode( substr( $path, strlen( $prefix ) ) );
		$ids      = get_posts(
			[
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'meta_key'         => ProtectedFiles::MOVED_FROM, // phpcs:ignore WordPress.DB.SlowDBQuery -- 404s under uploads only.
				'meta_value'       => $relative, // phpcs:ignore WordPress.DB.SlowDBQuery
				'suppress_filters' => false,
			]
		);

		if ( $ids && ProtectedFiles::is_protected( (int) $ids[0] ) ) {
			wp_safe_redirect( ProtectedFiles::url( (int) $ids[0] ), 302 );
			exit;
		}
	}
}
