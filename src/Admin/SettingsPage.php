<?php

namespace RedeemID\Admin;

use RedeemID\Api\Events;
use RedeemID\Api\Outbound;
use RedeemID\Config;
use RedeemID\Preferences\Preferences;
use RedeemID\Settings;
use RedeemID\Shopify\Admin;
use RedeemID\Shopify\AdminToken;
use RedeemID\Shopify\Discovery;
use RedeemID\Shopify\Domains;
use RedeemID\Support\ConfigValues;
use RedeemID\Support\Logger;
use RedeemID\Webhooks\Registrar;

/**
 * RedeemID → Settings, one tab per subject: the Shopify connection, sign-in,
 * members, the storefront, event webhooks, replacement text (TextPage) and
 * export / import (SettingsTransfer).
 *
 * Every Config::definitions() value can be saved here, in the options table,
 * so a site can be set up without editing wp-config.php. A constant of the
 * same name always wins, and its field shows as locked. Secrets are encrypted
 * before they are stored, are write-only (the field is always empty, a blank
 * submission keeps what is stored) and are never printed back.
 */
final class SettingsPage {
	public const PAGE   = 'redeemid-settings';
	public const ACTION   = 'redeemid_save_config';
	public const OUTBOUND = 'redeemid_outbound';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'save' ] );
		add_action( 'admin_post_' . self::OUTBOUND, [ self::class, 'outbound_action' ] );
	}

	/**
	 * Settings → Event webhooks: send a test event, or make a new secret.
	 */
	public static function outbound_action(): void {
		Menu::guard( self::OUTBOUND );
		$do   = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked by Menu::guard().
		$args = [];

		if ( 'rotate' === $do ) {
			$args['result'] = '' === Outbound::rotate() ? 'outbound_no_key' : 'outbound_rotated';
		} elseif ( 'test' === $do && Outbound::enabled() ) {
			$status         = Outbound::test();
			$args['result'] = Outbound::succeeded( $status ) ? 'outbound_test_ok' : 'outbound_test_failed';
			$args['n']      = is_int( $status ) ? $status : 0;
		}

		wp_safe_redirect( Menu::url( self::PAGE, [ 'tab' => 'events' ] + $args ) );
		exit;
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
		$input  = is_array( $input ) ? self::resolve_store( $input ) : [];
		$result = Config::values()->apply( $input, $clear );

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
	 * An owner may enter the store's own web address (shop.example.com)
	 * rather than its myshopify.com domain. Ask that storefront which store
	 * it is, and save the myshopify.com domain the APIs need.
	 *
	 * @param array<string, mixed> $input The posted config[] fields.
	 * @return array<string, mixed>
	 */
	private static function resolve_store( array $input ): array {
		$value = is_scalar( $input['REDEEMID_STORE_DOMAIN'] ?? null ) ? trim( (string) $input['REDEEMID_STORE_DOMAIN'] ) : '';

		if ( '' === $value || null === ConfigValues::invalid( 'REDEEMID_STORE_DOMAIN', $value ) ) {
			return $input;
		}

		$found = Domains::myshopify_for( ConfigValues::normalise_domain( $value ) );

		if ( '' !== $found ) {
			$input['REDEEMID_STORE_DOMAIN'] = $found;
		}

		return $input;
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
			Domains::forget();
		}

		if ( array_intersect( [ 'REDEEMID_STORE_DOMAIN', 'REDEEMID_ADMIN_CLIENT_ID', 'REDEEMID_ADMIN_CLIENT_SECRET' ], $changed ) ) {
			AdminToken::forget();
			delete_option( 'redeemid_admin_token_error' );
			delete_option( 'redeemid_admin_token_scopes' );
			delete_transient( 'redeemid_admin_scopes' );
			// Another store or app has none of this site's subscriptions yet; the next passing test registers them.
			delete_option( Registrar::RESULT );
		}
	}

	/**
	 * The page's tabs: slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return [
			'connection' => __( 'Shopify connection', 'redeemid' ),
			'sign-in'    => __( 'Sign-in', 'redeemid' ),
			'members'    => __( 'Members', 'redeemid' ),
			'storefront' => __( 'Storefront', 'redeemid' ),
			'events'     => __( 'Event webhooks', 'redeemid' ),
			'text'       => __( 'Text', 'redeemid' ),
			'transfer'   => __( 'Export / import', 'redeemid' ),
		];
	}

	public static function render(): void {
		$tabs   = self::tabs();
		$tab    = Menu::tab( $tabs );
		$intros = [
			'connection' => __( 'Enter the store\'s credentials here, or define them as constants in wp-config.php. A constant always wins and its field is locked here; that is the more secure choice, because the value then never touches the database. Secrets saved here are encrypted with a key from the WordPress salts (or REDEEMID_ENCRYPTION_KEY) and are never shown again: leave a secret blank to keep it.', 'redeemid' ),
			'sign-in'    => __( 'How members sign in, what happens when they sign out, and how buyers come back from checkout.', 'redeemid' ),
			'members'    => __( 'What members can manage themselves. Customers get their membership tags in Shopify, from Shopify Flow or a subscription or membership app; RedeemID only reads them. See docs/shopify-flow.md for recipes.', 'redeemid' ),
			'storefront' => __( 'Product, collection and cart pages served from your Shopify catalog.', 'redeemid' ),
			'events'     => __( 'Send RedeemID\'s events as signed JSON to one address: a CRM, an email service, Zapier, Make or your own endpoint. Sent in the background and retried for about 15 hours. Off while the address is empty.', 'redeemid' ),
			'text'       => __( 'Put RedeemID in your own words: any text the plugin shows, to visitors, members or staff, can read the way you want ("Account" can read "Portal"). Only the strings you give new text change. Replacements sit on top of translation, so text you leave alone keeps its translation. Each empty field shows the current text; clear a field to go back to it.', 'redeemid' ),
			'transfer'   => __( 'Move a set-up site\'s levels, rules and settings to another site, such as staging to production, or keep a copy before a big change.', 'redeemid' ),
		];

		Menu::page_start( __( 'Settings', 'redeemid' ), __( 'Settings', 'redeemid' ) );
		Menu::tabs( self::PAGE, $tabs, $tab, __( 'Settings sections', 'redeemid' ) );
		echo '<p class="redeemid-intro">' . esc_html( $intros[ $tab ] ) . '</p>';
		Menu::result_notice(
			[
				/* translators: %d: number of values changed */
				'config_saved'         => [ 'success', __( 'Connection saved. %d values changed.', 'redeemid' ) ],
				'config_invalid'       => [ 'error', __( 'Some values were not saved. They are marked below.', 'redeemid' ) ],
				'outbound_rotated'     => [ 'success', __( 'A new signing secret was made. Give it to every receiver: the old one no longer verifies.', 'redeemid' ) ],
				'outbound_no_key'      => [ 'error', __( 'No encryption key is available, so a signing secret cannot be stored. Check the WordPress salts in wp-config.php, or REDEEMID_ENCRYPTION_KEY.', 'redeemid' ) ],
				'outbound_test_ok'     => [ 'success', __( 'The test event was delivered.', 'redeemid' ) ],
				/* translators: %d: HTTP status code, or 0 when there was no answer */
				'outbound_test_failed' => [ 'error', __( 'The test event was not accepted (HTTP status %d; 0 means no answer within five seconds, or the address could not be reached).', 'redeemid' ) ],
			] + SettingsTransfer::messages()
		);
		// options.php leaves its "Settings saved." here; core prints it only on its own Settings screens.
		settings_errors();

		match ( $tab ) {
			'sign-in'    => self::sign_in(),
			'members'    => self::members(),
			'storefront' => self::storefront(),
			'events'     => self::events(),
			'text'       => TextPage::render(),
			'transfer'   => SettingsTransfer::render(),
			default      => self::connection(),
		};

		Menu::page_end();
	}

	private static function connection(): void {
		$invalid = self::invalid_fields();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<?php foreach ( self::sections() as [ $heading, $names ] ) : ?>
				<?php Menu::panel_start( $heading ); ?>
				<div class="redeemid-panel__body redeemid-panel__body--flush">
					<table class="form-table" role="presentation">
						<?php foreach ( $names as $name ) : ?>
							<?php self::field( $name, Config::definitions()[ $name ], $invalid[ $name ] ?? '' ); ?>
						<?php endforeach; ?>
					</table>
				</div>
				<?php Menu::panel_end(); ?>
			<?php endforeach; ?>
			<p class="submit"><?php submit_button( __( 'Save connection', 'redeemid' ), 'primary', 'submit', false ); ?></p>
		</form>
		<?php
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
			ConfigValues::BAD_DOMAIN => __( 'that address isn\'t a Shopify store. Use the store\'s web address or its your-store.myshopify.com domain.', 'redeemid' ),
			ConfigValues::TOO_LONG   => __( 'that is longer than any Shopify credential.', 'redeemid' ),
			ConfigValues::NO_KEY     => __( 'no encryption key is available, so secrets cannot be stored. Check the WordPress salts in wp-config.php, or REDEEMID_ENCRYPTION_KEY.', 'redeemid' ),
			default                  => __( 'paste the value without spaces or line breaks.', 'redeemid' ),
		};
	}

	/**
	 * A form for some of the non-secret settings (Settings::OPTION), saved
	 * through options.php. Settings on other tabs keep their saved values.
	 *
	 * @param string[] $keys The settings the rows hold.
	 * @param callable $rows Prints the form-table rows, given Settings::all().
	 */
	private static function settings_form( array $keys, callable $rows, string $title = '' ): void {
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'redeemid' ); ?>
			<?php echo Settings::fields_input( $keys ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by Settings::fields_input(). ?>
			<?php Menu::panel_start( $title ); ?>
			<div class="redeemid-panel__body redeemid-panel__body--flush">
			<table class="form-table" role="presentation">
				<?php $rows( Settings::all() ); ?>
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
	 * The webhook warning settings, for the Webhooks page's Health tab.
	 */
	public static function webhook_warnings(): void {
		self::settings_form(
			[ 'quiet_days', 'alert_email' ],
			static function ( array $settings ): void {
				?>
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
				<?php
			},
			__( 'Warnings', 'redeemid' )
		);
	}

	private static function sign_in(): void {
		self::settings_form(
			[ 'login_button', 'shopify_login_links', 'shopify_only_login', 'session_limit', 'clear_cart_on_logout' ],
			static function ( array $settings ): void {
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sign-in links', 'redeemid' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[login_button]" value="1" <?php checked( ! empty( $settings['login_button'] ) ); ?>> <?php esc_html_e( 'Show the member "Sign in with an email code" button on the WordPress login screen', 'redeemid' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[shopify_login_links]" value="1" <?php checked( ! empty( $settings['shopify_login_links'] ) ); ?>> <?php esc_html_e( 'Send visitors to Shopify sign-in from the site\'s login links (comment forms, themes, other plugins)', 'redeemid' ); ?></label>
						<p class="description"><?php esc_html_e( 'Staff keep the WordPress login: wp-admin still goes to wp-login.php. Menus can add Sign in and Sign out under Appearance → Menus → Shopify, and classic themes can use the [redeemid_login] shortcode.', 'redeemid' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'WordPress passwords', 'redeemid' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[shopify_only_login]" value="1" <?php checked( ! empty( $settings['shopify_only_login'] ) ); ?>> <?php esc_html_e( 'Linked members sign in with Shopify only', 'redeemid' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off: a WordPress user who links a Shopify customer keeps their WordPress password too. On: their password, password reset and application passwords stop working, and Shopify is the only way in. Nothing is deleted, so turning this off gives the password back. Staff are never affected.', 'redeemid' ); ?></p>
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
					<th scope="row"><?php esc_html_e( 'Shared devices', 'redeemid' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[clear_cart_on_logout]" value="1" <?php checked( ! empty( $settings['clear_cart_on_logout'] ) ); ?>> <?php esc_html_e( 'Clear the cart on sign-out', 'redeemid' ); ?></label>
						<p class="description"><?php esc_html_e( 'With Shopify\'s plugin active, a cart signed in to a customer is always dropped after sign-out. This also drops guest items added while signed in.', 'redeemid' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="redeemid-return-url"><?php esc_html_e( 'Return link for Shopify', 'redeemid' ); ?></label></th>
					<td>
						<input type="text" id="redeemid-return-url" class="regular-text code" readonly value="<?php echo esc_attr( \RedeemID\Sync\PostCheckout::return_url() ); ?>" onfocus="this.select()">
						<p class="description"><?php esc_html_e( 'Add this as a "Return to the site" link on Shopify\'s order status page and in the order confirmation email. Signed-in buyers go back to the page they bought from; guests are asked to log in with the email from their order.', 'redeemid' ); ?></p>
					</td>
				</tr>
				<?php
			}
		);
	}

	private static function members(): void {
		self::settings_form(
			[ 'subscription_url', 'member_fields' ],
			static function ( array $settings ): void {
				?>
				<tr>
					<th scope="row"><label for="redeemid-subscription-url"><?php esc_html_e( 'Subscription management', 'redeemid' ); ?></label></th>
					<td>
						<input type="url" id="redeemid-subscription-url" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[subscription_url]" value="<?php echo esc_attr( (string) $settings['subscription_url'] ); ?>" placeholder="https://">
						<p class="description"><?php esc_html_e( 'Where members manage or cancel a subscription, such as your subscription app\'s customer portal. Shown on the Member Account block; leave empty to hide the link.', 'redeemid' ); ?></p>
					</td>
				</tr>
				<?php
				self::member_fields( (array) $settings['member_fields'] );
			}
		);
	}

	private static function storefront(): void {
		self::settings_form(
			[ 'storefront_pages', 'product_path', 'collection_path', 'cart_path', 'storefront_first' ],
			static function ( array $settings ): void {
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Storefront pages', 'redeemid' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[storefront_pages]" value="1" <?php checked( ! empty( $settings['storefront_pages'] ) ); ?>> <?php esc_html_e( 'Serve product, collection and cart pages', 'redeemid' ); ?></label>
						<p class="description"><?php esc_html_e( 'Pages built from the Shopify Product, Shopify Collection and Shopify Cart blocks, with titles, descriptions and product structured data. Block themes can edit them under Appearance → Editor → Templates. A path a published WordPress page already uses is left alone.', 'redeemid' ); ?></p>
					</td>
				</tr>
				<?php
				foreach (
					[
						'product_path'    => [ __( 'Product pages', 'redeemid' ), '/{handle}/' ],
						'collection_path' => [ __( 'Collection pages', 'redeemid' ), '/{handle}/' ],
						'cart_path'       => [ __( 'Cart page', 'redeemid' ), '/' ],
					] as $redeemid_key => [ $redeemid_label, $redeemid_tail ]
				) :
					?>
					<tr>
						<th scope="row"><label for="redeemid-<?php echo esc_attr( $redeemid_key ); ?>"><?php echo esc_html( $redeemid_label ); ?></label></th>
						<td>
							<code><?php echo esc_html( untrailingslashit( home_url() ) ); ?>/</code><input type="text" id="redeemid-<?php echo esc_attr( $redeemid_key ); ?>" class="regular-text code" name="<?php echo esc_attr( Settings::OPTION ); ?>[<?php echo esc_attr( $redeemid_key ); ?>]" value="<?php echo esc_attr( (string) $settings[ $redeemid_key ] ); ?>"><code><?php echo esc_html( $redeemid_tail ); ?></code>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Shopify’s WordPress plugin', 'redeemid' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[storefront_first]" value="1" <?php checked( ! empty( $settings['storefront_first'] ) ); ?>> <?php esc_html_e( 'RedeemID takes precedence over Shopify’s WordPress plugin', 'redeemid' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off: while Shopify’s plugin is active, RedeemID leaves any of these paths it has claimed (it always claims /cart) and its cart stays the only cart. On: RedeemID answers its paths and runs the cart, and stops signing in Shopify’s plugin’s cart. Paths that differ from Shopify’s never clash either way.', 'redeemid' ); ?></p>
					</td>
				</tr>
				<?php
			}
		);
	}

	private static function events(): void {
		self::settings_form(
			[ 'outbound_url', 'outbound_events', 'outbound_customer' ],
			[ self::class, 'outbound' ]
		);
		self::outbound_secret();
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
					<p><?php echo esc_html( 'shopify_admin_unconfigured' === $definitions->get_error_code() ? __( 'Needs the Admin API: add its credentials on the Shopify connection tab.', 'redeemid' ) : __( 'Shopify could not be reached, so the fields can\'t be listed right now. Saved choices are kept.', 'redeemid' ) ); ?></p>
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

	/**
	 * The event webhook rows.
	 *
	 * @param array<string, mixed> $settings
	 */
	public static function outbound( array $settings ): void {
		$name   = Settings::OPTION;
		$chosen = Outbound::sanitize_events( $settings['outbound_events'] );
		?>
			<tr>
				<th scope="row"><label for="redeemid-outbound-url"><?php esc_html_e( 'Send events to', 'redeemid' ); ?></label></th>
				<td>
					<input type="url" id="redeemid-outbound-url" class="regular-text code" name="<?php echo esc_attr( $name ); ?>[outbound_url]" value="<?php echo esc_attr( (string) $settings['outbound_url'] ); ?>" placeholder="https://" spellcheck="false">
					<p class="description"><?php esc_html_e( 'An https address. Redirects are not followed, and anything but a 2xx answer within five seconds counts as a failure.', 'redeemid' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Events', 'redeemid' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Events', 'redeemid' ); ?></legend>
						<?php foreach ( array_keys( Events::CATALOG ) as $event ) : ?>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[outbound_events][]" value="<?php echo esc_attr( $event ); ?>" <?php checked( in_array( $event, $chosen, true ) ); ?>> <code><?php echo esc_html( $event ); ?></code></label><br>
						<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'wall_shown fires on page views and meter_used on metered reads, so each can mean one background job per view on a busy site.', 'redeemid' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Matching people', 'redeemid' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[outbound_customer]" value="1" <?php checked( ! empty( $settings['outbound_customer'] ) ); ?>> <?php esc_html_e( 'Include the Shopify customer ID for linked members', 'redeemid' ); ?></label>
					<p class="description"><?php esc_html_e( 'Lets a CRM or email service that syncs your Shopify customers match the person. Events never carry an email address or Shopify tags. Once sent, an event is the receiver\'s: erasing a member here does not remove it there, so list the receiver in your privacy policy.', 'redeemid' ); ?></p>
				</td>
			</tr>
		<?php
	}

	/**
	 * The signing secret and the test and new-secret buttons, outside the form.
	 */
	private static function outbound_secret(): void {
		if ( ! Outbound::enabled() ) {
			return;
		}

		$secret  = Outbound::secret();
		$actions = Menu::action_button_html( self::OUTBOUND, 'test', __( 'Send a test event', 'redeemid' ) )
			. Menu::action_button_html( self::OUTBOUND, 'rotate', __( 'Make a new secret', 'redeemid' ) );
		Menu::panel_start( __( 'Signing secret', 'redeemid' ), __( 'Every event webhook carries X-RedeemID-Signature: t=<time>,v1=<HMAC-SHA256 of "<time>.<body>" with this secret>. Check it, and that the time is recent, before trusting a delivery.', 'redeemid' ), $actions );
		?>
		<div class="redeemid-panel__body">
			<?php if ( '' === $secret ) : ?>
				<p class="redeemid-error-text"><?php esc_html_e( 'No encryption key is available, so no signing secret can be stored and nothing is sent. Check the WordPress salts in wp-config.php, or REDEEMID_ENCRYPTION_KEY.', 'redeemid' ); ?></p>
			<?php else : ?>
				<details>
					<summary><?php esc_html_e( 'Show the secret', 'redeemid' ); ?></summary>
					<p><label class="screen-reader-text" for="redeemid-outbound-secret"><?php esc_html_e( 'Signing secret', 'redeemid' ); ?></label><input type="text" id="redeemid-outbound-secret" class="large-text code" readonly value="<?php echo esc_attr( $secret ); ?>" onfocus="this.select()"></p>
				</details>
			<?php endif; ?>
		</div>
		<?php
		Menu::panel_end();
	}
}
