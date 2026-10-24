<?php

namespace WP2_Shopify\Blocks;

/**
 * Keeps content saved before a field changed type rendering.
 *
 * Some text fields became Blockstudio link fields ({url, ...}) or selects
 * ({value, label}). Both are object attributes, and WordPress drops a stored
 * plain string that does not match the attribute type, so old content would
 * silently lose its URL or product. This rewrites those strings into the new
 * shape before the block renders, in both places Blockstudio reads values:
 * top-level attributes (programmatic writes, patterns) and the editor's nested
 * blockstudio.attributes. Saving the post in the editor stores the new shape.
 */
final class Upgrade {
	/** Former text fields, per block, and the shape each became. */
	public const FIELDS = [
		Gate::PARENT              => [ 'fallbackCtaUrl' => 'link', 'redirectUrl' => 'link' ],
		'wp2-shopify/auth-action' => [ 'redirectAfterLogin' => 'link', 'redirectAfterLogout' => 'link' ],
		'wp2-shopify/plan-card'   => [ 'productHandle' => 'select' ],
	];

	/**
	 * render_block_data filter; also used directly on parsed blocks. Pure.
	 */
	public static function block( mixed $parsed ): mixed {
		if ( ! is_array( $parsed ) ) {
			return $parsed;
		}

		foreach ( self::FIELDS[ $parsed['blockName'] ?? '' ] ?? [] as $field => $shape ) {
			if ( is_string( $parsed['attrs'][ $field ] ?? null ) ) {
				$parsed['attrs'][ $field ] = self::value( $parsed['attrs'][ $field ], $shape );
			}

			if ( is_string( $parsed['attrs']['blockstudio']['attributes'][ $field ] ?? null ) ) {
				$parsed['attrs']['blockstudio']['attributes'][ $field ] = self::value( $parsed['attrs']['blockstudio']['attributes'][ $field ], $shape );
			}
		}

		return $parsed;
	}

	private static function value( string $value, string $shape ): array {
		return 'link' === $shape ? [ 'url' => $value ] : [ 'value' => $value, 'label' => $value ];
	}
}
