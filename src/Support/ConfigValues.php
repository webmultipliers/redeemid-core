<?php

namespace WP2_Shopify\Support;

/**
 * Where each configuration value comes from, and how a save from Shopify →
 * Settings changes the stored copy. Pure: constants, the stored option and the
 * cipher are passed in, so this is unit tested without WordPress.
 *
 * A defined wp-config.php constant always wins and cannot be edited from the
 * admin. Without one, the value saved in the options table is used. Secrets
 * are stored encrypted (Crypto) and never handed back to the form.
 */
final class ConfigValues {
	public const CONSTANT   = 'constant';
	public const SETTINGS   = 'settings';
	public const UNREADABLE = 'unreadable';
	public const NONE       = '';

	/** Why a submitted value was refused. */
	public const TOO_LONG   = 'too_long';
	public const BAD_DOMAIN = 'domain';
	public const BAD_FORMAT = 'format';
	public const NO_KEY     = 'no_key';

	/** Longest value the form accepts. Shopify IDs and tokens are far shorter. */
	public const MAX_LENGTH = 512;

	/**
	 * @param array<string, array{secret: bool, editable?: bool}> $definitions Config::definitions().
	 * @param \Closure(string): ?string                              $constant    The constant's value, or null when it is not defined.
	 * @param array<string, string>                                  $stored      The saved option: plain values, ciphertext for secrets.
	 * @param \Closure(): ?Crypto                                    $crypto      The cipher, or null when no key can be built.
	 */
	public function __construct(
		private readonly array $definitions,
		private readonly \Closure $constant,
		private readonly array $stored,
		private readonly \Closure $crypto
	) {}

	public function editable( string $name ): bool {
		return isset( $this->definitions[ $name ] ) && ( $this->definitions[ $name ]['editable'] ?? true );
	}

	public function locked( string $name ): bool {
		return null !== ( $this->constant )( $name );
	}

	/**
	 * @return array{value: string, source: string}
	 */
	public function resolve( string $name ): array {
		$constant = ( $this->constant )( $name );

		if ( null !== $constant ) {
			return [ 'value' => trim( $constant ), 'source' => '' === trim( $constant ) ? self::NONE : self::CONSTANT ];
		}

		$raw = $this->editable( $name ) ? (string) ( $this->stored[ $name ] ?? '' ) : '';

		if ( '' === $raw ) {
			return [ 'value' => '', 'source' => self::NONE ];
		}

		if ( empty( $this->definitions[ $name ]['secret'] ) ) {
			return [ 'value' => $raw, 'source' => self::SETTINGS ];
		}

		$plain = ( $this->crypto )()?->decrypt( $raw );

		return null === $plain ? [ 'value' => '', 'source' => self::UNREADABLE ] : [ 'value' => trim( $plain ), 'source' => self::SETTINGS ];
	}

	/**
	 * Apply a form submission. Locked and constant-only fields are ignored. A
	 * blank secret keeps what is stored; $clear removes it. A blank plain field
	 * removes it. An invalid value keeps the previous one and is reported.
	 *
	 * @param array<string, mixed> $input Field name => submitted value.
	 * @param string[]             $clear Secret names to remove.
	 * @return array{stored: array<string, string>, changed: string[], errors: array<string, string>} Errors map a name to TOO_LONG, BAD_DOMAIN, BAD_FORMAT or NO_KEY.
	 */
	public function apply( array $input, array $clear = [] ): array {
		$stored  = $this->stored;
		$changed = [];
		$errors  = [];

		foreach ( $this->definitions as $name => $def ) {
			if ( ! $this->editable( $name ) || $this->locked( $name ) ) {
				continue;
			}

			$before = $this->resolve( $name );
			$secret = ! empty( $def['secret'] );
			$value  = is_scalar( $input[ $name ] ?? null ) ? trim( (string) $input[ $name ] ) : '';

			if ( $secret && in_array( $name, $clear, true ) ) {
				unset( $stored[ $name ] );
			} elseif ( $secret && '' === $value ) {
				continue;
			} elseif ( '' === $value ) {
				unset( $stored[ $name ] );
			} else {
				$error = self::invalid( $name, $value );

				if ( null === $error ) {
					$value = 'WP2_SHOPIFY_STORE_DOMAIN' === $name ? self::normalise_domain( $value ) : $value;

					if ( $secret && $value === $before['value'] && self::SETTINGS === $before['source'] ) {
						continue;
					}

					$crypto = $secret ? ( $this->crypto )() : null;

					if ( $secret && ! $crypto ) {
						$error = self::NO_KEY;
					} else {
						$stored[ $name ] = $crypto ? $crypto->encrypt( $value ) : $value;
					}
				}

				if ( null !== $error ) {
					$errors[ $name ] = $error;
					continue;
				}
			}

			$after = ( new self( $this->definitions, $this->constant, $stored, $this->crypto ) )->resolve( $name );

			if ( $after !== $before ) {
				$changed[] = $name;
			}
		}

		return [ 'stored' => $stored, 'changed' => $changed, 'errors' => $errors ];
	}

	/**
	 * @return string|null Why the value is refused (TOO_LONG, BAD_DOMAIN, BAD_FORMAT), or null when it is fine.
	 */
	public static function invalid( string $name, string $value ): ?string {
		if ( strlen( $value ) > self::MAX_LENGTH ) {
			return self::TOO_LONG;
		}

		if ( 'WP2_SHOPIFY_STORE_DOMAIN' === $name ) {
			return preg_match( '/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', self::normalise_domain( $value ) ) ? null : self::BAD_DOMAIN;
		}

		// Shopify IDs and tokens are printable ASCII without spaces.
		return preg_match( '/^[\x21-\x7E]+$/', $value ) ? null : self::BAD_FORMAT;
	}

	/**
	 * No scheme, no path, lower case.
	 */
	public static function normalise_domain( string $domain ): string {
		$domain = (string) preg_replace( '#^https?://#', '', strtolower( trim( $domain ) ) );

		return rtrim( (string) strtok( $domain, '/' ), '/' );
	}
}
