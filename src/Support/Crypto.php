<?php

namespace RedeemID\Support;

/**
 * Authenticated encryption for tokens at rest.
 *
 * Uses libsodium (bundled with PHP 8.2) and a key that is either supplied
 * explicitly or derived with HKDF from the WordPress salts. It never stores the
 * key next to the ciphertext.
 */
final class Crypto {
	private const PREFIX = 'redeemid1:';

	private function __construct( private readonly string $key ) {}

	/**
	 * @param string $explicit_key Base64 32-byte key (REDEEMID_ENCRYPTION_KEY) or ''.
	 * @param string $salt_material Concatenated WordPress salts, used when no explicit key is set.
	 */
	public static function from_config( string $explicit_key, string $salt_material ): self {
		if ( '' !== $explicit_key ) {
			$decoded = base64_decode( $explicit_key, true );

			if ( false === $decoded || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $decoded ) ) {
				throw new \InvalidArgumentException( 'REDEEMID_ENCRYPTION_KEY must be a base64-encoded 32-byte key.' );
			}

			return new self( $decoded );
		}

		if ( strlen( $salt_material ) < 32 ) {
			throw new \InvalidArgumentException( 'WordPress salts are missing or too short to derive an encryption key.' );
		}

		return new self( hash_hkdf( 'sha256', $salt_material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'redeemid/token-encryption/v1' ) );
	}

	public function encrypt( string $plaintext ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return self::PREFIX . sodium_bin2base64( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $this->key ), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
	}

	/**
	 * @return string|null Null when the value is not ours, was tampered with, or the key changed.
	 */
	public function decrypt( string $ciphertext ): ?string {
		if ( ! self::is_encrypted( $ciphertext ) ) {
			return null;
		}

		try {
			$raw = sodium_base642bin( substr( $ciphertext, strlen( self::PREFIX ) ), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
		} catch ( \SodiumException ) {
			return null;
		}

		if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$this->key
		);

		return false === $plain ? null : $plain;
	}

	public static function is_encrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}
}
