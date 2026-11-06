<?php

namespace RedeemID\Admin;

use RedeemID\Config;
use RedeemID\Support\EventLog;
use RedeemID\Support\Logger;
use RedeemID\Webhooks\Endpoint;
use RedeemID\Webhooks\Monitor;
use RedeemID\Webhooks\Watchdog;
use RedeemID\Webhooks\Registrar;
use RedeemID\Webhooks\Topics;
use RedeemID\Webhooks\WpdbInboxStore;

/**
 * RedeemID → Webhooks: the Received tab lists what Shopify sent and what
 * became of it; Health holds the daily check, rejected deliveries and the
 * warning settings; Setup holds the endpoint, the signature check and the
 * app's webhook subscriptions. Deliveries with a bad signature never reach
 * the inbox; they are listed from the event log instead.
 */
final class Webhooks {
	public const PAGE   = 'redeemid-webhooks';
	public const ACTION = 'redeemid_webhooks';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'action' ] );
	}

	public static function retry_url( int $row_id ): string {
		return wp_nonce_url( add_query_arg( [ 'action' => self::ACTION, 'do' => 'retry', 'row' => $row_id ], admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	public static function action(): void {
		Menu::guard( self::ACTION );
		// phpcs:disable WordPress.Security.NonceVerification -- checked by Menu::guard().

		$do     = sanitize_key( wp_unslash( $_REQUEST['do'] ?? '' ) );
		$result = '';

		if ( 'register' === $do ) {
			$sync   = Registrar::sync();
			$result = $sync['error'] || $sync['failed'] ? 'register_failed' : 'registered';
		} elseif ( 'retry' === $do ) {
			$row = absint( $_REQUEST['row'] ?? 0 );

			if ( ( new WpdbInboxStore() )->requeue( $row ) ) {
				Endpoint::schedule( $row );
				Logger::log( 'admin.webhook_retry', [ 'row' => $row ] );
				$result = 'requeued';
			}
		}

		wp_safe_redirect( Menu::url( self::PAGE, [ 'result' => $result ] + ( 'retry' === $do ? [] : [ 'tab' => 'setup' ] ) ) );
		exit;
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * The page's tabs: slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return [
			'received' => __( 'Received', 'redeemid' ),
			'health'   => __( 'Health', 'redeemid' ),
			'setup'    => __( 'Setup', 'redeemid' ),
		];
	}

	public static function render(): void {
		$tabs = self::tabs();
		$tab  = Menu::tab( $tabs );
		Menu::page_start( __( 'Webhooks', 'redeemid' ), __( 'Shopify webhooks', 'redeemid' ) );
		Menu::tabs( self::PAGE, $tabs, $tab, __( 'Webhook sections', 'redeemid' ) );
		Menu::result_notice(
			[
				'registered'       => [ 'success', __( 'Webhook subscriptions are in place.', 'redeemid' ) ],
				'register_failed'  => [ 'error', __( 'Some webhook subscriptions could not be created. Details below.', 'redeemid' ) ],
				'requeued'         => [ 'success', __( 'The webhook was queued again.', 'redeemid' ) ],
				'checked_ok'       => [ 'success', __( 'Checked with Shopify: subscriptions are in place and deliveries look healthy.', 'redeemid' ) ],
				'checked_problems' => [ 'warning', __( 'Checked with Shopify. Some problems need attention; see Delivery health.', 'redeemid' ) ],
			]
		);

		if ( 'health' === $tab ) {
			echo '<p class="redeemid-intro">' . esc_html__( 'Members gain and lose access only when Shopify sends tag changes. This is the daily check that keeps deliveries flowing, what Shopify sent that failed its checks, and when to warn you.', 'redeemid' ) . '</p>';
			settings_errors();
			self::health();
		} elseif ( 'setup' === $tab ) {
			echo '<p class="redeemid-intro">' . esc_html__( 'Where Shopify sends deliveries, how they are verified, and the app\'s webhook subscriptions.', 'redeemid' ) . '</p>';
			self::setup();
		} else {
			self::received();
		}

		Menu::page_end();
	}

	/**
	 * What Shopify sent, and what became of it.
	 */
	private static function received(): void {
		$table = new WebhooksTable();
		$table->prepare_items();
		?>
			<p class="redeemid-intro"><?php echo esc_html( sprintf( /* translators: %d: days */ __( 'What Shopify sent, and what became of it. Kept for %d days. Payloads are not stored; only the fields the plugin acts on, until the delivery is processed.', 'redeemid' ), WpdbInboxStore::RETENTION / DAY_IN_SECONDS ) ); ?></p>
			<?php if ( Monitor::messages() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Webhook delivery needs attention.', 'redeemid' ); ?> <a href="<?php echo esc_url( Menu::url( self::PAGE, [ 'tab' => 'health' ] ) ); ?>"><?php esc_html_e( 'See Health', 'redeemid' ); ?></a></p></div>
			<?php endif; ?>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php if ( WebhooksTable::filters()['status'] ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( WebhooksTable::filters()['status'] ); ?>">
				<?php endif; ?>
				<?php $table->display(); ?>
			</form>
		<?php
	}

	/**
	 * Delivery health, rejected deliveries and the warning settings.
	 */
	private static function health(): void {
		$rejected = EventLog::query( [ 'event' => 'webhook.rejected' ], 10, 1 );
		$monitor  = Monitor::state();
		$messages = Monitor::messages();
		?>

			<?php
			Menu::panel_start(
				__( 'Delivery health', 'redeemid' ),
				__( 'Once a day the plugin checks that the subscriptions still exist (re-creating any Shopify removed) and keeps watch on the processing queue and WP-Cron.', 'redeemid' ),
				Menu::action_button_html( Monitor::ACTION, 'check', __( 'Check now', 'redeemid' ) ),
				'health'
			);
			?>
				<ul class="redeemid-checks">
					<?php foreach ( Monitor::problems() as $code => $problem ) : ?>
						<li>
							<span class="redeemid-checks__label"><?php echo Menu::status( Watchdog::FAIL === $problem['state'] ? 'error' : 'warn', Watchdog::FAIL === $problem['state'] ? __( 'Problem', 'redeemid' ) : __( 'Warning', 'redeemid' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							<span class="redeemid-checks__detail"><?php echo esc_html( Monitor::message( $code, $problem['n'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
					<?php if ( ! $messages ) : ?>
						<li><span class="redeemid-checks__label"><?php echo Menu::dot( true ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Healthy', 'redeemid' ); ?></span><span class="redeemid-checks__detail"><?php esc_html_e( 'Subscriptions, the processing queue and WP-Cron all look fine.', 'redeemid' ); ?></span></li>
					<?php endif; ?>
					<li>
						<span class="redeemid-checks__label"><?php esc_html_e( 'Last check with Shopify', 'redeemid' ); ?></span>
						<span class="redeemid-checks__detail"><?php echo Menu::ago( $monitor['checked_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					</li>
				</ul>
			<?php Menu::panel_end(); ?>

			<?php if ( $rejected['total'] ) : ?>
				<?php
				Menu::panel_start(
					__( 'Rejected deliveries', 'redeemid' ),
					__( 'Requests that failed the signature or store check. Shopify gets a 401 and retries; nothing was stored.', 'redeemid' ),
					sprintf( '<a class="button button-small" href="%s">%s</a>', esc_url( Menu::url( Events::PAGE, [ 'event' => 'webhook.rejected' ] ) ), esc_html( sprintf( /* translators: %d: count */ __( 'All %d', 'redeemid' ), $rejected['total'] ) ) )
				);
				?>
					<div class="redeemid-panel__body redeemid-panel__body--flush">
						<table class="widefat striped">
							<?php foreach ( $rejected['rows'] as $row ) : ?>
								<tr><td class="redeemid-col-time"><?php echo Menu::time( $row['created_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td><code><?php echo esc_html( $row['label'] ?: '?' ); ?></code></td><td><?php echo esc_html( $row['status'] ); ?></td></tr>
							<?php endforeach; ?>
						</table>
					</div>
				<?php Menu::panel_end(); ?>
			<?php endif; ?>

			<?php SettingsPage::webhook_warnings(); ?>
		<?php
	}

	/**
	 * The endpoint, signature check and subscriptions, with the register button.
	 */
	private static function setup(): void {
		$secret_set = '' !== Config::webhook_secret();
		$hooks      = get_option( Registrar::RESULT );
		?>

			<?php Menu::panel_start( __( 'Delivery setup', 'redeemid' ) ); ?>
				<ul class="redeemid-checks">
					<li>
						<span class="redeemid-checks__label"><?php esc_html_e( 'Endpoint', 'redeemid' ); ?></span>
						<span class="redeemid-checks__detail"><code><?php echo esc_html( rest_url( 'redeemid/v1/webhooks/events' ) ); ?></code></span>
					</li>
					<li>
						<span class="redeemid-checks__label"><?php echo Menu::dot( $secret_set ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Signatures', 'redeemid' ); ?></span>
						<span class="redeemid-checks__detail"><?php echo $secret_set ? esc_html__( 'Signatures are checked with the Admin app client secret.', 'redeemid' ) : esc_html__( 'The Admin app client secret is missing, so every delivery is rejected. Add it under Connection → Admin API.', 'redeemid' ); ?></span>
					</li>
					<li>
						<span class="redeemid-checks__label"><?php echo Menu::dot( is_array( $hooks ) && ! $hooks['error'] && ! $hooks['failed'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Subscriptions through the app', 'redeemid' ); ?></span>
						<span class="redeemid-checks__detail">
							<?php
							if ( is_array( $hooks ) ) {
								echo Menu::time( $hooks['at'] ) . ' · ' . esc_html( sprintf( /* translators: 1: already present 2: created */ __( '%1$d already present, %2$d created', 'redeemid' ), $hooks['existing'], count( $hooks['created'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
								echo $hooks['error'] ? ' · <strong>' . esc_html( $hooks['error'] ) . '</strong>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput
								foreach ( $hooks['failed'] as $topic => $message ) {
									echo '<br><code>' . esc_html( $topic ) . '</code>: ' . esc_html( $message ); // phpcs:ignore WordPress.Security.EscapeOutput
								}
								foreach ( (array) ( $hooks['skipped'] ?? [] ) as $topic => $scope ) {
									/* translators: 1: webhook topic 2: Admin API scope */
									echo '<br>' . wp_kses( sprintf( __( '%1$s skipped: add the %2$s scope to the Admin app, release a new version, then register again.', 'redeemid' ), '<code>' . esc_html( $topic ) . '</code>', '<code>' . esc_html( $scope ) . '</code>' ), [ 'code' => [] ] ); // phpcs:ignore WordPress.Security.EscapeOutput
								}
							} else {
								esc_html_e( 'Not registered yet.', 'redeemid' );
							}
							?>
						</span>
					</li>
					<li>
						<span class="redeemid-checks__label"><?php esc_html_e( 'Topics', 'redeemid' ); ?></span>
						<span class="redeemid-checks__detail">
							<details>
								<summary>
									<?php
									/* translators: %d: number of topics */
									echo esc_html( sprintf( __( '%d topics registered by the plugin', 'redeemid' ), count( Registrar::topics() ) ) );
									?>
								</summary>
								<?php foreach ( Registrar::topics() as $topic ) : ?>
									<code class="redeemid-chip"><?php echo esc_html( $topic ); ?></code>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Compliance topics are declared in the app configuration, not registered here:', 'redeemid' ); ?></p>
								<?php foreach ( array_diff( Topics::subscribable(), Registrar::topics() ) as $topic ) : ?>
									<code class="redeemid-chip"><?php echo esc_html( $topic ); ?></code>
								<?php endforeach; ?>
							</details>
						</span>
					</li>
					<?php if ( get_option( 'redeemid_app_uninstalled' ) ) : ?>
						<li>
							<span class="redeemid-checks__label"><?php echo Menu::dot( false ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'App', 'redeemid' ); ?></span>
							<span class="redeemid-checks__detail"><strong><?php esc_html_e( 'Shopify reported the app as uninstalled on', 'redeemid' ); ?> <?php echo Menu::time( get_option( 'redeemid_app_uninstalled' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></strong></span>
						</li>
					<?php endif; ?>
				</ul>
				<div class="redeemid-panel__footer">
					<?php Menu::action_button( self::ACTION, 'register', __( 'Register webhooks with Shopify', 'redeemid' ), [], 'primary' ); ?>
					<span class="description"><?php esc_html_e( 'Creates any subscription that is missing. Safe to run again.', 'redeemid' ); ?></span>
				</div>
			<?php Menu::panel_end(); ?>

		<?php
	}
}
