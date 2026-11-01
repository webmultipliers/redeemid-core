<?php

namespace RedeemID\Preferences;

use RedeemID\Api\Events;
use RedeemID\Settings;
use RedeemID\Shopify\Admin;
use RedeemID\Support\Logger;
use RedeemID\Sync\Mirror;

/**
 * Member preferences: customer metafields the site owner lets members see
 * or change, read from and written to Shopify.
 *
 * The owner defines each field in Shopify (Settings → Custom data →
 * Customers), which sets its name, type and choices, then picks under
 * RedeemID → Settings which fields members may see or change. This class is
 * the only way in, for redeemid_preferences(), redeemid_set_preferences() and
 * the /account/preferences REST route. It ships no form: a theme, a forms
 * plugin or a headless front end renders the fields its own way.
 *
 * - Shopify holds the answers. WordPress keeps no copy, so a privacy export
 *   or erasure has nothing extra to cover.
 * - A member only ever reads and writes their own customer: the owner ID
 *   comes from the mirror, never from the request.
 * - Only fields set to "Members can change" can be written, values are checked
 *   against the Shopify definition first, and the membership namespace (start,
 *   renewal and gift dates) can never be offered. Preferences open nothing.
 */
final class Preferences {
	public const SEE    = 'see';
	public const CHANGE = 'change';

	/** RedeemID reads membership dates from this namespace: never member-editable. */
	public const RESERVED_NAMESPACE = 'membership';

	/** Definition types members can see and change, and how they are sent. */
	public const TYPES = [
		'single_line_text_field',
		'multi_line_text_field',
		'list.single_line_text_field',
		'boolean',
		'number_integer',
		'number_decimal',
		'date',
	];

	private const CACHE      = 'redeemid_customer_definitions';
	private const PER_MINUTE = 10;
	private const TEXT_MAX   = 5000;

	public const DEFINITIONS_QUERY = 'query RedeemIDCustomerDefinitions { metafieldDefinitions(ownerType: CUSTOMER, first: 100) { nodes { name namespace key description type { name } validations { name value } } } }';

	public const VALUES_QUERY = 'query RedeemIDPreferences($id: ID!, $keys: [String!]) { customer(id: $id) { metafields(first: 50, keys: $keys) { nodes { namespace key value } } } }';

	public const SET_MUTATION = 'mutation RedeemIDSetPreferences($metafields: [MetafieldsSetInput!]!) { metafieldsSet(metafields: $metafields) { metafields { namespace key } userErrors { field message } } }';

	public const DELETE_MUTATION = 'mutation RedeemIDClearPreferences($metafields: [MetafieldIdentifierInput!]!) { metafieldsDelete(metafields: $metafields) { deletedMetafields { namespace key } userErrors { field message } } }';

	/**
	 * The shop's customer metafield definitions, cached for an hour. $fresh
	 * asks Shopify again, as the settings page does.
	 *
	 * @return array<string, array>|\WP_Error Keyed by namespace.key.
	 */
	public static function definitions( bool $fresh = false ): array|\WP_Error {
		$cached = $fresh ? false : get_transient( self::CACHE );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data = Admin::query( self::DEFINITIONS_QUERY, [], 'admin.customer_definitions' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$definitions = self::parse_definitions( $data );
		set_transient( self::CACHE, $definitions, HOUR_IN_SECONDS );

		return $definitions;
	}

	/**
	 * Definitions from the Admin API response. Pure.
	 *
	 * @return array<string, array{key: string, name: string, description: string, type: string, choices: string[], min: ?string, max: ?string, supported: bool}>
	 */
	public static function parse_definitions( array $data ): array {
		$out = [];

		foreach ( (array) ( $data['metafieldDefinitions']['nodes'] ?? [] ) as $node ) {
			if ( ! is_array( $node ) || empty( $node['namespace'] ) || empty( $node['key'] ) ) {
				continue;
			}

			$key         = $node['namespace'] . '.' . $node['key'];
			$type        = (string) ( $node['type']['name'] ?? '' );
			$validations = [];

			foreach ( (array) ( $node['validations'] ?? [] ) as $validation ) {
				if ( is_array( $validation ) && isset( $validation['name'] ) ) {
					$validations[ (string) $validation['name'] ] = (string) ( $validation['value'] ?? '' );
				}
			}

			$choices = isset( $validations['choices'] ) ? json_decode( $validations['choices'], true ) : [];

			$out[ $key ] = [
				'key'         => $key,
				'name'        => (string) ( $node['name'] ?? $key ),
				'description' => (string) ( $node['description'] ?? '' ),
				'type'        => $type,
				'choices'     => is_array( $choices ) ? array_values( array_filter( $choices, 'is_string' ) ) : [],
				'min'         => $validations['min'] ?? null,
				'max'         => $validations['max'] ?? null,
				'supported'   => in_array( $type, self::TYPES, true ) && ! self::reserved( $key ),
			];
		}

		return $out;
	}

	public static function reserved( string $key ): bool {
		return str_starts_with( $key, self::RESERVED_NAMESPACE . '.' );
	}

	/**
	 * The member_fields setting from a form post: namespace.key => see|change.
	 * Hidden fields are left out. Pure.
	 *
	 * @return array<string, string>
	 */
	public static function clean_modes( mixed $input ): array {
		$out = [];

		foreach ( is_array( $input ) ? $input : [] as $key => $mode ) {
			$key = (string) $key;

			if ( preg_match( '/^[A-Za-z0-9_-]{2,255}\.[A-Za-z0-9_-]{2,64}$/', $key ) && ! self::reserved( $key ) && in_array( $mode, [ self::SEE, self::CHANGE ], true ) ) {
				$out[ $key ] = $mode;
			}
		}

		return $out;
	}

	/**
	 * The fields members get: the owner's choices joined with Shopify's
	 * definitions. A chosen field whose definition is gone or unsupported is
	 * left out. Pure.
	 *
	 * @param array<string, array>  $definitions
	 * @param array<string, string> $modes
	 * @return array<string, array> Keyed by namespace.key, each with 'editable'.
	 */
	public static function allowed( array $definitions, array $modes ): array {
		$out = [];

		foreach ( self::clean_modes( $modes ) as $key => $mode ) {
			if ( ! empty( $definitions[ $key ]['supported'] ) ) {
				$out[ $key ] = $definitions[ $key ] + [ 'editable' => self::CHANGE === $mode ];
			}
		}

		return $out;
	}

	/**
	 * @return array<string, array>|\WP_Error
	 */
	public static function fields(): array|\WP_Error {
		$modes = (array) Settings::get( 'member_fields' );

		if ( ! $modes ) {
			return [];
		}

		$definitions = self::definitions();

		return is_wp_error( $definitions ) ? $definitions : self::allowed( $definitions, $modes );
	}

	/**
	 * The member's fields with their current values from Shopify.
	 *
	 * @return array<int, array{key: string, name: string, description: string, type: string, choices: string[], min: ?string, max: ?string, editable: bool, value: mixed}>|\WP_Error
	 */
	public static function read( int $user_id ): array|\WP_Error {
		$customer = $user_id ? Mirror::customer_id( $user_id ) : null;

		if ( ! $customer ) {
			return new \WP_Error( 'redeemid_not_linked', __( 'Sign in with your Shopify customer account first.', 'redeemid' ), [ 'status' => 403 ] );
		}

		$fields = self::fields();

		if ( is_wp_error( $fields ) || ! $fields ) {
			return $fields;
		}

		$data = Admin::query( self::VALUES_QUERY, [ 'id' => $customer->gid(), 'keys' => array_keys( $fields ) ], 'admin.preferences' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return self::present( $fields, self::parse_values( $data ) );
	}

	/**
	 * Raw metafield values from the Admin API response. Pure.
	 *
	 * @return array<string, string> Keyed by namespace.key.
	 */
	public static function parse_values( array $data ): array {
		$out = [];

		foreach ( (array) ( $data['customer']['metafields']['nodes'] ?? [] ) as $node ) {
			if ( is_array( $node ) && isset( $node['namespace'], $node['key'], $node['value'] ) ) {
				$out[ $node['namespace'] . '.' . $node['key'] ] = (string) $node['value'];
			}
		}

		return $out;
	}

	/**
	 * Fields as a list, in the owner's order, each with its decoded value. Pure.
	 *
	 * @param array<string, array>  $fields
	 * @param array<string, string> $raw
	 */
	public static function present( array $fields, array $raw ): array {
		$out = [];

		foreach ( $fields as $key => $field ) {
			unset( $field['supported'] );
			$out[] = $field + [ 'value' => self::decode( $field['type'], $raw[ $key ] ?? null ) ];
		}

		return $out;
	}

	/**
	 * A stored value as PHP: a list for lists, a bool, an int, or a string;
	 * null when unset. Pure.
	 */
	public static function decode( string $type, ?string $raw ): mixed {
		if ( null === $raw ) {
			return 'list.single_line_text_field' === $type ? [] : null;
		}

		return match ( $type ) {
			'list.single_line_text_field' => array_values( array_filter( (array) json_decode( $raw, true ), 'is_string' ) ),
			'boolean'                     => 'true' === $raw,
			'number_integer'              => (int) $raw,
			default                       => $raw,
		};
	}

	/**
	 * Check submitted values against the fields. Pure.
	 *
	 * An empty value (null, '' or an empty list) clears the field; false is a
	 * value for a true-or-false field.
	 *
	 * @param array<string, array> $fields From allowed().
	 * @param array<string, mixed> $values namespace.key => value.
	 * @return array{set: array<string, array{type: string, value: string}>, clear: string[], errors: array<string, string>}
	 */
	public static function validate( array $fields, array $values ): array {
		$set    = [];
		$clear  = [];
		$errors = [];

		foreach ( $values as $key => $value ) {
			$key   = (string) $key;
			$field = $fields[ $key ] ?? null;

			if ( null === $field ) {
				$errors[ $key ] = 'unknown_field';
				continue;
			}

			if ( empty( $field['editable'] ) ) {
				$errors[ $key ] = 'read_only';
				continue;
			}

			if ( null === $value || '' === $value || [] === $value ) {
				$clear[] = $key;
				continue;
			}

			$encoded = self::encode( $field, $value );

			if ( str_starts_with( $encoded[0], 'invalid' ) ) {
				$errors[ $key ] = $encoded[0];
				continue;
			}

			$set[ $key ] = [ 'type' => $field['type'], 'value' => $encoded[1] ];
		}

		return [ 'set' => $set, 'clear' => $clear, 'errors' => $errors ];
	}

	/**
	 * One value as Shopify stores it: ['ok', string] or ['invalid_…', '']. Pure.
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function encode( array $field, mixed $value ): array {
		$choices = (array) ( $field['choices'] ?? [] );
		$min     = $field['min'] ?? null;
		$max     = $field['max'] ?? null;

		switch ( $field['type'] ) {
			case 'single_line_text_field':
			case 'multi_line_text_field':
				if ( ! is_scalar( $value ) ) {
					return [ 'invalid_type', '' ];
				}

				$text  = trim( (string) $value );
				$limit = is_numeric( $max ) ? (int) $max : self::TEXT_MAX;

				if ( 'single_line_text_field' === $field['type'] && preg_match( '/[\r\n]/', $text ) ) {
					return [ 'invalid_type', '' ];
				}

				if ( $choices && ! in_array( $text, $choices, true ) ) {
					return [ 'invalid_choice', '' ];
				}

				if ( mb_strlen( $text ) > $limit || ( is_numeric( $min ) && mb_strlen( $text ) < (int) $min ) ) {
					return [ 'invalid_length', '' ];
				}

				return [ 'ok', $text ];

			case 'list.single_line_text_field':
				if ( ! is_array( $value ) ) {
					return [ 'invalid_type', '' ];
				}

				$list = [];

				foreach ( $value as $item ) {
					if ( ! is_scalar( $item ) || '' === trim( (string) $item ) || preg_match( '/[\r\n]/', (string) $item ) ) {
						return [ 'invalid_type', '' ];
					}

					$item = trim( (string) $item );

					if ( $choices && ! in_array( $item, $choices, true ) ) {
						return [ 'invalid_choice', '' ];
					}

					$list[ $item ] = $item;
				}

				return count( $list ) > 50 ? [ 'invalid_length', '' ] : [ 'ok', (string) json_encode( array_values( $list ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ]; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure, runs in unit tests.

			case 'boolean':
				if ( ! is_bool( $value ) && ! in_array( $value, [ 0, 1, '0', '1', 'true', 'false' ], true ) ) {
					return [ 'invalid_type', '' ];
				}

				return [ 'ok', in_array( $value, [ true, 1, '1', 'true' ], true ) ? 'true' : 'false' ];

			case 'number_integer':
			case 'number_decimal':
				$pattern = 'number_integer' === $field['type'] ? '/^-?\d{1,18}$/' : '/^-?\d{1,18}(\.\d{1,9})?$/';

				if ( ! is_scalar( $value ) || ! preg_match( $pattern, trim( (string) $value ) ) ) {
					return [ 'invalid_type', '' ];
				}

				$number = trim( (string) $value );

				if ( ( is_numeric( $min ) && (float) $number < (float) $min ) || ( is_numeric( $max ) && (float) $number > (float) $max ) ) {
					return [ 'invalid_range', '' ];
				}

				return [ 'ok', $number ];

			case 'date':
				if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
					return [ 'invalid_type', '' ];
				}

				if ( ( is_string( $min ) && '' !== $min && $value < $min ) || ( is_string( $max ) && '' !== $max && $value > $max ) ) {
					return [ 'invalid_range', '' ];
				}

				return [ 'ok', $value ];
		}

		return [ 'invalid_type', '' ];
	}

	/**
	 * Write the member's own values to Shopify, then read them back.
	 *
	 * @param array<string, mixed> $values namespace.key => value.
	 * @return array|\WP_Error The fields, as read() returns them.
	 */
	public static function write( int $user_id, array $values ): array|\WP_Error {
		$customer = $user_id ? Mirror::customer_id( $user_id ) : null;

		if ( ! $customer ) {
			return new \WP_Error( 'redeemid_not_linked', __( 'Sign in with your Shopify customer account first.', 'redeemid' ), [ 'status' => 403 ] );
		}

		if ( ! self::within_rate_limit( $user_id ) ) {
			return new \WP_Error( 'redeemid_preferences_busy', __( 'Too many changes. Please wait a minute and try again.', 'redeemid' ), [ 'status' => 429 ] );
		}

		$fields = self::fields();

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$checked = self::validate( $fields, $values );

		if ( $checked['errors'] ) {
			return new \WP_Error( 'redeemid_invalid_preferences', __( 'Some answers could not be saved.', 'redeemid' ), [ 'status' => 400, 'fields' => $checked['errors'] ] );
		}

		$owner = $customer->gid();

		if ( $checked['set'] ) {
			$result = self::mutate( 'metafieldsSet', self::SET_MUTATION, self::set_input( $owner, $checked['set'] ) );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( $checked['clear'] ) {
			$result = self::mutate( 'metafieldsDelete', self::DELETE_MUTATION, self::identifiers( $owner, $checked['clear'] ) );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$keys = [ ...array_keys( $checked['set'] ), ...$checked['clear'] ];

		if ( $keys ) {
			Logger::log( 'preferences.saved', [ 'user_id' => $user_id, 'fields' => $keys ] );
			Events::emit( Events::PREFERENCES_SAVED, [ 'fields' => implode( ',', $keys ), 'user_id' => $user_id ] );
		}

		return self::read( $user_id );
	}

	/**
	 * metafieldsSet input for one owner. Pure.
	 *
	 * @param array<string, array{type: string, value: string}> $set
	 */
	public static function set_input( string $owner, array $set ): array {
		$out = [];

		foreach ( $set as $key => $field ) {
			[ $namespace, $name ] = explode( '.', $key, 2 );
			$out[]                = [ 'ownerId' => $owner, 'namespace' => $namespace, 'key' => $name, 'type' => $field['type'], 'value' => $field['value'] ];
		}

		return $out;
	}

	/**
	 * metafieldsDelete identifiers for one owner. Pure.
	 *
	 * @param string[] $keys
	 */
	public static function identifiers( string $owner, array $keys ): array {
		return array_map(
			static function ( string $key ) use ( $owner ): array {
				[ $namespace, $name ] = explode( '.', $key, 2 );

				return [ 'ownerId' => $owner, 'namespace' => $namespace, 'key' => $name ];
			},
			array_values( $keys )
		);
	}

	private static function mutate( string $field, string $mutation, array $metafields ): true|\WP_Error {
		$data = Admin::query( $mutation, [ 'metafields' => $metafields ], 'admin.' . $field );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$errors = $data[ $field ]['userErrors'] ?? [];

		return $errors ? new \WP_Error( 'shopify_user_error', (string) ( $errors[0]['message'] ?? 'Shopify refused the change.' ), [ 'status' => 400 ] ) : true;
	}

	private static function within_rate_limit( int $user_id ): bool {
		$key = 'redeemid_prefs_' . $user_id;
		$n   = (int) get_transient( $key );

		if ( $n >= self::PER_MINUTE ) {
			return false;
		}

		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );

		return true;
	}
}
