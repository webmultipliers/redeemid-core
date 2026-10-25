<?php

namespace RedeemID\Features;

/**
 * One feature decision for one visitor, with provenance and the trace the
 * Test tab prints.
 */
final class Grant {
	public const GRANTED      = 'granted';
	public const UNREGISTERED = 'unregistered';
	public const NO_CONDITION = 'no_condition';
	public const NO_ROUTE     = 'no_route';
	public const FILTERED     = 'filtered';

	/**
	 * @param string[] $routes Labels of every route that passed, deduplicated: one grant, all routes shown.
	 * @param array<int, array{label: string, passed: bool, tests: array<int, array{type: string, value: string, arg: string, passed: bool, reason: string}>}> $trace
	 */
	public function __construct(
		public readonly string $slug,
		public readonly bool $granted,
		public readonly string $reason,
		public readonly array $routes = [],
		public readonly array $trace = [],
	) {}

	/** The first granting route's label ("Gold"), or ''. */
	public function provenance(): string {
		return $this->routes[0] ?? '';
	}

	public function with( bool $granted, string $reason ): self {
		return new self( $this->slug, $granted, $reason, $granted ? $this->routes : [], $this->trace );
	}
}
