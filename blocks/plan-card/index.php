<?php
/**
 * Plan card (spec 10.4): one membership plan or product with its live price
 * and a buy-now form.
 *
 * The markup holds no personal data and no nonce, so pages with plan cards
 * stay cacheable. The form posts to admin-post.php, which creates a cart,
 * attaches the signed-in customer's identity and redirects to checkout.
 * Prices are Shopify's, cached for a few minutes, never recomputed.
 *
 * @var array $a          Block attributes.
 * @var bool  $isEditor   Rendering inside the block editor.
 * @var bool  $isPreview  Rendering the block inserter's hover preview.
 */

defined( 'ABSPATH' ) || exit;

// A Blockstudio fetch-populated select stores {value, label}; older content holds the bare handle.
$wp2_handle      = sanitize_title( \WP2_Shopify\Blocks\Gate::option( $a['productHandle'] ?? '', '' ) );
$wp2_button      = (string) ( $a['buttonLabel'] ?? '' ) ?: __( 'Join now', 'wp2-shopify' );
$wp2_classes     = 'wp2-shopify-plan' . ( ! empty( $a['highlight'] ) ? ' is-highlighted' : '' );
$wp2_unavailable = static function ( string $message ) use ( $wp2_classes, $a ): void {
	?>
	<div useBlockProps class="<?php echo esc_attr( $wp2_classes ); ?> is-unavailable">
		<?php if ( ! empty( $a['heading'] ) ) : ?>
			<h3 class="wp2-shopify-plan__title"><?php echo esc_html( (string) $a['heading'] ); ?></h3>
		<?php endif; ?>
		<p class="wp2-shopify-plan__notice"><?php echo esc_html( $message ); ?></p>
	</div>
	<?php
};

// Blockstudio reads a template that returns early as one that rendered nothing, and then skips the
// block's stylesheet, so every branch that prints markup runs to the end of the file instead.
$wp2_product = null;
$wp2_problem = '';

if ( $isPreview ) {
	$wp2_problem = 'preview';
} elseif ( '' === $wp2_handle ) {
	$wp2_problem = $isEditor ? __( 'Choose a Shopify product in the block settings.', 'wp2-shopify' ) : __( 'This plan is not available right now.', 'wp2-shopify' );
} elseif ( ! \WP2_Shopify\Shopify\Storefront::available() ) {
	$wp2_problem = $isEditor ? __( 'Add a Storefront private (or public) access token in Shopify → Settings, or as WP2_SHOPIFY_STOREFRONT_PRIVATE_TOKEN in wp-config.php, to show live prices and checkout.', 'wp2-shopify' ) : __( 'This plan is not available right now.', 'wp2-shopify' );
} else {
	$wp2_product = \WP2_Shopify\Shopify\Storefront::product( $wp2_handle );

	if ( is_wp_error( $wp2_product ) ) {
		$wp2_problem = __( 'Plans are temporarily unavailable. Please try again shortly.', 'wp2-shopify' );
	} elseif ( null === $wp2_product || ! $wp2_product['variants'] ) {
		$wp2_problem = $isEditor ? sprintf( /* translators: %s: product handle */ __( 'No Shopify product with the handle "%s" is published to this sales channel.', 'wp2-shopify' ), $wp2_handle ) : __( 'This plan is not available right now.', 'wp2-shopify' );
	}
}

if ( 'preview' === $wp2_problem ) :
	// The inserter preview shows sample data, never a live Shopify call.
	?>
	<div useBlockProps class="<?php echo esc_attr( $wp2_classes ); ?>">
		<h3 class="wp2-shopify-plan__title"><?php echo esc_html( (string) ( $a['heading'] ?? '' ) ?: __( 'Gold membership', 'wp2-shopify' ) ); ?></h3>
		<p class="wp2-shopify-plan__price">$12.00</p>
		<p class="wp2-shopify-plan__description"><?php esc_html_e( 'Every story, the archive and the weekly briefing.', 'wp2-shopify' ); ?></p>
		<span class="wp-element-button"><?php echo esc_html( $wp2_button ); ?></span>
	</div>
	<?php
elseif ( '' !== $wp2_problem ) :
	$wp2_unavailable( $wp2_problem );
else :
$wp2_variant     = \WP2_Shopify\Shopify\Storefront::pick_variant( $wp2_product, (string) ( $a['variantId'] ?? '' ) );
$wp2_title       = (string) ( $a['heading'] ?? '' ) ?: $wp2_product['title'];
$wp2_description = (string) ( $a['description'] ?? '' ) ?: $wp2_product['description'];
$wp2_price       = \WP2_Shopify\Shopify\Storefront::format_money( $wp2_variant['price'] );
$wp2_compare     = ! empty( $a['showCompareAt'] ) && $wp2_variant['compareAt'] && (float) $wp2_variant['compareAt']['amount'] > (float) ( $wp2_variant['price']['amount'] ?? 0 ) ? \WP2_Shopify\Shopify\Storefront::format_money( $wp2_variant['compareAt'] ) : '';
$wp2_image       = ! empty( $a['showImage'] ) ? $wp2_product['image'] : null;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
$wp2_notice = $isEditor ? '' : \WP2_Shopify\Commerce\BuyNow::message( sanitize_key( wp_unslash( $_GET['wp2s_notice'] ?? '' ) ) );
$wp2_return = $isEditor ? '' : remove_query_arg( 'wp2s_notice', \WP2_Shopify\Blocks\Gate::current_url() );
?>
<div useBlockProps class="<?php echo esc_attr( $wp2_classes ); ?>">
	<?php if ( $wp2_image ) : ?>
		<img class="wp2-shopify-plan__image" src="<?php echo esc_url( $wp2_image['url'] ); ?>" alt="<?php echo esc_attr( $wp2_image['alt'] ); ?>" <?php echo $wp2_image['width'] ? 'width="' . (int) $wp2_image['width'] . '" height="' . (int) $wp2_image['height'] . '"' : ''; ?> loading="lazy">
	<?php endif; ?>

	<h3 class="wp2-shopify-plan__title"><?php echo esc_html( $wp2_title ); ?></h3>

	<?php if ( '' !== $wp2_price ) : ?>
		<p class="wp2-shopify-plan__price">
			<?php echo esc_html( $wp2_price ); ?>
			<?php if ( '' !== $wp2_compare ) : ?>
				<s class="wp2-shopify-plan__compare"><?php echo esc_html( $wp2_compare ); ?></s>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $wp2_description ) : ?>
		<p class="wp2-shopify-plan__description"><?php echo esc_html( wp_trim_words( $wp2_description, 40 ) ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $wp2_notice ) : ?>
		<p class="wp2-shopify-plan__notice" role="alert"><?php echo esc_html( $wp2_notice ); ?></p>
	<?php endif; ?>

	<?php if ( ! $wp2_variant['available'] ) : ?>
		<button type="button" class="wp-element-button" disabled><?php esc_html_e( 'Sold out', 'wp2-shopify' ); ?></button>
	<?php elseif ( $isEditor ) : ?>
		<span class="wp-element-button"><?php echo esc_html( $wp2_button ); ?></span>
	<?php else : ?>
		<form class="wp2-shopify-plan__form" method="post" action="<?php echo esc_url( \WP2_Shopify\Commerce\BuyNow::form_url() ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( \WP2_Shopify\Commerce\BuyNow::ACTION ); ?>">
			<input type="hidden" name="variant" value="<?php echo esc_attr( $wp2_variant['id'] ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $wp2_return ); ?>">
			<button type="submit" class="wp-element-button"><?php echo esc_html( $wp2_button ); ?></button>
		</form>
	<?php endif; ?>
</div>
<?php endif; ?>
