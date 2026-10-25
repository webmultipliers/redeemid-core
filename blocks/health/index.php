<?php
/**
 * Shopify Health checklist, for administrators only.
 *
 * The block is a Blockstudio dynamic island: the cached page carries only a
 * note, and the never-cached island fragment renders the checklist for an
 * administrator. Everyone else never receives a row of it.
 *
 * @var array $a                Block attributes.
 * @var bool  $isEditor         Rendering inside the block editor.
 * @var bool  $isIslandFragment Rendering the visitor's own fragment.
 */

defined( 'ABSPATH' ) || exit;

$redeemid_title  = '' !== trim( (string) ( $a['title'] ?? '' ) ) ? (string) $a['title'] : __( 'Shopify health', 'redeemid' );
$redeemid_admin  = ( $isEditor || $isIslandFragment ) && current_user_can( 'manage_options' );
$redeemid_checks = $redeemid_admin ? \RedeemID\Support\Health::checks() : [];
$redeemid_marks  = [ 'ok' => '✓', 'warn' => '!', 'fail' => '✗', 'info' => '·' ];
?>
<div useBlockProps class="redeemid-health">
	<p class="redeemid-health__title"><?php echo esc_html( $redeemid_title ); ?></p>
	<?php if ( $redeemid_checks ) : ?>
		<ul class="redeemid-health__list">
			<?php foreach ( $redeemid_checks as $redeemid_check ) : ?>
				<li class="redeemid-health__item is-<?php echo esc_attr( $redeemid_check['state'] ); ?>">
					<span class="redeemid-health__state" aria-hidden="true"><?php echo esc_html( $redeemid_marks[ $redeemid_check['state'] ] ); ?></span>
					<span class="redeemid-health__label"><?php echo esc_html( $redeemid_check['label'] ); ?></span>
					<span class="redeemid-health__detail"><?php echo esc_html( (string) ( $redeemid_check['detail'] ?? '' ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<p class="redeemid-health__note"><?php esc_html_e( 'Visible to administrators only.', 'redeemid' ); ?></p>
	<?php endif; ?>
</div>
