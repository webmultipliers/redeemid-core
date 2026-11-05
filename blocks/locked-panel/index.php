<?php
/**
 * The locked-visitor panel, shared by the Membership Gate and the item
 * paywall (RedeemID\Blocks\Gate::fallback_panel()). It is what people who
 * cannot read the content see, worded for why: a guest is asked to log in, a
 * WordPress user to connect their Shopify account, a customer who just paid
 * to refresh, a lapsed member to renew, another level's member to upgrade.
 *
 * The form (connect or refresh) is only ever rendered for a signed-in visitor,
 * on a page the plugin marks uncacheable; guests get plain links.
 *
 * Themes restyle it through .redeemid-gate__panel (data-redeemid-kind tells the
 * cases apart), or replace this template with a Blockstudio override
 * ("override": true on redeemid/locked-panel).
 *
 * An item sold on its own adds a buy-now button for its product (buyVariant,
 * buyLabel). That form carries no nonce, like every buy-now form, so it is
 * safe in cached HTML.
 *
 * @var array $a Block attributes: message, reason, kind, level, ctaLabel, ctaUrl,
 *               signInUrl, hint, formAction, formLabel, formNonce, redirectTo,
 *               buyVariant, buyLabel.
 */

defined( 'ABSPATH' ) || exit;

$redeemid_form = in_array( $a['formAction'] ?? '', [ 'redeemid_connect', 'redeemid_sync' ], true ) && ! empty( $a['formNonce'] );
?>
<?php // No region role: a page with several gates would list several "Members only" landmarks with nothing to tell them apart. The panel reads in place, after the teaser. ?>
<div class="redeemid-gate__panel" data-redeemid-kind="<?php echo esc_attr( (string) ( $a['kind'] ?? '' ) ); ?>" data-redeemid-level="<?php echo esc_attr( (string) ( $a['level'] ?? '' ) ); ?>">
	<p><?php echo esc_html( (string) ( $a['message'] ?? '' ) ); ?></p>
	<?php if ( ! empty( $a['reason'] ) ) : ?>
		<p class="redeemid-gate__reason"><?php echo esc_html( (string) $a['reason'] ); ?></p>
	<?php endif; ?>
	<div class="redeemid-gate__actions">
		<?php if ( ! empty( $a['ctaLabel'] ) && ! empty( $a['ctaUrl'] ) ) : ?>
			<a class="wp-element-button" href="<?php echo esc_url( (string) $a['ctaUrl'] ); ?>"><?php echo esc_html( (string) $a['ctaLabel'] ); ?></a>
		<?php endif; ?>
		<?php if ( ! empty( $a['buyVariant'] ) && ! empty( $a['buyLabel'] ) ) : ?>
			<form method="post" action="<?php echo esc_url( \RedeemID\Commerce\BuyNow::form_url() ); ?>" class="redeemid-inline-form redeemid-gate__buy">
				<input type="hidden" name="action" value="<?php echo esc_attr( \RedeemID\Commerce\BuyNow::ACTION ); ?>">
				<input type="hidden" name="variant" value="<?php echo esc_attr( (string) $a['buyVariant'] ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( (string) ( $a['redirectTo'] ?? '' ) ); ?>">
				<button type="submit" class="wp-element-button<?php echo ! empty( $a['ctaLabel'] ) && ! empty( $a['ctaUrl'] ) ? ' is-style-outline' : ''; ?>"><?php echo esc_html( (string) $a['buyLabel'] ); ?></button>
			</form>
		<?php endif; ?>
		<?php if ( ! empty( $a['signInUrl'] ) ) : ?>
			<a class="wp-element-button is-style-outline" href="<?php echo esc_url( (string) $a['signInUrl'] ); ?>" rel="nofollow"><?php esc_html_e( 'Sign in', 'redeemid' ); ?></a>
		<?php endif; ?>
		<?php if ( $redeemid_form ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="redeemid-inline-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( (string) $a['formAction'] ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( (string) ( $a['redirectTo'] ?? '' ) ); ?>">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( (string) $a['formNonce'] ); ?>">
				<button type="submit" class="wp-element-button<?php echo ! empty( $a['ctaLabel'] ) && ! empty( $a['ctaUrl'] ) ? ' is-style-outline' : ''; ?>"><?php echo esc_html( (string) ( $a['formLabel'] ?? '' ) ); ?></button>
			</form>
		<?php endif; ?>
	</div>
	<?php if ( ! empty( $a['hint'] ) ) : ?>
		<p class="redeemid-gate__hint"><?php echo esc_html( (string) $a['hint'] ); ?></p>
	<?php endif; ?>
</div>
