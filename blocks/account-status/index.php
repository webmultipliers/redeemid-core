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

$wp2_title  = '' !== trim( (string) ( $a['title'] ?? '' ) ) ? (string) $a['title'] : __( 'Your Shopify account', 'wp2-shopify' );
$wp2_rows   = [
	'signedIn'  => __( 'Signed in', 'wp2-shopify' ),
	'name'      => __( 'Display name', 'wp2-shopify' ),
	'linked'    => __( 'Linked to a Shopify customer', 'wp2-shopify' ),
	'shadow'    => __( 'Account created by Shopify sign-in', 'wp2-shopify' ),
	'lastSync'  => __( 'Membership last synced', 'wp2-shopify' ),
	'available' => __( 'Shopify sign-in available', 'wp2-shopify' ),
	'nonces'    => __( 'Session ready for account actions', 'wp2-shopify' ),
];
$wp2_values = array_fill_keys( array_keys( $wp2_rows ), '…' );
$wp2_status = null;
$wp2_note   = __( 'Checking who you are…', 'wp2-shopify' );

if ( $isEditor ) {
	$wp2_note = __( 'Fills in on the live page for each visitor.', 'wp2-shopify' );
} elseif ( $isIslandFragment ) {
	$wp2_status = \WP2_Shopify\Rest\Status::for_current_user();
	$wp2_in     = ! empty( $wp2_status['signedIn'] );
	$wp2_yes    = static fn ( $value ): string => $value ? __( 'Yes', 'wp2-shopify' ) : __( 'No', 'wp2-shopify' );
	$wp2_sync   = (int) ( $wp2_status['lastSync'] ?? 0 );

	// Redact nonces: the panel proves they arrive, it never shows them.
	if ( isset( $wp2_status['nonces'] ) ) {
		$wp2_status['nonces'] = array_fill_keys( array_keys( $wp2_status['nonces'] ), '(redacted)' );
	}

	$wp2_values = [
		'signedIn'  => $wp2_yes( $wp2_in ),
		'name'      => $wp2_in ? ( ( $wp2_status['displayName'] ?? '' ) ?: '—' ) : '—',
		'linked'    => $wp2_in ? $wp2_yes( ! empty( $wp2_status['linked'] ) ) : '—',
		'shadow'    => $wp2_in ? $wp2_yes( ! empty( $wp2_status['shadow'] ) ) : '—',
		'lastSync'  => ! $wp2_in ? '—' : ( $wp2_sync
			/* translators: 1: date and time, 2: how long ago, such as "5 mins" */
			? sprintf( __( '%1$s (%2$s ago)', 'wp2-shopify' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $wp2_sync ), human_time_diff( $wp2_sync ) )
			: __( 'Never', 'wp2-shopify' ) ),
		'available' => $wp2_yes( ! empty( $wp2_status['available'] ) ),
		'nonces'    => $wp2_in ? $wp2_yes( ! empty( $wp2_status['nonces']['logout'] ) && ! empty( $wp2_status['nonces']['rest'] ) ) : __( 'Not for guests', 'wp2-shopify' ),
	];

	if ( empty( $wp2_status['available'] ) ) {
		$wp2_note = __( 'Shopify sign-in is not available right now.', 'wp2-shopify' );
	} elseif ( ! $wp2_in ) {
		$wp2_note = __( 'You are not signed in. Log in with Shopify to see your membership.', 'wp2-shopify' );
	} elseif ( empty( $wp2_status['linked'] ) && empty( $wp2_status['shadow'] ) ) {
		$wp2_note = __( 'You are signed in, but your account is not connected to Shopify yet.', 'wp2-shopify' );
	} else {
		$wp2_note = __( 'Signed in and connected to Shopify.', 'wp2-shopify' );
	}
}

$wp2_class = 'wp2-shopify-account-status';
if ( $wp2_status && ! empty( $wp2_status['signedIn'] ) ) {
	$wp2_class .= ' is-signed-in';
}
if ( $wp2_status && ( ! empty( $wp2_status['linked'] ) || ! empty( $wp2_status['shadow'] ) ) ) {
	$wp2_class .= ' is-linked';
}
?>
<div useBlockProps class="<?php echo esc_attr( $wp2_class ); ?>">
	<p class="wp2-shopify-account-status__title"><?php echo esc_html( $wp2_title ); ?></p>
	<?php if ( ! $isEditor ) : ?>
		<table class="wp2-shopify-account-status__table">
			<tbody>
				<?php foreach ( $wp2_rows as $wp2_key => $wp2_label ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $wp2_label ); ?></th>
						<td><?php echo esc_html( $wp2_values[ $wp2_key ] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<p class="wp2-shopify-account-status__note" aria-live="polite"><?php echo esc_html( $wp2_note ); ?></p>
	<?php if ( $wp2_status && ! empty( $a['showRaw'] ) ) : ?>
		<details class="wp2-shopify-account-status__raw">
			<summary><?php esc_html_e( 'Raw status response', 'wp2-shopify' ); ?></summary>
			<pre><?php echo esc_html( (string) wp_json_encode( $wp2_status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
		</details>
	<?php endif; ?>
</div>
