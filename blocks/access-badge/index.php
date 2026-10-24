<?php
/**
 * Access badge: the level an item needs, for the item the block sits in or
 * for each post of a query loop. Colour is always paired with the label.
 *
 * @var array $a       Block attributes.
 * @var int   $post_id The post in context (each loop item, or the page).
 * @var bool  $isEditor
 */

defined( 'ABSPATH' ) || exit;

$wp2_post = get_post( (int) $post_id );

if ( ! $wp2_post instanceof \WP_Post || ! \WP2_Shopify\Access\ItemRule::gated( $wp2_post ) || \WP2_Shopify\Access\Gatekeeper::rule( $wp2_post )->open() ) {
	if ( $isEditor ) {
		echo '<span useBlockProps class="wp2-shopify-access-badge is-placeholder">' . esc_html__( 'Access badge: shows on gated items', 'wp2-shopify' ) . '</span>';
	}
	return;
}

$wp2_locked = \WP2_Shopify\Access\Gatekeeper::locked( $wp2_post );
$wp2_when   = \WP2_Shopify\Blocks\Gate::option( $a['showWhen'] ?? '', 'gated' );

if ( 'locked' === $wp2_when && ! $wp2_locked && ! $isEditor ) {
	return;
}

$wp2_level = \WP2_Shopify\Access\Badges::level( $wp2_post );
$wp2_text  = 'state' === \WP2_Shopify\Blocks\Gate::option( $a['text'] ?? '', 'level' )
	? \WP2_Shopify\Access\Badges::value( $wp2_post, 'state' )
	: ( $wp2_level?->label ?? __( 'Members', 'wp2-shopify' ) );
$wp2_color = $wp2_level && '' !== $wp2_level->badge ? sanitize_hex_color( $wp2_level->badge ) : '';
$wp2_style = $wp2_color ? '--wp2-badge:' . $wp2_color : '';
?>
<span useBlockProps class="wp2-shopify-access-badge <?php echo $wp2_locked ? 'is-locked' : 'is-unlocked'; ?>" style="<?php echo esc_attr( $wp2_style ); ?>" data-wp2-level="<?php echo esc_attr( $wp2_level->slug ?? '' ); ?>">
	<?php if ( false !== ( $a['showIcon'] ?? true ) && $wp2_locked ) : ?>
		<svg aria-hidden="true" focusable="false" width="12" height="12" viewBox="0 0 24 24"><path fill="currentColor" d="M17 9V7A5 5 0 0 0 7 7v2H5v13h14V9h-2Zm-8 0V7a3 3 0 0 1 6 0v2H9Z"/></svg>
		<span class="screen-reader-text"><?php esc_html_e( 'Locked:', 'wp2-shopify' ); ?></span>
	<?php endif; ?>
	<?php echo esc_html( $wp2_text ); ?>
</span>
