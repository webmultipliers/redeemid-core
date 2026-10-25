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
 * RedeemID → Overview: is everything connected. Settings live in SettingsPage.
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
				Config::has( 'REDEEMID_STORE_DOMAIN' ) && ! $missing,
				__( 'Configuration', 'redeemid' ),
				$missing ? sprintf( /* translators: %s: setting names */ __( 'Missing: %s. Add them in Settings or wp-config.php.', 'redeemid' ), implode( ', ', array_map( static fn( $name ) => Config::definitions()[ $name ]['label'], $missing ) ) ) : sprintf( /* translators: %s: store domain */ __( 'Store %s', 'redeemid' ), Config::store_domain() ),
				Menu::url( SettingsPage::PAGE ),
				'admin-settings',
				__( 'Open settings', 'redeemid' ),
			],
			[
				Discovery::available(),
				__( 'Customer sign-in', 'redeemid' ),
				Discovery::available() ? __( 'Customer Account API reachable.', 'redeemid' ) : __( 'Shopify discovery failed, so sign-in is off.', 'redeemid' ),
				Menu::url( Debug::PAGE ),
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
				Menu::url( Webhooks::PAGE ) . '#health',
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
				Menu::url( Debug::PAGE ) . '#official-plugin',
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
		Menu::result_notice(
			[
				'test_ok'          => [ 'success', __( 'Connection test passed: every configured Shopify API answered.', 'redeemid' ) ],
				'test_failed'      => [ 'error', __( 'Connection test failed for at least one API. See the setup checklist and App events.', 'redeemid' ) ],
				'callback_created' => [ 'success', __( 'The callback page is published.', 'redeemid' ) ],
				'callback_failed'  => [ 'error', __( 'The callback page could not be created.', 'redeemid' ) ],
				'tags_checked'     => [ 'success', __( 'Level tags checked against Shopify again.', 'redeemid' ) ],
			]
		);
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
					<a class="button <?php echo $missing ? 'button-primary' : ''; ?>" href="<?php echo esc_url( Menu::url( SettingsPage::PAGE ) ); ?>"><?php esc_html_e( 'Settings', 'redeemid' ); ?></a>
				</div>
			</section>

			<?php self::setup(); ?>

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

	/**
	 * The setup checklist. Open while anything is left to do, folded once all is done.
	 */
	private static function setup(): void {
		$steps = Setup::steps( Setup::facts() );
		$left  = count( array_filter( $steps, static fn( $step ) => ! $step['done'] ) );
		$names = [ 'sign_in' => __( 'sign-in', 'redeemid' ), 'admin' => __( 'Admin API', 'redeemid' ), 'storefront' => __( 'Storefront', 'redeemid' ) ];
		$flow  = 'https://github.com/webmultipliers/redeemid-core/blob/main/docs/shopify-flow.md';
		$text  = [
			'config'     => [ __( 'Connect your store', 'redeemid' ), __( 'Store domain, Customer Account API client ID and the Admin app credentials.', 'redeemid' ), sprintf( '<a class="button" href="%s">%s</a>', esc_url( Menu::url( SettingsPage::PAGE ) ), esc_html__( 'Settings', 'redeemid' ) ) ],
			'test'       => [ __( 'Test the connection', 'redeemid' ), __( 'Asks each configured Shopify API once.', 'redeemid' ), Menu::action_button_html( Setup::ACTION, 'test', __( 'Test connection', 'redeemid' ) ) ],
			'permalinks' => [ __( 'Turn on pretty permalinks', 'redeemid' ), __( 'Sign-in returns to a pretty URL.', 'redeemid' ), sprintf( '<a class="button" href="%s">%s</a>', esc_url( admin_url( 'options-permalink.php' ) ), esc_html__( 'Permalinks', 'redeemid' ) ) ],
			/* translators: %s: callback path */
			'callback'   => [ __( 'Publish the callback page', 'redeemid' ), sprintf( __( 'Sign-in results show on %s, inside your theme.', 'redeemid' ), '/' . Config::callback_slug() . '/' ), Menu::action_button_html( Setup::ACTION, 'callback', __( 'Create the page', 'redeemid' ) ) ],
			'webhooks'   => [ __( 'Register webhooks', 'redeemid' ), __( 'Shopify tells WordPress when a customer\'s tags change.', 'redeemid' ), Menu::action_button_html( Webhooks::ACTION, 'register', __( 'Register', 'redeemid' ) ) ],
			'levels'     => [ __( 'Define membership levels', 'redeemid' ), __( 'Each level names the Shopify customer tags that grant it. They are checked against the tags your customers have.', 'redeemid' ), sprintf( '<a class="button" href="%s">%s</a>', esc_url( Menu::url( Access::PAGE ) ), esc_html__( 'Levels', 'redeemid' ) ) . ' ' . Menu::action_button_html( Setup::ACTION, 'tags', __( 'Check tags again', 'redeemid' ) ) ],
			'flow'       => [ __( 'Tag members in Shopify', 'redeemid' ), __( 'A Shopify Flow workflow or a subscription app adds the level tag when someone buys and removes it when the membership ends. Done once a linked customer holds a level.', 'redeemid' ), sprintf( '<a class="button" href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $flow ), esc_html__( 'Flow recipes', 'redeemid' ) ) ],
		];
		?>
		<details class="redeemid-panel redeemid-setup" id="setup" <?php echo $left ? 'open' : ''; ?>>
			<summary class="redeemid-panel__header">
				<h2>
					<?php
					echo $left
						/* translators: %d: steps left */
						? esc_html( sprintf( _n( 'Setup: %d step left', 'Setup: %d steps left', $left, 'redeemid' ), $left ) )
						: esc_html__( 'Setup: done', 'redeemid' );
					?>
				</h2>
			</summary>
			<ol class="redeemid-checks redeemid-setup__steps">
				<?php foreach ( $steps as $step ) : ?>
					<?php [ $title, $help, $button ] = $text[ $step['key'] ]; ?>
					<li class="<?php echo $step['done'] ? 'is-done' : ''; ?>">
						<span class="redeemid-checks__label"><?php echo Menu::dot( $step['done'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( $title ); ?></span>
						<span class="redeemid-checks__detail">
							<?php echo esc_html( $help ); ?>
							<?php if ( 'test' === $step['key'] && '' !== $step['detail'] ) : ?>
								<br><strong class="redeemid-error-text">
									<?php
									/* translators: %s: API names */
									echo esc_html( sprintf( __( 'Failed: %s', 'redeemid' ), implode( ', ', array_map( static fn( $key ) => $names[ $key ] ?? $key, explode( ', ', $step['detail'] ) ) ) ) );
									?>
								</strong>
							<?php elseif ( 'levels' === $step['key'] && '' !== $step['detail'] ) : ?>
								<br><strong class="redeemid-error-text">
									<?php
									/* translators: %s: tags */
									echo esc_html( sprintf( __( 'No customer in Shopify has these tags yet: %s. Check the spelling.', 'redeemid' ), $step['detail'] ) );
									?>
								</strong>
							<?php endif; ?>
						</span>
						<span class="redeemid-setup__action"><?php echo $button; // phpcs:ignore WordPress.Security.EscapeOutput -- built above from escaped parts. ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
		</details>
		<?php
	}
}
