<?php

namespace RedeemID\Admin;

use RedeemID\Config;
use RedeemID\Shopify\AdminToken;
use RedeemID\Shopify\Discovery;
use RedeemID\Shopify\Domains;
use RedeemID\Support\ConfigValues;
use RedeemID\Support\Logger;
use RedeemID\Webhooks\Registrar;

/**
 * RedeemID → Connection: the Shopify credentials, one tab per part of the
 * connection (store, customer sign-in, Admin API, Storefront API, encryption).
 *
 * Every Config::definitions() value can be saved here, in the options table,
 * so a site can be set up without editing wp-config.php. A constant of the
 * same name always wins, and its field shows as locked. Secrets are encrypted
 * before they are stored, are write-only (the field is always empty, a blank
 * submission keeps what is stored) and are never printed back. Each tab saves
 * only its own fields.
 */
final class ConnectionPage {
	public const PAGE   = 'redeemid-connection';
	public const ACTION = 'redeemid_save_config';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'save' ] );
	}

	/**
	 * The page's tabs: slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		return array_map( static fn( $section ) => $section[0], self::sections() );
	}

	public static function render(): void {
		$sections = self::sections();
		$tab      = Menu::tab( self::tabs() );
		[ $label, $intro, $names ] = $sections[ $tab ];
		$invalid  = self::invalid_fields();

		Menu::page_start( __( 'Connection', 'redeemid' ), __( 'Connection', 'redeemid' ) );
		Menu::tabs( self::PAGE, self::tabs(), $tab, __( 'Connection sections', 'redeemid' ) );
		echo '<p class="redeemid-intro">' . esc_html__( 'Enter the store\'s credentials here, or define them as constants in wp-config.php. A constant always wins and its field is locked here; that is the more secure choice, because the value then never touches the database. Secrets saved here are encrypted and never shown again: leave a secret blank to keep it.', 'redeemid' ) . '</p>';
		Menu::result_notice(
			[
				/* translators: %d: number of values changed */
				'config_saved'   => [ 'success', __( 'Connection saved. %d values changed.', 'redeemid' ) ],
				'config_invalid' => [ 'error', __( 'Some values were not saved. They are marked below.', 'redeemid' ) ],
			]
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<?php Menu::panel_start( $label, $intro ); ?>
			<div class="redeemid-panel__body redeemid-panel__body--flush">
				<table class="form-table" role="presentation">
					<?php foreach ( $names as $name ) : ?>
						<?php self::field( $name, Config::definitions()[ $name ], $invalid[ $name ] ?? '' ); ?>
					<?php endforeach; ?>
				</table>
			</div>
			<div class="redeemid-panel__footer">
				<?php submit_button( __( 'Save connection', 'redeemid' ), 'primary', 'submit', false ); ?>
			</div>
			<?php Menu::panel_end(); ?>
		</form>
		<?php
		Menu::page_end();
	}

	public static function save(): void {
		Menu::guard( self::ACTION );
		$tab  = sanitize_key( wp_unslash( $_POST['tab'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked by Menu::guard().
		$args = self::result_args( self::store_posted() );
		wp_safe_redirect( Menu::tab_url( self::PAGE, self::tabs(), $tab, $args ) );
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
	 * The config values in tabs, in Config::definitions() order: slug =>
	 * [ label, intro, names ]. A value in no group gets an Other tab.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string[]}>
	 */
	private static function sections(): array {
		$groups = [
			'store'      => [ __( 'Store', 'redeemid' ), __( 'Which Shopify store this site belongs to. Its web address works too: RedeemID saves the myshopify.com domain it answers to.', 'redeemid' ), [ 'REDEEMID_STORE_DOMAIN' ] ],
			'sign-in'    => [ __( 'Customer sign-in', 'redeemid' ), __( 'The Customer Account API client that members sign in with, from the Headless or Hydrogen channel in Shopify.', 'redeemid' ), [ 'REDEEMID_CLIENT_ID', 'REDEEMID_CLIENT_SECRET' ] ],
			'admin-api'  => [ __( 'Admin API', 'redeemid' ), __( 'The Admin app\'s credentials, from the Dev Dashboard. RedeemID reads customers and tags with them, and checks webhook signatures with the secret.', 'redeemid' ), [ 'REDEEMID_ADMIN_CLIENT_ID', 'REDEEMID_ADMIN_CLIENT_SECRET' ] ],
			'storefront' => [ __( 'Storefront API', 'redeemid' ), __( 'The Storefront API tokens for product, collection and cart pages.', 'redeemid' ), [ 'REDEEMID_PUBLIC_ACCESS_TOKEN', 'REDEEMID_STOREFRONT_PRIVATE_TOKEN' ] ],
			'encryption' => [ __( 'Encryption', 'redeemid' ), __( 'The key secrets saved here are encrypted with. Without one, a key is made from the WordPress salts.', 'redeemid' ), [ 'REDEEMID_ENCRYPTION_KEY' ] ],
		];
		$all    = array_keys( Config::definitions() );
		$placed = [];
		$out    = [];

		foreach ( $groups as $slug => [ $label, $intro, $names ] ) {
			$names = array_values( array_intersect( $names, $all ) );

			if ( $names ) {
				$out[ $slug ] = [ $label, $intro, $names ];
				$placed       = array_merge( $placed, $names );
			}
		}

		$rest = array_values( array_diff( $all, $placed ) );

		if ( $rest ) {
			$out['other'] = [ __( 'Other', 'redeemid' ), '', $rest ];
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
}
