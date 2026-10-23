<?php

namespace WP2_Shopify\Entitlement;

/**
 * What the gate knows about the current visitor: the synced mirror only,
 * never a client-side flag (spec 5.3, 7.2).
 */
final class Member {
	/**
	 * @param string[] $tags Synced Shopify tags.
	 */
	public function __construct(
		public readonly bool $signed_in = false,
		public readonly bool $linked = false,
		public readonly array $tags = [],
		public readonly string $status = 'enabled',
		public readonly ?int $last_sync = null,
		public readonly bool $last_sync_failed = false,
	) {}

	public static function guest(): self {
		return new self();
	}
}
