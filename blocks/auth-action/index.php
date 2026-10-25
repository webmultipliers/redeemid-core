<?php
/**
 * Shopify sign-in control, rendered as a Blockstudio dynamic island.
 *
 * The page carries a neutral, cacheable placeholder: the guest "Log in" link,
 * which works without JavaScript. Blockstudio's island runtime then asks the
 * site, in one batched request for every island on the page, to render this
 * block again for the visitor, and swaps the result in. That fragment is never
 * cached, so it may carry the visitor's own nonces (owner decision on review
 * point 2). See https://blockstudio.dev/docs/blocks/islands.
 *
 * @var array  $a                   Block attributes.
 * @var bool   $isEditor            Rendering inside the block editor.
 * @var bool   $isIslandPlaceholder Rendering the cacheable placeholder.
 * @var bool   $isIslandFragment    Rendering the visitor's own fragment.
 */

defined( 'ABSPATH' ) || exit;

use RedeemID\Blocks\Gate;

$redeemid_mode    = Gate::option( $a['mode'] ?? '', 'auto' );
$redeemid_style   = Gate::option( $a['controlStyle'] ?? '', 'button' );
$redeemid_preview = Gate::option( $a['previewState'] ?? '', 'guest' );
$redeemid_login   = ( $a['loginLabel'] ?? '' ) ?: __( 'Log in with Shopify', 'redeemid' );
$redeemid_logout  = ( $a['logoutLabel'] ?? '' ) ?: __( 'Log out', 'redeemid' );
$redeemid_connect = ( $a['connectLabel'] ?? '' ) ?: __( 'Connect Shopify account', 'redeemid' );
$redeemid_class   = 'button' === $redeemid_style ? 'wp-element-button redeemid-auth__control' : 'redeemid-auth__control redeemid-auth__control--link';

if ( $isEditor ) {
	$redeemid_labels = [ 'guest' => $redeemid_login, 'member' => $redeemid_logout, 'unlinked' => $redeemid_connect ];
	?>
	<div useBlockProps class="redeemid-auth">
		<span class="<?php echo esc_attr( $redeemid_class ); ?>"><?php echo esc_html( $redeemid_labels[ $redeemid_preview ] ?? $redeemid_login ); ?></span>
		<small class="redeemid-auth__note"><?php esc_html_e( 'Preview. Each visitor sees the action for their own state.', 'redeemid' ); ?></small>
	</div>
	<?php
	return;
}

// The page the control sits on. The placeholder is cached per URL, so the URL itself is safe in it;
// the fragment is rendered by a separate request and reads the page from its same-origin referer.
$redeemid_here        = $isIslandFragment ? wp_validate_redirect( (string) wp_get_raw_referer(), home_url( '/' ) ) : Gate::current_url();
$redeemid_after_login = Gate::url( $a['redirectAfterLogin'] ?? '' ) ?: $redeemid_here;
$redeemid_after_out   = Gate::url( $a['redirectAfterLogout'] ?? '' ) ?: $redeemid_here;
// Only the island fragment is personal; anything else may land in a shared cache.
$redeemid_user        = $isIslandFragment ? get_current_user_id() : 0;
$redeemid_linked      = $redeemid_user && ( null !== \RedeemID\Sync\Mirror::customer_id( $redeemid_user ) || \RedeemID\Auth\ShadowUsers::is_shadow( $redeemid_user ) );

$redeemid_form = static function ( string $action, string $label, string $redirect ) use ( $redeemid_class ): void {
	?>
	<form class="redeemid-auth__form" method="post" action="<?php echo esc_url( \RedeemID\Auth\Flow::action_url() ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
		<?php wp_nonce_field( $action, '_wpnonce', false ); ?>
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">
		<button type="submit" class="<?php echo esc_attr( $redeemid_class ); ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
};

// No early return here: Blockstudio reads a template that returns as one that rendered nothing and skips its stylesheet.
if ( ! \RedeemID\Shopify\Discovery::available() ) :
	?>
	<div useBlockProps class="redeemid-auth is-unavailable">
		<button type="button" class="<?php echo esc_attr( $redeemid_class ); ?>" disabled><?php esc_html_e( 'Sign-in is temporarily unavailable', 'redeemid' ); ?></button>
	</div>
<?php else : ?>
<div useBlockProps class="redeemid-auth" aria-live="polite">
	<?php if ( ! $redeemid_user ) : ?>
		<?php if ( 'logout' !== $redeemid_mode && 'connect' !== $redeemid_mode ) : ?>
			<a class="<?php echo esc_attr( $redeemid_class ); ?>" href="<?php echo esc_url( \RedeemID\Auth\Flow::login_url( $redeemid_after_login ) ); ?>" rel="nofollow"><?php echo esc_html( $redeemid_login ); ?></a>
		<?php endif; ?>
	<?php elseif ( $redeemid_linked && ( 'auto' === $redeemid_mode || 'logout' === $redeemid_mode ) ) : ?>
		<?php $redeemid_form( 'redeemid_logout', $redeemid_logout, $redeemid_after_out ); ?>
	<?php elseif ( ! $redeemid_linked && ( 'auto' === $redeemid_mode || 'connect' === $redeemid_mode ) ) : ?>
		<?php $redeemid_form( 'redeemid_connect', $redeemid_connect, $redeemid_here ); ?>
	<?php endif; ?>
</div>
<?php endif; ?>
