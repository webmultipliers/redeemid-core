<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Config;
use WP2_Shopify\Support\EventLog;
use WP2_Shopify\Support\Logger;
use WP2_Shopify\Webhooks\Endpoint;
use WP2_Shopify\Webhooks\Registrar;
use WP2_Shopify\Webhooks\Topics;
use WP2_Shopify\Webhooks\WpdbInboxStore;

/**
 * Shopify → Webhooks: what Shopify sent, what became of it, and the app's
 * webhook subscriptions. Deliveries with a bad signature never reach the
 * inbox; they are listed from the event log instead.
 */
final class Webhooks {
	public const PAGE   = 'wp2-shopify-webhooks';
	public const ACTION = 'wp2_shopify_webhooks';

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

		wp_safe_redirect( Menu::url( self::PAGE, [ 'result' => $result ] ) );
		exit;
		// phpcs:enable WordPress.Security.NonceVerification
	}

	public static function render(): void {
		$secret_set = '' !== Config::webhook_secret();
		$hooks      = get_option( Registrar::RESULT );
		$rejected   = EventLog::query( [ 'event' => 'webhook.rejected' ], 10, 1 );
		$table      = new WebhooksTable();
		$table->prepare_items();
		Menu::page_start( __( 'Webhooks', 'wp2-shopify' ), __( 'Shopify webhooks', 'wp2-shopify' ), '', __( 'What Shopify sent, what became of it, and the app\'s webhook subscriptions.', 'wp2-shopify' ) );
		Menu::result_notice(
			[
				'registered'      => [ 'success', __( 'Webhook subscriptions are in place.', 'wp2-shopify' ) ],
				'register_failed' => [ 'error', __( 'Some webhook subscriptions could not be created. Details below.', 'wp2-shopify' ) ],
				'requeued'        => [ 'success', __( 'The webhook was queued again.', 'wp2-shopify' ) ],
			]
		);
		?>

			<?php Menu::panel_start( __( 'Delivery setup', 'wp2-shopify' ) ); ?>
				<ul class="wp2s-checks">
					<li>
						<span class="wp2s-checks__label"><?php esc_html_e( 'Endpoint', 'wp2-shopify' ); ?></span>
						<span class="wp2s-checks__detail"><code><?php echo esc_html( rest_url( 'wp2-shopify/v1/webhooks/events' ) ); ?></code></span>
					</li>
					<li>
						<span class="wp2s-checks__label"><?php echo Menu::dot( $secret_set ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Signatures', 'wp2-shopify' ); ?></span>
						<span class="wp2s-checks__detail"><?php echo $secret_set ? esc_html__( 'Signatures are checked with the Admin app client secret.', 'wp2-shopify' ) : esc_html__( 'The Admin app client secret is missing, so every delivery is rejected. Add it in Settings.', 'wp2-shopify' ); ?></span>
					</li>
					<li>
						<span class="wp2s-checks__label"><?php echo Menu::dot( is_array( $hooks ) && ! $hooks['error'] && ! $hooks['failed'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Subscriptions through the app', 'wp2-shopify' ); ?></span>
						<span class="wp2s-checks__detail">
							<?php
							if ( is_array( $hooks ) ) {
								echo Menu::time( $hooks['at'] ) . ' · ' . esc_html( sprintf( /* translators: 1: already present 2: created */ __( '%1$d already present, %2$d created', 'wp2-shopify' ), $hooks['existing'], count( $hooks['created'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
								echo $hooks['error'] ? ' · <strong>' . esc_html( $hooks['error'] ) . '</strong>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput
								foreach ( $hooks['failed'] as $topic => $message ) {
									echo '<br><code>' . esc_html( $topic ) . '</code>: ' . esc_html( $message ); // phpcs:ignore WordPress.Security.EscapeOutput
								}
							} else {
								esc_html_e( 'Not registered yet.', 'wp2-shopify' );
							}
							?>
						</span>
					</li>
					<li>
						<span class="wp2s-checks__label"><?php esc_html_e( 'Topics', 'wp2-shopify' ); ?></span>
						<span class="wp2s-checks__detail">
							<details>
								<summary>
									<?php
									/* translators: %d: number of topics */
									echo esc_html( sprintf( __( '%d topics registered by the plugin', 'wp2-shopify' ), count( Registrar::topics() ) ) );
									?>
								</summary>
								<?php foreach ( Registrar::topics() as $topic ) : ?>
									<code class="wp2s-chip"><?php echo esc_html( $topic ); ?></code>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Compliance topics are declared in the app configuration, not registered here:', 'wp2-shopify' ); ?></p>
								<?php foreach ( array_diff( Topics::subscribable(), Registrar::topics() ) as $topic ) : ?>
									<code class="wp2s-chip"><?php echo esc_html( $topic ); ?></code>
								<?php endforeach; ?>
							</details>
						</span>
					</li>
					<?php if ( get_option( 'wp2_shopify_app_uninstalled' ) ) : ?>
						<li>
							<span class="wp2s-checks__label"><?php echo Menu::dot( false ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'App', 'wp2-shopify' ); ?></span>
							<span class="wp2s-checks__detail"><strong><?php esc_html_e( 'Shopify reported the app as uninstalled on', 'wp2-shopify' ); ?> <?php echo Menu::time( get_option( 'wp2_shopify_app_uninstalled' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></strong></span>
						</li>
					<?php endif; ?>
				</ul>
				<div class="wp2s-panel__footer">
					<?php Menu::action_button( self::ACTION, 'register', __( 'Register webhooks with Shopify', 'wp2-shopify' ), [], 'primary' ); ?>
					<span class="description"><?php esc_html_e( 'Creates any subscription that is missing. Safe to run again.', 'wp2-shopify' ); ?></span>
				</div>
			<?php Menu::panel_end(); ?>

			<?php if ( $rejected['total'] ) : ?>
				<?php
				Menu::panel_start(
					__( 'Rejected deliveries', 'wp2-shopify' ),
					__( 'Requests that failed the signature or store check. Shopify gets a 401 and retries; nothing was stored.', 'wp2-shopify' ),
					sprintf( '<a class="button button-small" href="%s">%s</a>', esc_url( Menu::url( Events::PAGE, [ 'event' => 'webhook.rejected' ] ) ), esc_html( sprintf( /* translators: %d: count */ __( 'All %d', 'wp2-shopify' ), $rejected['total'] ) ) )
				);
				?>
					<div class="wp2s-panel__body wp2s-panel__body--flush">
						<table class="widefat striped">
							<?php foreach ( $rejected['rows'] as $row ) : ?>
								<tr><td class="wp2s-col-time"><?php echo Menu::time( $row['created_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td><code><?php echo esc_html( $row['label'] ?: '?' ); ?></code></td><td><?php echo esc_html( $row['status'] ); ?></td></tr>
							<?php endforeach; ?>
						</table>
					</div>
				<?php Menu::panel_end(); ?>
			<?php endif; ?>

			<h2 class="wp2s-section-title"><?php esc_html_e( 'Received', 'wp2-shopify' ); ?></h2>
			<p class="description"><?php echo esc_html( sprintf( /* translators: %d: days */ __( 'Kept for %d days. Payloads are not stored; only the fields the plugin acts on, until the delivery is processed.', 'wp2-shopify' ), WpdbInboxStore::RETENTION / DAY_IN_SECONDS ) ); ?></p>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php if ( WebhooksTable::filters()['status'] ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( WebhooksTable::filters()['status'] ); ?>">
				<?php endif; ?>
				<?php $table->display(); ?>
			</form>
		<?php
		Menu::page_end();
	}
}
