<?php

namespace RedeemID\Admin;

use RedeemID\Blocks\Registration;
use RedeemID\Commerce\CartBridge;
use RedeemID\Config;
use RedeemID\Integrations\OfficialPlugin;
use RedeemID\Requirements;
use RedeemID\Shopify\Admin as AdminApi;
use RedeemID\Shopify\AdminToken;
use RedeemID\Shopify\Discovery;
use RedeemID\Shopify\Storefront;
use RedeemID\Support\ConfigValues;

/**
 * RedeemID → Debug. The Connection tab: where each config value comes from,
 * the Shopify connection and its maintenance actions. The Platform tab:
 * platform checks and Shopify's own WordPress plugin. Secret values are
 * never displayed, only their presence. (A dry run of who gets what is
 * Access → Test.)
 */
final class Debug {
	public const PAGE   = 'redeemid-debug';
	public const ACTION = 'redeemid_diagnostics';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'action' ] );
	}

	public static function action(): void {
		Menu::guard( self::ACTION );
		// phpcs:disable WordPress.Security.NonceVerification -- checked by Menu::guard().

		$do     = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$result = '';

		switch ( $do ) {
			case 'token':
				AdminToken::forget();
				delete_option( 'redeemid_admin_token_error' );
				delete_transient( 'redeemid_admin_scopes' );
				$result = is_wp_error( AdminToken::get() ) ? 'token_error' : 'token_ok';
				break;
			case 'discovery':
				Discovery::flush();
				$result = Discovery::available() ? 'discovery_ok' : 'discovery_error';
				break;
		}

		wp_safe_redirect( Menu::url( self::PAGE, [ 'result' => $result ] ) );
		exit;
		// phpcs:enable WordPress.Security.NonceVerification
	}

	public static function render(): void {
		$tabs = self::tabs();
		$tab  = Menu::tab( $tabs );
		Menu::page_start( __( 'Debug', 'redeemid' ), __( 'Shopify debug', 'redeemid' ) );
		Menu::tabs( self::PAGE, $tabs, $tab, __( 'Debug sections', 'redeemid' ) );
		echo '<p class="redeemid-intro">' . esc_html( 'platform' === $tab ? __( 'What this site runs on, and how RedeemID shares the site with Shopify\'s own WordPress plugin.', 'redeemid' ) : __( 'Where each config value comes from and whether each Shopify API answers, with maintenance tools. Secret values are never shown, only whether they are present.', 'redeemid' ) ) . '</p>';
		Menu::result_notice(
			[
				'discovery_ok'    => [ 'success', __( 'Shopify discovery refreshed.', 'redeemid' ) ],
				'discovery_error' => [ 'error', __( 'Shopify discovery failed. Check the store domain.', 'redeemid' ) ],
				'token_ok'        => [ 'success', __( 'A fresh Admin token was issued.', 'redeemid' ) ],
				'token_error'     => [ 'error', __( 'Shopify refused the Admin token request. See below and App events.', 'redeemid' ) ],
			]
		);

		if ( 'platform' === $tab ) {
			self::platform();
			self::official_plugin();
		} else {
			self::connection();
		}

		Menu::page_end();
	}

	/**
	 * The page's tabs: slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return [
			'connection' => __( 'Connection', 'redeemid' ),
			'platform'   => __( 'Platform', 'redeemid' ),
		];
	}

	private static function connection(): void {
		$token_err = get_option( 'redeemid_admin_token_error' );
		$ok        = [ Menu::class, 'dot' ];
		$fmt_time  = [ Menu::class, 'time' ];
		?>
			<?php
			Menu::panel_start(
				__( 'Configuration', 'redeemid' ),
				__( 'These are the only values the plugin reads. Each comes from a wp-config.php constant of that name, which always wins, or from RedeemID → Settings.', 'redeemid' ),
				sprintf( '<a class="button" href="%s">%s</a>', esc_url( Menu::url( SettingsPage::PAGE ) ), esc_html__( 'Edit settings', 'redeemid' ) )
			);
			?>
				<div class="redeemid-panel__body redeemid-panel__body--flush redeemid-table-scroll">
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Constant', 'redeemid' ); ?></th>
								<th><?php esc_html_e( 'Value', 'redeemid' ); ?></th>
								<th><?php esc_html_e( 'Source', 'redeemid' ); ?></th>
								<th><?php esc_html_e( 'What it is for', 'redeemid' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( Config::definitions() as $name => $def ) : ?>
							<?php $source = Config::source( $name ); ?>
							<tr>
								<td><?php echo $ok( ConfigValues::UNREADABLE !== $source && ( Config::has( $name ) || ! $def['required'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><code class="redeemid-code-small"><?php echo esc_html( $name ); ?></code></td>
								<td><?php echo esc_html( $def['label'] ); ?></td>
								<td><?php echo self::source_label( $source, $def['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td class="redeemid-muted"><?php echo esc_html( $def['help'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php Menu::panel_end(); ?>

			<?php Menu::panel_start( __( 'Shopify connection', 'redeemid' ) ); ?>
				<ul class="redeemid-checks">
					<li>
						<span class="redeemid-checks__label"><?php echo $ok( Discovery::available() ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Customer Account API', 'redeemid' ); ?></span>
						<span class="redeemid-checks__detail"><?php esc_html_e( 'Discovery, last fetched', 'redeemid' ); ?> <?php echo $fmt_time( Discovery::last_fetched() ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					</li>
					<li>
						<span class="redeemid-checks__label"><?php echo $ok( AdminApi::available() && ! $token_err ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Admin API', 'redeemid' ); ?></span>
						<span class="redeemid-checks__detail">
							<?php esc_html_e( 'Dev Dashboard app, 24-hour tokens renewed automatically:', 'redeemid' ); ?>
							<?php
							if ( AdminToken::configured() ) {
								$scopes = (string) get_option( 'redeemid_admin_token_scopes', '' );
								echo '' !== $scopes ? esc_html__( 'granted scopes', 'redeemid' ) . ' <code>' . esc_html( $scopes ) . '</code>' : esc_html__( 'no token requested yet.', 'redeemid' ); // phpcs:ignore WordPress.Security.EscapeOutput
							} else {
								esc_html_e( 'not configured.', 'redeemid' );
							}
							if ( is_array( $token_err ) ) {
								echo ' <strong>' . esc_html__( 'Last token request failed:', 'redeemid' ) . ' ' . esc_html( (string) $token_err['error'] ) . '</strong> ' . $fmt_time( $token_err['at'] ?? 0 ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
							?>
						</span>
					</li>
					<li>
						<span class="redeemid-checks__label"><?php echo $ok( Storefront::available() ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Storefront API', 'redeemid' ); ?></span>
						<span class="redeemid-checks__detail"><?php esc_html_e( 'Plan cards and buy-now:', 'redeemid' ); ?> <?php echo esc_html( Storefront::available() ? ( Config::has( 'REDEEMID_STOREFRONT_PRIVATE_TOKEN' ) ? __( 'private access token present; buy-now and cart calls send the buyer\'s IP.', 'redeemid' ) : __( 'public access token present. Add the Storefront private access token so Shopify doesn\'t throttle checkout as if every visitor were one IP.', 'redeemid' ) ) : __( 'no Storefront private or public access token is set, so plan cards show as unavailable.', 'redeemid' ) ); ?></span>
					</li>
					<li>
						<span class="redeemid-checks__label"><?php esc_html_e( 'Shopify app settings', 'redeemid' ); ?></span>
						<span class="redeemid-checks__detail">
							<?php esc_html_e( 'Callback URI', 'redeemid' ); ?> <code><?php echo esc_html( Config::callback_url() ); ?></code><br>
							<?php esc_html_e( 'JavaScript origin', 'redeemid' ); ?> <code><?php echo esc_html( untrailingslashit( home_url() ) ); ?></code><br>
							<?php esc_html_e( 'Logout URI', 'redeemid' ); ?> <code><?php echo esc_html( home_url( '/' ) ); ?></code>
						</span>
					</li>
					<li>
						<span class="redeemid-checks__label"><?php esc_html_e( 'Webhooks', 'redeemid' ); ?></span>
						<span class="redeemid-checks__detail"><a href="<?php echo esc_url( Menu::url( Webhooks::PAGE, [ 'tab' => 'health' ] ) ); ?>"><?php esc_html_e( 'RedeemID → Webhooks → Health and setup', 'redeemid' ); ?></a></span>
					</li>
				</ul>
				<div class="redeemid-panel__footer">
					<?php Menu::action_button( self::ACTION, 'discovery', __( 'Refresh Shopify discovery', 'redeemid' ) ); ?>
					<?php Menu::action_button( self::ACTION, 'token', __( 'Request a fresh Admin token', 'redeemid' ) ); ?>
				</div>
			<?php Menu::panel_end(); ?>
		<?php
	}

	private static function platform(): void {
		$blocks   = get_option( Registration::STATUS_OPTION );
		$problems = Requirements::problems();
		$ok       = [ Menu::class, 'dot' ];
		?>
				<?php Menu::panel_start( __( 'Platform', 'redeemid' ) ); ?>
					<ul class="redeemid-checks">
						<li>
							<span class="redeemid-checks__label"><?php echo $ok( ! $problems ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'PHP and WordPress', 'redeemid' ); ?></span>
							<span class="redeemid-checks__detail">PHP <?php echo esc_html( PHP_VERSION ); ?> (≥ <?php echo esc_html( Requirements::MIN_PHP ); ?>), WordPress <?php echo esc_html( get_bloginfo( 'version' ) ); ?> (≥ <?php echo esc_html( Requirements::MIN_WP ); ?>)</span>
						</li>
						<li>
							<span class="redeemid-checks__label"><?php echo $ok( defined( 'BLOCKSTUDIO_VERSION' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>Blockstudio</span>
							<span class="redeemid-checks__detail"><?php echo esc_html( defined( 'BLOCKSTUDIO_VERSION' ) ? BLOCKSTUDIO_VERSION : __( 'not loaded', 'redeemid' ) ); ?></span>
						</li>
						<li>
							<span class="redeemid-checks__label"><?php echo $ok( is_array( $blocks ) && 'ok' === $blocks['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Plugin blocks', 'redeemid' ); ?></span>
							<span class="redeemid-checks__detail">
								<?php echo esc_html( is_array( $blocks ) ? $blocks['status'] . ( $blocks['missing'] ?? [] ? ' (' . implode( ', ', $blocks['missing'] ) . ')' : '' ) : __( 'not checked yet', 'redeemid' ) ); ?>.
								<?php if ( is_array( $blocks ) ) : ?>
									<?php echo esc_html( sprintf( /* translators: 1: yes/no 2: yes/no */ __( 'Theme has Blockstudio blocks: %1$s. Theme block path untouched: %2$s.', 'redeemid' ), ! empty( $blocks['theme_blockstudio'] ) ? 'yes' : 'no', ! empty( $blocks['theme_path_intact'] ) ? 'yes' : 'no' ) ); ?>
								<?php endif; ?>
							</span>
						</li>
						<li>
							<span class="redeemid-checks__label"><?php echo $ok( '' !== get_option( 'permalink_structure' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Pretty permalinks', 'redeemid' ); ?></span>
							<span class="redeemid-checks__detail"><?php esc_html_e( 'The sign-in callback needs them.', 'redeemid' ); ?></span>
						</li>
						<li>
							<span class="redeemid-checks__label"><?php esc_html_e( 'Shopify API version', 'redeemid' ); ?></span>
							<span class="redeemid-checks__detail"><code><?php echo esc_html( Config::api_version() ); ?></code></span>
						</li>
					</ul>
				<?php Menu::panel_end(); ?>
		<?php
	}

	/**
	 * Present, and from where. Escaped HTML.
	 */
	private static function source_label( string $source, bool $required ): string {
		return match ( $source ) {
			ConfigValues::CONSTANT   => Menu::status( 'ok', __( 'wp-config.php', 'redeemid' ) ),
			ConfigValues::SETTINGS   => Menu::status( 'ok', __( 'Settings (database)', 'redeemid' ) ),
			ConfigValues::UNREADABLE => Menu::status( 'error', __( 'Unreadable', 'redeemid' ) ) . '<br><span class="redeemid-error-text">' . esc_html__( 'Saved in Settings but unreadable: the encryption key changed. Enter it again.', 'redeemid' ) . '</span>',
			default                  => $required ? Menu::status( 'error', __( 'Missing (required)', 'redeemid' ) ) : Menu::status( 'neutral', __( 'Not set (optional)', 'redeemid' ) ),
		};
	}

	/**
	 * Shopify's own plugin: whether it is here, which store it uses and which routes it owns.
	 */
	private static function official_plugin(): void {
		$info     = OfficialPlugin::info();
		$bridge   = CartBridge::last();
		$ok       = [ Menu::class, 'dot' ];
		$fmt_time = [ Menu::class, 'time' ];
		?>
		<?php Menu::panel_start( __( 'Shopify\'s WordPress plugin', 'redeemid' ), __( 'RedeemID runs with or without it. When it is active, it owns the anonymous storefront and RedeemID owns sign-in and access. RedeemID never reads its access token.', 'redeemid' ), '', 'official-plugin' ); ?>
		<ul class="redeemid-checks">
			<li><span class="redeemid-checks__label"><?php echo $ok( in_array( $info['state'], [ OfficialPlugin::ABSENT, OfficialPlugin::SAME_STORE ], true ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Status', 'redeemid' ); ?></span><span class="redeemid-checks__detail"><?php echo esc_html( OfficialPlugin::summary() ); ?></span></li>
			<?php if ( OfficialPlugin::ABSENT !== $info['state'] ) : ?>
				<li><span class="redeemid-checks__label"><?php esc_html_e( 'Owns /cart', 'redeemid' ); ?></span><span class="redeemid-checks__detail"><?php echo esc_html( $info['owns_cart'] ? __( 'yes. Its rewrite rule wins over any page at /cart.', 'redeemid' ) : __( 'no (rewrite rules not flushed yet, or removed).', 'redeemid' ) ); ?></span></li>
				<li><span class="redeemid-checks__label"><?php esc_html_e( 'Serves /products/{handle}', 'redeemid' ); ?></span><span class="redeemid-checks__detail"><?php echo esc_html( $info['product_routes'] ? __( 'yes. Product SEO is the official plugin\'s.', 'redeemid' ) : __( 'no.', 'redeemid' ) ); ?></span></li>
				<li><span class="redeemid-checks__label"><?php echo $ok( CartBridge::enabled() ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Cart sign-in bridge', 'redeemid' ); ?></span><span class="redeemid-checks__detail">
					<?php
					echo esc_html( CartBridge::enabled() ? __( 'active. Signed-in customers check out from its cart as themselves.', 'redeemid' ) : __( 'off. It needs the same store and a Storefront token.', 'redeemid' ) );
					echo ' ' . esc_html__( 'Last attached:', 'redeemid' ) . ' ' . $fmt_time( $bridge['ok_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput
					if ( $bridge['error'] ) {
						echo ' · <strong>' . esc_html__( 'Last failure:', 'redeemid' ) . ' ' . esc_html( $bridge['error'] ) . '</strong> ' . $fmt_time( $bridge['error_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
					?>
				</span></li>
			<?php endif; ?>
		</ul>
		<?php
		Menu::panel_end();
	}
}
