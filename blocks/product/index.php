<?php
/**
 * Product: one Shopify product with its live price, an options picker,
 * quantity and a buy-now form.
 *
 * Like the plan card, the markup holds no personal data and no nonce, so the
 * page stays cacheable, and buying needs no JavaScript: the form posts the
 * chosen variant and quantity to admin-post.php, which creates a cart (with
 * the signed-in customer attached) and redirects to Shopify checkout.
 * A product that can only be sold on a selling plan sells its first variant
 * on its first plan; the Plan Card block picks plans precisely.
 *
 * @var array $a          Block attributes.
 * @var bool  $isEditor   Rendering inside the block editor.
 * @var bool  $isPreview  Rendering the block inserter's hover preview.
 */

defined( 'ABSPATH' ) || exit;

$redeemid_chosen = sanitize_title( \RedeemID\Blocks\Gate::option( $a['productHandle'] ?? '', '' ) );
$redeemid_handle = \RedeemID\Commerce\Pages::handle_for( \RedeemID\Commerce\Pages::PRODUCT, $redeemid_chosen );
$redeemid_button = (string) ( $a['buttonLabel'] ?? '' ) ?: __( 'Buy now', 'redeemid' );
$redeemid_cart   = \RedeemID\Commerce\Cart::enabled();
$redeemid_add    = (string) ( $a['cartLabel'] ?? '' ) ?: __( 'Add to cart', 'redeemid' );
$redeemid_tag    = \RedeemID\Commerce\Pages::is_page_block( \RedeemID\Commerce\Pages::PRODUCT, $redeemid_chosen ) ? 'h1' : 'h3';

// Every branch that prints markup runs to the end of the file; see the plan card.
$redeemid_product = null;
$redeemid_problem = '';

if ( $isPreview ) {
	$redeemid_problem = 'preview';
} elseif ( '' === $redeemid_handle ) {
	$redeemid_problem = $isEditor ? __( 'Choose a Shopify product in the block settings.', 'redeemid' ) : __( 'This product is not available right now.', 'redeemid' );
} elseif ( ! \RedeemID\Shopify\Storefront::available() ) {
	$redeemid_problem = $isEditor ? __( 'Add a Storefront private (or public) access token in RedeemID → Settings, or as REDEEMID_STOREFRONT_PRIVATE_TOKEN in wp-config.php, to show live prices and checkout.', 'redeemid' ) : __( 'This product is not available right now.', 'redeemid' );
} else {
	$redeemid_product = \RedeemID\Shopify\Storefront::product( $redeemid_handle );

	if ( is_wp_error( $redeemid_product ) ) {
		$redeemid_problem = __( 'Products are temporarily unavailable. Please try again shortly.', 'redeemid' );
	} elseif ( null === $redeemid_product || ! $redeemid_product['variants'] ) {
		$redeemid_problem = $isEditor ? sprintf( /* translators: %s: product handle */ __( 'No Shopify product with the handle "%s" is published to this sales channel.', 'redeemid' ), $redeemid_handle ) : __( 'This product is not available right now.', 'redeemid' );
	}
}

if ( 'preview' === $redeemid_problem ) :
	?>
	<div useBlockProps class="redeemid-product">
		<h3 class="redeemid-product__title"><?php esc_html_e( 'Field notebook', 'redeemid' ); ?></h3>
		<p class="redeemid-product__price">$18.00</p>
		<span class="wp-element-button"><?php echo esc_html( $redeemid_button ); ?></span>
	</div>
	<?php
elseif ( '' !== $redeemid_problem ) :
	?>
	<div useBlockProps class="redeemid-product is-unavailable">
		<p class="redeemid-product__notice"><?php echo esc_html( $redeemid_problem ); ?></p>
	</div>
	<?php
else :
$redeemid_variant  = \RedeemID\Shopify\Storefront::pick_variant( $redeemid_product );
$redeemid_plan     = \RedeemID\Shopify\Storefront::pick_plan( $redeemid_product, $redeemid_variant );
$redeemid_choices  = $redeemid_product['has_options'] && ! $redeemid_plan ? $redeemid_product['variants'] : [];
$redeemid_charge   = $redeemid_plan && $redeemid_plan['price'] ? $redeemid_plan : $redeemid_variant;
$redeemid_compare  = ! empty( $a['showCompareAt'] ) && ! $redeemid_plan && $redeemid_variant['compareAt'] && (float) $redeemid_variant['compareAt']['amount'] > (float) ( $redeemid_variant['price']['amount'] ?? 0 ) ? \RedeemID\Shopify\Storefront::format_money( $redeemid_variant['compareAt'] ) : '';
$redeemid_prices   = array_unique( array_map( static fn( $v ) => (string) ( $v['price']['amount'] ?? '' ), $redeemid_choices ) );
$redeemid_sellable = array_filter( $redeemid_choices ?: [ $redeemid_variant ], static fn( $v ) => $v['available'] );
$redeemid_image    = ! empty( $a['showImage'] ) ? $redeemid_product['image'] : null;
$redeemid_field    = wp_unique_id( 'redeemid-product-' );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
$redeemid_notice = $isEditor ? '' : \RedeemID\Commerce\Cart::notice_html( sanitize_key( wp_unslash( $_GET['redeemid_notice'] ?? '' ) ) );
$redeemid_return = $isEditor ? '' : remove_query_arg( 'redeemid_notice', \RedeemID\Blocks\Gate::current_url() );
?>
<div useBlockProps class="redeemid-product">
	<?php if ( $redeemid_image ) : ?>
		<img class="redeemid-product__image" src="<?php echo esc_url( $redeemid_image['url'] ); ?>" alt="<?php echo esc_attr( $redeemid_image['alt'] ); ?>" <?php echo $redeemid_image['width'] ? 'width="' . (int) $redeemid_image['width'] . '" height="' . (int) $redeemid_image['height'] . '"' : ''; ?> loading="lazy">
	<?php endif; ?>

	<<?php echo esc_attr( $redeemid_tag ); ?> class="redeemid-product__title"><?php echo esc_html( $redeemid_product['title'] ); ?></<?php echo esc_attr( $redeemid_tag ); ?>>

	<?php // With options at different prices, each option names its price, so the headline price would mislead. ?>
	<?php if ( count( $redeemid_prices ) <= 1 ) : ?>
		<p class="redeemid-product__price">
			<?php if ( '' !== $redeemid_compare ) : ?>
				<span class="screen-reader-text"><?php esc_html_e( 'Price:', 'redeemid' ); ?></span>
			<?php endif; ?>
			<?php echo esc_html( \RedeemID\Shopify\Storefront::format_money( $redeemid_charge['price'] ) ); ?>
			<?php if ( '' !== $redeemid_compare ) : ?>
				<s class="redeemid-product__compare"><span class="screen-reader-text"><?php esc_html_e( 'Regular price:', 'redeemid' ); ?></span> <?php echo esc_html( $redeemid_compare ); ?></s>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( $redeemid_plan && '' !== $redeemid_plan['name'] ) : ?>
		<p class="redeemid-product__terms"><?php echo esc_html( $redeemid_plan['name'] ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $a['showDescription'] ) && '' !== $redeemid_product['description'] ) : ?>
		<p class="redeemid-product__description"><?php echo esc_html( $redeemid_product['description'] ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $redeemid_notice ) : ?>
		<p class="redeemid-product__notice" role="status"><?php echo wp_kses_post( $redeemid_notice ); ?></p>
	<?php endif; ?>

	<?php if ( ! $redeemid_sellable ) : ?>
		<button type="button" class="wp-element-button" disabled><?php esc_html_e( 'Sold out', 'redeemid' ); ?></button>
	<?php else : ?>
		<form class="redeemid-product__form" method="post" action="<?php echo esc_url( \RedeemID\Commerce\BuyNow::form_url() ); ?>">
			<?php // The buttons name the action, so one form can add to the cart or buy now; Enter adds to the cart. ?>
			<input type="hidden" name="op" value="add">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redeemid_return ); ?>">
			<?php if ( $redeemid_plan ) : ?>
				<input type="hidden" name="selling_plan" value="<?php echo esc_attr( $redeemid_plan['id'] ); ?>">
			<?php endif; ?>

			<?php if ( $redeemid_choices ) : ?>
				<p class="redeemid-product__field">
					<label for="<?php echo esc_attr( $redeemid_field ); ?>-variant"><?php esc_html_e( 'Option', 'redeemid' ); ?></label>
					<select id="<?php echo esc_attr( $redeemid_field ); ?>-variant" name="variant">
						<?php foreach ( $redeemid_choices as $redeemid_choice ) : ?>
							<option value="<?php echo esc_attr( $redeemid_choice['id'] ); ?>" <?php selected( $redeemid_choice['id'], $redeemid_variant['id'] ); ?> <?php disabled( ! $redeemid_choice['available'] ); ?>>
								<?php
								echo esc_html(
									$redeemid_choice['title']
									. ( count( $redeemid_prices ) > 1 ? ' · ' . \RedeemID\Shopify\Storefront::format_money( $redeemid_choice['price'] ) : '' )
									. ( $redeemid_choice['available'] ? '' : ' · ' . __( 'sold out', 'redeemid' ) )
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php else : ?>
				<input type="hidden" name="variant" value="<?php echo esc_attr( $redeemid_variant['id'] ); ?>">
			<?php endif; ?>

			<?php if ( ! empty( $a['showQuantity'] ) ) : ?>
				<p class="redeemid-product__field">
					<label for="<?php echo esc_attr( $redeemid_field ); ?>-quantity"><?php esc_html_e( 'Quantity', 'redeemid' ); ?></label>
					<input id="<?php echo esc_attr( $redeemid_field ); ?>-quantity" name="quantity" type="number" inputmode="numeric" min="1" max="10" value="1">
				</p>
			<?php endif; ?>

			<p class="redeemid-product__buttons">
				<?php if ( $isEditor ) : ?>
					<?php if ( $redeemid_cart ) : ?>
						<span class="wp-element-button"><?php echo esc_html( $redeemid_add ); ?></span>
					<?php endif; ?>
					<?php if ( ! $redeemid_cart || ! empty( $a['showBuyNow'] ) ) : ?>
						<span class="wp-element-button<?php echo $redeemid_cart ? ' is-secondary' : ''; ?>"><?php echo esc_html( $redeemid_button ); ?></span>
					<?php endif; ?>
				<?php else : ?>
					<?php if ( $redeemid_cart ) : ?>
						<button type="submit" class="wp-element-button" name="action" value="<?php echo esc_attr( \RedeemID\Commerce\Cart::ACTION ); ?>"><?php echo esc_html( $redeemid_add ); ?></button>
					<?php endif; ?>
					<?php if ( ! $redeemid_cart || ! empty( $a['showBuyNow'] ) ) : ?>
						<button type="submit" class="wp-element-button<?php echo $redeemid_cart ? ' is-secondary' : ''; ?>" name="action" value="<?php echo esc_attr( \RedeemID\Commerce\BuyNow::ACTION ); ?>"><?php echo esc_html( $redeemid_button ); ?></button>
					<?php endif; ?>
				<?php endif; ?>
			</p>
		</form>
	<?php endif; ?>
</div>
<?php endif; ?>
