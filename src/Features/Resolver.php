<?php

namespace RedeemID\Features;

use RedeemID\Access\Checker;
use RedeemID\Access\Level;
use RedeemID\Access\Levels;
use RedeemID\Entitlement\Decision;
use RedeemID\Entitlement\Evaluator;
use RedeemID\Entitlement\Member;
use RedeemID\Entitlement\Rule;

/**
 * Which features a visitor holds, with provenance. Pure: no WordPress calls,
 * so the whole acceptance matrix is unit tested.
 *
 * It reuses the content engine: a level test is Checker::holds() (inclusion
 * expanded first, the Evaluator's fail-closed checks underneath), and a tag
 * test is the Evaluator on a one-tag rule.
 *
 * Features fail closed one step further than content: a linked customer who
 * is disabled or never synced holds no feature except a Public one,
 * even through the built-in Signed in level or a custom test. A signed-in
 * visitor with no linked customer can still hold Signed in and custom-test
 * routes (staff, a role), never a tag or paid level.
 */
final class Resolver {
	private const EXPLAINS_MORE = [ Decision::GUEST, Decision::DISABLED, Decision::NO_MIRROR, Decision::NOT_LINKED ];

	private readonly Checker $checker;

	/**
	 * @param array<string, array{label?: string, callback: callable}> $tests Custom tests by name.
	 *        A callback gets (Member $member, string $arg, int $user_id, int $now) and grants only by returning true.
	 */
	public function __construct(
		private readonly Levels $levels,
		private readonly Evaluator $evaluator,
		private readonly array $tests = [],
	) {
		$this->checker = new Checker( $levels, $evaluator );
	}

	/**
	 * @param Feature[] $features
	 * @return array<string, Grant> By slug, for every feature.
	 */
	public function resolve( array $features, Member $member, int $now, int $user_id = 0 ): array {
		$grants = [];

		foreach ( $features as $feature ) {
			$grants[ $feature->slug ] = $this->decide( $feature, $member, $now, $user_id );
		}

		return $grants;
	}

	public function decide( Feature $feature, Member $member, int $now, int $user_id = 0 ): Grant {
		if ( ! $feature->routes ) {
			return new Grant( $feature->slug, false, Grant::NO_CONDITION );
		}

		$broken = $member->signed_in && $member->linked ? $this->evaluator->account_problem( $member, $now ) : null;
		$routes = [];
		$trace  = [];
		$reason = null;

		foreach ( $feature->routes as $route ) {
			$passed = (bool) $route->tests;
			$tests  = [];

			foreach ( $route->tests as $test ) {
				$why = $this->test( $test, $member, $now, $user_id, $broken );

				$tests[] = $test + [ 'passed' => null === $why, 'reason' => $why ?? Grant::GRANTED ];

				if ( null !== $why ) {
					$passed = false;

					if ( null === $reason || ( ! in_array( $reason, self::EXPLAINS_MORE, true ) && in_array( $why, self::EXPLAINS_MORE, true ) ) ) {
						$reason = $why;
					}
				}
			}

			$label   = $this->label( $route );
			$trace[] = [ 'label' => $label, 'passed' => $passed, 'tests' => $tests ];

			if ( $passed ) {
				$routes[] = $label;
			}
		}

		$routes = array_values( array_unique( $routes ) );

		return $routes
			? new Grant( $feature->slug, true, Grant::GRANTED, $routes, $trace )
			: new Grant( $feature->slug, false, $reason ?? Grant::NO_ROUTE, [], $trace );
	}

	/**
	 * Why one test fails, or null when it passes.
	 */
	private function test( array $test, Member $member, int $now, int $user_id, ?Decision $broken ): ?string {
		if ( Route::LEVEL === $test['type'] && Level::PUBLIC === $test['value'] ) {
			return null;
		}

		if ( ! $member->signed_in ) {
			return Decision::GUEST;
		}

		if ( $broken ) {
			return $broken->reason;
		}

		switch ( $test['type'] ) {
			case Route::LEVEL:
				$decision = $this->checker->holds( $test['value'], $member, $now );
				return $decision->granted ? null : $decision->reason;

			case Route::TAG:
				$decision = $this->evaluator->decide( new Rule( [ $test['value'] ] ), $member, $now );
				return $decision->granted ? null : $decision->reason;

			case Route::TEST:
				$callback = $this->tests[ $test['value'] ]['callback'] ?? null;

				if ( ! is_callable( $callback ) ) {
					return 'unknown_test';
				}

				try {
					return true === $callback( $member, (string) $test['arg'], $user_id, $now ) ? null : 'test_failed';
				} catch ( \Throwable ) {
					return 'test_error';
				}
		}

		return Decision::BAD_RULE;
	}

	/**
	 * The route's provenance label: the owner's, or built from its tests
	 * ("Gold", "Add-on purchase", a custom test's label). Never a tag name.
	 */
	public function label( Route $route ): string {
		if ( '' !== $route->label ) {
			return $route->label;
		}

		$parts = [];

		foreach ( $route->tests as $test ) {
			$parts[] = match ( $test['type'] ) {
				Route::LEVEL => $this->levels->get( $test['value'] )?->label ?? $test['value'],
				Route::TAG   => 'Add-on purchase',
				Route::TEST  => (string) ( $this->tests[ $test['value'] ]['label'] ?? $test['value'] ),
				default      => $test['value'],
			};
		}

		return implode( ' + ', array_unique( $parts ) );
	}

	/**
	 * The route in plain language, for admin: "Gold", "the ad-free tag",
	 * "Silver and the vip tag". Tag names are fine here; this is never shown
	 * to visitors.
	 */
	public function describe( Route $route ): string {
		$parts = array_map(
			fn( $t ) => match ( $t['type'] ) {
				Route::LEVEL => ( $this->levels->get( $t['value'] )?->label ?? $t['value'] . ' (unknown level)' ),
				Route::TAG   => 'the "' . $t['value'] . '" tag',
				Route::TEST  => ( isset( $this->tests[ $t['value'] ] ) ? (string) ( $this->tests[ $t['value'] ]['label'] ?? $t['value'] ) : $t['value'] . ' (unknown test)' ) . ( '' !== $t['arg'] ? ' (' . $t['arg'] . ')' : '' ),
				default      => $t['value'] . ' (unknown test type)',
			},
			$route->tests
		);

		return implode( ' and ', $parts ) . ( '' !== $route->label ? ' — shown as "' . $route->label . '"' : '' );
	}

	/**
	 * Problems with a route that make it grant nobody.
	 *
	 * @return string[] unknown_level, unknown_test, bad_test.
	 */
	public function problems( Route $route ): array {
		$problems = [];

		foreach ( $route->tests as $t ) {
			if ( Route::LEVEL === $t['type'] && ! $this->levels->has( $t['value'] ) ) {
				$problems[] = 'unknown_level';
			} elseif ( Route::TEST === $t['type'] && ! isset( $this->tests[ $t['value'] ] ) ) {
				$problems[] = 'unknown_test';
			} elseif ( ! in_array( $t['type'], Route::TYPES, true ) ) {
				$problems[] = 'bad_test';
			}
		}

		return array_values( array_unique( $problems ) );
	}
}
