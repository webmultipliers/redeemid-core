<?php
/**
 * Paywall break. Marks where an item's teaser ends.
 *
 * The cut happens before rendering, in WP2_Shopify\Access\Gatekeeper: for a
 * locked visitor everything after this block is removed from the parsed
 * content, so it never renders on any surface. For everyone else the break
 * itself renders nothing.
 *
 * @var bool $isEditor
 */

defined( 'ABSPATH' ) || exit;

if ( ! $isEditor ) {
	return;
}
?>
<div useBlockProps class="wp2-shopify-paywall-break" role="separator">
	<span class="wp2-shopify-paywall-break__label"><?php esc_html_e( 'Paywall: locked visitors stop here', 'wp2-shopify' ); ?></span>
	<small><?php esc_html_e( 'The break beats the post type teaser setting, which beats the excerpt. Keep it at the top level: inside a group, the whole group counts as locked.', 'wp2-shopify' ); ?></small>
</div>
