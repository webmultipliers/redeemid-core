<?php
/**
 * Public teaser area of wp2-shopify/membership-gate. Anything placed here is
 * public: it is indexable and shown to every visitor who is not entitled.
 *
 * @var bool $isEditor
 */

defined( 'ABSPATH' ) || exit;

if ( $isEditor ) {
	?>
	<div useBlockProps class="wp2-shopify-gate-area is-editor is-teaser">
		<span class="wp2-shopify-gate-area__label"><?php esc_html_e( 'Public teaser — visible to everyone', 'wp2-shopify' ); ?></span>
		<small><?php esc_html_e( 'Shown only when “Non-members see” is set to the Public teaser area.', 'wp2-shopify' ); ?></small>
		<InnerBlocks templateLock="false" />
	</div>
	<?php
	return;
}
?>
<div useBlockProps class="wp2-shopify-gate-area is-teaser">
	<InnerBlocks />
</div>
