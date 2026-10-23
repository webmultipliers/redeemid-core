<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Blocks\Registration;
use WP2_Shopify\Config;
use WP2_Shopify\Entitlement\Rule;
use WP2_Shopify\Requirements;
use WP2_Shopify\Settings;
use WP2_Shopify\Shopify\Admin as AdminApi;
use WP2_Shopify\Shopify\AdminToken;
use WP2_Shopify\Shopify\Discovery;
use WP2_Shopify\Support\CustomerId;
use WP2_Shopify\Sync\Mirror;
use WP2_Shopify\Sync\Reconciler;
use WP2_Shopify\Webhooks\Registrar;
use WP2_Shopify\Webhooks\Signature;
use WP2_Shopify\Webhooks\Topics;
use WP2_Shopify\Webhooks\WpdbInboxStore;

/**
 * Settings → Shopify: configuration status and diagnostics (spec 14.2).
 * Secret values are never displayed, only their presence.
 */
final class Diagnostics {
	private const PAGE = 'wp2-shopify';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_init', [ self::class, 'settings' ] );
		add_action( 'admin_post_wp2_shopify_diagnostics', [ self::class, 'action' ] );
		add_action( 'admin_notices', [ self::class, 'notices' ] );
	}

	public static function menu(): void {
		add_options_page( __( 'Shopify', 'wp2-shopify' ), __( 'Shopify', 'wp2-shopify' ), 'manage_options', self::PAGE, [ self::class, 'render' ] );
	}

	public static function settings(): void {
		register_setting( 'wp2_shopify', Settings::OPTION, [ 'type' => 'array', 'sanitize_callback' => [ Settings::class, 'sanitize' ] ] );
	}

	public static function notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( Config::uses_deprecated_secret_name() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'WP2 Shopify: WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN is deprecated. Rename it to WP2_SHOPIFY_CLIENT_SECRET in wp-config.php.', 'wp2-shopify' ) . '</p></div>';
		}

		$upgrade = get_option( 'wp2_shopify_upgrade_notice' );

		if ( is_array( $upgrade ) ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of linked users */
						__( 'WP2 Shopify 2.0 upgrade: stored Shopify sessions were discarded for security, so linked customers sign in once more. Re-register your Shopify webhooks to the new /webhooks/events endpoint (the old /iwebhooks path is gone). %d linked users were updated.', 'wp2-shopify' ),
						(int) $upgrade['backfilled']
					)
				)
			);
		}
	}

	public static function action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'wp2-shopify' ), 403 );
		}

		check_admin_referer( 'wp2_shopify_diagnostics' );

		$do     = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$result = '';

		switch ( $do ) {
			case 'reconcile':
				$stats  = Reconciler::run();
				$result = $stats['error'] ? 'reconcile_error' : 'reconcile_ok';
				break;
			case 'webhooks':
				$sync   = Registrar::sync();
				$result = $sync['error'] || $sync['failed'] ? 'webhooks_error' : 'webhooks_ok';
				break;
			case 'discovery':
				Discovery::flush();
				$result = Discovery::available() ? 'discovery_ok' : 'discovery_error';
				break;
			case 'dismiss_upgrade':
				delete_option( 'wp2_shopify_upgrade_notice' );
				break;
			case 'dryrun':
				$query = [
					'dry_customer' => sanitize_text_field( wp_unslash( $_POST['customer'] ?? '' ) ),
					'dry_tags'     => sanitize_text_field( wp_unslash( $_POST['tags'] ?? '' ) ),
					'dry_mode'     => 'all' === ( $_POST['mode'] ?? '' ) ? 'all' : 'any',
				];
				wp_safe_redirect( add_query_arg( $query, admin_url( 'options-general.php?page=' . self::PAGE ) ) . '#dry-run' );
				exit;
		}

		wp_safe_redirect( add_query_arg( 'result', $result, admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}

	public static function render(): void {
		$inbox     = new WpdbInboxStore();
		$counts    = $inbox->counts();
		$last      = $inbox->last_received();
		$recon     = get_option( Reconciler::STATS );
		$blocks    = get_option( Registration::STATUS_OPTION );
		$settings  = Settings::all();
		$problems  = Requirements::problems();
		$sample    = '{"selftest":true}';
		$secret    = Config::webhook_secret();
		$hmac_ok   = '' !== $secret && Signature::verify( $sample, Signature::compute( $sample, $secret ), $secret );
		$hooks     = get_option( Registrar::RESULT );
		$token_err = get_option( 'wp2_shopify_admin_token_error' );
		$ok        = static fn( bool $pass ) => $pass ? '<span style="color:#008a20">●</span> ' : '<span style="color:#d63638">●</span> ';
		$fmt_time  = static fn( $t ) => $t ? esc_html( wp_date( 'Y-m-d H:i', (int) $t ) ) : '—';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Shopify', 'wp2-shopify' ); ?></h1>

			<h2><?php esc_html_e( 'Configuration (wp-config.php)', 'wp2-shopify' ); ?></h2>
			<table class="widefat striped" style="max-width:960px">
				<tbody>
				<?php foreach ( Config::definitions() as $name => $def ) : ?>
					<tr>
						<td><?php echo $ok( Config::has( $name ) || ! $def['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?><code><?php echo esc_html( $name ); ?></code></td>
						<td><?php echo esc_html( $def['label'] ); ?></td>
						<td><?php echo Config::has( $name ) ? esc_html__( 'Present', 'wp2-shopify' ) : ( $def['required'] ? '<strong>' . esc_html__( 'Missing (required)', 'wp2-shopify' ) . '</strong>' : esc_html__( 'Not set', 'wp2-shopify' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo esc_html( $def['help'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Platform', 'wp2-shopify' ); ?></h2>
			<ul>
				<li><?php echo $ok( ! $problems ); // phpcs:ignore WordPress.Security.EscapeOutput ?>PHP <?php echo esc_html( PHP_VERSION ); ?> (≥ <?php echo esc_html( Requirements::MIN_PHP ); ?>), WordPress <?php echo esc_html( get_bloginfo( 'version' ) ); ?> (≥ <?php echo esc_html( Requirements::MIN_WP ); ?>)</li>
				<li><?php echo $ok( defined( 'BLOCKSTUDIO_VERSION' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>Blockstudio <?php echo esc_html( defined( 'BLOCKSTUDIO_VERSION' ) ? BLOCKSTUDIO_VERSION : __( 'not loaded', 'wp2-shopify' ) ); ?></li>
				<li><?php echo $ok( is_array( $blocks ) && 'ok' === $blocks['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php esc_html_e( 'Plugin blocks:', 'wp2-shopify' ); ?>
					<?php echo esc_html( is_array( $blocks ) ? $blocks['status'] . ( $blocks['missing'] ?? [] ? ' (' . implode( ', ', $blocks['missing'] ) . ')' : '' ) : __( 'not checked yet', 'wp2-shopify' ) ); ?>.
					<?php if ( is_array( $blocks ) ) : ?>
						<?php echo esc_html( sprintf( /* translators: 1: yes/no 2: yes/no */ __( 'Theme has Blockstudio blocks: %1$s. Theme block path untouched: %2$s.', 'wp2-shopify' ), ! empty( $blocks['theme_blockstudio'] ) ? 'yes' : 'no', ! empty( $blocks['theme_path_intact'] ) ? 'yes' : 'no' ) ); ?>
					<?php endif; ?>
				</li>
				<li><?php esc_html_e( 'Shopify API version:', 'wp2-shopify' ); ?> <code><?php echo esc_html( Config::api_version() ); ?></code></li>
			</ul>

			<h2><?php esc_html_e( 'Shopify connection', 'wp2-shopify' ); ?></h2>
			<ul>
				<li><?php echo $ok( Discovery::available() ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Customer Account API discovery, last fetched', 'wp2-shopify' ); ?> <?php echo $fmt_time( Discovery::last_fetched() ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
				<li><?php echo $ok( AdminApi::available() && ! $token_err ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Admin API access (purchase-to-tag and reconciliation):', 'wp2-shopify' ); ?>
					<?php
					if ( AdminToken::uses_client_credentials() ) {
						esc_html_e( 'Dev Dashboard app, 24-hour tokens renewed automatically.', 'wp2-shopify' );
						$scopes = (string) get_option( 'wp2_shopify_admin_token_scopes', '' );
						echo '' !== $scopes ? ' ' . esc_html__( 'Granted scopes:', 'wp2-shopify' ) . ' <code>' . esc_html( $scopes ) . '</code>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput
					} elseif ( AdminToken::configured() ) {
						esc_html_e( 'permanent token.', 'wp2-shopify' );
					} else {
						esc_html_e( 'not configured.', 'wp2-shopify' );
					}
					if ( is_array( $token_err ) ) {
						echo ' <strong>' . esc_html__( 'Last token request failed:', 'wp2-shopify' ) . ' ' . esc_html( (string) $token_err['error'] ) . '</strong>'; // phpcs:ignore WordPress.Security.EscapeOutput
					}
					?>
				</li>
				<li><?php esc_html_e( 'Shopify app settings: callback URI', 'wp2-shopify' ); ?> <code><?php echo esc_html( Config::callback_url() ); ?></code>, <?php esc_html_e( 'JavaScript origin', 'wp2-shopify' ); ?> <code><?php echo esc_html( untrailingslashit( home_url() ) ); ?></code>, <?php esc_html_e( 'logout URI', 'wp2-shopify' ); ?> <code><?php echo esc_html( home_url( '/' ) ); ?></code></li>
			</ul>

			<h2><?php esc_html_e( 'Webhooks', 'wp2-shopify' ); ?></h2>
			<p><?php esc_html_e( 'Endpoint:', 'wp2-shopify' ); ?> <code><?php echo esc_html( rest_url( 'wp2-shopify/v1/webhooks/events' ) ); ?></code></p>
			<ul>
				<li><?php echo $ok( $hmac_ok ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'HMAC self-test with the configured secret', 'wp2-shopify' ); ?></li>
				<li><?php esc_html_e( 'Last received:', 'wp2-shopify' ); ?> <?php echo $last ? esc_html( $last['topic'] . ' · ' . $last['status'] ) . ' · ' . $fmt_time( $last['received_at'] ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
				<li><?php esc_html_e( 'Last rejected signature:', 'wp2-shopify' ); ?> <?php echo $fmt_time( get_option( 'wp2_shopify_webhook_rejected' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
				<li>
					<?php
					foreach ( [ 'queued', 'processed', 'unmatched', 'ignored', 'dead' ] as $status ) {
						echo esc_html( $status . ': ' . ( $counts[ $status ] ?? 0 ) ) . ' &nbsp; ';
					}
					?>
				</li>
				<li><?php esc_html_e( 'Subscribe to:', 'wp2-shopify' ); ?> <code><?php echo esc_html( implode( ', ', Topics::subscribable() ) ); ?></code></li>
				<li><?php esc_html_e( 'Registered through the app:', 'wp2-shopify' ); ?>
					<?php
					if ( is_array( $hooks ) ) {
						echo $fmt_time( $hooks['at'] ) . ' · ' . esc_html( sprintf( /* translators: 1: already present 2: created */ __( '%1$d already present, %2$d created', 'wp2-shopify' ), $hooks['existing'], count( $hooks['created'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
						echo $hooks['error'] ? ' · <strong>' . esc_html( $hooks['error'] ) . '</strong>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput
						foreach ( $hooks['failed'] as $topic => $message ) {
							echo '<br><code>' . esc_html( $topic ) . '</code>: ' . esc_html( $message ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
					} else {
						esc_html_e( 'not yet.', 'wp2-shopify' );
					}
					?>
				</li>
				<?php if ( get_option( 'wp2_shopify_app_uninstalled' ) ) : ?>
					<li><strong><?php esc_html_e( 'Shopify reported the app as uninstalled on', 'wp2-shopify' ); ?> <?php echo $fmt_time( get_option( 'wp2_shopify_app_uninstalled' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></strong></li>
				<?php endif; ?>
			</ul>

			<h2><?php esc_html_e( 'Reconciliation', 'wp2-shopify' ); ?></h2>
			<p>
				<?php
				if ( is_array( $recon ) ) {
					echo esc_html( sprintf( /* translators: 1: checked 2: changed 3: missing */ __( 'Last run checked %1$d, changed %2$d, missing in Shopify %3$d.', 'wp2-shopify' ), $recon['checked'], $recon['changed'], $recon['missing'] ) ) . ' ' . $fmt_time( $recon['finished'] ?? 0 ) . ( $recon['error'] ? ' · ' . esc_html( $recon['error'] ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
				} else {
					esc_html_e( 'Not run yet.', 'wp2-shopify' );
				}
				?>
				<?php esc_html_e( 'Next run:', 'wp2-shopify' ); ?> <?php echo $fmt_time( wp_next_scheduled( Reconciler::HOOK ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</p>
			<?php self::action_button( 'reconcile', __( 'Run reconciliation now', 'wp2-shopify' ) ); ?>
			<?php self::action_button( 'webhooks', __( 'Register webhooks with Shopify', 'wp2-shopify' ) ); ?>
			<?php self::action_button( 'discovery', __( 'Refresh Shopify discovery', 'wp2-shopify' ) ); ?>

			<h2 id="dry-run"><?php esc_html_e( 'Dry-run entitlement check', 'wp2-shopify' ); ?></h2>
			<?php self::dry_run(); ?>

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
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private static function action_button( string $do, string $label ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
			<input type="hidden" name="action" value="wp2_shopify_diagnostics">
			<input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>">
			<?php wp_nonce_field( 'wp2_shopify_diagnostics' ); ?>
			<?php submit_button( $label, 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Show the synced mirror for a customer and what a rule would decide, changing nothing.
	 */
	private static function dry_run(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only display.
		$customer = sanitize_text_field( wp_unslash( $_GET['dry_customer'] ?? '' ) );
		$tags     = sanitize_text_field( wp_unslash( $_GET['dry_tags'] ?? '' ) );
		$mode     = 'all' === ( $_GET['dry_mode'] ?? '' ) ? 'all' : 'any';
		// phpcs:enable
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wp2_shopify_diagnostics">
			<input type="hidden" name="do" value="dryrun">
			<?php wp_nonce_field( 'wp2_shopify_diagnostics' ); ?>
			<input name="customer" placeholder="<?php esc_attr_e( 'Shopify customer ID', 'wp2-shopify' ); ?>" value="<?php echo esc_attr( $customer ); ?>">
			<input name="tags" placeholder="<?php esc_attr_e( 'Required tags, comma separated', 'wp2-shopify' ); ?>" value="<?php echo esc_attr( $tags ); ?>">
			<select name="mode"><option value="any"><?php esc_html_e( 'any', 'wp2-shopify' ); ?></option><option value="all" <?php selected( 'all', $mode ); ?>><?php esc_html_e( 'all', 'wp2-shopify' ); ?></option></select>
			<?php submit_button( __( 'Check', 'wp2-shopify' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
		if ( '' === $customer ) {
			return;
		}

		$id   = CustomerId::parse( $customer );
		$user = $id ? Mirror::find_user( $id ) : null;

		if ( ! $user ) {
			echo '<p>' . esc_html__( 'No WordPress user is linked to that customer.', 'wp2-shopify' ) . '</p>';
			return;
		}

		$member   = Mirror::member( $user->ID );
		$decision = Settings::evaluator()->decide( Rule::from_attributes( [ 'requiredTags' => $tags, 'matchMode' => $mode ] ), $member, time() );
		?>
		<table class="widefat" style="max-width:640px">
			<tr><th><?php esc_html_e( 'WordPress user', 'wp2-shopify' ); ?></th><td>#<?php echo (int) $user->ID; ?></td></tr>
			<tr><th><?php esc_html_e( 'Synced tags', 'wp2-shopify' ); ?></th><td><?php echo esc_html( implode( ', ', $member->tags ) ?: '—' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Status', 'wp2-shopify' ); ?></th><td><?php echo esc_html( $member->status ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Last sync', 'wp2-shopify' ); ?></th><td><?php echo $member->last_sync ? esc_html( wp_date( 'Y-m-d H:i', $member->last_sync ) ) : '—'; ?> (<?php echo esc_html( (string) get_user_meta( $user->ID, Mirror::SYNC_SOURCE, true ) ); ?>)</td></tr>
			<tr><th><?php esc_html_e( 'Decision', 'wp2-shopify' ); ?></th><td><strong><?php echo esc_html( $decision->granted ? __( 'Unlocked', 'wp2-shopify' ) : __( 'Locked', 'wp2-shopify' ) ); ?></strong> (<?php echo esc_html( $decision->reason ); ?>)</td></tr>
		</table>
		<?php
	}
}
