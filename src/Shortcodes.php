<?php
namespace WP2_Shopify;

class Shortcodes {
	public static function init(): void {
		add_shortcode( 'wp2_shopify_identity', [ self::class, 'render_identity_card' ] );
	}

	public static function render_identity_card( $atts ): string {
		$user_id = get_current_user_id(); // Returns 0 if not logged in

		Assets::force_enqueue_once();

		// Get meta only if user exists
		$customer_id = $user_id ? get_user_meta( $user_id, '_wp2_shopify_customer_id', true ) : '';
		$tags        = $user_id ? get_user_meta( $user_id, '_wp2_shopify_tags', true ) : [];
		$last_sync   = $user_id ? get_user_meta( $user_id, '_wp2_last_sync', true ) : '';

		$is_connected = ! empty( $customer_id );
		$status_class = $is_connected ? 'connected' : 'disconnected';

		$account_settings_meta = $user_id ? get_user_meta( $user_id, '_wp2_shopify_account_settings', true ) : '';
		$account_settings = [];

		if ( $account_settings_meta ) {
			$decoded_settings = json_decode( $account_settings_meta, true );

			if ( is_array( $decoded_settings ) ) {
				$account_settings = $decoded_settings;
			}
		}

		ob_start();
		?>
		<div class="wp2-identity-card <?php echo esc_attr( $status_class ); ?>">
			<div class="wp2-id-header">
				<h3>Shopify Identity</h3>
				<span class="wp2-status-badge"><?php echo $is_connected ? 'Connected' : 'Not Connected'; ?></span>
			</div>

			<div class="wp2-id-body">
				<?php if ( $is_connected ) : ?>
					<div class="wp2-id-details">
						<div class="wp2-detail-row">
							<span class="label">Customer ID:</span>
							<span class="value"><?php echo esc_html( $customer_id ); ?></span>
						</div>
						<div class="wp2-detail-row">
							<span class="label">Tags:</span>
							<span class="value"><?php echo ! empty( $tags ) && is_array( $tags ) ? esc_html( implode( ', ', $tags ) ) : 'None'; ?></span>
						</div>
						<div class="wp2-id-actions">
							<button type="button" class="button wp2-btn-sync" id="wp2-btn-sync">Sync Data</button>
							<button type="button" class="button wp2-btn-disconnect" id="wp2-btn-disconnect">Disconnect</button>
						</div>
					</div>
				<?php else : ?>
					<p class="wp2-id-desc">
						<?php echo $user_id ? 'Connect your account to access identity-aware tooling.' : 'Please log in with Shopify to manage your identity.'; ?>
					</p>
					<div class="wp2-id-actions">
						<button type="button" class="button button-primary wp2-btn-connect" id="wp2-btn-connect">
							<?php echo $user_id ? 'Connect Shopify Account' : 'Log In with Shopify'; ?>
						</button>
					</div>
				<?php endif; ?>
			</div>
			<div id="wp2-id-message" style="display:none; margin-top:10px; font-size:0.9em;"></div>
		</div>
		<?php

		return ob_get_clean();
	}
}
