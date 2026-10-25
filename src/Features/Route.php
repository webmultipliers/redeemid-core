<?php

namespace RedeemID\Features;

use RedeemID\Access\Level;

/**
 * One granting route of a feature: tests that must all pass, plus the label
 * provenance shows ("from Gold", "from add-on purchase"). A feature's routes
 * are joined by OR, so any one route grants it.
 *
 * Test types: level (a level slug, inclusion expanded), tag (a Shopify
 * customer tag from the mirror), test (a custom test registered with the
 * redeemid_feature_tests filter, with an optional argument).
 */
final class Route {
	public const LEVEL = 'level';
	public const TAG   = 'tag';
	public const TEST  = 'test';

	public const TYPES = [ self::LEVEL, self::TAG, self::TEST ];

	/**
	 * @param array<int, array{type: string, value: string, arg: string}> $tests
	 */
	public function __construct(
		public readonly array $tests,
		public readonly string $label = '',
	) {}

	/**
	 * Build a route from stored or filtered config. Unknown test types are
	 * kept as an unusable test, so the route fails closed instead of
	 * quietly losing a condition.
	 *
	 * Input: [ 'label' => 'Add-on purchase', 'tests' => [ [ 'type' => 'tag', 'value' => 'ad-free' ] ] ].
	 */
	public static function from_array( array $a ): self {
		$tests = [];

		foreach ( (array) ( $a['tests'] ?? [] ) as $test ) {
			if ( ! is_array( $test ) ) {
				continue;
			}

			$type  = is_string( $test['type'] ?? null ) ? strtolower( trim( $test['type'] ) ) : '';
			$value = is_string( $test['value'] ?? null ) ? trim( $test['value'] ) : '';
			$arg   = is_scalar( $test['arg'] ?? null ) ? trim( (string) $test['arg'] ) : '';

			if ( '' === $value ) {
				continue;
			}

			$value   = self::TAG === $type ? $value : Level::slug( $value );
			$tests[] = [ 'type' => in_array( $type, self::TYPES, true ) ? $type : 'unknown', 'value' => $value, 'arg' => $arg ];
		}

		return new self( $tests, trim( (string) ( $a['label'] ?? '' ) ) );
	}

	public function to_array(): array {
		return [ 'label' => $this->label, 'tests' => $this->tests ];
	}

	/**
	 * @return string[] Level slugs this route names.
	 */
	public function levels(): array {
		return array_values( array_map( static fn( $t ) => $t['value'], array_filter( $this->tests, static fn( $t ) => self::LEVEL === $t['type'] ) ) );
	}

	/**
	 * Is this route exactly "holds this level"? The level screen's shortcut
	 * adds and removes such routes.
	 */
	public function is_level_only( string $slug ): bool {
		return 1 === count( $this->tests ) && self::LEVEL === $this->tests[0]['type'] && $slug === $this->tests[0]['value'];
	}

	/**
	 * One route per line: "[Label =] test, test". A test is level:<slug>,
	 * tag:<tag>, test:<name> or test:<name>=<arg>; a bare word is a level
	 * slug, the primary pattern.
	 *
	 * @return self[]
	 */
	public static function parse( string $text ): array {
		$routes = [];

		foreach ( preg_split( '/\R/', $text ) ?: [] as $line ) {
			$line  = trim( $line );
			$label = '';

			if ( '' === $line ) {
				continue;
			}

			// A label is text before " = " that is not itself a test (test:name=arg has no spaces round "=").
			if ( preg_match( '/^([^,:]+?)\s+=\s+(.+)$/', $line, $m ) ) {
				$label = trim( $m[1] );
				$line  = trim( $m[2] );
			}

			$tests = [];

			foreach ( array_filter( array_map( 'trim', explode( ',', $line ) ), 'strlen' ) as $part ) {
				$type  = self::LEVEL;
				$value = $part;
				$arg   = '';

				if ( preg_match( '/^(level|tag|test)\s*:\s*(.+)$/i', $part, $t ) ) {
					$type  = strtolower( $t[1] );
					$value = trim( $t[2] );
				}

				if ( self::TEST === $type && str_contains( $value, '=' ) ) {
					[ $value, $arg ] = array_map( 'trim', explode( '=', $value, 2 ) );
				}

				$tests[] = [ 'type' => $type, 'value' => $value, 'arg' => $arg ];
			}

			if ( $tests ) {
				$routes[] = self::from_array( [ 'label' => $label, 'tests' => $tests ] );
			}
		}

		return $routes;
	}

	/**
	 * The inverse of parse().
	 *
	 * @param self[] $routes
	 */
	public static function format( array $routes ): string {
		$lines = [];

		foreach ( $routes as $route ) {
			$tests = array_map(
				static fn( $t ) => match ( $t['type'] ) {
					self::LEVEL => $t['value'],
					self::TEST  => 'test:' . $t['value'] . ( '' !== $t['arg'] ? '=' . $t['arg'] : '' ),
					default     => $t['type'] . ':' . $t['value'],
				},
				$route->tests
			);

			$lines[] = ( '' !== $route->label ? $route->label . ' = ' : '' ) . implode( ', ', $tests );
		}

		return implode( "\n", $lines );
	}
}
