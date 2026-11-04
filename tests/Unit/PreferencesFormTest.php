<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Blocks\PreferencesForm;

final class PreferencesFormTest extends TestCase {
	private function fields(): array {
		return [
			'preferences.topics' => [ 'key' => 'preferences.topics', 'type' => 'list.single_line_text_field', 'editable' => true ],
			'preferences.news'   => [ 'key' => 'preferences.news', 'type' => 'boolean', 'editable' => true ],
			'preferences.level'  => [ 'key' => 'preferences.level', 'type' => 'single_line_text_field', 'editable' => true ],
			'preferences.region' => [ 'key' => 'preferences.region', 'type' => 'single_line_text_field', 'editable' => false ],
		];
	}

	public function test_form_values_become_typed_values(): void {
		$values = PreferencesForm::posted(
			$this->fields(),
			[ 'preferences.topics' => [ 'Food', ' Travel ', '' ], 'preferences.news' => '1', 'preferences.level' => ' Beginner ' ],
			[ 'preferences.topics', 'preferences.news', 'preferences.level' ]
		);

		$this->assertSame( [ 'preferences.topics' => [ 'Food', 'Travel' ], 'preferences.news' => true, 'preferences.level' => 'Beginner' ], $values );
	}

	public function test_unticked_and_emptied_fields_save_false_and_clear(): void {
		$values = PreferencesForm::posted( $this->fields(), [ 'preferences.news' => '0' ], [ 'preferences.topics', 'preferences.news' ] );

		$this->assertSame( [ 'preferences.topics' => [], 'preferences.news' => false ], $values );
	}

	public function test_a_list_typed_one_per_line(): void {
		$this->assertSame( [ 'preferences.topics' => [ 'Food', 'Travel' ] ], PreferencesForm::posted( $this->fields(), [ 'preferences.topics' => "Food\r\n\nTravel" ], [ 'preferences.topics' ] ) );
	}

	public function test_read_only_and_unknown_fields_are_never_sent(): void {
		$this->assertSame( [], PreferencesForm::posted( $this->fields(), [ 'preferences.region' => 'EU', 'x.y' => 'z' ], [ 'preferences.region', 'x.y' ] ) );
	}
}
