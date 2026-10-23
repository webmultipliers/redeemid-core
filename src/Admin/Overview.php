<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Config;
use WP2_Shopify\Integrations\OfficialPlugin;
use WP2_Shopify\Settings;
use WP2_Shopify\Shopify\Admin as AdminApi;
use WP2_Shopify\Shopify\Discovery;
use WP2_Shopify\Support\EventLog;
use WP2_Shopify\Sync\Mirror;
use WP2_Shopify\Sync\Reconciler;
use WP2_Shopify\Webhooks\WpdbInboxStore;

/**
 * Shopify → Overview: is everything connected, and the plugin's settings.
 */
final class Overview {
	public static function render(): void {
		global $wpdb;

		$settings = Settings::all();
		$inbox    = new WpdbInboxStore();
		$last     = $inbox->last_received();
		$recon    = get_option( Reconciler::STATS );
		$linked   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s", Mirror::NUMERIC ) );
		$missing  = array_keys( array_filter( Config::definitions(), static fn( $def, $name ) => $def['required'] && ! Config::has( $name ), ARRAY_FILTER_USE_BOTH ) );
		$failures = EventLog::count_since( 'shopify.http_failed', time() - DAY_IN_SECONDS ) + EventLog::count_since( 'webhook.rejected', time() - DAY_IN_SECONDS );
		$cards    = [
			[
				Config::has( 'WP2_SHOPIFY_STORE_DOMAIN' ) && ! $missing,
				__( 'Configuration', 'wp2-shopify' ),
				$missing ? sprintf( /* translators: %s: constant names */ __( 'Missing in wp-config.php: %s', 'wp2-shopify' ), implode( ', ', $missing ) ) : sprintf( /* translators: %s: store domain */ __( 'Store %s', 'wp2-shopify' ), Config::store_domain() ),
				Menu::url( Debug::PAGE ),
			],
			[
				Discovery::available(),
				__( 'Customer sign-in', 'wp2-shopify' ),
				Discovery::available() ? __( 'Customer Account API reachable.', 'wp2-shopify' ) : __( 'Shopify discovery failed, so sign-in is off.', 'wp2-shopify' ),
				Menu::url( Debug::PAGE ),
			],
			[
				AdminApi::available() && ! get_option( 'wp2_shopify_admin_token_error' ),
				__( 'Admin API', 'wp2-shopify' ),
				AdminApi::available() ? ( get_option( 'wp2_shopify_admin_token_error' ) ? __( 'The last token request failed.', 'wp2-shopify' ) : __( 'Dev Dashboard app, tokens renewed automatically.', 'wp2-shopify' ) ) : __( 'Not configured.', 'wp2-shopify' ),
				Menu::url( Events::PAGE ),
			],
			[
				(bool) $last,
				__( 'Webhooks', 'wp2-shopify' ),
				$last ? sprintf( /* translators: 1: topic 2: time */ __( 'Last: %1$s, %2$s ago', 'wp2-shopify' ), $last['topic'], human_time_diff( (int) $last['received_at'] ) ) : __( 'Nothing received yet.', 'wp2-shopify' ),
				Menu::url( Webhooks::PAGE ),
			],
			[
				true,
				__( 'Linked customers', 'wp2-shopify' ),
				is_array( $recon ) ? sprintf( /* translators: 1: count 2: time */ __( '%1$d linked. Last reconciliation %2$s ago.', 'wp2-shopify' ), $linked, human_time_diff( (int) ( $recon['finished'] ?? 0 ) ) ) : sprintf( /* translators: %d: count */ __( '%d linked.', 'wp2-shopify' ), $linked ),
				Menu::url( Customers::PAGE ),
			],
			[
				in_array( OfficialPlugin::state(), [ OfficialPlugin::ABSENT, OfficialPlugin::SAME_STORE ], true ),
				__( 'Shopify\'s WordPress plugin', 'wp2-shopify' ),
				OfficialPlugin::summary(),
				Menu::url( Debug::PAGE ) . '#official-plugin',
			],
			[
				0 === $failures,
				__( 'Last 24 hours', 'wp2-shopify' ),
				sprintf( /* translators: %d: count */ __( '%d failed Shopify calls or rejected webhooks.', 'wp2-shopify' ), $failures ),
				Menu::url( Events::PAGE ),
			],
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Shopify', 'wp2-shopify' ); ?></h1>

			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;max-width:1100px;margin:16px 0 24px">
				<?php foreach ( $cards as [ $ok, $title, $detail, $link ] ) : ?>
					<a href="<?php echo esc_url( $link ); ?>" class="card" style="margin:0;max-width:none;text-decoration:none;color:inherit">
						<h2 class="title" style="margin-top:0"><?php echo Menu::dot( $ok ) . esc_html( $title ); // phpcs:ignore WordPress.Security.EscapeOutput ?></h2>
						<p style="overflow-wrap:anywhere"><?php echo esc_html( $detail ); ?></p>
					</a>
				<?php endforeach; ?>
			</div>

			<h2><?php esc_html_e( 'Settings', 'wp2-shopify' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'wp2_shopify' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="wp2s-fresh"><?php esc_html_e( 'Membership freshness window (days)', 'wp2-shopify' ); ?></label></th>
						<td><input id="wp2s-fresh" type="number" min="0" max="365" name="<?php echo esc_attr( Settings::OPTION ); ?>[freshness_days]" value="<?php echo esc_attr( (string) $settings['freshness_days'] ); ?>"> <p class="description"><?php esc_html_e( 'Synced tags older than this no longer unlock content. 0 disables the limit.', 'wp2-shopify' ); ?></p></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'When Shopify is unreachable', 'wp2-shopify' ); ?></th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( Settings::OPTION ); ?>[stale_behavior]" value="honor" <?php checked( 'honor', $settings['stale_behavior'] ); ?>> <?php esc_html_e( 'Honor synced tags within the window (default)', 'wp2-shopify' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( Settings::OPTION ); ?>[stale_behavior]" value="fail_closed" <?php checked( 'fail_closed', $settings['stale_behavior'] ); ?>> <?php esc_html_e( 'Lock immediately after a failed sync', 'wp2-shopify' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><label for="wp2s-map"><?php esc_html_e( 'Membership products', 'wp2-shopify' ); ?></label></th>
						<td>
							<textarea id="wp2s-map" rows="5" cols="60" class="code" name="<?php echo esc_attr( Settings::OPTION ); ?>[membership_map]" placeholder="1234567890: member, gold"><?php echo esc_textarea( (string) $settings['membership_map'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line: Shopify product ID, a colon, then the customer tags a paid order grants. The plugin adds these tags on payment and removes them on refund or cancellation. It never touches other tags.', 'wp2-shopify' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Shared devices', 'wp2-shopify' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[clear_cart_on_logout]" value="1" <?php checked( ! empty( $settings['clear_cart_on_logout'] ) ); ?>> <?php esc_html_e( 'Clear the cart on sign-out', 'wp2-shopify' ); ?></label>
							<p class="description"><?php esc_html_e( 'With Shopify\'s plugin active, a cart signed in to a customer is always dropped after sign-out. This also drops guest items added while signed in.', 'wp2-shopify' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
