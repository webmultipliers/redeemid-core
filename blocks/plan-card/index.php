<?php
/**
 * Plan card: one membership plan or product with its live price
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
$redeemid_handle      = sanitize_title( \RedeemID\Blocks\Gate::option( $a['productHandle'] ?? '', '' ) );
$redeemid_button      = (string) ( $a['buttonLabel'] ?? '' ) ?: __( 'Join now', 'redeemid' );
$redeemid_classes     = 'redeemid-plan' . ( ! empty( $a['highlight'] ) ? ' is-highlighted' : '' );
$redeemid_unavailable = static function ( string $message ) use ( $redeemid_classes, $a ): void {
	?>
	<div useBlockProps class="<?php echo esc_attr( $redeemid_classes ); ?> is-unavailable">
		<?php if ( ! empty( $a['heading'] ) ) : ?>
			<h3 class="redeemid-plan__title"><?php echo esc_html( (string) $a['heading'] ); ?></h3>
		<?php endif; ?>
		<p class="redeemid-plan__notice"><?php echo esc_html( $message ); ?></p>
	</div>
	<?php
};

// Blockstudio reads a template that returns early as one that rendered nothing, and then skips the
// block's stylesheet, so every branch that prints markup runs to the end of the file instead.
$redeemid_product = null;
$redeemid_problem = '';

if ( $isPreview ) {
	$redeemid_problem = 'preview';
} elseif ( '' === $redeemid_handle ) {
	$redeemid_problem = $isEditor ? __( 'Choose a Shopify product in the block settings.', 'redeemid' ) : __( 'This plan is not available right now.', 'redeemid' );
} elseif ( ! \RedeemID\Shopify\Storefront::available() ) {
	$redeemid_problem = $isEditor ? __( 'Add a Storefront private (or public) access token in RedeemID → Settings, or as REDEEMID_STOREFRONT_PRIVATE_TOKEN in wp-config.php, to show live prices and checkout.', 'redeemid' ) : __( 'This plan is not available right now.', 'redeemid' );
} else {
	$redeemid_product = \RedeemID\Shopify\Storefront::product( $redeemid_handle );

	if ( is_wp_error( $redeemid_product ) ) {
		$redeemid_problem = __( 'Plans are temporarily unavailable. Please try again shortly.', 'redeemid' );
	} elseif ( null === $redeemid_product || ! $redeemid_product['variants'] ) {
		$redeemid_problem = $isEditor ? sprintf( /* translators: %s: product handle */ __( 'No Shopify product with the handle "%s" is published to this sales channel.', 'redeemid' ), $redeemid_handle ) : __( 'This plan is not available right now.', 'redeemid' );
	}
}

if ( 'preview' === $redeemid_problem ) :
	// The inserter preview shows sample data, never a live Shopify call.
	?>
	<div useBlockProps class="<?php echo esc_attr( $redeemid_classes ); ?>">
		<h3 class="redeemid-plan__title"><?php echo esc_html( (string) ( $a['heading'] ?? '' ) ?: __( 'Gold membership', 'redeemid' ) ); ?></h3>
		<p class="redeemid-plan__price">$12.00</p>
		<p class="redeemid-plan__description"><?php esc_html_e( 'Every story, the archive and the weekly briefing.', 'redeemid' ); ?></p>
		<span class="wp-element-button"><?php echo esc_html( $redeemid_button ); ?></span>
	</div>
	<?php
elseif ( '' !== $redeemid_problem ) :
	$redeemid_unavailable( $redeemid_problem );
else :
$redeemid_variant     = \RedeemID\Shopify\Storefront::pick_variant( $redeemid_product, (string) ( $a['variantId'] ?? '' ) );
$redeemid_title       = (string) ( $a['heading'] ?? '' ) ?: $redeemid_product['title'];
$redeemid_description = (string) ( $a['description'] ?? '' ) ?: $redeemid_product['description'];
$redeemid_plan        = \RedeemID\Shopify\Storefront::pick_plan( $redeemid_product, $redeemid_variant, (string) ( $a['sellingPlanId'] ?? '' ) );
$redeemid_charge      = $redeemid_plan && $redeemid_plan['price'] ? $redeemid_plan : $redeemid_variant;
$redeemid_price       = \RedeemID\Shopify\Storefront::format_money( $redeemid_charge['price'] );
$redeemid_compare_at  = $redeemid_plan ? $redeemid_plan['compareAt'] : $redeemid_variant['compareAt'];
$redeemid_compare     = ! empty( $a['showCompareAt'] ) && $redeemid_compare_at && (float) $redeemid_compare_at['amount'] > (float) ( $redeemid_charge['price']['amount'] ?? 0 ) ? \RedeemID\Shopify\Storefront::format_money( $redeemid_compare_at ) : '';
$redeemid_image       = ! empty( $a['showImage'] ) ? $redeemid_product['image'] : null;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
$redeemid_notice = $isEditor ? '' : \RedeemID\Commerce\BuyNow::message( sanitize_key( wp_unslash( $_GET['redeemid_notice'] ?? '' ) ) );
$redeemid_return = $isEditor ? '' : remove_query_arg( 'redeemid_notice', \RedeemID\Blocks\Gate::current_url() );

// What this card says to a signed-in member, from the level whose Shopify products include this one.
$redeemid_member = $isEditor || empty( $a['memberAware'] ) ? [ 'state' => '', 'via' => '' ] : \RedeemID\Commerce\PlanState::viewer( $redeemid_handle );
$redeemid_via    = '' !== $redeemid_member['via'] ? ( \RedeemID\Access\LevelStore::levels()->get( $redeemid_member['via'] )?->label ?? '' ) : '';
$redeemid_manage = (string) \RedeemID\Settings::get( 'subscription_url' );

if ( \RedeemID\Commerce\PlanState::UPGRADE === $redeemid_member['state'] ) {
	/* translators: %s: plan title */
	$redeemid_button = (string) ( $a['upgradeLabel'] ?? '' ) ?: sprintf( __( 'Upgrade to %s', 'redeemid' ), $redeemid_title );
} elseif ( \RedeemID\Commerce\PlanState::ADD === $redeemid_member['state'] ) {
	/* translators: %s: plan title */
	$redeemid_button = (string) ( $a['addLabel'] ?? '' ) ?: sprintf( __( 'Add %s', 'redeemid' ), $redeemid_title );
}

$redeemid_classes .= '' !== $redeemid_member['state'] ? ' is-member-' . $redeemid_member['state'] : '';
?>
<div useBlockProps class="<?php echo esc_attr( $redeemid_classes ); ?>">
	<?php if ( $redeemid_image ) : ?>
		<img class="redeemid-plan__image" src="<?php echo esc_url( $redeemid_image['url'] ); ?>" alt="<?php echo esc_attr( $redeemid_image['alt'] ); ?>" <?php echo $redeemid_image['width'] ? 'width="' . (int) $redeemid_image['width'] . '" height="' . (int) $redeemid_image['height'] . '"' : ''; ?> loading="lazy">
	<?php endif; ?>

	<h3 class="redeemid-plan__title"><?php echo esc_html( $redeemid_title ); ?></h3>

	<?php if ( '' !== $redeemid_price ) : ?>
		<p class="redeemid-plan__price">
			<?php echo esc_html( $redeemid_price ); ?>
			<?php if ( '' !== $redeemid_compare ) : ?>
				<s class="redeemid-plan__compare"><?php echo esc_html( $redeemid_compare ); ?></s>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( $redeemid_plan && '' !== $redeemid_plan['name'] ) : ?>
		<p class="redeemid-plan__terms"><?php echo esc_html( $redeemid_plan['name'] ); ?></p>
	<?php endif; ?>

	<?php if ( $isEditor && $redeemid_variant['plans'] ) : ?>
		<div class="redeemid-plan__notice">
			<p><?php esc_html_e( 'Selling plans for this variant (put an ID in the block settings to sell on that plan):', 'redeemid' ); ?></p>
			<ul>
				<?php foreach ( $redeemid_variant['plans'] as $redeemid_option ) : ?>
					<li><code><?php echo esc_html( (string) preg_replace( '#^.*/#', '', $redeemid_option['id'] ) ); ?></code> <?php echo esc_html( $redeemid_option['name'] . ( $redeemid_option['price'] ? ' · ' . \RedeemID\Shopify\Storefront::format_money( $redeemid_option['price'] ) : '' ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $redeemid_description ) : ?>
		<p class="redeemid-plan__description"><?php echo esc_html( wp_trim_words( $redeemid_description, 40 ) ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $redeemid_notice ) : ?>
		<p class="redeemid-plan__notice" role="alert"><?php echo esc_html( $redeemid_notice ); ?></p>
	<?php endif; ?>

	<?php if ( \RedeemID\Commerce\PlanState::UPGRADE === $redeemid_member['state'] && '' !== $redeemid_via ) : ?>
		<p class="redeemid-plan__member"><?php echo esc_html( sprintf( /* translators: %s: the level the member holds now */ __( 'You have %s now.', 'redeemid' ), $redeemid_via ) ); ?></p>
	<?php endif; ?>

	<?php if ( in_array( $redeemid_member['state'], [ \RedeemID\Commerce\PlanState::CURRENT, \RedeemID\Commerce\PlanState::INCLUDED ], true ) ) : ?>
		<p class="redeemid-plan__member">
			<?php
			echo esc_html(
				\RedeemID\Commerce\PlanState::INCLUDED === $redeemid_member['state'] && '' !== $redeemid_via
					/* translators: %s: the level that includes this plan */
					? sprintf( __( 'Included in your %s membership.', 'redeemid' ), $redeemid_via )
					: __( 'This is your plan.', 'redeemid' )
			);
			?>
		</p>
		<?php if ( '' !== $redeemid_manage ) : ?>
			<a class="wp-element-button redeemid-plan__manage" href="<?php echo esc_url( $redeemid_manage ); ?>"><?php esc_html_e( 'Manage membership', 'redeemid' ); ?></a>
		<?php endif; ?>
	<?php elseif ( ! $redeemid_variant['available'] ) : ?>
		<button type="button" class="wp-element-button" disabled><?php esc_html_e( 'Sold out', 'redeemid' ); ?></button>
	<?php elseif ( $isEditor ) : ?>
		<span class="wp-element-button"><?php echo esc_html( $redeemid_button ); ?></span>
	<?php else : ?>
		<form class="redeemid-plan__form" method="post" action="<?php echo esc_url( \RedeemID\Commerce\BuyNow::form_url() ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( \RedeemID\Commerce\BuyNow::ACTION ); ?>">
			<input type="hidden" name="variant" value="<?php echo esc_attr( $redeemid_variant['id'] ); ?>">
			<?php if ( $redeemid_plan ) : ?>
				<input type="hidden" name="selling_plan" value="<?php echo esc_attr( $redeemid_plan['id'] ); ?>">
			<?php endif; ?>
			<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redeemid_return ); ?>">
			<button type="submit" class="wp-element-button"><?php echo esc_html( $redeemid_button ); ?></button>
		</form>
	<?php endif; ?>
</div>
<?php endif; ?>
