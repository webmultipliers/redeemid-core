<?php

namespace RedeemID\Integrations;

/**
 * Keyboard access for the cart toggle of Shopify's WordPress plugin.
 *
 * In 1.0.0 its cart-toggle block prints an <a> with no href, which a keyboard
 * can't reach. Give it a button role and a tab stop, and open the cart on
 * Enter or Space. Wherever a theme places the block, it gets the fix; without
 * Shopify's plugin nothing here runs.
 */
final class OfficialCartToggle {
	public const BLOCK  = 'create-block/shopify-cart-toggle-block';
	public const HANDLE = 'redeemid-cart-toggle';

	public static function register(): void {
		add_filter( 'render_block_' . self::BLOCK, [ self::class, 'markup' ] );
		add_action( 'wp_enqueue_scripts', [ self::class, 'script' ] );
	}

	public static function markup( mixed $html ): mixed {
		if ( ! is_string( $html ) || ! class_exists( \WP_HTML_Tag_Processor::class ) ) {
			return $html;
		}

		$tags = new \WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag( [ 'tag_name' => 'A', 'class_name' => 'navigation__view-cart-link' ] ) ) {
			if ( null === $tags->get_attribute( 'href' ) ) {
				$tags->set_attribute( 'role', 'button' );
				$tags->set_attribute( 'tabindex', '0' );
				$tags->set_attribute( 'aria-haspopup', 'dialog' );
			}
		}

		return $tags->get_updated_html();
	}

	public static function script(): void {
		if ( OfficialPlugin::ABSENT === OfficialPlugin::state() ) {
			return;
		}

		wp_register_script( self::HANDLE, '', [], REDEEMID_VERSION, [ 'in_footer' => true ] );
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script(
			self::HANDLE,
			"document.addEventListener('keydown',function(e){var t=e.target;if((e.key==='Enter'||e.key===' ')&&t.matches&&t.matches('a.navigation__view-cart-link[role=\"button\"]')){e.preventDefault();t.click();}});"
		);
	}
}
