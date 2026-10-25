<?php
/**
 * Members only area of redeemid/membership-gate.
 *
 * On the frontend this template only runs when the parent's rule granted
 * access (see RedeemID\Blocks\Gate). Rendered anywhere else, for example
 * moved out of its gate by a copy-paste, it renders nothing: fail closed.
 *
 * @var bool $isEditor
 */

defined( 'ABSPATH' ) || exit;

if ( $isEditor ) {
	?>
	<div useBlockProps class="redeemid-gate-area is-editor is-members">
		<span class="redeemid-gate-area__label"><?php esc_html_e( 'Members only — never sent to non-members', 'redeemid' ); ?></span>
		<InnerBlocks templateLock="false" />
	</div>
	<?php
	return;
}
?>
<div useBlockProps class="redeemid-gate-area is-members">
	<InnerBlocks />
</div>
