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
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Shopify settings', 'wp2-shopify' ); ?></h1>
			<?php
			Menu::result_notice(
				[
					'config_saved'   => [ 'success', __( 'Connection saved. %d values changed.', 'wp2-shopify' ) ],
					'config_invalid' => [ 'error', __( 'Some values were not saved. They are marked below.', 'wp2-shopify' ) ],
				]
			);
			?>

			<h2><?php esc_html_e( 'Shopify connection', 'wp2-shopify' ); ?></h2>
			<p class="description" style="max-width:820px">
				<?php esc_html_e( 'Enter the store\'s credentials here, or define them as constants in wp-config.php. A constant always wins and its field is locked here; that is the more secure choice, because the value then never touches the database. Secrets saved here are encrypted with a key from the WordPress salts (or WP2_SHOPIFY_ENCRYPTION_KEY) and are never shown again: leave a secret blank to keep it.', 'wp2-shopify' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( Config::definitions() as $name => $def ) : ?>
						<?php self::field( $name, $def, $invalid[ $name ] ?? '' ); ?>
					<?php endforeach; ?>
				</table>
				<?php submit_button( __( 'Save connection', 'wp2-shopify' ) ); ?>
			</form>

			<?php self::behaviour(); ?>
		</div>
		<?php
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
					<span style="color:#d63638" title="<?php esc_attr_e( 'Required', 'wp2-shopify' ); ?>">*</span>
				<?php endif; ?>
			</th>
			<td>
				<?php if ( $locked || ! $editable ) : ?>
					<input id="<?php echo esc_attr( $id ); ?>" class="regular-text code" type="text" disabled
						value="<?php echo esc_attr( $secret ? ( ConfigValues::CONSTANT === $source ? '••••••••••••' : '' ) : Config::get( $name ) ); ?>"
						placeholder="<?php echo esc_attr( $locked ? __( '(empty)', 'wp2-shopify' ) : __( 'Not set', 'wp2-shopify' ) ); ?>">
					<p class="description">
						<span class="dashicons dashicons-lock" aria-hidden="true" style="font-size:16px;width:16px;height:16px;vertical-align:text-bottom"></span>
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
					<input id="<?php echo esc_attr( $id ); ?>" class="regular-text code" type="password" name="<?php echo esc_attr( $field ); ?>" value="" autocomplete="new-password" spellcheck="false"
						placeholder="<?php echo esc_attr( ConfigValues::SETTINGS === $source ? __( 'Saved. Leave blank to keep it.', 'wp2-shopify' ) : __( 'Not set', 'wp2-shopify' ) ); ?>"
						<?php echo $invalid ? 'aria-invalid="true" style="border-color:#d63638"' : ''; ?>>
					<?php if ( ConfigValues::NONE !== $source ) : ?>
						<label style="margin-left:8px"><input type="checkbox" name="clear[]" value="<?php echo esc_attr( $name ); ?>"> <?php esc_html_e( 'Remove the saved value', 'wp2-shopify' ); ?></label>
					<?php endif; ?>
					<?php if ( ConfigValues::UNREADABLE === $source ) : ?>
						<p class="description" style="color:#d63638"><?php esc_html_e( 'A value is saved but can no longer be decrypted, so it is ignored. The WordPress salts or WP2_SHOPIFY_ENCRYPTION_KEY changed since it was saved. Enter it again.', 'wp2-shopify' ); ?></p>
					<?php endif; ?>
				<?php else : ?>
					<input id="<?php echo esc_attr( $id ); ?>" class="regular-text code" type="text" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( Config::get( $name ) ); ?>" spellcheck="false"
						<?php echo 'WP2_SHOPIFY_STORE_DOMAIN' === $name ? 'placeholder="your-store.myshopify.com"' : ''; ?>
						<?php echo $invalid ? 'aria-invalid="true" style="border-color:#d63638"' : ''; ?>>
				<?php endif; ?>
				<?php if ( $invalid ) : ?>
					<p class="description" style="color:#d63638"><?php esc_html_e( 'Not saved:', 'wp2-shopify' ); ?> <?php echo esc_html( self::reason( $invalid ) ); ?></p>
				<?php endif; ?>
				<p class="description" style="max-width:640px"><?php echo esc_html( $def['help'] ); ?> <code style="font-size:11px"><?php echo esc_html( $name ); ?></code></p>
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
		?>
		<h2><?php esc_html_e( 'Membership', 'wp2-shopify' ); ?></h2>
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
				<tr>
					<th><?php esc_html_e( 'Shared devices', 'wp2-shopify' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[clear_cart_on_logout]" value="1" <?php checked( ! empty( $settings['clear_cart_on_logout'] ) ); ?>> <?php esc_html_e( 'Clear the cart on sign-out', 'wp2-shopify' ); ?></label>
						<p class="description"><?php esc_html_e( 'With Shopify\'s plugin active, a cart signed in to a customer is always dropped after sign-out. This also drops guest items added while signed in.', 'wp2-shopify' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}
}
