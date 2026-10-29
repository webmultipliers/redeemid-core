<?php

namespace RedeemID\Admin;

use RedeemID\Access\Level;
use RedeemID\Access\LevelStore;
use RedeemID\Features\Registry;
use RedeemID\Files\ProtectedFiles;

/**
 * The "Who can download" field on a media item, in the media modal and on
 * the attachment's edit screen. Choosing anything but "Everyone" moves the
 * file into the protected folder; choosing "Everyone" moves it back.
 */
final class FileFields {
	private const FIELD = 'redeemid_file_access';

	public static function register(): void {
		add_filter( 'attachment_fields_to_edit', [ self::class, 'fields' ], 10, 2 );
		add_filter( 'attachment_fields_to_save', [ self::class, 'save' ], 10, 2 );
	}

	/**
	 * Choice value => label. Values: public, inherit, level:<slug>, feature:<slug>.
	 *
	 * @return array<string, string>
	 */
	public static function choices(): array {
		$choices = [
			'public'                  => __( 'Everyone (a normal public file)', 'redeemid' ),
			ProtectedFiles::INHERIT => __( 'Whoever can read the item it\'s attached to', 'redeemid' ),
		];

		foreach ( LevelStore::levels()->all() as $slug => $level ) {
			if ( ! $level->builtin() || Level::SIGNED_IN === $slug ) {
				/* translators: %s: level label */
				$choices[ 'level:' . $slug ] = sprintf( __( 'Members holding %s', 'redeemid' ), $level->label );
			}
		}

		foreach ( Registry::features() as $slug => $feature ) {
			/* translators: %s: feature label */
			$choices[ 'feature:' . $slug ] = sprintf( __( 'Members with the %s feature', 'redeemid' ), $feature->label );
		}

		return $choices;
	}

	/**
	 * A stored rule as a choice value. Pure.
	 *
	 * @param array{mode: string, levels: string[], feature: string}|null $rule
	 */
	public static function choice( ?array $rule ): string {
		return match ( $rule['mode'] ?? null ) {
			ProtectedFiles::INHERIT => ProtectedFiles::INHERIT,
			ProtectedFiles::LEVELS  => 'level:' . ( $rule['levels'][0] ?? '' ),
			ProtectedFiles::FEATURE => 'feature:' . $rule['feature'],
			default                 => 'public',
		};
	}

	/**
	 * A choice value as a rule, or null for a public file. Pure.
	 *
	 * @return array{mode: string, levels: string[], feature: string}|null
	 */
	public static function rule( string $choice ): ?array {
		[ $kind, $slug ] = array_pad( explode( ':', $choice, 2 ), 2, '' );

		return match ( $kind ) {
			ProtectedFiles::INHERIT => ProtectedFiles::sanitize( [ 'mode' => ProtectedFiles::INHERIT ] ),
			'level'                 => ProtectedFiles::sanitize( [ 'mode' => ProtectedFiles::LEVELS, 'levels' => [ $slug ] ] ),
			'feature'               => ProtectedFiles::sanitize( [ 'mode' => ProtectedFiles::FEATURE, 'feature' => $slug ] ),
			default                 => null,
		};
	}

	public static function fields( mixed $fields, mixed $post ): mixed {
		if ( ! is_array( $fields ) || ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $fields;
		}

		$current = self::choice( ProtectedFiles::rule( $post->ID ) );
		$name    = 'attachments[' . $post->ID . '][' . self::FIELD . ']';
		$html    = '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';

		foreach ( self::choices() as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		$html .= '</select>';

		$fields[ self::FIELD ] = [
			'label' => __( 'Who can download', 'redeemid' ),
			'input' => 'html',
			'html'  => $html,
			'helps' => ProtectedFiles::is_protected( $post->ID )
				? __( 'Protected: the file\'s address checks access on every download.', 'redeemid' )
				: __( 'Anything but Everyone moves the file to the protected folder, so its address checks access on every download.', 'redeemid' ),
		];

		return $fields;
	}

	public static function save( mixed $post, mixed $attachment ): mixed {
		if ( ! is_array( $post ) || ! is_array( $attachment ) || ! isset( $attachment[ self::FIELD ] ) || ! current_user_can( 'edit_post', (int) ( $post['ID'] ?? 0 ) ) ) {
			return $post;
		}

		$id     = (int) $post['ID'];
		$choice = sanitize_text_field( (string) $attachment[ self::FIELD ] );

		if ( ! array_key_exists( $choice, self::choices() ) || self::choice( ProtectedFiles::rule( $id ) ) === $choice ) {
			return $post;
		}

		$rule   = self::rule( $choice );
		$result = null === $rule ? ProtectedFiles::unprotect( $id ) : ProtectedFiles::protect( $id, $rule );

		if ( is_wp_error( $result ) ) {
			$post['errors'][ self::FIELD ]['errors'][] = $result->get_error_message();
		}

		return $post;
	}
}
