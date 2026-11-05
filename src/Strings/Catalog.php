<?php

namespace RedeemID\Strings;

/**
 * Every string RedeemID can show, read from the bundled translation
 * template (languages/redeemid.pot), so Text can list them all with where
 * each one appears.
 *
 * parse(), area() and audience() are pure.
 */
final class Catalog {
	public const MEMBERS = 'members';
	public const STAFF   = 'staff';

	/**
	 * Where a string appears, by the first path prefix its source file
	 * matches. The order matters: the first match wins.
	 */
	private const AREAS = [
		'blocks/'               => 'blocks',
		'assets/block-access'   => 'editor',
		'assets/'               => 'store',
		'src/Auth/'             => 'signin',
		'src/Blocks/'           => 'signin',
		'src/Preferences/'      => 'signin',
		'src/Access/Cli'        => 'admin',
		'src/Access/Explain'    => 'admin',
		'src/Access/'           => 'access',
		'src/Features/'         => 'access',
		'src/Files/Probe'       => 'admin',
		'src/Files/'            => 'access',
		'src/Commerce/'         => 'store',
		'src/Privacy'           => 'privacy',
		'src/Admin/'            => 'admin',
		'redeemid.php'          => 'plugin',
	];

	/** Areas that visitors and members see; the rest are staff only. */
	private const MEMBER_AREAS = [ 'blocks', 'signin', 'access', 'store', 'privacy' ];

	/** @var array<string, array>|null */
	private static ?array $loaded = null;

	/**
	 * The bundled template, parsed once per request.
	 *
	 * @return array<string, array{key: string, single: string, plural: string, context: string, comment: string, refs: string[], format: bool, area: string, audience: string}>
	 */
	public static function all(): array {
		if ( null === self::$loaded ) {
			$file         = dirname( __DIR__, 2 ) . '/languages/redeemid.pot';
			$pot          = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- a local file.
			self::$loaded = self::parse( $pot );
		}

		return self::$loaded;
	}

	/**
	 * The entries of a .pot file, keyed by Overrides::key(). The header entry is left out.
	 *
	 * @return array<string, array{key: string, single: string, plural: string, context: string, comment: string, refs: string[], format: bool, area: string, audience: string}>
	 */
	public static function parse( string $pot ): array {
		$entries = [];

		foreach ( preg_split( "/\n\s*\n/", str_replace( "\r\n", "\n", $pot ) ) ?: [] as $block ) {
			$entry = [ 'context' => '', 'single' => '', 'plural' => '', 'comment' => [], 'refs' => [], 'format' => false ];
			$field = '';

			foreach ( explode( "\n", trim( $block ) ) as $line ) {
				if ( str_starts_with( $line, '#.' ) ) {
					$entry['comment'][] = trim( substr( $line, 2 ) );
				} elseif ( str_starts_with( $line, '#:' ) ) {
					foreach ( preg_split( '/\s+/', trim( substr( $line, 2 ) ) ) ?: [] as $ref ) {
						$file = preg_replace( '/:\d+$/', '', $ref );

						if ( '' !== $file && ! in_array( $file, $entry['refs'], true ) ) {
							$entry['refs'][] = $file;
						}
					}
				} elseif ( str_starts_with( $line, '#,' ) ) {
					$entry['format'] = $entry['format'] || str_contains( $line, 'php-format' );
				} elseif ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+"(.*)"$/', $line, $m ) ) {
					$field = [ 'msgctxt' => 'context', 'msgid' => 'single', 'msgid_plural' => 'plural' ][ $m[1] ] ?? '';

					if ( '' !== $field ) {
						$entry[ $field ] = self::unquote( $m[2] );
					}
				} elseif ( '' !== $field && preg_match( '/^"(.*)"$/', $line, $m ) ) {
					$entry[ $field ] .= self::unquote( $m[1] );
				}
			}

			if ( '' === $entry['single'] ) {
				continue;
			}

			$key             = Overrides::key( $entry['single'], $entry['context'] );
			$area            = self::area( $entry['refs'] );
			$entries[ $key ] = [
				'key'      => $key,
				'single'   => $entry['single'],
				'plural'   => $entry['plural'],
				'context'  => $entry['context'],
				'comment'  => implode( ' ', $entry['comment'] ),
				'refs'     => $entry['refs'],
				'format'   => $entry['format'],
				'area'     => $area,
				'audience' => self::audience( $entry['refs'] ),
			];
		}

		return $entries;
	}

	/**
	 * The area of the first source file that has one. The plugin header
	 * (redeemid.php) counts only when no other file uses the string.
	 *
	 * @param string[] $refs Source files, without line numbers.
	 */
	public static function area( array $refs ): string {
		$found = [];

		foreach ( $refs as $ref ) {
			foreach ( self::AREAS as $prefix => $area ) {
				if ( str_starts_with( $ref, $prefix ) ) {
					// A block's own block.json is its editor title, description and keywords.
					$found[] = 'blocks' === $area && str_ends_with( $ref, 'block.json' ) ? 'editor' : $area;
					break;
				}
			}
		}

		return array_values( array_diff( $found, [ 'plugin' ] ) )[0] ?? $found[0] ?? 'admin';
	}

	/**
	 * Members if any source file is in an area visitors and members see.
	 *
	 * @param string[] $refs
	 */
	public static function audience( array $refs ): string {
		foreach ( $refs as $ref ) {
			if ( in_array( self::area( [ $ref ] ), self::MEMBER_AREAS, true ) ) {
				return self::MEMBERS;
			}
		}

		return self::STAFF;
	}

	/**
	 * The block a string belongs to, from its first blocks/<name>/ file, or ''.
	 *
	 * @param string[] $refs
	 */
	public static function block( array $refs ): string {
		foreach ( $refs as $ref ) {
			if ( preg_match( '#^blocks/([a-z0-9-]+)/#', $ref, $m ) ) {
				return $m[1];
			}
		}

		return '';
	}

	/**
	 * Undo .po escaping inside a quoted string.
	 */
	private static function unquote( string $text ): string {
		return (string) preg_replace_callback(
			'/\\\\(.)/s',
			static fn( array $m ): string => [ 'n' => "\n", 't' => "\t", 'r' => "\r", '"' => '"', '\\' => '\\' ][ $m[1] ] ?? $m[0],
			$text
		);
	}

	/**
	 * Forget the parsed template. For tests.
	 */
	public static function reset(): void {
		self::$loaded = null;
	}
}
