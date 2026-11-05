<?php

namespace RedeemID\Strings;

use RedeemID\Support\Logger;

/**
 * Owners' replacement text for RedeemID's strings ("Account" can read
 * "Portal"), applied on top of translation.
 *
 * The gettext filters for the redeemid text domain swap in a replacement
 * after WordPress has translated the string, so a site keeps every
 * translation it doesn't replace. A replacement set for one language wins
 * over one set for every language.
 *
 * Only strings given a replacement change: there is deliberately no
 * find-and-replace across strings, which would rewrite text nobody looked
 * at. Code can add or change replacements with the
 * redeemid_string_overrides filter (the same shape as the option).
 */
final class Text {
	public const OPTION = 'redeemid_strings';

	/** @var array<string, array<string, string|array>>|null */
	private static ?array $all = null;

	private static ?string $locale = null;

	/** While true, strings come back translated but not replaced (the Text page reads translations). */
	public static bool $bypass = false;

	public static function register(): void {
		add_filter( 'gettext_redeemid', [ self::class, 'gettext' ], 10, 2 );
		add_filter( 'gettext_with_context_redeemid', [ self::class, 'gettext_with_context' ], 10, 3 );
		add_filter( 'ngettext_redeemid', [ self::class, 'ngettext' ], 10, 4 );
		add_filter( 'ngettext_with_context_redeemid', [ self::class, 'ngettext_with_context' ], 10, 5 );
		add_action( 'change_locale', static function (): void {
			self::$locale = null;
		} );
		add_action( 'update_option_' . self::OPTION, [ self::class, 'reset' ] );
	}

	public static function gettext( string $translation, string $text ): string {
		return self::lookup( Overrides::key( $text ), $translation );
	}

	public static function gettext_with_context( string $translation, string $text, string $context ): string {
		return self::lookup( Overrides::key( $text, $context ), $translation );
	}

	public static function ngettext( string $translation, string $single, string $plural, mixed $number ): string {
		return self::lookup( Overrides::key( $single ), $translation, (int) $number );
	}

	public static function ngettext_with_context( string $translation, string $single, string $plural, mixed $number, string $context ): string {
		return self::lookup( Overrides::key( $single, $context ), $translation, (int) $number );
	}

	/**
	 * The string's own replacement, else its translation.
	 */
	private static function lookup( string $key, string $translation, ?int $count = null ): string {
		if ( self::$bypass ) {
			return $translation;
		}

		$all = self::all();

		if ( ! $all ) {
			return $translation;
		}

		$value = Overrides::pick( $all, self::locale(), $key );

		return null === $value ? $translation : Overrides::form( $value, $count );
	}

	/**
	 * The language strings are shown in now: a switched locale, else the
	 * one WordPress loaded translations for (the user's own in the admin).
	 */
	private static function locale(): string {
		if ( null !== self::$locale ) {
			return self::$locale;
		}

		$locale = is_locale_switched() ? get_locale() : determine_locale();

		// Before init the current user (and so the admin language) may not be known yet.
		if ( did_action( 'init' ) ) {
			self::$locale = $locale;
		}

		return $locale;
	}

	/**
	 * The replacements in use: the saved ones, then the code filter.
	 *
	 * @return array<string, array<string, string|array{0: string, 1: string}>>
	 */
	public static function all(): array {
		if ( null !== self::$all ) {
			return self::$all;
		}

		$all = Overrides::normalize( apply_filters( 'redeemid_string_overrides', self::saved() ) );

		// A theme's filter is only added once the theme has loaded.
		if ( did_action( 'after_setup_theme' ) ) {
			self::$all = $all;
		}

		return $all;
	}

	/**
	 * The replacements saved on Text, without the code filter.
	 *
	 * @return array<string, array<string, string|array{0: string, 1: string}>>
	 */
	public static function saved(): array {
		return Overrides::normalize( get_option( self::OPTION, [] ) );
	}

	/**
	 * Whether code changes the saved replacements.
	 */
	public static function managed_in_code(): bool {
		return has_filter( 'redeemid_string_overrides' ) && self::all() !== self::saved();
	}

	/**
	 * Save replacements for one language ('' is every language). A null or
	 * empty value removes that key's replacement. Values whose placeholders
	 * don't match the original are refused and reported.
	 *
	 * @param array<string, mixed> $changes key => text, [ one, more ] or null.
	 * @return array<string, string> Refused keys => Overrides::problem() code.
	 */
	public static function save( string $locale, array $changes ): array {
		$all     = self::saved();
		$errors  = [];
		$changed = 0;

		foreach ( $changes as $key => $value ) {
			$key   = (string) $key;
			$value = Overrides::value( $value );

			if ( null === $value ) {
				if ( isset( $all[ $locale ][ $key ] ) ) {
					unset( $all[ $locale ][ $key ] );
					++$changed;
				}
				continue;
			}

			$checked = self::check( $key, $value );

			if ( null === $checked['error'] ) {
				if ( ( $all[ $locale ][ $key ] ?? null ) !== $checked['value'] ) {
					$all[ $locale ][ $key ] = $checked['value'];
					++$changed;
				}
			} else {
				$errors[ $key ] = $checked['error'];
			}
		}

		$all = Overrides::normalize( array_filter( $all ) );
		update_option( self::OPTION, $all, true );
		self::reset();

		if ( $changed ) {
			Logger::log( 'admin.strings_saved', [ 'locale' => '' === $locale ? '*' : $locale, 'changed' => $changed ] );
		}

		return $errors;
	}

	/**
	 * Replace every saved replacement with $data (a settings import),
	 * dropping any that don't fit their original.
	 *
	 * @return string[] Dropped entries, as "locale: original".
	 */
	public static function replace( array $data ): array {
		$out     = [];
		$dropped = [];

		foreach ( Overrides::normalize( $data ) as $locale => $rows ) {
			foreach ( $rows as $key => $value ) {
				$checked = self::check( (string) $key, $value );

				if ( null === $checked['error'] ) {
					$out[ $locale ][ $key ] = $checked['value'];
				} else {
					$dropped[] = ( '' === $locale ? '*' : $locale ) . ': ' . Overrides::split( (string) $key )[1];
				}
			}
		}

		update_option( self::OPTION, Overrides::normalize( $out ), true );
		self::reset();

		return $dropped;
	}

	/**
	 * A cleaned value and why it can't be used, if it can't.
	 *
	 * @param string|array{0: string, 1: string} $value
	 * @return array{value: string|array{0: string, 1: string}, error: string|null}
	 */
	public static function check( string $key, string|array $value ): array {
		$entry    = Catalog::all()[ $key ] ?? null;
		$original = $entry['single'] ?? Overrides::split( $key )[1];
		$plural   = '' !== ( $entry['plural'] ?? '' ) ? $entry['plural'] : $original;
		$format   = $entry ? (bool) $entry['format'] : null;
		$forms    = is_array( $value ) ? $value : [ $value ];
		$clean    = [];

		foreach ( $forms as $i => $text ) {
			$raw     = (string) $text;
			$text    = self::clean( $original, $raw );
			$problem = '' === $text && '' !== trim( $raw ) ? 'markup' : Overrides::problem( 1 === $i ? $plural : $original, $text, $format );

			if ( '' !== $problem ) {
				return [ 'value' => $value, 'error' => $problem ];
			}

			$clean[] = $text;
		}

		return [ 'value' => is_array( $value ) ? [ $clean[0], $clean[1] ?? $clean[0] ] : $clean[0], 'error' => null ];
	}

	/**
	 * Strip markup the original doesn't have: a replacement may use the
	 * tags and attributes of its original, nothing else.
	 */
	public static function clean( string $original, string $text ): string {
		$text = trim( wp_check_invalid_utf8( $text ) );
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );

		if ( ! str_contains( $text, '<' ) ) {
			return $text;
		}

		$allowed = [];
		preg_match_all( '/<([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>/', $original, $tags, PREG_SET_ORDER );

		foreach ( $tags as $tag ) {
			$name = strtolower( $tag[1] );
			preg_match_all( '/([a-zA-Z][a-zA-Z0-9-]*)\s*=/', $tag[2], $attrs );
			$allowed[ $name ] = ( $allowed[ $name ] ?? [] ) + array_fill_keys( array_map( 'strtolower', $attrs[1] ), true );
		}

		return trim( wp_kses( $text, $allowed ) );
	}

	public static function reset(): void {
		self::$all    = null;
		self::$locale = null;
	}

	/**
	 * The editor script's part: the same replacements for wp.i18n, for the
	 * strings RedeemID's editor script shows. Empty when there are none.
	 */
	public static function script(): string {
		$all    = self::all();
		$locale = self::locale();
		$map    = [];

		foreach ( [ Overrides::EVERY, $locale ] as $scope ) {
			foreach ( $all[ $scope ] ?? [] as $key => $value ) {
				$map[ $key ] = $value;
			}
		}

		if ( ! $map ) {
			return '';
		}

		return '( function ( map, hooks ) {'
			. 'var pick = function ( key, n, fallback ) { var v = map[ key ]; if ( undefined === v ) { return fallback; } return "string" === typeof v ? v : ( 1 === n || undefined === n ? v[ 0 ] : v[ 1 ] ); };'
			. 'hooks.addFilter( "i18n.gettext_redeemid", "redeemid/text", function ( t, text ) { return pick( text, undefined, t ); } );'
			. 'hooks.addFilter( "i18n.gettext_with_context_redeemid", "redeemid/text", function ( t, text, ctx ) { return pick( ctx + "\u0004" + text, undefined, t ); } );'
			. 'hooks.addFilter( "i18n.ngettext_redeemid", "redeemid/text", function ( t, one, more, n ) { return pick( one, n, t ); } );'
			. 'hooks.addFilter( "i18n.ngettext_with_context_redeemid", "redeemid/text", function ( t, one, more, n, ctx ) { return pick( ctx + "\u0004" + one, n, t ); } );'
			. '} )( ' . wp_json_encode( (object) $map ) . ', wp.hooks );';
	}
}
