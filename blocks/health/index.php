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

$wp2_title  = '' !== trim( (string) ( $a['title'] ?? '' ) ) ? (string) $a['title'] : __( 'Shopify health', 'wp2-shopify' );
$wp2_admin  = ( $isEditor || $isIslandFragment ) && current_user_can( 'manage_options' );
$wp2_checks = $wp2_admin ? \WP2_Shopify\Support\Health::checks() : [];
$wp2_marks  = [ 'ok' => '✓', 'warn' => '!', 'fail' => '✗', 'info' => '·' ];
?>
<div useBlockProps class="wp2-shopify-health">
	<p class="wp2-shopify-health__title"><?php echo esc_html( $wp2_title ); ?></p>
	<?php if ( $wp2_checks ) : ?>
		<ul class="wp2-shopify-health__list">
			<?php foreach ( $wp2_checks as $wp2_check ) : ?>
				<li class="wp2-shopify-health__item is-<?php echo esc_attr( $wp2_check['state'] ); ?>">
					<span class="wp2-shopify-health__state" aria-hidden="true"><?php echo esc_html( $wp2_marks[ $wp2_check['state'] ] ); ?></span>
					<span class="wp2-shopify-health__label"><?php echo esc_html( $wp2_check['label'] ); ?></span>
					<span class="wp2-shopify-health__detail"><?php echo esc_html( (string) ( $wp2_check['detail'] ?? '' ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<p class="wp2-shopify-health__note"><?php esc_html_e( 'Visible to administrators only.', 'wp2-shopify' ); ?></p>
	<?php endif; ?>
</div>
