<?php
/**
 * Public teaser area of redeemid/membership-gate. Anything placed here is
 * public: it is indexable and shown to every visitor who is not entitled.
 *
 * @var bool $isEditor
 */

defined( 'ABSPATH' ) || exit;

if ( $isEditor ) {
	?>
	<div useBlockProps class="redeemid-gate-area is-editor is-teaser">
		<span class="redeemid-gate-area__label"><?php esc_html_e( 'Public teaser — visible to everyone', 'redeemid' ); ?></span>
		<small><?php esc_html_e( 'Shown only when “Non-members see” is set to the Public teaser area.', 'redeemid' ); ?></small>
		<InnerBlocks templateLock="false" />
	</div>
	<?php
	return;
}
?>
<div useBlockProps class="redeemid-gate-area is-teaser">
	<InnerBlocks />
</div>
