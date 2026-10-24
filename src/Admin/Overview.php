<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Config;
use WP2_Shopify\Integrations\OfficialPlugin;
use WP2_Shopify\Shopify\Admin as AdminApi;
use WP2_Shopify\Shopify\Discovery;
use WP2_Shopify\Support\EventLog;
use WP2_Shopify\Sync\Mirror;
use WP2_Shopify\Sync\Reconciler;
use WP2_Shopify\Webhooks\WpdbInboxStore;

/**
 * Shopify → Overview: is everything connected. Settings live in SettingsPage.
 */
final class Overview {
	public static function render(): void {
		global $wpdb;

		$inbox    = new WpdbInboxStore();
		$last     = $inbox->last_received();
		$recon    = get_option( Reconciler::STATS );
		$linked   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s", Mirror::NUMERIC ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin-only count.
		$missing  = array_keys( array_filter( Config::definitions(), static fn( $def, $name ) => $def['required'] && ! Config::has( $name ), ARRAY_FILTER_USE_BOTH ) );
		$failures = EventLog::count_since( 'shopify.http_failed', time() - DAY_IN_SECONDS ) + EventLog::count_since( 'webhook.rejected', time() - DAY_IN_SECONDS );
		$cards    = [
			[
				Config::has( 'WP2_SHOPIFY_STORE_DOMAIN' ) && ! $missing,
				__( 'Configuration', 'wp2-shopify' ),
				$missing ? sprintf( /* translators: %s: setting names */ __( 'Missing: %s. Add them in Settings or wp-config.php.', 'wp2-shopify' ), implode( ', ', array_map( static fn( $name ) => Config::definitions()[ $name ]['label'], $missing ) ) ) : sprintf( /* translators: %s: store domain */ __( 'Store %s', 'wp2-shopify' ), Config::store_domain() ),
				Menu::url( SettingsPage::PAGE ),
				'admin-settings',
				__( 'Open settings', 'wp2-shopify' ),
			],
			[
				Discovery::available(),
				__( 'Customer sign-in', 'wp2-shopify' ),
				Discovery::available() ? __( 'Customer Account API reachable.', 'wp2-shopify' ) : __( 'Shopify discovery failed, so sign-in is off.', 'wp2-shopify' ),
				Menu::url( Debug::PAGE ),
				'admin-users',
				__( 'Check in Debug', 'wp2-shopify' ),
			],
			[
				AdminApi::available() && ! get_option( 'wp2_shopify_admin_token_error' ),
				__( 'Admin API', 'wp2-shopify' ),
				AdminApi::available() ? ( get_option( 'wp2_shopify_admin_token_error' ) ? __( 'The last token request failed.', 'wp2-shopify' ) : __( 'Dev Dashboard app, tokens renewed automatically.', 'wp2-shopify' ) ) : __( 'Not configured.', 'wp2-shopify' ),
				Menu::url( Events::PAGE ),
				'rest-api',
				__( 'View app events', 'wp2-shopify' ),
			],
			[
				(bool) $last,
				__( 'Webhooks', 'wp2-shopify' ),
				$last ? sprintf( /* translators: 1: topic 2: time */ __( 'Last: %1$s, %2$s ago', 'wp2-shopify' ), $last['topic'], human_time_diff( (int) $last['received_at'] ) ) : __( 'Nothing received yet.', 'wp2-shopify' ),
				Menu::url( Webhooks::PAGE ),
				'controls-repeat',
				__( 'View webhooks', 'wp2-shopify' ),
			],
			[
				true,
				__( 'Linked customers', 'wp2-shopify' ),
				is_array( $recon ) ? sprintf( /* translators: 1: count 2: time */ __( '%1$d linked. Last reconciliation %2$s ago.', 'wp2-shopify' ), $linked, human_time_diff( (int) ( $recon['finished'] ?? 0 ) ) ) : sprintf( /* translators: %d: count */ __( '%d linked.', 'wp2-shopify' ), $linked ),
				Menu::url( Customers::PAGE ),
				'groups',
				__( 'View customers', 'wp2-shopify' ),
			],
			[
				in_array( OfficialPlugin::state(), [ OfficialPlugin::ABSENT, OfficialPlugin::SAME_STORE ], true ),
				__( 'Shopify\'s WordPress plugin', 'wp2-shopify' ),
				OfficialPlugin::summary(),
				Menu::url( Debug::PAGE ) . '#official-plugin',
				'admin-plugins',
				__( 'Details', 'wp2-shopify' ),
			],
			[
				0 === $failures,
				__( 'Last 24 hours', 'wp2-shopify' ),
				sprintf( /* translators: %d: count */ __( '%d failed Shopify calls or rejected webhooks.', 'wp2-shopify' ), $failures ),
				Menu::url( Events::PAGE ),
				'chart-line',
				__( 'View app events', 'wp2-shopify' ),
			],
		];
		$problems = count( array_filter( $cards, static fn( $card ) => ! $card[0] ) );

		Menu::page_start( __( 'Overview', 'wp2-shopify' ), __( 'Shopify', 'wp2-shopify' ) );
		?>
			<section class="wp2s-panel">
				<div class="wp2s-hero <?php echo $problems ? 'is-warn' : ''; ?>">
					<span class="wp2s-hero__icon"><span class="dashicons <?php echo $problems ? 'dashicons-warning' : 'dashicons-yes-alt'; ?>" aria-hidden="true"></span></span>
					<div class="wp2s-hero__text">
						<h2>
							<?php
							echo $problems
								/* translators: %d: number of checks that need attention */
								? esc_html( sprintf( _n( '%d check needs attention', '%d checks need attention', $problems, 'wp2-shopify' ), $problems ) )
								: esc_html__( 'Everything is connected', 'wp2-shopify' );
							?>
						</h2>
						<p><?php esc_html_e( 'Sign-in, memberships and webhooks at a glance. Select a card for the details.', 'wp2-shopify' ); ?></p>
					</div>
					<a class="button <?php echo $missing ? 'button-primary' : ''; ?>" href="<?php echo esc_url( Menu::url( SettingsPage::PAGE ) ); ?>"><?php esc_html_e( 'Settings', 'wp2-shopify' ); ?></a>
				</div>
			</section>

			<div class="wp2s-cards">
				<?php foreach ( $cards as [ $ok, $title, $detail, $link, $icon, $more ] ) : ?>
					<a href="<?php echo esc_url( $link ); ?>" class="wp2s-card">
						<div class="wp2s-card__head">
							<h2 class="wp2s-card__title"><span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span><?php echo esc_html( $title ); ?></h2>
							<?php echo Menu::status( $ok, $ok ? __( 'OK', 'wp2-shopify' ) : __( 'Attention', 'wp2-shopify' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</div>
						<p class="wp2s-card__detail"><?php echo esc_html( $detail ); ?></p>
						<span class="wp2s-card__link"><?php echo esc_html( $more ); ?> &rarr;</span>
					</a>
				<?php endforeach; ?>
			</div>
		<?php
		Menu::page_end();
	}
}
