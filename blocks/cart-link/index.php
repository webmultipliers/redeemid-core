<?php
/**
 * Cart link: a plain link to the cart page, the same for every visitor so
 * the page stays cacheable. A view script fills in the item count from the
 * readable redeemid_cart_count cookie (a number only, never a cart ID).
 * Renders nothing while RedeemID's cart is off, for example while Shopify's
 * own plugin runs the cart.
 *
 * @var array $a          Block attributes.
 * @var bool  $isEditor   Rendering inside the block editor.
 * @var bool  $isPreview  Rendering the block inserter's hover preview.
 */

defined( 'ABSPATH' ) || exit;

$redeemid_label = (string) ( $a['label'] ?? '' ) ?: __( 'Cart', 'redeemid' );
$redeemid_show  = $isEditor || $isPreview || \RedeemID\Commerce\Cart::enabled();

if ( $redeemid_show ) :
	?>
	<a useBlockProps class="redeemid-cart-link" href="<?php echo esc_url( $isEditor ? '#' : \RedeemID\Commerce\Cart::url() ); ?>" data-redeemid-cart-link data-redeemid-items="<?php echo esc_attr( /* translators: %d: number of items */ __( '%d items', 'redeemid' ) ); ?>" data-redeemid-item="<?php esc_attr_e( '1 item', 'redeemid' ); ?>">
		<?php if ( ! empty( $a['showIcon'] ) ) : ?>
			<svg class="redeemid-cart-link__icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M7 18a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm10 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4ZM2 2h3.3l.9 2H21l-3 9H8.1l-.9 2H19v2H4l2.2-4.4L3.9 4H2V2Z"/></svg>
		<?php endif; ?>
		<span class="redeemid-cart-link__label"><?php echo esc_html( $redeemid_label ); ?></span>
		<span class="redeemid-cart-link__count" hidden></span>
	</a>
	<?php
endif;
