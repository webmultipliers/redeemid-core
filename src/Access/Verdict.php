<?php

namespace WP2_Shopify\Access;

use WP2_Shopify\Entitlement\Decision;

/**
 * A visitor's result against an effective rule, with the level a wall should
 * offer next when they are locked out.
 */
final class Verdict {
	public function __construct(
		public readonly Decision $decision,
		public readonly ?string $next_level,
	) {}

	public function granted(): bool {
		return $this->decision->granted;
	}
}
