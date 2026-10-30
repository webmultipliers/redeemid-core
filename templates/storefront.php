<?php
/**
 * A storefront page (product, collection or cart) for classic themes.
 * A theme can replace it with redeemid-product.php, redeemid-collection.php
 * or redeemid-cart.php. Block themes use the block templates instead
 * (Appearance → Editor → Templates).
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="site-main redeemid-storefront-main">
	<?php echo do_blocks( \RedeemID\Commerce\Pages::block_markup() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- block output. ?>
</main>
<?php
get_footer();
