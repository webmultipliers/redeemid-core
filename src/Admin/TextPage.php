<?php

namespace RedeemID\Admin;

use RedeemID\Strings\Catalog;
use RedeemID\Strings\Overrides;
use RedeemID\Strings\Text;

/**
 * RedeemID → Settings → Text: replace any of RedeemID's strings with the site's own
 * words ("Account" → "Portal"), for every language or for one.
 *
 * The list is read from the bundled translation template, so it holds every
 * string the plugin can show: in blocks, sign-in, paywall messages, store
 * pages, privacy text and the admin. Replacements sit on top of translation:
 * a string with no replacement keeps its translation.
 */
final class TextPage {
	public const ACTION = 'redeemid_save_text';

	/** Per user: what the last save refused, so the fields can show it. */
	private const ERRORS = 'redeemid_text_errors_';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'save' ] );
	}

	/**
	 * The Text tab of RedeemID → Settings.
	 */
	public static function url( array $args = [] ): string {
		return Menu::url( SettingsPage::PAGE, [ 'tab' => 'text' ] + $args );
	}

	/**
	 * Area slug => label, in the order the filter lists them.
	 *
	 * @return array<string, string>
	 */
	public static function areas(): array {
		return [
			'blocks'  => __( 'Blocks on the site', 'redeemid' ),
			'signin'  => __( 'Sign-in and account', 'redeemid' ),
			'access'  => __( 'Paywall and access', 'redeemid' ),
			'store'   => __( 'Store pages and cart', 'redeemid' ),
			'privacy' => __( 'Privacy', 'redeemid' ),
			'editor'  => __( 'Block editor', 'redeemid' ),
			'admin'   => __( 'Admin screens and emails', 'redeemid' ),
			'plugin'  => __( 'Plugins screen', 'redeemid' ),
		];
	}

	/**
	 * Languages a replacement can be set for: '' (every language), then the
	 * site's installed languages.
	 *
	 * @return array<string, string> Locale => label.
	 */
	public static function languages(): array {
		$out = [ Overrides::EVERY => __( 'Every language', 'redeemid' ) ];

		foreach ( array_unique( array_merge( [ get_locale(), 'en_US' ], get_available_languages() ) ) as $locale ) {
			$name           = function_exists( 'locale_get_display_name' ) ? (string) locale_get_display_name( $locale, $locale ) : '';
			$out[ $locale ] = '' !== $name ? sprintf( '%s (%s)', $name, $locale ) : $locale;
		}

		return $out;
	}

	/**
	 * The list's filters, from the query string (or the save form).
	 *
	 * @return array{lang: string, s: string, audience: string, area: string, view: string}
	 */
	public static function filters( ?array $source = null ): array {
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only filters.
		$source   = $source ?? $_GET;
		$lang     = sanitize_text_field( wp_unslash( (string) ( $source['lang'] ?? '' ) ) );
		$audience = sanitize_key( wp_unslash( (string) ( $source['audience'] ?? '' ) ) );
		$area     = sanitize_key( wp_unslash( (string) ( $source['area'] ?? '' ) ) );
		$view     = sanitize_key( wp_unslash( (string) ( $source['view'] ?? '' ) ) );

		return [
			'lang'     => array_key_exists( $lang, self::languages() ) ? $lang : Overrides::EVERY,
			's'        => trim( sanitize_text_field( wp_unslash( (string) ( $source['s'] ?? '' ) ) ) ),
			'audience' => in_array( $audience, [ Catalog::MEMBERS, Catalog::STAFF ], true ) ? $audience : '',
			'area'     => array_key_exists( $area, self::areas() ) ? $area : '',
			'view'     => in_array( $view, [ 'changed', 'unused' ], true ) ? $view : '',
		];
	}

	/**
	 * The filters as query args, without the empty ones.
	 *
	 * @param array<string, string> $f
	 * @return array<string, string>
	 */
	private static function args( array $f ): array {
		return array_filter( $f, static fn( $v ) => '' !== $v );
	}

	public static function save(): void {
		Menu::guard( self::ACTION );
		// phpcs:disable WordPress.Security.NonceVerification -- checked by Menu::guard().
		$f     = self::filters( $_POST );
		$keys  = (array) wp_unslash( $_POST['keys'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- keys are the original strings, matched as-is.
		$texts = (array) wp_unslash( $_POST['text'] ?? [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Text::save() cleans each value against its original.
		$paged = absint( $_POST['paged'] ?? 1 );
		// phpcs:enable WordPress.Security.NonceVerification
		$changes = [];

		foreach ( $keys as $hash => $key ) {
			if ( is_string( $key ) && md5( $key ) === $hash ) {
				$value           = $texts[ $hash ] ?? '';
				$changes[ $key ] = is_array( $value ) ? array_map( 'strval', array_slice( array_values( $value ), 0, 2 ) ) : (string) $value;
			}
		}

		$refused = Text::save( $f['lang'], $changes );
		$errors  = [];

		foreach ( $refused as $key => $code ) {
			$errors[ $key ] = [ 'error' => $code, 'value' => $changes[ $key ] ];
		}

		if ( $errors ) {
			set_transient( self::ERRORS . get_current_user_id(), $errors, 10 * MINUTE_IN_SECONDS );
		}

		$args = self::args( $f ) + [ 'result' => $errors ? 'text_invalid' : 'text_saved', 'n' => count( $errors ) ];

		if ( $paged > 1 ) {
			$args['paged'] = $paged;
		}

		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Why a replacement was refused, for its field.
	 */
	public static function problem_text( string $code, string $hint ): string {
		return match ( $code ) {
			'percent'     => __( 'Not saved: a percent sign on its own breaks this text. Write %% for one.', 'redeemid' ),
			/* translators: %s: the placeholders the original has, such as %s or %1$d */
			'placeholder' => '' !== $hint ? sprintf( __( 'Not saved: use only the placeholders the original has (%s), with the same letters.', 'redeemid' ), $hint ) : __( 'Not saved: this text has a placeholder the original doesn\'t have.', 'redeemid' ),
			'long'        => __( 'Not saved: keep it under 2,000 characters.', 'redeemid' ),
			'markup'      => __( 'Not saved: only the HTML the original uses is allowed.', 'redeemid' ),
			default       => __( 'Not saved.', 'redeemid' ),
		};
	}

	public static function render(): void {
		$f      = self::filters();
		$errors = get_transient( self::ERRORS . get_current_user_id() );
		$errors = is_array( $errors ) ? $errors : [];
		delete_transient( self::ERRORS . get_current_user_id() );

		$table = new TextTable( $errors );
		$table->prepare_items();

		Menu::result_notice(
			[
				'text_saved'   => [ 'success', __( 'Text saved.', 'redeemid' ) ],
				'text_invalid' => [ 'error', __( 'The rest was saved, but the replacements marked below were not.', 'redeemid' ) ],
			]
		);

		if ( Text::managed_in_code() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Code on this site also sets replacements (the redeemid_string_overrides filter). Where it sets one, the code\'s text is what shows.', 'redeemid' ) . '</p></div>';
		}

		if ( ! Catalog::all() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The list of strings (languages/redeemid.pot) is missing, so there is nothing to show. Reinstall the plugin.', 'redeemid' ) . '</p></div>';
		}

		$languages = self::languages();
		?>
		<form method="get" id="redeemid-text-filters" class="redeemid-text-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( SettingsPage::PAGE ); ?>">
			<input type="hidden" name="tab" value="text">
			<?php foreach ( array_intersect_key( self::args( $f ), [ 'view' => 1, 's' => 1 ] ) as $key => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<?php if ( count( $languages ) > 2 || '' !== $f['lang'] ) : ?>
				<p class="redeemid-inline-form">
					<label for="redeemid-text-lang"><?php esc_html_e( 'Replacements for', 'redeemid' ); ?></label>
					<select name="lang" id="redeemid-text-lang">
						<?php foreach ( $languages as $locale => $label ) : ?>
							<option value="<?php echo esc_attr( $locale ); ?>" <?php selected( $f['lang'], $locale ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Switch', 'redeemid' ); ?></button>
					<span class="description"><?php esc_html_e( 'A replacement for one language wins over one for every language.', 'redeemid' ); ?></span>
				</p>
			<?php else : ?>
				<input type="hidden" name="lang" value="<?php echo esc_attr( $f['lang'] ); ?>">
			<?php endif; ?>
		</form>
		<form method="get" class="redeemid-text-search">
			<?php foreach ( self::args( [ 'page' => SettingsPage::PAGE, 'tab' => 'text' ] + $f ) as $key => $value ) : ?>
				<?php if ( 's' !== $key ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
				<?php endif; ?>
			<?php endforeach; ?>
			<?php $table->search_box( __( 'Search text', 'redeemid' ), 'redeemid-text' ); ?>
		</form>
		<?php $table->views(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="redeemid-text-form-table">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="paged" value="<?php echo (int) $table->get_pagenum(); ?>">
			<?php foreach ( self::args( $f ) as $key => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<?php $table->display(); ?>
			<?php // After the table: the list table prints its own nonce field, and the last one wins. ?>
			<?php wp_nonce_field( self::ACTION ); ?>
			<?php if ( $table->has_items() ) : ?>
				<p class="submit">
					<?php submit_button( __( 'Save text on this page', 'redeemid' ), 'primary', 'submit', false ); ?>
				</p>
			<?php endif; ?>
		</form>
		<?php
	}
}
