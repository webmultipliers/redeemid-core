<?php
/**
 * Collection: one page of a Shopify collection's products as a grid.
 *
 * Rendered on the server from a cached Storefront read, with no personal
 * data, so pages stay cacheable. Pages are plain links carrying Shopify's
 * cursor (redeemid_after or redeemid_before, scoped to this collection by
 * redeemid_list), so paging works without JavaScript and each page has its
 * own URL. A product with one variant gets an Add to cart form (buy-now while
 * RedeemID's cart is off); one with options
 * or a required selling plan links to its product page instead, which the
 * redeemid_product_url filter can point anywhere (Shopify's own product page
 * by default, when the store has one).
 *
 * @var array $a          Block attributes.
 * @var bool  $isEditor   Rendering inside the block editor.
 * @var bool  $isPreview  Rendering the block inserter's hover preview.
 */

defined( 'ABSPATH' ) || exit;

$redeemid_chosen   = sanitize_title( \RedeemID\Blocks\Gate::option( $a['collectionHandle'] ?? '', '' ) );
$redeemid_handle   = \RedeemID\Commerce\Pages::handle_for( \RedeemID\Commerce\Pages::COLLECTION, $redeemid_chosen );
$redeemid_page_of  = \RedeemID\Commerce\Pages::is_page_block( \RedeemID\Commerce\Pages::COLLECTION, $redeemid_chosen );
$redeemid_cart     = \RedeemID\Commerce\Cart::enabled();
$redeemid_per_page = max( 1, min( \RedeemID\Shopify\Storefront::MAX_PER_PAGE, (int) ( $a['perPage'] ?? 12 ) ) );
$redeemid_columns  = max( 1, min( 6, (int) ( $a['columns'] ?? 3 ) ) );
$redeemid_button   = $redeemid_cart ? ( (string) ( $a['cartLabel'] ?? '' ) ?: __( 'Add to cart', 'redeemid' ) ) : ( (string) ( $a['buttonLabel'] ?? '' ) ?: __( 'Buy now', 'redeemid' ) );

// A cursor in the URL applies only to the collection it was made for.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; cursors are validated by Storefront::cursor().
$redeemid_mine   = ! $isEditor && isset( $_GET['redeemid_list'] ) && sanitize_title( wp_unslash( $_GET['redeemid_list'] ) ) === $redeemid_handle;
$redeemid_after  = $redeemid_mine && isset( $_GET['redeemid_after'] ) ? sanitize_text_field( wp_unslash( $_GET['redeemid_after'] ) ) : '';
$redeemid_before = $redeemid_mine && isset( $_GET['redeemid_before'] ) ? sanitize_text_field( wp_unslash( $_GET['redeemid_before'] ) ) : '';
// phpcs:enable

$redeemid_collection = null;
$redeemid_problem    = '';

if ( $isPreview ) {
	$redeemid_problem = 'preview';
} elseif ( '' === $redeemid_handle ) {
	$redeemid_problem = $isEditor ? __( 'Choose a Shopify collection in the block settings.', 'redeemid' ) : __( 'These products are not available right now.', 'redeemid' );
} elseif ( ! \RedeemID\Shopify\Storefront::available() ) {
	$redeemid_problem = $isEditor ? __( 'Add a Storefront private (or public) access token in RedeemID → Settings, or as REDEEMID_STOREFRONT_PRIVATE_TOKEN in wp-config.php, to show live products.', 'redeemid' ) : __( 'These products are not available right now.', 'redeemid' );
} else {
	$redeemid_collection = \RedeemID\Shopify\Storefront::collection( $redeemid_handle, $redeemid_per_page, $redeemid_after, $redeemid_before );

	// A stale or hand-edited cursor falls back to the first page rather than an error.
	if ( is_wp_error( $redeemid_collection ) && ( '' !== $redeemid_after || '' !== $redeemid_before ) ) {
		$redeemid_collection = \RedeemID\Shopify\Storefront::collection( $redeemid_handle, $redeemid_per_page );
	}

	if ( is_wp_error( $redeemid_collection ) ) {
		$redeemid_problem = __( 'Products are temporarily unavailable. Please try again shortly.', 'redeemid' );
	} elseif ( null === $redeemid_collection ) {
		$redeemid_problem = $isEditor ? sprintf( /* translators: %s: collection handle */ __( 'No Shopify collection with the handle "%s" is published to this sales channel.', 'redeemid' ), $redeemid_handle ) : __( 'These products are not available right now.', 'redeemid' );
	} elseif ( ! $redeemid_collection['products'] ) {
		$redeemid_problem = __( 'No products here yet.', 'redeemid' );
	}
}

$redeemid_style = '--redeemid-columns:' . $redeemid_columns;

if ( 'preview' === $redeemid_problem ) :
	?>
	<div useBlockProps class="redeemid-collection" style="<?php echo esc_attr( $redeemid_style ); ?>">
		<ul class="redeemid-collection__grid">
			<?php foreach ( [ __( 'Field notebook', 'redeemid' ), __( 'Canvas tote', 'redeemid' ), __( 'Enamel pin', 'redeemid' ) ] as $redeemid_sample ) : ?>
				<li class="redeemid-collection__item"><h3 class="redeemid-collection__name"><?php echo esc_html( $redeemid_sample ); ?></h3><p class="redeemid-collection__price">$18.00</p></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
elseif ( '' !== $redeemid_problem ) :
	?>
	<div useBlockProps class="redeemid-collection is-unavailable">
		<p class="redeemid-collection__notice"><?php echo esc_html( $redeemid_problem ); ?></p>
	</div>
	<?php
else :
$redeemid_here = $isEditor ? '' : remove_query_arg( [ 'redeemid_notice', 'redeemid_list', 'redeemid_after', 'redeemid_before' ], \RedeemID\Blocks\Gate::current_url() );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
$redeemid_notice = $isEditor ? '' : \RedeemID\Commerce\Cart::notice_html( sanitize_key( wp_unslash( $_GET['redeemid_notice'] ?? '' ) ) );
$redeemid_return = $isEditor ? '' : remove_query_arg( 'redeemid_notice', \RedeemID\Blocks\Gate::current_url() );
$redeemid_page   = static fn( string $key, string $cursor ): string => add_query_arg( [ 'redeemid_list' => $redeemid_handle, $key => rawurlencode( $cursor ) ], $redeemid_here );
?>
<div useBlockProps class="redeemid-collection" style="<?php echo esc_attr( $redeemid_style ); ?>">
	<?php if ( $redeemid_page_of ) : ?>
		<h1 class="redeemid-collection__title"><?php echo esc_html( $redeemid_collection['title'] ); ?></h1>
		<?php if ( '' !== $redeemid_collection['description'] ) : ?>
			<p class="redeemid-collection__description"><?php echo esc_html( $redeemid_collection['description'] ); ?></p>
		<?php endif; ?>
	<?php elseif ( ! empty( $a['showTitle'] ) ) : ?>
		<h2 class="redeemid-collection__title"><?php echo esc_html( $redeemid_collection['title'] ); ?></h2>
	<?php endif; ?>

	<?php if ( '' !== $redeemid_notice ) : ?>
		<p class="redeemid-collection__notice" role="status"><?php echo wp_kses_post( $redeemid_notice ); ?></p>
	<?php endif; ?>

	<ul class="redeemid-collection__grid">
		<?php foreach ( $redeemid_collection['products'] as $redeemid_item ) : ?>
			<?php
			$redeemid_url     = (string) apply_filters( 'redeemid_product_url', $redeemid_item['url'], $redeemid_item['handle'] );
			$redeemid_price   = \RedeemID\Shopify\Storefront::format_money( $redeemid_item['price'] );
			$redeemid_compare = $redeemid_item['compareAt'] ? \RedeemID\Shopify\Storefront::format_money( $redeemid_item['compareAt'] ) : '';
			?>
			<li class="redeemid-collection__item">
				<?php if ( $redeemid_item['image'] ) : ?>
					<img class="redeemid-collection__image" src="<?php echo esc_url( $redeemid_item['image']['url'] ); ?>" alt="<?php echo esc_attr( $redeemid_item['image']['alt'] ); ?>" <?php echo $redeemid_item['image']['width'] ? 'width="' . (int) $redeemid_item['image']['width'] . '" height="' . (int) $redeemid_item['image']['height'] . '"' : ''; ?> loading="lazy">
				<?php endif; ?>

				<h3 class="redeemid-collection__name">
					<?php if ( '' !== $redeemid_url ) : ?>
						<a href="<?php echo esc_url( $redeemid_url ); ?>"><?php echo esc_html( $redeemid_item['title'] ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $redeemid_item['title'] ); ?>
					<?php endif; ?>
				</h3>

				<?php if ( '' !== $redeemid_price ) : ?>
					<p class="redeemid-collection__price">
						<?php
						echo esc_html(
							$redeemid_item['from']
								/* translators: %s: the lowest price among the product's options */
								? sprintf( __( 'From %s', 'redeemid' ), $redeemid_price )
								: $redeemid_price
						);
						?>
						<?php if ( '' !== $redeemid_compare ) : ?>
							<s class="redeemid-collection__compare"><span class="screen-reader-text"><?php esc_html_e( 'Regular price:', 'redeemid' ); ?></span> <?php echo esc_html( $redeemid_compare ); ?></s>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<?php if ( ! $redeemid_item['available'] ) : ?>
					<p class="redeemid-collection__status"><?php esc_html_e( 'Sold out', 'redeemid' ); ?></p>
				<?php elseif ( ! empty( $a['showBuy'] ) && '' !== $redeemid_item['variant'] ) : ?>
					<?php if ( $isEditor ) : ?>
						<span class="wp-element-button"><?php echo esc_html( $redeemid_button ); ?></span>
					<?php else : ?>
						<form class="redeemid-collection__form" method="post" action="<?php echo esc_url( \RedeemID\Commerce\BuyNow::form_url() ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( $redeemid_cart ? \RedeemID\Commerce\Cart::ACTION : \RedeemID\Commerce\BuyNow::ACTION ); ?>">
							<input type="hidden" name="op" value="add">
							<input type="hidden" name="variant" value="<?php echo esc_attr( $redeemid_item['variant'] ); ?>">
							<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redeemid_return ); ?>">
							<button type="submit" class="wp-element-button" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: button label 2: product title */ __( '%1$s: %2$s', 'redeemid' ), $redeemid_button, $redeemid_item['title'] ) ); ?>"><?php echo esc_html( $redeemid_button ); ?></button>
						</form>
					<?php endif; ?>
				<?php elseif ( '' !== $redeemid_url ) : ?>
					<a class="wp-element-button" href="<?php echo esc_url( $redeemid_url ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product title */ __( 'Choose options: %s', 'redeemid' ), $redeemid_item['title'] ) ); ?>"><?php esc_html_e( 'Choose options', 'redeemid' ); ?></a>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( ! empty( $a['showPagination'] ) && ( '' !== $redeemid_collection['previous'] || '' !== $redeemid_collection['next'] ) ) : ?>
		<nav class="redeemid-collection__pages" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: collection title */ __( '%s pages', 'redeemid' ), $redeemid_collection['title'] ) ); ?>">
			<?php if ( '' !== $redeemid_collection['previous'] && ! $isEditor ) : ?>
				<a rel="prev" href="<?php echo esc_url( $redeemid_page( 'redeemid_before', $redeemid_collection['previous'] ) ); ?>"><?php esc_html_e( 'Previous', 'redeemid' ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $redeemid_collection['next'] && ! $isEditor ) : ?>
				<a rel="next" href="<?php echo esc_url( $redeemid_page( 'redeemid_after', $redeemid_collection['next'] ) ); ?>"><?php esc_html_e( 'Next', 'redeemid' ); ?></a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>
</div>
<?php endif; ?>
