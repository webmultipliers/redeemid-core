<?php

namespace RedeemID;

/**
 * Shortcodes under the plugin's old names keep rendering, with a deprecation
 * notice: page builders and classic widgets store them where Legacy::import()
 * does not look. Each renders exactly as its new name does.
 */
final class Compat {
	public static function register(): void {
		foreach ( array_keys( Legacy::SHORTCODES ) as $old ) {
			add_shortcode( $old, [ self::class, 'legacy_shortcode' ] );
		}
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function legacy_shortcode( array|string $atts, ?string $content, string $tag ): string {
		global $shortcode_tags;

		$new = Legacy::SHORTCODES[ $tag ] ?? '';
		_deprecated_function( esc_html( "[{$tag}]" ), '2.0.0', esc_html( "[{$new}]" ) );

		return isset( $shortcode_tags[ $new ] ) ? (string) call_user_func( $shortcode_tags[ $new ], $atts, $content, $new ) : '';
	}
}
