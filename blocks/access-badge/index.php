<?php
/**
 * Access badge: the level an item needs, for the item the block sits in or
 * for each post of a query loop: items gated by their access rule, and items
 * whose body sits in a Membership Gate set to an access level. Colour is
 * always paired with the label. With a free label set, items anyone can read
 * get a plain badge with that label instead of nothing.
 *
 * @var array $a       Block attributes.
 * @var int   $post_id The post in context (each loop item, or the page).
 * @var bool  $isEditor
 */

defined( 'ABSPATH' ) || exit;

$wp2_post   = get_post( (int) $post_id );
$wp2_ruled  = $wp2_post instanceof \WP_Post && \WP2_Shopify\Access\ItemRule::gated( $wp2_post ) && ! \WP2_Shopify\Access\Gatekeeper::rule( $wp2_post )->open();
// An item without a rule of its own may still gate its body with a level gate.
$wp2_inline = ! $wp2_ruled && $wp2_post instanceof \WP_Post ? \WP2_Shopify\Access\Badges::inline_gate( $wp2_post ) : null;
$wp2_free   = trim( (string) ( $a['freeLabel'] ?? '' ) );
$wp2_locked = false;
$wp2_show   = $wp2_ruled || $wp2_inline;

if ( $wp2_show ) {
	$wp2_locked = $wp2_inline ? $wp2_inline['locked'] : \WP2_Shopify\Access\Gatekeeper::locked( $wp2_post );
	$wp2_show   = $wp2_locked || $isEditor || 'locked' !== \WP2_Shopify\Blocks\Gate::option( $a['showWhen'] ?? '', 'gated' );
}

if ( $wp2_show ) {
	$wp2_level = $wp2_inline ? $wp2_inline['level'] : \WP2_Shopify\Access\Badges::level( $wp2_post );
	$wp2_state = $wp2_locked ? __( 'Locked', 'wp2-shopify' ) : __( 'Included with your membership', 'wp2-shopify' );
	$wp2_text  = 'state' === \WP2_Shopify\Blocks\Gate::option( $a['text'] ?? '', 'level' )
		? $wp2_state
		: ( $wp2_level?->label ?? __( 'Members', 'wp2-shopify' ) );
	$wp2_color = $wp2_level && '' !== $wp2_level->badge ? sanitize_hex_color( $wp2_level->badge ) : '';
	$wp2_style = $wp2_color ? '--wp2-badge:' . $wp2_color : '';
}
?>
<?php if ( $wp2_show ) : ?>
<span useBlockProps class="wp2-shopify-access-badge <?php echo $wp2_locked ? 'is-locked' : 'is-unlocked'; ?>" style="<?php echo esc_attr( $wp2_style ); ?>" data-wp2-level="<?php echo esc_attr( $wp2_level->slug ?? '' ); ?>">
	<?php if ( false !== ( $a['showIcon'] ?? true ) && $wp2_locked ) : ?>
		<svg aria-hidden="true" focusable="false" width="12" height="12" viewBox="0 0 24 24"><path fill="currentColor" d="M17 9V7A5 5 0 0 0 7 7v2H5v13h14V9h-2Zm-8 0V7a3 3 0 0 1 6 0v2H9Z"/></svg>
		<span class="screen-reader-text"><?php esc_html_e( 'Locked:', 'wp2-shopify' ); ?></span>
	<?php endif; ?>
	<?php echo esc_html( $wp2_text ); ?>
</span>
<?php elseif ( ! $wp2_ruled && ! $wp2_inline && '' !== $wp2_free && $wp2_post instanceof \WP_Post ) : ?>
<span useBlockProps class="wp2-shopify-access-badge is-free" data-wp2-level="free"><?php echo esc_html( $wp2_free ); ?></span>
<?php elseif ( $isEditor && ! $wp2_ruled && ! $wp2_inline ) : ?>
<span useBlockProps class="wp2-shopify-access-badge is-placeholder"><?php esc_html_e( 'Access badge: shows on gated items', 'wp2-shopify' ); ?></span>
<?php endif; ?>
