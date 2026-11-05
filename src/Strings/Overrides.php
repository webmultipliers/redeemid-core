<?php

namespace RedeemID\Strings;

/**
 * Replacement text for RedeemID's strings: the pure rules.
 *
 * Stored as locale => key => text, where '' is every language and the key
 * is the original English string (with its context in front, joined by
 * "\x04", the way gettext keys it). A plural string's text is a pair:
 * [ one, more than one ].
 *
 * A replacement can only use the placeholders its original has, with the
 * same types, so sprintf() never sees a placeholder it has no value for.
 */
final class Overrides {
	public const EVERY = '';

	/** Longest replacement kept, in characters. */
	public const MAX = 2000;

	private const TOKEN = '/%%|%(?:(\d+)\$)?[-+ 0]*(?:\'.)?-?\d*(?:\.\d+)?([bcdeEfFgGosuxX])/';

	public static function key( string $single, string $context = '' ): string {
		return '' === $context ? $single : $context . "\x04" . $single;
	}

	/**
	 * The context and the original string of a key.
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function split( string $key ): array {
		$at = strpos( $key, "\x04" );

		return false === $at ? [ '', $key ] : [ substr( $key, 0, $at ), substr( $key, $at + 1 ) ];
	}

	/**
	 * The arguments a format string reads: argument number => type.
	 * Null when a % is not part of a valid placeholder (sprintf() would throw).
	 *
	 * @return array<int, string>|null
	 */
	public static function placeholders( string $text ): ?array {
		$args = [];
		$next = 1;
		$left = preg_replace( self::TOKEN, '', $text );

		if ( null === $left || str_contains( $left, '%' ) ) {
			return null;
		}

		preg_match_all( self::TOKEN, $text, $found, PREG_SET_ORDER );

		foreach ( $found as $m ) {
			if ( '%%' === $m[0] ) {
				continue;
			}

			$number          = '' !== ( $m[1] ?? '' ) ? (int) $m[1] : $next++;
			$args[ $number ] = $m[2];
		}

		return $args;
	}

	/**
	 * Whether an original is a format string: the template says so, or,
	 * for strings it doesn't list, it has a placeholder or a %%.
	 */
	public static function is_format( string $original, ?bool $flagged = null ): bool {
		if ( null !== $flagged ) {
			return $flagged;
		}

		return (bool) preg_match( self::TOKEN, $original );
	}

	/**
	 * Why a replacement can't stand in for its original, or '' when it can.
	 *
	 * - 'percent': a % that isn't a placeholder (write %% for a percent sign).
	 * - 'placeholder': a placeholder the original doesn't have, or of another type.
	 * - 'long': over MAX characters.
	 */
	public static function problem( string $original, string $replacement, ?bool $format = null ): string {
		if ( mb_strlen( $replacement ) > self::MAX ) {
			return 'long';
		}

		if ( ! self::is_format( $original, $format ) ) {
			return '';
		}

		$want = self::placeholders( $original ) ?? [];
		$have = self::placeholders( $replacement );

		if ( null === $have ) {
			return 'percent';
		}

		foreach ( $have as $number => $type ) {
			if ( ( $want[ $number ] ?? null ) !== $type ) {
				return 'placeholder';
			}
		}

		return '';
	}

	/**
	 * The placeholders of an original, for a hint: "%s, %d" or "%1$s, %2$s".
	 */
	public static function hint( string $original ): string {
		preg_match_all( self::TOKEN, $original, $found );

		return implode( ', ', array_values( array_unique( array_diff( $found[0], [ '%%' ] ) ) ) );
	}

	/**
	 * Keep only well-formed entries: string locales and keys, a string or a
	 * pair of strings, trimmed, none empty. Text is not checked against its
	 * original here; Store::save() does that.
	 *
	 * @return array<string, array<string, string|array{0: string, 1: string}>>
	 */
	public static function normalize( mixed $data ): array {
		$out = [];

		foreach ( is_array( $data ) ? $data : [] as $locale => $rows ) {
			$locale = (string) $locale;

			if ( ! preg_match( '/^(?:[a-zA-Z]{2,3}(?:_[a-zA-Z0-9]{2,8})*)?$/', $locale ) || ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $key => $value ) {
				$key   = (string) $key;
				$value = self::value( $value );

				if ( '' !== $key && null !== $value ) {
					$out[ $locale ][ $key ] = $value;
				}
			}

			if ( isset( $out[ $locale ] ) ) {
				ksort( $out[ $locale ] );
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * A stored value, cleaned: a string, a [ one, more ] pair (more falls
	 * back to one), or null when there is nothing to keep.
	 *
	 * @return string|array{0: string, 1: string}|null
	 */
	public static function value( mixed $value ): string|array|null {
		if ( is_array( $value ) ) {
			$one  = is_string( $value[0] ?? null ) ? trim( $value[0] ) : '';
			$more = is_string( $value[1] ?? null ) ? trim( $value[1] ) : '';

			if ( '' === $one && '' === $more ) {
				return null;
			}

			return [ '' !== $one ? $one : $more, '' !== $more ? $more : $one ];
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		return trim( $value );
	}

	/**
	 * The replacement for a key: the locale's own, else every language's.
	 *
	 * @param array<string, array<string, string|array>> $all
	 * @return string|array{0: string, 1: string}|null
	 */
	public static function pick( array $all, string $locale, string $key ): string|array|null {
		return $all[ $locale ][ $key ] ?? $all[ self::EVERY ][ $key ] ?? null;
	}

	/**
	 * One form of a replacement: a plain string for gettext, or the
	 * one/more form a plural string needs for $count.
	 *
	 * @param string|array{0: string, 1: string} $value
	 */
	public static function form( string|array $value, ?int $count = null ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		return null !== $count && 1 !== $count ? $value[1] : $value[0];
	}

	/**
	 * Every locale's replacements flattened for a diff: "locale: original" => text.
	 * Every language shows as "*".
	 *
	 * @param array<string, array<string, string|array>> $all
	 * @return array<string, string|array>
	 */
	public static function flat( array $all ): array {
		$flat = [];

		foreach ( $all as $locale => $rows ) {
			foreach ( $rows as $key => $value ) {
				[ $context, $single ] = self::split( (string) $key );
				$flat[ ( '' === $locale ? '*' : $locale ) . ': ' . ( '' !== $context ? $context . ' | ' : '' ) . $single ] = $value;
			}
		}

		return $flat;
	}
}
