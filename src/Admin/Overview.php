<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Config;
use WP2_Shopify\Integrations\OfficialPlugin;
use WP2_Shopify\Shopify\Admin as AdminApi;
use WP2_Shopify\Shopify\Discovery;
use WP2_Shopify\Support\EventLog;
use WP2_Shopify\Sync\Mirror;
use WP2_Shopify\Webhooks\Monitor;
use WP2_Shopify\Webhooks\WpdbInboxStore;

/**
 * Shopify → Overview: is everything connected. Settings live in SettingsPage.
 */
final class Overview {
	public static function render(): void {
		global $wpdb;

		$inbox    = new WpdbInboxStore();
		$last     = $inbox->last_received();
		$health   = Monitor::messages();
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
				$last && ! $health,
				__( 'Webhooks', 'wp2-shopify' ),
				$health ? $health[0] : ( $last ? sprintf( /* translators: 1: topic 2: time */ __( 'Last: %1$s, %2$s ago', 'wp2-shopify' ), $last['topic'], human_time_diff( (int) $last['received_at'] ) ) : __( 'Nothing received yet.', 'wp2-shopify' ) ),
				Menu::url( Webhooks::PAGE ) . '#health',
				'controls-repeat',
				__( 'View webhooks', 'wp2-shopify' ),
			],
			[
				true,
				__( 'Linked customers', 'wp2-shopify' ),
				sprintf( /* translators: %d: count */ __( '%d linked.', 'wp2-shopify' ), $linked ),
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
		Menu::result_notice(
			[
				'test_ok'          => [ 'success', __( 'Connection test passed: every configured Shopify API answered.', 'wp2-shopify' ) ],
				'test_failed'      => [ 'error', __( 'Connection test failed for at least one API. See the setup checklist and App events.', 'wp2-shopify' ) ],
				'callback_created' => [ 'success', __( 'The callback page is published.', 'wp2-shopify' ) ],
				'callback_failed'  => [ 'error', __( 'The callback page could not be created.', 'wp2-shopify' ) ],
				'tags_checked'     => [ 'success', __( 'Level tags checked against Shopify again.', 'wp2-shopify' ) ],
			]
		);
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

			<?php self::setup(); ?>

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

	/**
	 * The setup checklist. Open while anything is left to do, folded once all is done.
	 */
	private static function setup(): void {
		$steps = Setup::steps( Setup::facts() );
		$left  = count( array_filter( $steps, static fn( $step ) => ! $step['done'] ) );
		$names = [ 'sign_in' => __( 'sign-in', 'wp2-shopify' ), 'admin' => __( 'Admin API', 'wp2-shopify' ), 'storefront' => __( 'Storefront', 'wp2-shopify' ) ];
		$flow  = 'https://github.com/webmultipliers/shopify-for-wp/blob/development/docs/shopify-flow.md';
		$text  = [
			'config'     => [ __( 'Connect your store', 'wp2-shopify' ), __( 'Store domain, Customer Account API client ID and the Admin app credentials.', 'wp2-shopify' ), sprintf( '<a class="button" href="%s">%s</a>', esc_url( Menu::url( SettingsPage::PAGE ) ), esc_html__( 'Settings', 'wp2-shopify' ) ) ],
			'test'       => [ __( 'Test the connection', 'wp2-shopify' ), __( 'Asks each configured Shopify API once.', 'wp2-shopify' ), Menu::action_button_html( Setup::ACTION, 'test', __( 'Test connection', 'wp2-shopify' ) ) ],
			'permalinks' => [ __( 'Turn on pretty permalinks', 'wp2-shopify' ), __( 'Sign-in returns to a pretty URL.', 'wp2-shopify' ), sprintf( '<a class="button" href="%s">%s</a>', esc_url( admin_url( 'options-permalink.php' ) ), esc_html__( 'Permalinks', 'wp2-shopify' ) ) ],
			/* translators: %s: callback path */
			'callback'   => [ __( 'Publish the callback page', 'wp2-shopify' ), sprintf( __( 'Sign-in results show on %s, inside your theme.', 'wp2-shopify' ), '/' . Config::callback_slug() . '/' ), Menu::action_button_html( Setup::ACTION, 'callback', __( 'Create the page', 'wp2-shopify' ) ) ],
			'webhooks'   => [ __( 'Register webhooks', 'wp2-shopify' ), __( 'Shopify tells WordPress when a customer\'s tags change.', 'wp2-shopify' ), Menu::action_button_html( Webhooks::ACTION, 'register', __( 'Register', 'wp2-shopify' ) ) ],
			'levels'     => [ __( 'Define membership levels', 'wp2-shopify' ), __( 'Each level names the Shopify customer tags that grant it. They are checked against the tags your customers have.', 'wp2-shopify' ), sprintf( '<a class="button" href="%s">%s</a>', esc_url( Menu::url( Access::PAGE ) ), esc_html__( 'Levels', 'wp2-shopify' ) ) . ' ' . Menu::action_button_html( Setup::ACTION, 'tags', __( 'Check tags again', 'wp2-shopify' ) ) ],
			'flow'       => [ __( 'Tag members in Shopify', 'wp2-shopify' ), __( 'A Shopify Flow workflow or a subscription app adds the level tag when someone buys and removes it when the membership ends. Done once a linked customer holds a level.', 'wp2-shopify' ), sprintf( '<a class="button" href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $flow ), esc_html__( 'Flow recipes', 'wp2-shopify' ) ) ],
		];
		?>
		<details class="wp2s-panel wp2s-setup" id="setup" <?php echo $left ? 'open' : ''; ?>>
			<summary class="wp2s-panel__header">
				<h2>
					<?php
					echo $left
						/* translators: %d: steps left */
						? esc_html( sprintf( _n( 'Setup: %d step left', 'Setup: %d steps left', $left, 'wp2-shopify' ), $left ) )
						: esc_html__( 'Setup: done', 'wp2-shopify' );
					?>
				</h2>
			</summary>
			<ol class="wp2s-checks wp2s-setup__steps">
				<?php foreach ( $steps as $step ) : ?>
					<?php [ $title, $help, $button ] = $text[ $step['key'] ]; ?>
					<li class="<?php echo $step['done'] ? 'is-done' : ''; ?>">
						<span class="wp2s-checks__label"><?php echo Menu::dot( $step['done'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( $title ); ?></span>
						<span class="wp2s-checks__detail">
							<?php echo esc_html( $help ); ?>
							<?php if ( 'test' === $step['key'] && '' !== $step['detail'] ) : ?>
								<br><strong class="wp2s-error-text">
									<?php
									/* translators: %s: API names */
									echo esc_html( sprintf( __( 'Failed: %s', 'wp2-shopify' ), implode( ', ', array_map( static fn( $key ) => $names[ $key ] ?? $key, explode( ', ', $step['detail'] ) ) ) ) );
									?>
								</strong>
							<?php elseif ( 'levels' === $step['key'] && '' !== $step['detail'] ) : ?>
								<br><strong class="wp2s-error-text">
									<?php
									/* translators: %s: tags */
									echo esc_html( sprintf( __( 'No customer in Shopify has these tags yet: %s. Check the spelling.', 'wp2-shopify' ), $step['detail'] ) );
									?>
								</strong>
							<?php endif; ?>
						</span>
						<span class="wp2s-setup__action"><?php echo $button; // phpcs:ignore WordPress.Security.EscapeOutput -- built above from escaped parts. ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
		</details>
		<?php
	}
}
