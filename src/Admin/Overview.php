<?php

namespace RedeemID\Admin;

use RedeemID\Config;
use RedeemID\Integrations\OfficialPlugin;
use RedeemID\Shopify\Admin as AdminApi;
use RedeemID\Shopify\Discovery;
use RedeemID\Support\EventLog;
use RedeemID\Sync\Mirror;
use RedeemID\Webhooks\Monitor;
use RedeemID\Webhooks\WpdbInboxStore;

/**
 * RedeemID → Overview: is everything connected. Setup is its own page
 * (Wizard), credentials are on ConnectionPage, settings on SettingsPage.
 */
final class Overview {
	public static function render(): void {
		global $wpdb;

		$inbox    = new WpdbInboxStore();
		$last     = $inbox->last_received();
		$health   = Monitor::messages();
		$linked   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s", \RedeemID\Support\Multisite::meta_key( Mirror::NUMERIC ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin-only count.
		$missing  = array_keys( array_filter( Config::definitions(), static fn( $def, $name ) => $def['required'] && ! Config::has( $name ), ARRAY_FILTER_USE_BOTH ) );
		$failures = EventLog::count_since( 'shopify.http_failed', time() - DAY_IN_SECONDS ) + EventLog::count_since( 'webhook.rejected', time() - DAY_IN_SECONDS );
		$cards    = [
			[
				Config::has( 'REDEEMID_STORE_DOMAIN' ) && ! $missing,
				__( 'Configuration', 'redeemid' ),
				$missing ? sprintf( /* translators: %s: setting names */ __( 'Missing: %s. Add them under Connection or in wp-config.php.', 'redeemid' ), implode( ', ', array_map( static fn( $name ) => Config::definitions()[ $name ]['label'], $missing ) ) ) : sprintf( /* translators: %s: store domain */ __( 'Store %s', 'redeemid' ), Config::store_domain() ),
				Menu::url( ConnectionPage::PAGE ),
				'admin-settings',
				__( 'Open connection', 'redeemid' ),
			],
			[
				Discovery::available(),
				__( 'Customer sign-in', 'redeemid' ),
				Discovery::available() ? __( 'Customer Account API reachable.', 'redeemid' ) : __( 'Shopify discovery failed, so sign-in is off.', 'redeemid' ),
				Menu::url( Debug::PAGE, [ 'tab' => 'connection' ] ),
				'admin-users',
				__( 'Check in Debug', 'redeemid' ),
			],
			[
				AdminApi::available() && ! get_option( 'redeemid_admin_token_error' ),
				__( 'Admin API', 'redeemid' ),
				AdminApi::available() ? ( get_option( 'redeemid_admin_token_error' ) ? __( 'The last token request failed.', 'redeemid' ) : __( 'Dev Dashboard app, tokens renewed automatically.', 'redeemid' ) ) : __( 'Not configured.', 'redeemid' ),
				Menu::url( Events::PAGE ),
				'rest-api',
				__( 'View app events', 'redeemid' ),
			],
			[
				$last && ! $health,
				__( 'Webhooks', 'redeemid' ),
				$health ? $health[0] : ( $last ? sprintf( /* translators: 1: topic 2: time */ __( 'Last: %1$s, %2$s ago', 'redeemid' ), $last['topic'], human_time_diff( (int) $last['received_at'] ) ) : __( 'Nothing received yet.', 'redeemid' ) ),
				Menu::url( Webhooks::PAGE, [ 'tab' => 'health' ] ),
				'controls-repeat',
				__( 'View webhooks', 'redeemid' ),
			],
			[
				true,
				__( 'Linked customers', 'redeemid' ),
				sprintf( /* translators: %d: count */ __( '%d linked.', 'redeemid' ), $linked ),
				Menu::url( Customers::PAGE ),
				'groups',
				__( 'View customers', 'redeemid' ),
			],
			[
				in_array( OfficialPlugin::state(), [ OfficialPlugin::ABSENT, OfficialPlugin::SAME_STORE ], true ),
				__( 'Shopify\'s WordPress plugin', 'redeemid' ),
				OfficialPlugin::summary(),
				Menu::url( Debug::PAGE, [ 'tab' => 'platform' ] ),
				'admin-plugins',
				__( 'Details', 'redeemid' ),
			],
			[
				0 === $failures,
				__( 'Last 24 hours', 'redeemid' ),
				sprintf( /* translators: %d: count */ __( '%d failed Shopify calls or rejected webhooks.', 'redeemid' ), $failures ),
				Menu::url( Events::PAGE ),
				'chart-line',
				__( 'View app events', 'redeemid' ),
			],
		];
		$problems = count( array_filter( $cards, static fn( $card ) => ! $card[0] ) );

		Menu::page_start( __( 'Overview', 'redeemid' ), __( 'RedeemID', 'redeemid' ) );
		?>
			<section class="redeemid-panel">
				<div class="redeemid-hero <?php echo $problems ? 'is-warn' : ''; ?>">
					<span class="redeemid-hero__icon"><span class="dashicons <?php echo $problems ? 'dashicons-warning' : 'dashicons-yes-alt'; ?>" aria-hidden="true"></span></span>
					<div class="redeemid-hero__text">
						<h2>
							<?php
							echo $problems
								/* translators: %d: number of checks that need attention */
								? esc_html( sprintf( _n( '%d check needs attention', '%d checks need attention', $problems, 'redeemid' ), $problems ) )
								: esc_html__( 'Everything is connected', 'redeemid' );
							?>
						</h2>
						<p><?php esc_html_e( 'Sign-in, memberships and webhooks at a glance. Select a card for the details.', 'redeemid' ); ?></p>
					</div>
					<a class="button <?php echo $missing ? 'button-primary' : ''; ?>" href="<?php echo esc_url( Menu::url( ConnectionPage::PAGE ) ); ?>"><?php esc_html_e( 'Connection', 'redeemid' ); ?></a>
				</div>
			</section>

			<div class="redeemid-cards">
				<?php foreach ( $cards as [ $ok, $title, $detail, $link, $icon, $more ] ) : ?>
					<a href="<?php echo esc_url( $link ); ?>" class="redeemid-card">
						<div class="redeemid-card__head">
							<h2 class="redeemid-card__title"><span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span><?php echo esc_html( $title ); ?></h2>
							<?php echo Menu::status( $ok, $ok ? __( 'OK', 'redeemid' ) : __( 'Attention', 'redeemid' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</div>
						<p class="redeemid-card__detail"><?php echo esc_html( $detail ); ?></p>
						<span class="redeemid-card__link"><?php echo esc_html( $more ); ?> &rarr;</span>
					</a>
				<?php endforeach; ?>
			</div>
		<?php
		Menu::page_end();
	}
}
