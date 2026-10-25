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

$redeemid_label = '' !== trim( (string) ( $a['label'] ?? '' ) ) ? (string) $a['label'] : __( 'Refresh membership', 'redeemid' );
$redeemid_class = 'redeemid-refresh' . ( empty( $a['compact'] ) ? '' : ' is-compact' );

$redeemid_status = ! $isEditor && $isIslandFragment ? \RedeemID\Rest\Status::for_current_user() : [];
$redeemid_linked = ! empty( $redeemid_status['signedIn'] ) && ( ! empty( $redeemid_status['linked'] ) || ! empty( $redeemid_status['shadow'] ) ) && ! empty( $redeemid_status['nonces']['rest'] );
$redeemid_sync   = (int) ( $redeemid_status['lastSync'] ?? 0 );
$redeemid_note   = $redeemid_sync
	/* translators: %s: how long ago, such as "5 mins" */
	? sprintf( __( 'Synced from Shopify %s ago.', 'redeemid' ), human_time_diff( $redeemid_sync ) )
	: __( 'Not synced yet.', 'redeemid' );

// No early return: Blockstudio reads a template that returns as one that rendered nothing and skips script.view.js.
if ( $isEditor ) :
	?>
	<div useBlockProps class="<?php echo esc_attr( $redeemid_class ); ?>">
		<button type="button" class="wp-element-button redeemid-refresh__button" disabled><?php echo esc_html( $redeemid_label ); ?></button>
		<span class="redeemid-refresh__message"><?php esc_html_e( 'Shows to signed-in, linked customers only.', 'redeemid' ); ?></span>
	</div>
<?php elseif ( ! $redeemid_linked ) : ?>
	<?php // The placeholder, guests and unlinked accounts: nothing to refresh, but keep the wrapper for the island. ?>
	<div useBlockProps class="<?php echo esc_attr( $redeemid_class ); ?>" hidden></div>
<?php else : ?>
	<div useBlockProps class="<?php echo esc_attr( $redeemid_class ); ?>"
		data-redeemid-refresh-endpoint="<?php echo esc_url( rest_url( \RedeemID\Rest\Routes::NS . '/account/sync' ) ); ?>"
		data-redeemid-refresh-nonce="<?php echo esc_attr( $redeemid_status['nonces']['rest'] ); ?>">
		<button type="button" class="wp-element-button redeemid-refresh__button"><?php echo esc_html( $redeemid_label ); ?></button>
		<span class="redeemid-refresh__message" aria-live="polite"><?php echo empty( $a['compact'] ) ? esc_html( $redeemid_note ) : ''; ?></span>
	</div>
<?php endif; ?>
