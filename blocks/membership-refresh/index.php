<?php
/**
 * Refresh Membership: the front-end "sync now", rendered as a Blockstudio
 * dynamic island.
 *
 * The page carries an empty placeholder for everyone. The island fragment,
 * rendered per visitor by a never-cached request, shows the button only to
 * signed-in, linked visitors and carries their REST nonce, so the nonce never
 * reaches a cached page. script.view.js only handles the click.
 *
 * @var array $a                Block attributes.
 * @var bool  $isEditor         Rendering inside the block editor.
 * @var bool  $isIslandFragment Rendering the visitor's own fragment.
 */

defined( 'ABSPATH' ) || exit;

$wp2_label = '' !== trim( (string) ( $a['label'] ?? '' ) ) ? (string) $a['label'] : __( 'Refresh membership', 'wp2-shopify' );
$wp2_class = 'wp2-shopify-refresh' . ( empty( $a['compact'] ) ? '' : ' is-compact' );

$wp2_status = ! $isEditor && $isIslandFragment ? \WP2_Shopify\Rest\Status::for_current_user() : [];
$wp2_linked = ! empty( $wp2_status['signedIn'] ) && ( ! empty( $wp2_status['linked'] ) || ! empty( $wp2_status['shadow'] ) ) && ! empty( $wp2_status['nonces']['rest'] );
$wp2_sync   = (int) ( $wp2_status['lastSync'] ?? 0 );
$wp2_note   = $wp2_sync
	/* translators: %s: how long ago, such as "5 mins" */
	? sprintf( __( 'Synced from Shopify %s ago.', 'wp2-shopify' ), human_time_diff( $wp2_sync ) )
	: __( 'Not synced yet.', 'wp2-shopify' );

// No early return: Blockstudio reads a template that returns as one that rendered nothing and skips script.view.js.
if ( $isEditor ) :
	?>
	<div useBlockProps class="<?php echo esc_attr( $wp2_class ); ?>">
		<button type="button" class="wp-element-button wp2-shopify-refresh__button" disabled><?php echo esc_html( $wp2_label ); ?></button>
		<span class="wp2-shopify-refresh__message"><?php esc_html_e( 'Shows to signed-in, linked customers only.', 'wp2-shopify' ); ?></span>
	</div>
<?php elseif ( ! $wp2_linked ) : ?>
	<?php // The placeholder, guests and unlinked accounts: nothing to refresh, but keep the wrapper for the island. ?>
	<div useBlockProps class="<?php echo esc_attr( $wp2_class ); ?>" hidden></div>
<?php else : ?>
	<div useBlockProps class="<?php echo esc_attr( $wp2_class ); ?>"
		data-wp2-refresh-endpoint="<?php echo esc_url( rest_url( \WP2_Shopify\Rest\Routes::NS . '/account/sync' ) ); ?>"
		data-wp2-refresh-nonce="<?php echo esc_attr( $wp2_status['nonces']['rest'] ); ?>">
		<button type="button" class="wp-element-button wp2-shopify-refresh__button"><?php echo esc_html( $wp2_label ); ?></button>
		<span class="wp2-shopify-refresh__message" aria-live="polite"><?php echo empty( $a['compact'] ) ? esc_html( $wp2_note ) : ''; ?></span>
	</div>
<?php endif; ?>
