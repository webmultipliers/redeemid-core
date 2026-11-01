<?php

namespace RedeemID\Admin;

use RedeemID\Config;
use RedeemID\Preferences\Preferences;
use RedeemID\Settings;
use RedeemID\Shopify\Admin;
use RedeemID\Shopify\AdminToken;
use RedeemID\Shopify\Discovery;
use RedeemID\Support\ConfigValues;
use RedeemID\Support\Logger;

/**
 * RedeemID → Settings: the Shopify connection and the plugin's behaviour.
 *
 * Every Config::definitions() value can be saved here, in the options table,
 * so a site can be set up without editing wp-config.php. A constant of the
 * same name always wins, and its field shows as locked. Secrets are encrypted
 * before they are stored, are write-only (the field is always empty, a blank
 * submission keeps what is stored) and are never printed back.
 */
final class SettingsPage {
	public const PAGE   = 'redeemid-settings';
	public const ACTION = 'redeemid_save_config';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'save' ] );
	}

	public static function save(): void {
		Menu::guard( self::ACTION );
		wp_safe_redirect( Menu::url( self::PAGE, self::result_args( self::store_posted() ) ) );
		exit;
	}

	/**
	 * Save the posted config[] and clear[] fields. The caller checks the nonce.
	 * Setup (the wizard) saves its steps' fields through here too.
	 *
	 * @return array{stored: array, changed: string[], errors: array<string, string>}
	 */
	public static function store_posted(): array {
		// phpcs:disable WordPress.Security.NonceVerification -- checked by the caller.
		$input  = wp_unslash( $_POST['config'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- ConfigValues::apply validates each field.
		$clear  = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['clear'] ?? [] ) );
		// phpcs:enable WordPress.Security.NonceVerification
		$result = Config::values()->apply( is_array( $input ) ? $input : [], $clear );

		update_option( Config::OPTION, $result['stored'], true );
		Config::reset();
		self::after_change( $result['changed'] );

		if ( $result['changed'] ) {
			// Names only: no value, secret or not, is ever logged.
			Logger::log( 'admin.config_saved', [ 'changed' => implode( ',', $result['changed'] ) ] );
		}

		return $result;
	}

	/**
	 * The query args a page shows a save's outcome from: result, n and invalid.
	 *
	 * @param array{changed: string[], errors: array<string, string>} $result From store_posted().
	 * @return array<string, string|int>
	 */
	public static function result_args( array $result ): array {
		$args = [ 'result' => $result['errors'] ? 'config_invalid' : 'config_saved', 'n' => count( $result['changed'] ) ];

		if ( $result['errors'] ) {
			$args['invalid'] = implode( ',', array_map( static fn( $name, $code ) => $name . ':' . $code, array_keys( $result['errors'] ), $result['errors'] ) );
		}

		return $args;
	}

	/**
	 * Why the last save refused each field, from the invalid query arg.
	 *
	 * @return array<string, string> Config name => ConfigValues code.
	 */
	public static function invalid_fields(): array {
		$invalid = [];

		foreach ( explode( ',', sanitize_text_field( wp_unslash( $_GET['invalid'] ?? '' ) ) ) as $pair ) { // phpcs:ignore WordPress.Security.NonceVerification
			[ $name, $code ] = array_pad( explode( ':', $pair, 2 ), 2, '' );
			$invalid[ $name ] = sanitize_key( $code );
		}

		return $invalid;
	}

	/**
	 * Drop what was cached for the old values.
	 *
	 * @param string[] $changed
	 */
	private static function after_change( array $changed ): void {
		if ( in_array( 'REDEEMID_STORE_DOMAIN', $changed, true ) ) {
			Discovery::forget_store();
		}

		if ( array_intersect( [ 'REDEEMID_STORE_DOMAIN', 'REDEEMID_ADMIN_CLIENT_ID', 'REDEEMID_ADMIN_CLIENT_SECRET' ], $changed ) ) {
			AdminToken::forget();
			delete_option( 'redeemid_admin_token_error' );
			delete_option( 'redeemid_admin_token_scopes' );
			delete_transient( 'redeemid_admin_scopes' );
		}
	}

	public static function render(): void {
		$invalid = self::invalid_fields();
		Menu::page_start( __( 'Settings', 'redeemid' ), __( 'Shopify settings', 'redeemid' ) );
		Menu::result_notice(
			[
				/* translators: %d: number of values changed */
				'config_saved'   => [ 'success', __( 'Connection saved. %d values changed.', 'redeemid' ) ],
				'config_invalid' => [ 'error', __( 'Some values were not saved. They are marked below.', 'redeemid' ) ],
			]
		);

		$sections = self::sections();
		?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php Menu::panel_start( __( 'Shopify connection', 'redeemid' ), __( 'Enter the store\'s credentials here, or define them as constants in wp-config.php. A constant always wins and its field is locked here; that is the more secure choice, because the value then never touches the database. Secrets saved here are encrypted with a key from the WordPress salts (or REDEEMID_ENCRYPTION_KEY) and are never shown again: leave a secret blank to keep it.', 'redeemid' ) ); ?>
					<div class="redeemid-panel__body redeemid-panel__body--flush">
						<table class="form-table" role="presentation">
							<?php foreach ( $sections as [ $heading, $names ] ) : ?>
								<tr class="redeemid-section-row">
									<th colspan="2" scope="colgroup"><?php echo esc_html( $heading ); ?></th>
								</tr>
								<?php foreach ( $names as $name ) : ?>
									<?php self::field( $name, Config::definitions()[ $name ], $invalid[ $name ] ?? '' ); ?>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</table>
					</div>
					<div class="redeemid-panel__footer">
						<?php submit_button( __( 'Save connection', 'redeemid' ), 'primary', 'submit', false ); ?>
					</div>
				<?php Menu::panel_end(); ?>
			</form>

			<?php self::behaviour(); ?>
		<?php
		Menu::page_end();
	}

	/**
	 * The config values in groups, in Config::definitions() order. Display only.
	 *
	 * @return array<int, array{0: string, 1: string[]}>
	 */
	private static function sections(): array {
		$groups = [
			[ __( 'Store', 'redeemid' ), [ 'REDEEMID_STORE_DOMAIN' ] ],
			[ __( 'Customer sign-in', 'redeemid' ), [ 'REDEEMID_CLIENT_ID', 'REDEEMID_CLIENT_SECRET' ] ],
			[ __( 'Admin API', 'redeemid' ), [ 'REDEEMID_ADMIN_CLIENT_ID', 'REDEEMID_ADMIN_CLIENT_SECRET' ] ],
			[ __( 'Storefront API', 'redeemid' ), [ 'REDEEMID_PUBLIC_ACCESS_TOKEN', 'REDEEMID_STOREFRONT_PRIVATE_TOKEN' ] ],
			[ __( 'Encryption', 'redeemid' ), [ 'REDEEMID_ENCRYPTION_KEY' ] ],
		];
		$all    = array_keys( Config::definitions() );
		$placed = [];
		$out    = [];

		foreach ( $groups as [ $heading, $names ] ) {
			$names = array_values( array_intersect( $names, $all ) );

			if ( $names ) {
				$out[]  = [ $heading, $names ];
				$placed = array_merge( $placed, $names );
			}
		}

		$rest = array_values( array_diff( $all, $placed ) );

		if ( $rest ) {
			$out[] = [ __( 'Other', 'redeemid' ), $rest ];
		}

		return $out;
	}

	/**
	 * Where a value comes from, as a status label. Escaped HTML.
	 */
	private static function source_status( string $source, bool $required ): string {
		return match ( $source ) {
			ConfigValues::CONSTANT   => Menu::status( 'neutral', __( 'wp-config.php', 'redeemid' ) ),
			ConfigValues::SETTINGS   => Menu::status( 'ok', __( 'Saved', 'redeemid' ) ),
			ConfigValues::UNREADABLE => Menu::status( 'error', __( 'Unreadable', 'redeemid' ) ),
			default                  => $required ? Menu::status( 'warn', __( 'Required', 'redeemid' ) ) : Menu::status( 'neutral', __( 'Optional', 'redeemid' ) ),
		};
	}

	/**
	 * @param array{label: string, secret: bool, required: bool, help: string, editable?: bool} $def
	 * @param string                                                                            $invalid Why the last save refused this field (a ConfigValues code), or ''.
	 */
	public static function field( string $name, array $def, string $invalid ): void {
		$id       = 'redeemid-' . strtolower( str_replace( '_', '-', $name ) );
		$source   = Config::source( $name );
		$locked   = Config::locked( $name );
		$editable = Config::values()->editable( $name );
		$secret   = ! empty( $def['secret'] );
		$field    = 'config[' . $name . ']';
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $def['label'] ); ?></label>
				<?php if ( $def['required'] ) : ?>
					<span class="redeemid-required" title="<?php esc_attr_e( 'Required', 'redeemid' ); ?>">*</span>
				<?php endif; ?>
			</th>
			<td>
				<?php if ( $locked || ! $editable ) : ?>
					<input id="<?php echo esc_attr( $id ); ?>" class="regular-text code" type="text" disabled
						value="<?php echo esc_attr( $secret ? ( ConfigValues::CONSTANT === $source ? '••••••••••••' : '' ) : Config::get( $name ) ); ?>"
						placeholder="<?php echo esc_attr( $locked ? __( '(empty)', 'redeemid' ) : __( 'Not set', 'redeemid' ) ); ?>">
					<p class="description">
						<span class="dashicons dashicons-lock redeemid-icon-inline" aria-hidden="true"></span>
						<?php
						if ( $locked ) {
							/* translators: %s: constant name */
							echo esc_html( sprintf( __( 'Set in wp-config.php by %s, which always wins. Remove the constant to edit it here.', 'redeemid' ), Config::constant_name( $name ) ) );
						} else {
							/* translators: %s: constant name */
							echo esc_html( sprintf( __( 'Only read from wp-config.php: define %s there.', 'redeemid' ), $name ) );
						}
						?>
					</p>
				<?php elseif ( $secret ) : ?>
					<input id="<?php echo esc_attr( $id ); ?>" class="regular-text code<?php echo $invalid ? ' redeemid-invalid' : ''; ?>" type="password" name="<?php echo esc_attr( $field ); ?>" value="" autocomplete="new-password" spellcheck="false"
						placeholder="<?php echo esc_attr( ConfigValues::SETTINGS === $source ? __( 'Saved. Leave blank to keep it.', 'redeemid' ) : __( 'Not set', 'redeemid' ) ); ?>"
						<?php echo $invalid ? 'aria-invalid="true"' : ''; ?>>
					<?php if ( ConfigValues::NONE !== $source ) : ?>
						<label class="redeemid-clear"><input type="checkbox" name="clear[]" value="<?php echo esc_attr( $name ); ?>"> <?php esc_html_e( 'Remove the saved value', 'redeemid' ); ?></label>
					<?php endif; ?>
					<?php if ( ConfigValues::UNREADABLE === $source ) : ?>
						<p class="description redeemid-error-text"><?php esc_html_e( 'A value is saved but can no longer be decrypted, so it is ignored. The WordPress salts or REDEEMID_ENCRYPTION_KEY changed since it was saved. Enter it again.', 'redeemid' ); ?></p>
					<?php endif; ?>
				<?php else : ?>
					<input id="<?php echo esc_attr( $id ); ?>" class="regular-text code<?php echo $invalid ? ' redeemid-invalid' : ''; ?>" type="text" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( Config::get( $name ) ); ?>" spellcheck="false"
						<?php echo 'REDEEMID_STORE_DOMAIN' === $name ? 'placeholder="your-store.myshopify.com"' : ''; ?>
						<?php echo $invalid ? 'aria-invalid="true"' : ''; ?>>
				<?php endif; ?>
				<?php if ( $invalid ) : ?>
					<p class="description redeemid-error-text"><?php esc_html_e( 'Not saved:', 'redeemid' ); ?> <?php echo esc_html( self::reason( $invalid ) ); ?></p>
				<?php endif; ?>
				<p class="description redeemid-help"><?php echo esc_html( $def['help'] ); ?></p>
				<div class="redeemid-field-meta">
					<?php echo self::source_status( $source, $def['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<code class="redeemid-code-small"><?php echo esc_html( $name ); ?></code>
				</div>
			</td>
		</tr>
		<?php
	}

	private static function reason( string $code ): string {
		return match ( $code ) {
			ConfigValues::BAD_DOMAIN => __( 'use the store\'s your-store.myshopify.com domain, not a custom domain.', 'redeemid' ),
			ConfigValues::TOO_LONG   => __( 'that is longer than any Shopify credential.', 'redeemid' ),
			ConfigValues::NO_KEY     => __( 'no encryption key is available, so secrets cannot be stored. Check the WordPress salts in wp-config.php, or REDEEMID_ENCRYPTION_KEY.', 'redeemid' ),
			default                  => __( 'paste the value without spaces or line breaks.', 'redeemid' ),
		};
	}

	/**
	 * The non-secret behaviour settings (Settings::OPTION), saved through options.php.
	 */
	private static function behaviour(): void {
		$settings = Settings::all();
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'redeemid' ); ?>
			<?php Menu::panel_start( __( 'Membership', 'redeemid' ), __( 'How members sign in and come back from checkout, what happens on sign-out, and when to warn you about webhooks.', 'redeemid' ) ); ?>
			<div class="redeemid-panel__body redeemid-panel__body--flush">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Membership tags', 'redeemid' ); ?></th>
					<td>
						<p class="description"><?php esc_html_e( 'Customers get their tags in Shopify, from Shopify Flow or a subscription or membership app. This plugin only reads them. See docs/shopify-flow.md for recipes.', 'redeemid' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Shared devices', 'redeemid' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[clear_cart_on_logout]" value="1" <?php checked( ! empty( $settings['clear_cart_on_logout'] ) ); ?>> <?php esc_html_e( 'Clear the cart on sign-out', 'redeemid' ); ?></label>
						<p class="description"><?php esc_html_e( 'With Shopify\'s plugin active, a cart signed in to a customer is always dropped after sign-out. This also drops guest items added while signed in.', 'redeemid' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sign-in links', 'redeemid' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[login_button]" value="1" <?php checked( ! empty( $settings['login_button'] ) ); ?>> <?php esc_html_e( 'Show "Log in with Shopify" on the WordPress login screen', 'redeemid' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[shopify_login_links]" value="1" <?php checked( ! empty( $settings['shopify_login_links'] ) ); ?>> <?php esc_html_e( 'Send visitors to Shopify sign-in from the site\'s login links (comment forms, themes, other plugins)', 'redeemid' ); ?></label>
						<p class="description"><?php esc_html_e( 'Staff keep the WordPress login: wp-admin still goes to wp-login.php. Menus can add Log in and Log out under Appearance → Menus → Shopify, and classic themes can use the [redeemid_login] shortcode.', 'redeemid' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="redeemid-session-limit"><?php esc_html_e( 'Signed-in browsers per member', 'redeemid' ); ?></label></th>
					<td>
						<input type="number" min="0" max="<?php echo (int) \RedeemID\Auth\SessionCap::MAX; ?>" id="redeemid-session-limit" class="small-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[session_limit]" value="<?php echo (int) $settings['session_limit']; ?>">
						<p class="description"><?php esc_html_e( 'Slows down one membership shared by many people: when a linked member signs in on more browsers than this, their oldest sessions on this site end. Their Shopify account is untouched, and staff are never capped. 0 turns it off.', 'redeemid' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="redeemid-subscription-url"><?php esc_html_e( 'Subscription management', 'redeemid' ); ?></label></th>
					<td>
						<input type="url" id="redeemid-subscription-url" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[subscription_url]" value="<?php echo esc_attr( (string) $settings['subscription_url'] ); ?>" placeholder="https://">
						<p class="description"><?php esc_html_e( 'Where members manage or cancel a subscription, such as your subscription app\'s customer portal. Shown on the Member Account block; leave empty to hide the link.', 'redeemid' ); ?></p>
					</td>
				</tr>
				<?php self::member_fields( (array) $settings['member_fields'] ); ?>
				<tr>
					<th scope="row"><label for="redeemid-return-url"><?php esc_html_e( 'Return link for Shopify', 'redeemid' ); ?></label></th>
					<td>
						<input type="text" id="redeemid-return-url" class="regular-text code" readonly value="<?php echo esc_attr( \RedeemID\Sync\PostCheckout::return_url() ); ?>" onfocus="this.select()">
						<p class="description"><?php esc_html_e( 'Add this as a "Return to the site" link on Shopify\'s order status page and in the order confirmation email. Signed-in buyers go back to the page they bought from; guests are asked to log in with the email from their order.', 'redeemid' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="redeemid-quiet-days"><?php esc_html_e( 'Webhook silence warning', 'redeemid' ); ?></label></th>
					<td>
						<input type="number" min="0" max="90" id="redeemid-quiet-days" class="small-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[quiet_days]" value="<?php echo (int) $settings['quiet_days']; ?>"> <?php esc_html_e( 'days', 'redeemid' ); ?>
						<p class="description"><?php esc_html_e( 'Warn when no webhook has arrived for this long while customers are linked. Quiet stores can raise it; 0 turns the warning off. Access never expires: this only tells you deliveries may have stopped.', 'redeemid' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Alerts', 'redeemid' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[alert_email]" value="1" <?php checked( ! empty( $settings['alert_email'] ) ); ?>> <?php echo esc_html( sprintf( /* translators: %s: email address */ __( 'Email %s when webhook delivery has a problem', 'redeemid' ), (string) get_option( 'admin_email' ) ) ); ?></label>
						<p class="description"><?php esc_html_e( 'Sent by the daily check: at once for a new problem, then at most once a day while it lasts.', 'redeemid' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Storefront pages', 'redeemid' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[storefront_pages]" value="1" <?php checked( ! empty( $settings['storefront_pages'] ) ); ?>> <?php esc_html_e( 'Serve product, collection and cart pages', 'redeemid' ); ?></label>
						<p class="description"><?php esc_html_e( 'Pages built from the Shopify Product, Shopify Collection and Shopify Cart blocks, with titles, descriptions and product structured data. Block themes can edit them under Appearance → Editor → Templates. A path a published WordPress page already uses is left alone.', 'redeemid' ); ?></p>
						<?php
						foreach (
							[
								'product_path'    => [ __( 'Product pages', 'redeemid' ), '/{handle}/' ],
								'collection_path' => [ __( 'Collection pages', 'redeemid' ), '/{handle}/' ],
								'cart_path'       => [ __( 'Cart page', 'redeemid' ), '/' ],
							] as $redeemid_key => [ $redeemid_label, $redeemid_tail ]
						) :
							?>
							<p>
								<label for="redeemid-<?php echo esc_attr( $redeemid_key ); ?>"><?php echo esc_html( $redeemid_label ); ?></label><br>
								<code><?php echo esc_html( untrailingslashit( home_url() ) ); ?>/</code><input type="text" id="redeemid-<?php echo esc_attr( $redeemid_key ); ?>" class="regular-text code" name="<?php echo esc_attr( Settings::OPTION ); ?>[<?php echo esc_attr( $redeemid_key ); ?>]" value="<?php echo esc_attr( (string) $settings[ $redeemid_key ] ); ?>"><code><?php echo esc_html( $redeemid_tail ); ?></code>
							</p>
						<?php endforeach; ?>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[storefront_first]" value="1" <?php checked( ! empty( $settings['storefront_first'] ) ); ?>> <?php esc_html_e( 'RedeemID takes precedence over Shopify’s WordPress plugin', 'redeemid' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off: while Shopify’s plugin is active, RedeemID leaves any of these paths it has claimed (it always claims /cart) and its cart stays the only cart. On: RedeemID answers its paths and runs the cart, and stops signing in Shopify’s plugin’s cart. Paths that differ from Shopify’s never clash either way.', 'redeemid' ); ?></p>
					</td>
				</tr>
			</table>
			</div>
			<div class="redeemid-panel__footer">
				<?php submit_button( null, 'primary', 'submit', false ); ?>
			</div>
			<?php Menu::panel_end(); ?>
		</form>
		<?php
	}

	/**
	 * The Member preferences row: every customer metafield defined in
	 * Shopify, each Hidden, Members can see or Members can change. Read fresh
	 * from Shopify each time this page loads.
	 *
	 * @param array<string, string> $modes
	 */
	private static function member_fields( array $modes ): void {
		$definitions = Admin::available() ? Preferences::definitions( true ) : new \WP_Error( 'shopify_admin_unconfigured', '' );
		$name        = Settings::OPTION . '[member_fields]';
		$options     = [
			''                       => __( 'Hidden', 'redeemid' ),
			Preferences::SEE    => __( 'Members can see', 'redeemid' ),
			Preferences::CHANGE => __( 'Members can change', 'redeemid' ),
		];
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Member preferences', 'redeemid' ); ?></th>
			<td>
				<p class="description"><?php esc_html_e( 'Customer metafields defined in Shopify (Settings → Custom data → Customers). Choose which ones members may see or change. Answers are saved to the customer in Shopify, never in WordPress, and your theme, a forms plugin or a headless front end shows them through redeemid_preferences() and redeemid_set_preferences() or GET and POST /redeemid/v1/account/preferences. Fields in the membership namespace are never offered.', 'redeemid' ); ?></p>
				<?php if ( is_wp_error( $definitions ) ) : ?>
					<p><?php echo esc_html( 'shopify_admin_unconfigured' === $definitions->get_error_code() ? __( 'Needs the Admin API (Shopify connection above).', 'redeemid' ) : __( 'Shopify could not be reached, so the fields can\'t be listed right now. Saved choices are kept.', 'redeemid' ) ); ?></p>
					<?php foreach ( Preferences::clean_modes( $modes ) as $redeemid_key => $redeemid_mode ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $name . '[' . $redeemid_key . ']' ); ?>" value="<?php echo esc_attr( $redeemid_mode ); ?>">
					<?php endforeach; ?>
				<?php elseif ( ! $definitions ) : ?>
					<p><?php esc_html_e( 'No customer metafields are defined in Shopify yet.', 'redeemid' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="max-width:48rem">
						<tbody>
						<?php foreach ( $definitions as $redeemid_key => $redeemid_def ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $redeemid_def['name'] ); ?></strong><br>
									<code><?php echo esc_html( $redeemid_key ); ?></code> <span class="description"><?php echo esc_html( $redeemid_def['type'] ); ?></span>
								</td>
								<td>
									<?php if ( $redeemid_def['supported'] ) : ?>
										<select name="<?php echo esc_attr( $name . '[' . $redeemid_key . ']' ); ?>" aria-label="<?php echo esc_attr( $redeemid_def['name'] ); ?>">
											<?php foreach ( $options as $redeemid_value => $redeemid_label ) : ?>
												<option value="<?php echo esc_attr( $redeemid_value ); ?>" <?php selected( $modes[ $redeemid_key ] ?? '', $redeemid_value ); ?>><?php echo esc_html( $redeemid_label ); ?></option>
											<?php endforeach; ?>
										</select>
									<?php else : ?>
										<span class="description"><?php echo esc_html( Preferences::reserved( $redeemid_key ) ? __( 'Used by RedeemID for membership dates', 'redeemid' ) : __( 'Type not supported', 'redeemid' ) ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
