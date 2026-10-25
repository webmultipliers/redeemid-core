<?php
/**
 * Shopify Account Status, rendered as a Blockstudio dynamic island.
 *
 * The page carries the same neutral placeholder for everyone, so it stays
 * cacheable. Blockstudio's island runtime then asks the site, in one batched,
 * never-cached request for every island on the page, to render the panel for
 * the visitor from Rest\Status, the same helper behind GET /auth/status.
 * Nonces are only checked for, never printed.
 *
 * @var array $a                Block attributes.
 * @var bool  $isEditor         Rendering inside the block editor.
 * @var bool  $isIslandFragment Rendering the visitor's own fragment.
 */

defined( 'ABSPATH' ) || exit;

$redeemid_title  = '' !== trim( (string) ( $a['title'] ?? '' ) ) ? (string) $a['title'] : __( 'Your Shopify account', 'redeemid' );
$redeemid_rows   = [
	'signedIn'  => __( 'Signed in', 'redeemid' ),
	'name'      => __( 'Display name', 'redeemid' ),
	'linked'    => __( 'Linked to a Shopify customer', 'redeemid' ),
	'shadow'    => __( 'Account created by Shopify sign-in', 'redeemid' ),
	'lastSync'  => __( 'Membership last synced', 'redeemid' ),
	'available' => __( 'Shopify sign-in available', 'redeemid' ),
	'nonces'    => __( 'Session ready for account actions', 'redeemid' ),
];
$redeemid_values = array_fill_keys( array_keys( $redeemid_rows ), '…' );
$redeemid_status = null;
$redeemid_note   = __( 'Checking who you are…', 'redeemid' );

if ( $isEditor ) {
	$redeemid_note = __( 'Fills in on the live page for each visitor.', 'redeemid' );
} elseif ( $isIslandFragment ) {
	$redeemid_status = \RedeemID\Rest\Status::for_current_user();
	$redeemid_in     = ! empty( $redeemid_status['signedIn'] );
	$redeemid_yes    = static fn ( $value ): string => $value ? __( 'Yes', 'redeemid' ) : __( 'No', 'redeemid' );
	$redeemid_sync   = (int) ( $redeemid_status['lastSync'] ?? 0 );

	// Redact nonces: the panel proves they arrive, it never shows them.
	if ( isset( $redeemid_status['nonces'] ) ) {
		$redeemid_status['nonces'] = array_fill_keys( array_keys( $redeemid_status['nonces'] ), '(redacted)' );
	}

	$redeemid_values = [
		'signedIn'  => $redeemid_yes( $redeemid_in ),
		'name'      => $redeemid_in ? ( ( $redeemid_status['displayName'] ?? '' ) ?: '—' ) : '—',
		'linked'    => $redeemid_in ? $redeemid_yes( ! empty( $redeemid_status['linked'] ) ) : '—',
		'shadow'    => $redeemid_in ? $redeemid_yes( ! empty( $redeemid_status['shadow'] ) ) : '—',
		'lastSync'  => ! $redeemid_in ? '—' : ( $redeemid_sync
			/* translators: 1: date and time, 2: how long ago, such as "5 mins" */
			? sprintf( __( '%1$s (%2$s ago)', 'redeemid' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $redeemid_sync ), human_time_diff( $redeemid_sync ) )
			: __( 'Never', 'redeemid' ) ),
		'available' => $redeemid_yes( ! empty( $redeemid_status['available'] ) ),
		'nonces'    => $redeemid_in ? $redeemid_yes( ! empty( $redeemid_status['nonces']['logout'] ) && ! empty( $redeemid_status['nonces']['rest'] ) ) : __( 'Not for guests', 'redeemid' ),
	];

	if ( empty( $redeemid_status['available'] ) ) {
		$redeemid_note = __( 'Shopify sign-in is not available right now.', 'redeemid' );
	} elseif ( ! $redeemid_in ) {
		$redeemid_note = __( 'You are not signed in. Log in with Shopify to see your membership.', 'redeemid' );
	} elseif ( empty( $redeemid_status['linked'] ) && empty( $redeemid_status['shadow'] ) ) {
		$redeemid_note = __( 'You are signed in, but your account is not connected to Shopify yet.', 'redeemid' );
	} else {
		$redeemid_note = __( 'Signed in and connected to Shopify.', 'redeemid' );
	}
}

$redeemid_class = 'redeemid-account-status';
if ( $redeemid_status && ! empty( $redeemid_status['signedIn'] ) ) {
	$redeemid_class .= ' is-signed-in';
}
if ( $redeemid_status && ( ! empty( $redeemid_status['linked'] ) || ! empty( $redeemid_status['shadow'] ) ) ) {
	$redeemid_class .= ' is-linked';
}
?>
<div useBlockProps class="<?php echo esc_attr( $redeemid_class ); ?>">
	<p class="redeemid-account-status__title"><?php echo esc_html( $redeemid_title ); ?></p>
	<?php if ( ! $isEditor ) : ?>
		<table class="redeemid-account-status__table">
			<tbody>
				<?php foreach ( $redeemid_rows as $redeemid_key => $redeemid_label ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $redeemid_label ); ?></th>
						<td><?php echo esc_html( $redeemid_values[ $redeemid_key ] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<p class="redeemid-account-status__note" aria-live="polite"><?php echo esc_html( $redeemid_note ); ?></p>
	<?php if ( $redeemid_status && ! empty( $a['showRaw'] ) ) : ?>
		<details class="redeemid-account-status__raw">
			<summary><?php esc_html_e( 'Raw status response', 'redeemid' ); ?></summary>
			<pre><?php echo esc_html( (string) wp_json_encode( $redeemid_status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
		</details>
	<?php endif; ?>
</div>
