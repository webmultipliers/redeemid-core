<?php

namespace RedeemID\Blocks;

use RedeemID\Auth\Flow;
use RedeemID\Preferences\Preferences;

/**
 * The Member Preferences block (blocks/member-preferences): the fields an
 * owner lets members see or change, as one form.
 *
 * A member's form posts to admin-post.php, so it works without JavaScript;
 * script.view.js saves it through /account/preferences instead when it can.
 * A guest's form is filled in the browser only: script.view.js holds the
 * answers there (redeemid.holdPreferences) and, once the visitor has signed
 * in and is linked, shows them in the member's form to confirm. Nothing about
 * a guest reaches WordPress. Saving goes through Preferences::write(), which
 * checks every value against the Shopify definition.
 */
final class PreferencesForm {
	public const ACTION = 'redeemid_save_preferences';
	public const NOTICE = 'redeemid_preferences';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * Posted form values as Preferences::write() takes them. Every field the
	 * form showed is named in `shown`, so an unticked box saves false and an
	 * empty list clears. Pure.
	 *
	 * @param array<string, array> $fields From Preferences::fields().
	 * @param array<string, mixed> $values The posted values[...] array.
	 * @param string[]             $shown  The posted shown[] list.
	 * @return array<string, mixed>
	 */
	public static function posted( array $fields, array $values, array $shown ): array {
		$out = [];

		foreach ( $shown as $key ) {
			$key   = (string) $key;
			$field = $fields[ $key ] ?? null;

			if ( ! $field || empty( $field['editable'] ) ) {
				continue;
			}

			$raw = $values[ $key ] ?? null;

			$out[ $key ] = match ( $field['type'] ) {
				'boolean'                     => ! empty( $raw ) && '0' !== $raw,
				'list.single_line_text_field' => self::listed( $raw ),
				default                       => is_scalar( $raw ) ? trim( (string) $raw ) : '',
			};
		}

		return $out;
	}

	/**
	 * A list from ticked boxes, or from a text box with one item per line. Pure.
	 *
	 * @return string[]
	 */
	private static function listed( mixed $raw ): array {
		$items = is_array( $raw ) ? $raw : preg_split( '/\R/', is_scalar( $raw ) ? (string) $raw : '' );

		return array_values( array_filter( array_map( static fn( $item ) => trim( (string) $item ), (array) $items ), 'strlen' ) );
	}

	public static function handle(): void {
		$back = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect_to'] ?? '' ) ), home_url( '/' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked below; only used to go back.

		if ( ! is_user_logged_in() || ! check_admin_referer( self::ACTION ) ) {
			wp_safe_redirect( add_query_arg( self::NOTICE, 'error', $back ) );
			exit;
		}

		$fields = Preferences::fields();
		$result = 'error';

		if ( is_array( $fields ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is checked against its Shopify definition in Preferences::write().
			$values = Preferences::write( get_current_user_id(), self::posted( $fields, (array) wp_unslash( $_POST['values'] ?? [] ), array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['shown'] ?? [] ) ) ) );
			$result = is_wp_error( $values ) ? self::code( $values ) : 'saved';
		}

		wp_safe_redirect( add_query_arg( self::NOTICE, $result, $back ) . '#redeemid-preferences' );
		exit;
	}

	private static function code( \WP_Error $error ): string {
		return match ( $error->get_error_code() ) {
			'redeemid_invalid_preferences' => 'invalid',
			'redeemid_preferences_busy'    => 'busy',
			default                        => 'error',
		};
	}

	/**
	 * What the form says after a save, by result code.
	 *
	 * @return array<string, string>
	 */
	public static function messages(): array {
		return [
			'saved'   => __( 'Saved to your account.', 'redeemid' ),
			'held'    => __( 'Saved in this browser. Sign in to keep them on your account.', 'redeemid' ),
			'confirm' => __( 'You answered these before you logged in. Check them, then save them to your account.', 'redeemid' ),
			'invalid' => __( 'Some answers could not be saved. Check the highlighted fields.', 'redeemid' ),
			'busy'    => __( 'Too many changes. Please wait a minute and try again.', 'redeemid' ),
			'error'   => __( 'Your preferences could not be saved. Try again later.', 'redeemid' ),
			'field'   => __( 'Check this answer.', 'redeemid' ),
		];
	}

	public static function login_url( string $back ): string {
		return Flow::login_url( $back );
	}

	/**
	 * One field's control. Read-only fields show their value as text.
	 *
	 * @param array{key: string, name: string, description: string, type: string, choices: string[], min: ?string, max: ?string} $field
	 */
	public static function control( array $field, mixed $value, bool $editable, string $prefix ): void {
		$id      = $prefix . sanitize_html_class( str_replace( '.', '-', $field['key'] ) );
		$name    = 'values[' . $field['key'] . ']';
		$type    = $field['type'];
		$choices = (array) ( $field['choices'] ?? [] );
		$help    = '' !== $field['description'] ? $id . '-help' : '';
		$common  = sprintf( ' data-redeemid-key="%s" data-redeemid-type="%s"%s', esc_attr( $field['key'] ), esc_attr( $type ), '' !== $help ? ' aria-describedby="' . esc_attr( $help ) . '"' : '' );
		$list    = 'list.single_line_text_field' === $type;

		if ( ! $editable ) {
			$shown = is_array( $value ) ? implode( ', ', $value ) : ( is_bool( $value ) ? ( $value ? __( 'Yes', 'redeemid' ) : __( 'No', 'redeemid' ) ) : (string) $value );
			?>
			<div class="redeemid-preferences__field is-read-only">
				<span class="redeemid-preferences__label"><?php echo esc_html( $field['name'] ); ?></span>
				<span class="redeemid-preferences__value"><?php echo esc_html( '' !== $shown ? $shown : '—' ); ?></span>
			</div>
			<?php
			return;
		}

		?>
		<input type="hidden" name="shown[]" value="<?php echo esc_attr( $field['key'] ); ?>">
		<?php if ( 'boolean' === $type ) : ?>
			<div class="redeemid-preferences__field is-check">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
				<label><input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( true === $value ); ?><?php echo $common; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>> <?php echo esc_html( $field['name'] ); ?></label>
				<?php self::help( $help, $field['description'] ); ?>
			</div>
		<?php elseif ( $choices && $list ) : ?>
			<fieldset class="redeemid-preferences__field is-choices"<?php echo $common; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>>
				<legend class="redeemid-preferences__label"><?php echo esc_html( $field['name'] ); ?></legend>
				<?php foreach ( $choices as $choice ) : ?>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $choice ); ?>" <?php checked( in_array( $choice, (array) $value, true ) ); ?>> <?php echo esc_html( $choice ); ?></label>
				<?php endforeach; ?>
				<?php self::help( $help, $field['description'] ); ?>
			</fieldset>
		<?php else : ?>
			<div class="redeemid-preferences__field">
				<label class="redeemid-preferences__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['name'] ); ?></label>
				<?php if ( $choices ) : ?>
					<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"<?php echo $common; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>>
						<option value=""><?php esc_html_e( 'Choose…', 'redeemid' ); ?></option>
						<?php foreach ( $choices as $choice ) : ?>
							<option value="<?php echo esc_attr( $choice ); ?>" <?php selected( (string) $value, $choice ); ?>><?php echo esc_html( $choice ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php elseif ( 'multi_line_text_field' === $type || $list ) : ?>
					<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="3"<?php echo $common; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>><?php echo esc_textarea( is_array( $value ) ? implode( "\n", $value ) : (string) $value ); ?></textarea>
					<?php if ( $list ) : ?>
						<span class="redeemid-preferences__hint"><?php esc_html_e( 'One per line.', 'redeemid' ); ?></span>
					<?php endif; ?>
				<?php else : ?>
					<?php
					$input = match ( $type ) {
						'number_integer', 'number_decimal' => 'number',
						'date'                             => 'date',
						default                            => 'text',
					};
					$limits = 'text' === $input
						? ( is_numeric( $field['max'] ) ? sprintf( ' maxlength="%d"', (int) $field['max'] ) : '' )
						: ( null !== $field['min'] && '' !== $field['min'] ? sprintf( ' min="%s"', esc_attr( (string) $field['min'] ) ) : '' ) . ( null !== $field['max'] && '' !== $field['max'] ? sprintf( ' max="%s"', esc_attr( (string) $field['max'] ) ) : '' );
					$step = 'number_decimal' === $type ? ' step="any"' : ( 'number_integer' === $type ? ' step="1"' : '' );
					?>
					<input type="<?php echo esc_attr( $input ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( is_scalar( $value ) ? (string) $value : '' ); ?>"<?php echo $limits . $step . $common; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>>
				<?php endif; ?>
				<?php self::help( $help, $field['description'] ); ?>
			</div>
		<?php endif; ?>
		<?php
	}

	private static function help( string $id, string $text ): void {
		if ( '' !== $id ) {
			printf( '<span class="redeemid-preferences__help" id="%s">%s</span>', esc_attr( $id ), esc_html( $text ) );
		}
	}
}
