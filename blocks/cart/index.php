<?php
/**
 * Cart: the visitor's Shopify cart, from Commerce\Cart. Personal, so the
 * page is kept out of shared caches. Every control is a plain form posting
 * to admin-post.php (Cart::ACTION), so it works without JavaScript.
 *
 * @var array $a          Block attributes.
 * @var bool  $isEditor   Rendering inside the block editor.
 * @var bool  $isPreview  Rendering the block inserter's hover preview.
 */

defined( 'ABSPATH' ) || exit;

$redeemid_heading  = (string) ( $a['heading'] ?? '' );
$redeemid_checkout = (string) ( $a['checkoutLabel'] ?? '' ) ?: __( 'Check out', 'redeemid' );
$redeemid_continue = (string) ( $a['continueUrl'] ?? '' ) ?: home_url( '/' );
$redeemid_enabled  = \RedeemID\Commerce\Cart::enabled();
$redeemid_cart     = null;
// The page heading on the cart page; a section heading anywhere else.
$redeemid_tag = \RedeemID\Commerce\Pages::is_page_block( \RedeemID\Commerce\Pages::CART, '' ) ? 'h1' : 'h2';

if ( $isEditor || $isPreview ) {
	$redeemid_cart = [
		'lines'    => [
			[ 'id' => '', 'quantity' => 2, 'total' => [ 'amount' => '36.00', 'currency' => 'USD' ], 'plan' => '', 'option' => '', 'handle' => '', 'title' => __( 'Field notebook', 'redeemid' ), 'image' => null ],
		],
		'subtotal' => [ 'amount' => '36.00', 'currency' => 'USD' ],
		'codes'    => [],
	];
} elseif ( $redeemid_enabled ) {
	\RedeemID\Features\Cache::private_page();
	$redeemid_cart = \RedeemID\Commerce\Cart::current();
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
$redeemid_notice = $isEditor ? '' : \RedeemID\Commerce\Cart::notice_html( sanitize_key( wp_unslash( $_GET['redeemid_notice'] ?? '' ) ) );
$redeemid_return = $isEditor ? '' : remove_query_arg( 'redeemid_notice', \RedeemID\Blocks\Gate::current_url() );
$redeemid_hidden = static function ( string $op ) use ( $redeemid_return ): void {
	?>
	<input type="hidden" name="action" value="<?php echo esc_attr( \RedeemID\Commerce\Cart::ACTION ); ?>">
	<input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>">
	<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redeemid_return ); ?>">
	<?php
};

if ( $redeemid_enabled || $isEditor || $isPreview ) :
	?>
	<section useBlockProps class="redeemid-cart" aria-labelledby="redeemid-cart-heading">
		<<?php echo esc_attr( $redeemid_tag ); ?> id="redeemid-cart-heading" class="redeemid-cart__heading"><?php echo esc_html( $redeemid_heading ?: __( 'Your cart', 'redeemid' ) ); ?></<?php echo esc_attr( $redeemid_tag ); ?>>

		<?php if ( '' !== $redeemid_notice ) : ?>
			<p class="redeemid-cart__notice" role="status"><?php echo wp_kses_post( $redeemid_notice ); ?></p>
		<?php endif; ?>

		<?php if ( ! $redeemid_cart || ! $redeemid_cart['lines'] ) : ?>
			<p class="redeemid-cart__empty"><?php esc_html_e( 'Your cart is empty.', 'redeemid' ); ?></p>
			<p><a href="<?php echo esc_url( $redeemid_continue ); ?>"><?php esc_html_e( 'Continue shopping', 'redeemid' ); ?></a></p>
		<?php else : ?>
			<ul class="redeemid-cart__lines">
				<?php foreach ( $redeemid_cart['lines'] as $redeemid_i => $redeemid_line ) : ?>
					<?php
					$redeemid_url   = '' !== $redeemid_line['handle'] ? (string) apply_filters( 'redeemid_product_url', '', $redeemid_line['handle'] ) : '';
					$redeemid_field = 'redeemid-cart-qty-' . $redeemid_i;
					?>
					<li class="redeemid-cart__line">
						<?php if ( $redeemid_line['image'] ) : ?>
							<img class="redeemid-cart__image" src="<?php echo esc_url( $redeemid_line['image']['url'] ); ?>" alt="" width="80" height="80" loading="lazy">
						<?php endif; ?>
						<div class="redeemid-cart__item">
							<p class="redeemid-cart__title">
								<?php if ( '' !== $redeemid_url ) : ?>
									<a href="<?php echo esc_url( $redeemid_url ); ?>"><?php echo esc_html( $redeemid_line['title'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $redeemid_line['title'] ); ?>
								<?php endif; ?>
							</p>
							<?php if ( '' !== $redeemid_line['option'] || '' !== $redeemid_line['plan'] ) : ?>
								<p class="redeemid-cart__option"><?php echo esc_html( implode( ' · ', array_filter( [ $redeemid_line['option'], $redeemid_line['plan'] ] ) ) ); ?></p>
							<?php endif; ?>
							<form class="redeemid-cart__qty" method="post" action="<?php echo esc_url( \RedeemID\Commerce\Cart::form_url() ); ?>">
								<?php $redeemid_hidden( 'update' ); ?>
								<input type="hidden" name="line" value="<?php echo esc_attr( $redeemid_line['id'] ); ?>">
								<label for="<?php echo esc_attr( $redeemid_field ); ?>"><?php echo esc_html( sprintf( /* translators: %s: product title */ __( 'Quantity of %s', 'redeemid' ), $redeemid_line['title'] ) ); ?></label>
								<input id="<?php echo esc_attr( $redeemid_field ); ?>" name="quantity" type="number" inputmode="numeric" min="0" max="<?php echo (int) \RedeemID\Commerce\Cart::MAX_QUANTITY; ?>" value="<?php echo (int) $redeemid_line['quantity']; ?>">
								<button type="submit" class="redeemid-cart__button"><?php esc_html_e( 'Update', 'redeemid' ); ?></button>
								<button type="submit" class="redeemid-cart__button" name="remove" value="1" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product title */ __( 'Remove %s', 'redeemid' ), $redeemid_line['title'] ) ); ?>"><?php esc_html_e( 'Remove', 'redeemid' ); ?></button>
							</form>
						</div>
						<p class="redeemid-cart__total"><?php echo esc_html( \RedeemID\Shopify\Storefront::format_money( $redeemid_line['total'] ) ); ?></p>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( ! empty( $a['showDiscount'] ) ) : ?>
				<form class="redeemid-cart__discount" method="post" action="<?php echo esc_url( \RedeemID\Commerce\Cart::form_url() ); ?>">
					<?php $redeemid_hidden( 'discount' ); ?>
					<label for="redeemid-cart-code"><?php esc_html_e( 'Discount code', 'redeemid' ); ?></label>
					<input id="redeemid-cart-code" name="code" type="text" autocomplete="off" value="<?php echo esc_attr( $redeemid_cart['codes'][0]['code'] ?? '' ); ?>">
					<button type="submit" class="redeemid-cart__button"><?php esc_html_e( 'Apply', 'redeemid' ); ?></button>
					<?php foreach ( $redeemid_cart['codes'] as $redeemid_code ) : ?>
						<?php if ( ! $redeemid_code['applies'] ) : ?>
							<p class="redeemid-cart__hint"><?php echo esc_html( sprintf( /* translators: %s: discount code */ __( '%s doesn’t apply to this cart.', 'redeemid' ), $redeemid_code['code'] ) ); ?></p>
						<?php endif; ?>
					<?php endforeach; ?>
				</form>
			<?php endif; ?>

			<div class="redeemid-cart__summary">
				<p class="redeemid-cart__subtotal"><?php esc_html_e( 'Subtotal', 'redeemid' ); ?> <strong><?php echo esc_html( \RedeemID\Shopify\Storefront::format_money( $redeemid_cart['subtotal'] ) ); ?></strong></p>
				<p class="redeemid-cart__hint"><?php esc_html_e( 'Taxes and shipping are worked out at checkout.', 'redeemid' ); ?></p>
				<?php if ( $isEditor ) : ?>
					<span class="wp-element-button"><?php echo esc_html( $redeemid_checkout ); ?></span>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( \RedeemID\Commerce\Cart::form_url() ); ?>">
						<?php $redeemid_hidden( 'checkout' ); ?>
						<button type="submit" class="wp-element-button"><?php echo esc_html( $redeemid_checkout ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</section>
	<?php
endif;
