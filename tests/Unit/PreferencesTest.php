<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Api\Events;
use RedeemID\Preferences\Preferences;

final class PreferencesTest extends TestCase {
	private static function definitions(): array {
		return Preferences::parse_definitions(
			[
				'metafieldDefinitions' => [
					'nodes' => [
						[ 'name' => 'Topics', 'namespace' => 'preferences', 'key' => 'topics', 'description' => 'What you want', 'type' => [ 'name' => 'list.single_line_text_field' ], 'validations' => [ [ 'name' => 'choices', 'value' => '["Politics","Food","Travel"]' ] ] ],
						[ 'name' => 'Level', 'namespace' => 'preferences', 'key' => 'level', 'description' => '', 'type' => [ 'name' => 'single_line_text_field' ], 'validations' => [ [ 'name' => 'choices', 'value' => '["Beginner","Expert"]' ] ] ],
						[ 'name' => 'Shirt', 'namespace' => 'preferences', 'key' => 'note', 'description' => '', 'type' => [ 'name' => 'multi_line_text_field' ], 'validations' => [ [ 'name' => 'max', 'value' => '10' ] ] ],
						[ 'name' => 'Print', 'namespace' => 'preferences', 'key' => 'print', 'description' => '', 'type' => [ 'name' => 'boolean' ], 'validations' => [] ],
						[ 'name' => 'Kids', 'namespace' => 'preferences', 'key' => 'kids', 'description' => '', 'type' => [ 'name' => 'number_integer' ], 'validations' => [ [ 'name' => 'min', 'value' => '0' ], [ 'name' => 'max', 'value' => '9' ] ] ],
						[ 'name' => 'Birthday', 'namespace' => 'preferences', 'key' => 'birthday', 'description' => '', 'type' => [ 'name' => 'date' ], 'validations' => [] ],
						[ 'name' => 'Avatar', 'namespace' => 'preferences', 'key' => 'avatar', 'description' => '', 'type' => [ 'name' => 'file_reference' ], 'validations' => [] ],
						[ 'name' => 'Member since', 'namespace' => 'membership', 'key' => 'member_since', 'description' => '', 'type' => [ 'name' => 'date' ], 'validations' => [] ],
						[ 'name' => 'Broken' ],
					],
				],
			]
		);
	}

	private static function fields(): array {
		return Preferences::allowed(
			self::definitions(),
			[
				'preferences.topics'   => 'change',
				'preferences.level'    => 'see',
				'preferences.note'     => 'change',
				'preferences.print'    => 'change',
				'preferences.kids'     => 'change',
				'preferences.birthday' => 'change',
			]
		);
	}

	public function test_definitions_are_parsed_with_choices_and_support(): void {
		$defs = self::definitions();

		$this->assertCount( 8, $defs );
		$this->assertSame( [ 'Politics', 'Food', 'Travel' ], $defs['preferences.topics']['choices'] );
		$this->assertSame( '10', $defs['preferences.note']['max'] );
		$this->assertTrue( $defs['preferences.topics']['supported'] );
		$this->assertFalse( $defs['preferences.avatar']['supported'] );
		$this->assertFalse( $defs['membership.member_since']['supported'] );
	}

	public function test_modes_keep_valid_keys_and_never_the_membership_namespace(): void {
		$this->assertSame(
			[ 'preferences.topics' => 'change', 'preferences.level' => 'see' ],
			Preferences::clean_modes(
				[
					'preferences.topics'      => 'change',
					'preferences.level'       => 'see',
					'preferences.hidden'      => '',
					'membership.member_since' => 'change',
					'no-dot'                  => 'see',
					'preferences.x'           => 'admin',
				]
			)
		);
		$this->assertSame( [], Preferences::clean_modes( 'nonsense' ) );
	}

	public function test_allowed_drops_unsupported_and_missing_definitions(): void {
		$allowed = Preferences::allowed( self::definitions(), [ 'preferences.avatar' => 'change', 'preferences.gone' => 'see', 'preferences.level' => 'see' ] );

		$this->assertSame( [ 'preferences.level' ], array_keys( $allowed ) );
		$this->assertFalse( $allowed['preferences.level']['editable'] );
	}

	public function test_valid_values_are_encoded_as_shopify_stores_them(): void {
		$checked = Preferences::validate(
			self::fields(),
			[
				'preferences.topics'   => [ 'Food', 'Travel', 'Food' ],
				'preferences.note'     => '  XL  ',
				'preferences.print'    => false,
				'preferences.kids'     => '3',
				'preferences.birthday' => '1990-02-28',
			]
		);

		$this->assertSame( [], $checked['errors'] );
		$this->assertSame( '["Food","Travel"]', $checked['set']['preferences.topics']['value'] );
		$this->assertSame( 'XL', $checked['set']['preferences.note']['value'] );
		$this->assertSame( 'false', $checked['set']['preferences.print']['value'] );
		$this->assertSame( '3', $checked['set']['preferences.kids']['value'] );
		$this->assertSame( 'number_integer', $checked['set']['preferences.kids']['type'] );
		$this->assertSame( '1990-02-28', $checked['set']['preferences.birthday']['value'] );
	}

	public function test_bad_values_are_refused_with_a_reason(): void {
		$checked = Preferences::validate(
			self::fields(),
			[
				'preferences.topics'   => [ 'Sport' ],
				'preferences.level'    => 'Expert',
				'preferences.note'     => str_repeat( 'x', 11 ),
				'preferences.print'    => 'maybe',
				'preferences.kids'     => '12',
				'preferences.birthday' => '1990-02-30',
				'preferences.avatar'   => 'x',
				'membership.renews_on' => '2030-01-01',
			]
		);

		$this->assertSame(
			[
				'preferences.topics'   => 'invalid_choice',
				'preferences.level'    => 'read_only',
				'preferences.note'     => 'invalid_length',
				'preferences.print'    => 'invalid_type',
				'preferences.kids'     => 'invalid_range',
				'preferences.birthday' => 'invalid_type',
				'preferences.avatar'   => 'unknown_field',
				'membership.renews_on' => 'unknown_field',
			],
			$checked['errors']
		);
		$this->assertSame( [], $checked['set'] );
	}

	public function test_empty_values_clear_the_field(): void {
		$checked = Preferences::validate( self::fields(), [ 'preferences.topics' => [], 'preferences.note' => '', 'preferences.kids' => null ] );

		$this->assertSame( [ 'preferences.topics', 'preferences.note', 'preferences.kids' ], $checked['clear'] );
		$this->assertSame( [], $checked['set'] );
	}

	public function test_single_line_text_refuses_line_breaks(): void {
		$field = [ 'type' => 'single_line_text_field', 'choices' => [], 'min' => null, 'max' => null ];

		$this->assertSame( 'invalid_type', Preferences::encode( $field, "a\nb" )[0] );
		$this->assertSame( [ 'ok', 'ab' ], Preferences::encode( $field, 'ab' ) );
	}

	public function test_values_are_read_back_decoded_in_order(): void {
		$raw = Preferences::parse_values(
			[
				'customer' => [
					'metafields' => [
						'nodes' => [
							[ 'namespace' => 'preferences', 'key' => 'print', 'value' => 'true' ],
							[ 'namespace' => 'preferences', 'key' => 'topics', 'value' => '["Food"]' ],
							[ 'namespace' => 'preferences', 'key' => 'kids', 'value' => '2' ],
						],
					],
				],
			]
		);
		$out = Preferences::present( self::fields(), $raw );

		$this->assertSame( [ 'preferences.topics', 'preferences.level', 'preferences.note', 'preferences.print', 'preferences.kids', 'preferences.birthday' ], array_column( $out, 'key' ) );
		$this->assertSame( [ 'Food' ], $out[0]['value'] );
		$this->assertNull( $out[1]['value'] );
		$this->assertTrue( $out[3]['value'] );
		$this->assertSame( 2, $out[4]['value'] );
		$this->assertArrayNotHasKey( 'supported', $out[0] );
	}

	public function test_mutation_inputs_target_only_the_given_owner(): void {
		$owner = 'gid://shopify/Customer/7';

		$this->assertSame(
			[ [ 'ownerId' => $owner, 'namespace' => 'preferences', 'key' => 'kids', 'type' => 'number_integer', 'value' => '3' ] ],
			Preferences::set_input( $owner, [ 'preferences.kids' => [ 'type' => 'number_integer', 'value' => '3' ] ] )
		);
		$this->assertSame( [ [ 'ownerId' => $owner, 'namespace' => 'preferences', 'key' => 'note' ] ], Preferences::identifiers( $owner, [ 'preferences.note' ] ) );
	}

	public function test_the_event_carries_field_names_only(): void {
		$event = Events::envelope( Events::PREFERENCES_SAVED, [ 'fields' => 'preferences.topics', 'user_id' => 4, 'values' => [ 'Food' ] ], 1 );

		$this->assertSame( [ 'fields' => 'preferences.topics', 'user_id' => 4 ], $event['props'] );
	}

	public function test_the_public_list_has_editable_fields_without_values(): void {
		$list = Preferences::public_list( self::fields() );

		$this->assertSame( [ 'preferences.topics', 'preferences.note', 'preferences.print', 'preferences.kids', 'preferences.birthday' ], array_column( $list, 'key' ) );
		$this->assertSame( [ 'key', 'name', 'description', 'type', 'choices', 'min', 'max' ], array_keys( $list[0] ) );
	}
}
