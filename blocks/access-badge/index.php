<?php
/**
 * Access badge: the level an item needs, for the item the block sits in or
 * for each post of a query loop: items gated by their access rule, and items
 * whose body sits in a Membership Gate set to an access level. Colour is
 * always paired with the label, and the text is black or white, whichever
 * reads better on the level's colour. With a free label set, items anyone can read
 * get a plain badge with that label instead of nothing.
 *
 * @var array $a       Block attributes.
 * @var int   $post_id The post in context (each loop item, or the page).
 * @var bool  $isEditor
 */

defined( 'ABSPATH' ) || exit;

$redeemid_post   = get_post( (int) $post_id );
$redeemid_ruled  = $redeemid_post instanceof \WP_Post && \RedeemID\Access\ItemRule::gated( $redeemid_post ) && ! \RedeemID\Access\Gatekeeper::rule( $redeemid_post )->open();
// An item without a rule of its own may still gate its body with a level gate.
$redeemid_inline = ! $redeemid_ruled && $redeemid_post instanceof \WP_Post ? \RedeemID\Access\Badges::inline_gate( $redeemid_post ) : null;
$redeemid_free   = trim( (string) ( $a['freeLabel'] ?? '' ) );
$redeemid_locked = false;
$redeemid_show   = $redeemid_ruled || $redeemid_inline;

if ( $redeemid_show ) {
	$redeemid_locked = $redeemid_inline ? $redeemid_inline['locked'] : \RedeemID\Access\Gatekeeper::locked( $redeemid_post );
	$redeemid_show   = $redeemid_locked || $isEditor || 'locked' !== \RedeemID\Blocks\Gate::option( $a['showWhen'] ?? '', 'gated' );
}

if ( $redeemid_show ) {
	$redeemid_level = $redeemid_inline ? $redeemid_inline['level'] : \RedeemID\Access\Badges::level( $redeemid_post );
	$redeemid_state = $redeemid_locked ? __( 'Locked', 'redeemid' ) : __( 'Included with your membership', 'redeemid' );
	$redeemid_text  = 'state' === \RedeemID\Blocks\Gate::option( $a['text'] ?? '', 'level' )
		? $redeemid_state
		: ( $redeemid_level?->label ?? __( 'Members', 'redeemid' ) );
	$redeemid_color = $redeemid_level && '' !== $redeemid_level->badge ? sanitize_hex_color( $redeemid_level->badge ) : '';
	$redeemid_style = $redeemid_color ? '--redeemid-badge:' . $redeemid_color . ';--redeemid-badge-ink:' . \RedeemID\Access\Badges::ink( $redeemid_color ) : '';
}
?>
<?php if ( $redeemid_show ) : ?>
<span useBlockProps class="redeemid-access-badge <?php echo $redeemid_locked ? 'is-locked' : 'is-unlocked'; ?>" style="<?php echo esc_attr( $redeemid_style ); ?>" data-redeemid-level="<?php echo esc_attr( $redeemid_level->slug ?? '' ); ?>">
	<?php if ( false !== ( $a['showIcon'] ?? true ) && $redeemid_locked ) : ?>
		<svg aria-hidden="true" focusable="false" width="12" height="12" viewBox="0 0 24 24"><path fill="currentColor" d="M17 9V7A5 5 0 0 0 7 7v2H5v13h14V9h-2Zm-8 0V7a3 3 0 0 1 6 0v2H9Z"/></svg>
		<span class="screen-reader-text"><?php esc_html_e( 'Locked:', 'redeemid' ); ?></span>
	<?php endif; ?>
	<?php echo esc_html( $redeemid_text ); ?>
</span>
<?php elseif ( ! $redeemid_ruled && ! $redeemid_inline && '' !== $redeemid_free && $redeemid_post instanceof \WP_Post ) : ?>
<span useBlockProps class="redeemid-access-badge is-free" data-redeemid-level="free"><?php echo esc_html( $redeemid_free ); ?></span>
<?php elseif ( $isEditor && ! $redeemid_ruled && ! $redeemid_inline ) : ?>
<span useBlockProps class="redeemid-access-badge is-placeholder"><?php esc_html_e( 'Access badge: shows on gated items', 'redeemid' ); ?></span>
<?php endif; ?>
