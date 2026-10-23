<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Blocks\Registration;
use WP2_Shopify\Config;
use WP2_Shopify\Entitlement\Rule;
use WP2_Shopify\Integrations\OfficialPlugin;
use WP2_Shopify\Requirements;
use WP2_Shopify\Settings;
use WP2_Shopify\Shopify\Admin as AdminApi;
use WP2_Shopify\Shopify\AdminToken;
use WP2_Shopify\Shopify\Discovery;
use WP2_Shopify\Support\CustomerId;
use WP2_Shopify\Sync\Mirror;
use WP2_Shopify\Sync\Reconciler;

/**
 * Shopify → Debug: wp-config constants, platform checks, the Shopify
 * connection, maintenance actions and the dry-run entitlement check.
 * Secret values are never displayed, only their presence.
 */
final class Debug {
	public const PAGE   = 'wp2-shopify-debug';
	public const ACTION = 'wp2_shopify_diagnostics';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'action' ] );
	}

	public static function action(): void {
		Menu::guard( self::ACTION );

		$do     = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$result = '';

		switch ( $do ) {
			case 'reconcile':
				$stats  = Reconciler::run();
				$result = $stats['error'] ? 'reconcile_error' : 'reconcile_ok';
				break;
			case 'token':
				AdminToken::forget();
				delete_option( 'wp2_shopify_admin_token_error' );
				delete_transient( 'wp2_shopify_admin_scopes' );
				$result = is_wp_error( AdminToken::get() ) ? 'token_error' : 'token_ok';
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
				wp_safe_redirect( Menu::url( self::PAGE, $query ) . '#dry-run' );
				exit;
		}

		wp_safe_redirect( Menu::url( self::PAGE, [ 'result' => $result ] ) );
		exit;
	}

	public static function render(): void {
		$recon     = get_option( Reconciler::STATS );
		$blocks    = get_option( Registration::STATUS_OPTION );
		$problems  = Requirements::problems();
		$token_err = get_option( 'wp2_shopify_admin_token_error' );
		$retired   = array_filter( Config::retired(), 'defined', ARRAY_FILTER_USE_KEY );
		$ok        = [ Menu::class, 'dot' ];
		$fmt_time  = [ Menu::class, 'time' ];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Shopify debug', 'wp2-shopify' ); ?></h1>
			<?php
			Menu::result_notice(
				[
					'reconcile_ok'    => [ 'success', __( 'Reconciliation finished.', 'wp2-shopify' ) ],
					'reconcile_error' => [ 'error', __( 'Reconciliation stopped with an error. See below and App events.', 'wp2-shopify' ) ],
					'discovery_ok'    => [ 'success', __( 'Shopify discovery refreshed.', 'wp2-shopify' ) ],
					'discovery_error' => [ 'error', __( 'Shopify discovery failed. Check the store domain.', 'wp2-shopify' ) ],
					'token_ok'        => [ 'success', __( 'A fresh Admin token was issued.', 'wp2-shopify' ) ],
					'token_error'     => [ 'error', __( 'Shopify refused the Admin token request. See below and App events.', 'wp2-shopify' ) ],
				]
			);
			?>

			<h2><?php esc_html_e( 'Configuration (wp-config.php)', 'wp2-shopify' ); ?></h2>
			<p class="description"><?php esc_html_e( 'These are the only constants the plugin reads. Secret values are never shown.', 'wp2-shopify' ); ?></p>
			<table class="widefat striped" style="max-width:1100px">
				<tbody>
				<?php foreach ( Config::definitions() as $name => $def ) : ?>
					<tr>
						<td><?php echo $ok( Config::has( $name ) || ! $def['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?><code><?php echo esc_html( $name ); ?></code></td>
						<td><?php echo esc_html( $def['label'] ); ?></td>
						<td><?php echo Config::has( $name ) ? esc_html__( 'Present', 'wp2-shopify' ) : ( $def['required'] ? '<strong>' . esc_html__( 'Missing (required)', 'wp2-shopify' ) . '</strong>' : esc_html__( 'Not set (optional)', 'wp2-shopify' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo esc_html( $def['help'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $retired ) : ?>
				<h3><?php esc_html_e( 'Defined but ignored', 'wp2-shopify' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Remove these from wp-config.php.', 'wp2-shopify' ); ?></p>
				<table class="widefat striped" style="max-width:1100px">
					<?php foreach ( $retired as $name => $why ) : ?>
						<tr><td><?php echo $ok( false ); // phpcs:ignore WordPress.Security.EscapeOutput ?><code><?php echo esc_html( $name ); ?></code></td><td><?php echo esc_html( $why ); ?></td></tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

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
				<li><?php echo $ok( '' !== get_option( 'permalink_structure' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Pretty permalinks (the sign-in callback needs them)', 'wp2-shopify' ); ?></li>
				<li><?php esc_html_e( 'Shopify API version:', 'wp2-shopify' ); ?> <code><?php echo esc_html( Config::api_version() ); ?></code></li>
			</ul>

			<h2><?php esc_html_e( 'Shopify connection', 'wp2-shopify' ); ?></h2>
			<ul>
				<li><?php echo $ok( Discovery::available() ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Customer Account API discovery, last fetched', 'wp2-shopify' ); ?> <?php echo $fmt_time( Discovery::last_fetched() ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
				<li><?php echo $ok( AdminApi::available() && ! $token_err ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Admin API (Dev Dashboard app, 24-hour tokens renewed automatically):', 'wp2-shopify' ); ?>
					<?php
					if ( AdminToken::configured() ) {
						$scopes = (string) get_option( 'wp2_shopify_admin_token_scopes', '' );
						echo '' !== $scopes ? esc_html__( 'granted scopes', 'wp2-shopify' ) . ' <code>' . esc_html( $scopes ) . '</code>' : esc_html__( 'no token requested yet.', 'wp2-shopify' ); // phpcs:ignore WordPress.Security.EscapeOutput
					} else {
						esc_html_e( 'not configured.', 'wp2-shopify' );
					}
					if ( is_array( $token_err ) ) {
						echo ' <strong>' . esc_html__( 'Last token request failed:', 'wp2-shopify' ) . ' ' . esc_html( (string) $token_err['error'] ) . '</strong> ' . $fmt_time( $token_err['at'] ?? 0 ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
					?>
				</li>
				<li><?php esc_html_e( 'Shopify app settings: callback URI', 'wp2-shopify' ); ?> <code><?php echo esc_html( Config::callback_url() ); ?></code>, <?php esc_html_e( 'JavaScript origin', 'wp2-shopify' ); ?> <code><?php echo esc_html( untrailingslashit( home_url() ) ); ?></code>, <?php esc_html_e( 'logout URI', 'wp2-shopify' ); ?> <code><?php echo esc_html( home_url( '/' ) ); ?></code></li>
				<li><?php esc_html_e( 'Webhooks:', 'wp2-shopify' ); ?> <a href="<?php echo esc_url( Menu::url( Webhooks::PAGE ) ); ?>"><?php esc_html_e( 'Shopify → Webhooks', 'wp2-shopify' ); ?></a></li>
			</ul>
			<?php Menu::action_button( self::ACTION, 'discovery', __( 'Refresh Shopify discovery', 'wp2-shopify' ) ); ?>
			<?php Menu::action_button( self::ACTION, 'token', __( 'Request a fresh Admin token', 'wp2-shopify' ) ); ?>

			<?php self::official_plugin(); ?>

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
			<?php Menu::action_button( self::ACTION, 'reconcile', __( 'Run reconciliation now', 'wp2-shopify' ) ); ?>

			<h2 id="dry-run"><?php esc_html_e( 'Dry-run entitlement check', 'wp2-shopify' ); ?></h2>
			<?php self::dry_run(); ?>
		</div>
		<?php
	}

	/**
	 * Show the synced mirror for a customer and what a rule would decide, changing nothing.
	 */
	/**
	 * Shopify's own plugin: whether it is here, which store it uses and which routes it owns.
	 */
	private static function official_plugin(): void {
		$info = OfficialPlugin::info();
		$ok   = [ Menu::class, 'dot' ];
		?>
		<h2 id="official-plugin"><?php esc_html_e( 'Shopify\'s WordPress plugin', 'wp2-shopify' ); ?></h2>
		<p class="description"><?php esc_html_e( 'WP2 runs with or without it. When it is active, it owns the anonymous storefront and WP2 owns sign-in and access. WP2 never reads its access token.', 'wp2-shopify' ); ?></p>
		<ul>
			<li><?php echo $ok( in_array( $info['state'], [ OfficialPlugin::ABSENT, OfficialPlugin::SAME_STORE ], true ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( OfficialPlugin::summary() ); ?></li>
			<?php if ( OfficialPlugin::ABSENT !== $info['state'] ) : ?>
				<li><?php esc_html_e( 'Owns /cart:', 'wp2-shopify' ); ?> <?php echo esc_html( $info['owns_cart'] ? __( 'yes. Its rewrite rule wins over any page at /cart.', 'wp2-shopify' ) : __( 'no (rewrite rules not flushed yet, or removed).', 'wp2-shopify' ) ); ?></li>
				<li><?php esc_html_e( 'Serves /products/{handle}:', 'wp2-shopify' ); ?> <?php echo esc_html( $info['product_routes'] ? __( 'yes. Product SEO is the official plugin\'s.', 'wp2-shopify' ) : __( 'no.', 'wp2-shopify' ) ); ?></li>
			<?php endif; ?>
		</ul>
		<?php
	}

	private static function dry_run(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only display.
		$customer = sanitize_text_field( wp_unslash( $_GET['dry_customer'] ?? '' ) );
		$tags     = sanitize_text_field( wp_unslash( $_GET['dry_tags'] ?? '' ) );
		$mode     = 'all' === ( $_GET['dry_mode'] ?? '' ) ? 'all' : 'any';
		// phpcs:enable
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="do" value="dryrun">
			<?php wp_nonce_field( self::ACTION ); ?>
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
