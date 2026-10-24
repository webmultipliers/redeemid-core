<?php
/**
 * The locked-visitor panel, shared by the Membership Gate and the item
 * paywall (WP2_Shopify\Blocks\Gate::fallback_panel()). Everything here is
 * public: it is what people who cannot read the content see.
 *
 * Themes restyle it through .wp2-shopify-gate__panel, or replace this template
 * with a Blockstudio override ("override": true on wp2-shopify/locked-panel).
 *
 * @var array $a Block attributes: message, reason, ctaLabel, ctaUrl, signInUrl.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wp2-shopify-gate__panel" role="region" aria-label="<?php esc_attr_e( 'Members only', 'wp2-shopify' ); ?>">
	<p><?php echo esc_html( (string) ( $a['message'] ?? '' ) ); ?></p>
	<?php if ( ! empty( $a['reason'] ) ) : ?>
		<p class="wp2-shopify-gate__reason"><?php echo esc_html( (string) $a['reason'] ); ?></p>
	<?php endif; ?>
	<div class="wp2-shopify-gate__actions">
		<?php if ( ! empty( $a['ctaLabel'] ) && ! empty( $a['ctaUrl'] ) ) : ?>
			<a class="wp-element-button" href="<?php echo esc_url( (string) $a['ctaUrl'] ); ?>"><?php echo esc_html( (string) $a['ctaLabel'] ); ?></a>
		<?php endif; ?>
		<?php if ( ! empty( $a['signInUrl'] ) ) : ?>
			<a class="wp-element-button is-style-outline" href="<?php echo esc_url( (string) $a['signInUrl'] ); ?>" rel="nofollow"><?php esc_html_e( 'Log in with Shopify', 'wp2-shopify' ); ?></a>
		<?php endif; ?>
	</div>
</div>
