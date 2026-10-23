<?php
/**
 * Shopify sign-in control.
 *
 * Renders a neutral, cacheable shell: the guest "Log in" link, which works
 * without JavaScript. script.view.js asks the status endpoint who is visiting
 * and swaps in the right action (owner decision on review point 2).
 *
 * @var array $a          Block attributes.
 * @var bool  $isEditor   Rendering inside the block editor.
 */

defined( 'ABSPATH' ) || exit;

$wp2_opt = static fn( $value, string $fallback ): string => is_array( $value ) ? (string) ( $value['value'] ?? $fallback ) : ( $value ? (string) $value : $fallback );

$wp2_mode    = $wp2_opt( $a['mode'] ?? '', 'auto' );
$wp2_style   = $wp2_opt( $a['controlStyle'] ?? '', 'button' );
$wp2_preview = $wp2_opt( $a['previewState'] ?? '', 'guest' );
$wp2_login   = $a['loginLabel'] ?: __( 'Log in with Shopify', 'wp2-shopify' );
$wp2_logout  = $a['logoutLabel'] ?: __( 'Log out', 'wp2-shopify' );
$wp2_connect = $a['connectLabel'] ?: __( 'Connect Shopify account', 'wp2-shopify' );
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

if ( ! \WP2_Shopify\Shopify\Discovery::available() ) {
	?>
	<div useBlockProps class="wp2-shopify-auth is-unavailable">
		<button type="button" class="<?php echo esc_attr( $wp2_class ); ?>" disabled><?php esc_html_e( 'Sign-in is temporarily unavailable', 'wp2-shopify' ); ?></button>
	</div>
	<?php
	return;
}

$wp2_login_url = \WP2_Shopify\Auth\Flow::login_url( (string) ( $a['redirectAfterLogin'] ?: '' ) );
$wp2_config    = [
	'mode'          => $wp2_mode,
	'statusUrl'     => add_query_arg( 'action', \WP2_Shopify\Rest\Status::AJAX_ACTION, admin_url( 'admin-ajax.php' ) ),
	'labels'        => [ 'login' => $wp2_login, 'logout' => $wp2_logout, 'connect' => $wp2_connect ],
	'className'     => $wp2_class,
	'afterLogin'    => (string) ( $a['redirectAfterLogin'] ?: '' ),
	'afterLogout'   => (string) ( $a['redirectAfterLogout'] ?: '' ),
];
?>
<div useBlockProps class="wp2-shopify-auth" data-wp2-auth="<?php echo esc_attr( wp_json_encode( $wp2_config ) ); ?>" aria-live="polite">
	<?php if ( 'logout' !== $wp2_mode && 'connect' !== $wp2_mode ) : ?>
		<a class="<?php echo esc_attr( $wp2_class ); ?>" href="<?php echo esc_url( $wp2_login_url ); ?>" rel="nofollow"><?php echo esc_html( $wp2_login ); ?></a>
	<?php endif; ?>
</div>
