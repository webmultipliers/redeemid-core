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

use WP2_Shopify\Blocks\Gate;

$wp2_mode    = Gate::option( $a['mode'] ?? '', 'auto' );
$wp2_style   = Gate::option( $a['controlStyle'] ?? '', 'button' );
$wp2_preview = Gate::option( $a['previewState'] ?? '', 'guest' );
$wp2_login   = ( $a['loginLabel'] ?? '' ) ?: __( 'Log in with Shopify', 'wp2-shopify' );
$wp2_logout  = ( $a['logoutLabel'] ?? '' ) ?: __( 'Log out', 'wp2-shopify' );
$wp2_connect = ( $a['connectLabel'] ?? '' ) ?: __( 'Connect Shopify account', 'wp2-shopify' );
$wp2_class   = 'button' === $wp2_style ? 'wp-element-button wp2-shopify-auth__control' : 'wp2-shopify-auth__control wp2-shopify-auth__control--link';

if ( $isEditor ) {
	$wp2_labels = [ 'guest' => $wp2_login, 'member' => $wp2_logout, 'unlinked' => $wp2_connect ];
	?>
	<div useBlockProps class="wp2-shopify-auth">
		<span class="<?php echo esc_attr( $wp2_class ); ?>"><?php echo esc_html( $wp2_labels[ $wp2_preview ] ?? $wp2_login ); ?></span>
		<small class="wp2-shopify-auth__note"><?php esc_html_e( 'Preview. Each visitor sees the action for their own state.', 'wp2-shopify' ); ?></small>
	</div>
	<?php
	return;
}

// The page the control sits on. The placeholder is cached per URL, so the URL itself is safe in it;
// the fragment is rendered by a separate request and reads the page from its same-origin referer.
$wp2_here        = $isIslandFragment ? wp_validate_redirect( (string) wp_get_raw_referer(), home_url( '/' ) ) : Gate::current_url();
$wp2_after_login = Gate::url( $a['redirectAfterLogin'] ?? '' ) ?: $wp2_here;
$wp2_after_out   = Gate::url( $a['redirectAfterLogout'] ?? '' ) ?: $wp2_here;
// Only the island fragment is personal; anything else may land in a shared cache.
$wp2_user        = $isIslandFragment ? get_current_user_id() : 0;
$wp2_linked      = $wp2_user && ( null !== \WP2_Shopify\Sync\Mirror::customer_id( $wp2_user ) || \WP2_Shopify\Auth\ShadowUsers::is_shadow( $wp2_user ) );

$wp2_form = static function ( string $action, string $label, string $redirect ) use ( $wp2_class ): void {
	?>
	<form class="wp2-shopify-auth__form" method="post" action="<?php echo esc_url( \WP2_Shopify\Auth\Flow::action_url() ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
		<?php wp_nonce_field( $action, '_wpnonce', false ); ?>
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">
		<button type="submit" class="<?php echo esc_attr( $wp2_class ); ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
};

// No early return here: Blockstudio reads a template that returns as one that rendered nothing and skips its stylesheet.
if ( ! \WP2_Shopify\Shopify\Discovery::available() ) :
	?>
	<div useBlockProps class="wp2-shopify-auth is-unavailable">
		<button type="button" class="<?php echo esc_attr( $wp2_class ); ?>" disabled><?php esc_html_e( 'Sign-in is temporarily unavailable', 'wp2-shopify' ); ?></button>
	</div>
<?php else : ?>
<div useBlockProps class="wp2-shopify-auth" aria-live="polite">
	<?php if ( ! $wp2_user ) : ?>
		<?php if ( 'logout' !== $wp2_mode && 'connect' !== $wp2_mode ) : ?>
			<a class="<?php echo esc_attr( $wp2_class ); ?>" href="<?php echo esc_url( \WP2_Shopify\Auth\Flow::login_url( $wp2_after_login ) ); ?>" rel="nofollow"><?php echo esc_html( $wp2_login ); ?></a>
		<?php endif; ?>
	<?php elseif ( $wp2_linked && ( 'auto' === $wp2_mode || 'logout' === $wp2_mode ) ) : ?>
		<?php $wp2_form( 'wp2_shopify_logout', $wp2_logout, $wp2_after_out ); ?>
	<?php elseif ( ! $wp2_linked && ( 'auto' === $wp2_mode || 'connect' === $wp2_mode ) ) : ?>
		<?php $wp2_form( 'wp2_shopify_connect', $wp2_connect, $wp2_here ); ?>
	<?php endif; ?>
</div>
<?php endif; ?>
