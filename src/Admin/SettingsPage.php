<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Config;
use WP2_Shopify\Settings;
use WP2_Shopify\Shopify\AdminToken;
use WP2_Shopify\Shopify\Discovery;
use WP2_Shopify\Support\ConfigValues;
use WP2_Shopify\Support\Logger;

/**
 * Shopify → Settings: the Shopify connection and the plugin's behaviour.
 *
 * Every Config::definitions() value can be saved here, in the options table,
 * so a site can be set up without editing wp-config.php. A constant of the
 * same name always wins, and its field shows as locked. Secrets are encrypted
 * before they are stored, are write-only (the field is always empty, a blank
 * submission keeps what is stored) and are never printed back.
 */
final class SettingsPage {
	public const PAGE   = 'wp2-shopify-settings';
	public const ACTION = 'wp2_shopify_save_config';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'save' ] );
	}

	public static function save(): void {
		Menu::guard( self::ACTION );
		// phpcs:disable WordPress.Security.NonceVerification -- checked by Menu::guard().

		$input  = wp_unslash( $_POST['config'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- ConfigValues::apply validates each field.
		$clear  = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['clear'] ?? [] ) );
		$result = Config::values()->apply( is_array( $input ) ? $input : [], $clear );

		update_option( Config::OPTION, $result['stored'], true );
		Config::reset();
		self::after_change( $result['changed'] );

		if ( $result['changed'] ) {
			// Names only: no value, secret or not, is ever logged.
			Logger::log( 'admin.config_saved', [ 'changed' => implode( ',', $result['changed'] ) ] );
		}

		$args = [ 'result' => $result['errors'] ? 'config_invalid' : 'config_saved', 'n' => count( $result['changed'] ) ];

		if ( $result['errors'] ) {
			$args['invalid'] = implode( ',', array_map( static fn( $name, $code ) => $name . ':' . $code, array_keys( $result['errors'] ), $result['errors'] ) );
		}

		wp_safe_redirect( Menu::url( self::PAGE, $args ) );
		exit;
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Drop what was cached for the old values.
	 *
	 * @param string[] $changed
	 */
	private static function after_change( array $changed ): void {
		if ( in_array( 'WP2_SHOPIFY_STORE_DOMAIN', $changed, true ) ) {
			Discovery::forget_store();
		}

		if ( array_intersect( [ 'WP2_SHOPIFY_STORE_DOMAIN', 'WP2_SHOPIFY_ADMIN_CLIENT_ID', 'WP2_SHOPIFY_ADMIN_CLIENT_SECRET' ], $changed ) ) {
			AdminToken::forget();
			delete_option( 'wp2_shopify_admin_token_error' );
			delete_option( 'wp2_shopify_admin_token_scopes' );
			delete_transient( 'wp2_shopify_admin_scopes' );
		}
	}

	public static function render(): void {
		$invalid = [];

		foreach ( explode( ',', sanitize_text_field( wp_unslash( $_GET['invalid'] ?? '' ) ) ) as $pair ) { // phpcs:ignore WordPress.Security.NonceVerification
			[ $name, $code ] = array_pad( explode( ':', $pair, 2 ), 2, '' );
			$invalid[ $name ] = sanitize_key( $code );
		}
		Menu::page_start( __( 'Settings', 'wp2-shopify' ), __( 'Shopify settings', 'wp2-shopify' ) );
		Menu::result_notice(
			[
				/* translators: %d: number of values changed */
				'config_saved'   => [ 'success', __( 'Connection saved. %d values changed.', 'wp2-shopify' ) ],
				'config_invalid' => [ 'error', __( 'Some values were not saved. They are marked below.', 'wp2-shopify' ) ],
			]
		);

		$sections = self::sections();
		?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php Menu::panel_start( __( 'Shopify connection', 'wp2-shopify' ), __( 'Enter the store\'s credentials here, or define them as constants in wp-config.php. A constant always wins and its field is locked here; that is the more secure choice, because the value then never touches the database. Secrets saved here are encrypted with a key from the WordPress salts (or WP2_SHOPIFY_ENCRYPTION_KEY) and are never shown again: leave a secret blank to keep it.', 'wp2-shopify' ) ); ?>
					<div class="wp2s-panel__body wp2s-panel__body--flush">
						<table class="form-table" role="presentation">
							<?php foreach ( $sections as [ $heading, $names ] ) : ?>
								<tr class="wp2s-section-row">
									<th colspan="2" scope="colgroup"><?php echo esc_html( $heading ); ?></th>
								</tr>
								<?php foreach ( $names as $name ) : ?>
									<?php self::field( $name, Config::definitions()[ $name ], $invalid[ $name ] ?? '' ); ?>
								<?php endforeach; ?>
							<?php endforeach; ?>
						</table>
					</div>
					<div class="wp2s-panel__footer">
						<?php submit_button( __( 'Save connection', 'wp2-shopify' ), 'primary', 'submit', false ); ?>
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
			[ __( 'Store', 'wp2-shopify' ), [ 'WP2_SHOPIFY_STORE_DOMAIN' ] ],
			[ __( 'Customer sign-in', 'wp2-shopify' ), [ 'WP2_SHOPIFY_CLIENT_ID', 'WP2_SHOPIFY_CLIENT_SECRET' ] ],
			[ __( 'Admin API', 'wp2-shopify' ), [ 'WP2_SHOPIFY_ADMIN_CLIENT_ID', 'WP2_SHOPIFY_ADMIN_CLIENT_SECRET' ] ],
			[ __( 'Storefront API', 'wp2-shopify' ), [ 'WP2_SHOPIFY_PUBLIC_ACCESS_TOKEN', 'WP2_SHOPIFY_STOREFRONT_PRIVATE_TOKEN' ] ],
			[ __( 'Encryption', 'wp2-shopify' ), [ 'WP2_SHOPIFY_ENCRYPTION_KEY' ] ],
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
			$out[] = [ __( 'Other', 'wp2-shopify' ), $rest ];
		}

		return $out;
	}

	/**
	 * Where a value comes from, as a status label. Escaped HTML.
	 */
	private static function source_status( string $source, bool $required ): string {
		return match ( $source ) {
			ConfigValues::CONSTANT   => Menu::status( 'neutral', __( 'wp-config.php', 'wp2-shopify' ) ),
			ConfigValues::SETTINGS   => Menu::status( 'ok', __( 'Saved', 'wp2-shopify' ) ),
			ConfigValues::UNREADABLE => Menu::status( 'error', __( 'Unreadable', 'wp2-shopify' ) ),
			default                  => $required ? Menu::status( 'warn', __( 'Required', 'wp2-shopify' ) ) : Menu::status( 'neutral', __( 'Optional', 'wp2-shopify' ) ),
		};
	}

	/**
	 * @param array{label: string, secret: bool, required: bool, help: string, editable?: bool} $def
	 * @param string                                                                            $invalid Why the last save refused this field (a ConfigValues code), or ''.
	 */
	private static function field( string $name, array $def, string $invalid ): void {
		$id       = 'wp2s-' . strtolower( str_replace( '_', '-', $name ) );
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
					<span class="wp2s-required" title="<?php esc_attr_e( 'Required', 'wp2-shopify' ); ?>">*</span>
				<?php endif; ?>
			</th>
			<td>
				<?php if ( $locked || ! $editable ) : ?>
					<input id="<?php echo esc_attr( $id ); ?>" class="regular-text code" type="text" disabled
						value="<?php echo esc_attr( $secret ? ( ConfigValues::CONSTANT === $source ? '••••••••••••' : '' ) : Config::get( $name ) ); ?>"
						placeholder="<?php echo esc_attr( $locked ? __( '(empty)', 'wp2-shopify' ) : __( 'Not set', 'wp2-shopify' ) ); ?>">
					<p class="description">
						<span class="dashicons dashicons-lock wp2s-icon-inline" aria-hidden="true"></span>
						<?php
						if ( $locked ) {
							/* translators: %s: constant name */
							echo esc_html( sprintf( __( 'Set in wp-config.php by %s, which always wins. Remove the constant to edit it here.', 'wp2-shopify' ), Config::constant_name( $name ) ) );
						} else {
							/* translators: %s: constant name */
							echo esc_html( sprintf( __( 'Only read from wp-config.php: define %s there.', 'wp2-shopify' ), $name ) );
						}
						?>
					</p>
				<?php elseif ( $secret ) : ?>
					<input id="<?php echo esc_attr( $id ); ?>" class="regular-text code<?php echo $invalid ? ' wp2s-invalid' : ''; ?>" type="password" name="<?php echo esc_attr( $field ); ?>" value="" autocomplete="new-password" spellcheck="false"
						placeholder="<?php echo esc_attr( ConfigValues::SETTINGS === $source ? __( 'Saved. Leave blank to keep it.', 'wp2-shopify' ) : __( 'Not set', 'wp2-shopify' ) ); ?>"
						<?php echo $invalid ? 'aria-invalid="true"' : ''; ?>>
					<?php if ( ConfigValues::NONE !== $source ) : ?>
						<label class="wp2s-clear"><input type="checkbox" name="clear[]" value="<?php echo esc_attr( $name ); ?>"> <?php esc_html_e( 'Remove the saved value', 'wp2-shopify' ); ?></label>
					<?php endif; ?>
					<?php if ( ConfigValues::UNREADABLE === $source ) : ?>
						<p class="description wp2s-error-text"><?php esc_html_e( 'A value is saved but can no longer be decrypted, so it is ignored. The WordPress salts or WP2_SHOPIFY_ENCRYPTION_KEY changed since it was saved. Enter it again.', 'wp2-shopify' ); ?></p>
					<?php endif; ?>
				<?php else : ?>
					<input id="<?php echo esc_attr( $id ); ?>" class="regular-text code<?php echo $invalid ? ' wp2s-invalid' : ''; ?>" type="text" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( Config::get( $name ) ); ?>" spellcheck="false"
						<?php echo 'WP2_SHOPIFY_STORE_DOMAIN' === $name ? 'placeholder="your-store.myshopify.com"' : ''; ?>
						<?php echo $invalid ? 'aria-invalid="true"' : ''; ?>>
				<?php endif; ?>
				<?php if ( $invalid ) : ?>
					<p class="description wp2s-error-text"><?php esc_html_e( 'Not saved:', 'wp2-shopify' ); ?> <?php echo esc_html( self::reason( $invalid ) ); ?></p>
				<?php endif; ?>
				<p class="description wp2s-help"><?php echo esc_html( $def['help'] ); ?></p>
				<div class="wp2s-field-meta">
					<?php echo self::source_status( $source, $def['required'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<code class="wp2s-code-small"><?php echo esc_html( $name ); ?></code>
				</div>
			</td>
		</tr>
		<?php
	}

	private static function reason( string $code ): string {
		return match ( $code ) {
			ConfigValues::BAD_DOMAIN => __( 'use the store\'s your-store.myshopify.com domain, not a custom domain.', 'wp2-shopify' ),
			ConfigValues::TOO_LONG   => __( 'that is longer than any Shopify credential.', 'wp2-shopify' ),
			ConfigValues::NO_KEY     => __( 'no encryption key is available, so secrets cannot be stored. Check the WordPress salts in wp-config.php, or WP2_SHOPIFY_ENCRYPTION_KEY.', 'wp2-shopify' ),
			default                  => __( 'paste the value without spaces or line breaks.', 'wp2-shopify' ),
		};
	}

	/**
	 * The non-secret behaviour settings (Settings::OPTION), saved through options.php.
	 */
	private static function behaviour(): void {
		$settings = Settings::all();
		$saved    = get_option( Settings::OPTION, [] );
		// Left by versions that tagged purchases themselves; the next save drops it.
		$old_map  = is_array( $saved ) ? trim( (string) ( $saved['membership_map'] ?? '' ) ) : '';
		?>
		<?php if ( '' !== $old_map ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'This plugin no longer adds or removes tags when an order is paid, cancelled or refunded. Tags it already added stay in Shopify. Set up the same rules in Shopify Flow (see docs/shopify-flow.md) before new orders arrive. Your old product map, for reference:', 'wp2-shopify' ); ?></p>
				<pre><?php echo esc_html( $old_map ); ?></pre>
			</div>
		<?php endif; ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'wp2_shopify' ); ?>
			<?php Menu::panel_start( __( 'Membership', 'wp2-shopify' ), __( 'How synced Shopify tags unlock content, and what happens on sign-out.', 'wp2-shopify' ) ); ?>
			<div class="wp2s-panel__body wp2s-panel__body--flush">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Membership tags', 'wp2-shopify' ); ?></th>
					<td>
						<p class="description"><?php esc_html_e( 'Customers get their tags in Shopify, from Shopify Flow or a subscription or membership app. This plugin only reads them. See docs/shopify-flow.md for recipes.', 'wp2-shopify' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Shared devices', 'wp2-shopify' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[clear_cart_on_logout]" value="1" <?php checked( ! empty( $settings['clear_cart_on_logout'] ) ); ?>> <?php esc_html_e( 'Clear the cart on sign-out', 'wp2-shopify' ); ?></label>
						<p class="description"><?php esc_html_e( 'With Shopify\'s plugin active, a cart signed in to a customer is always dropped after sign-out. This also drops guest items added while signed in.', 'wp2-shopify' ); ?></p>
					</td>
				</tr>
			</table>
			</div>
			<div class="wp2s-panel__footer">
				<?php submit_button( null, 'primary', 'submit', false ); ?>
			</div>
			<?php Menu::panel_end(); ?>
		</form>
		<?php
	}
}
