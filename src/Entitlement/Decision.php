<?php

namespace WP2_Shopify\Entitlement;

final class Decision {
	public const GRANTED      = 'granted';
	public const GUEST        = 'guest';
	public const NOT_LINKED   = 'not_linked';
	public const MISSING_TAGS = 'missing_tags';
	public const DISABLED     = 'disabled';
	public const NO_MIRROR    = 'no_mirror';
	public const BAD_RULE     = 'bad_rule';

	private function __construct(
		public readonly bool $granted,
		public readonly string $reason,
	) {}

	public static function grant(): self {
		return new self( true, self::GRANTED );
	}

	public static function deny( string $reason ): self {
		return new self( false, $reason );
	}
}
