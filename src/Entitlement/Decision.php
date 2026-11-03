<?php

namespace RedeemID\Entitlement;

final class Decision {
	public const GRANTED      = 'granted';
	public const GUEST        = 'guest';
	public const NOT_LINKED   = 'not_linked';
	public const MISSING_TAGS = 'missing_tags';
	public const DISABLED     = 'disabled';
	public const NO_MIRROR    = 'no_mirror';
	public const BAD_RULE     = 'bad_rule';
	public const DRIP         = 'drip';
	public const METERED      = 'metered';
	public const BOUGHT       = 'bought';

	private function __construct(
		public readonly bool $granted,
		public readonly string $reason,
	) {}

	public static function grant(): self {
		return new self( true, self::GRANTED );
	}

	/**
	 * Read as one of the signed-in reader's metered items, not through a level.
	 */
	public static function metered(): self {
		return new self( true, self::METERED );
	}

	/**
	 * Read because the visitor bought this one item: they hold its unlock tag.
	 */
	public static function bought(): self {
		return new self( true, self::BOUGHT );
	}

	public static function deny( string $reason ): self {
		return new self( false, $reason );
	}
}
