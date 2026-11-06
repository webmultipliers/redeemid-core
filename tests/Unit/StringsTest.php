<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Strings\Catalog;
use RedeemID\Strings\Overrides;
use RedeemID\Support\Transfer;

final class StringsTest extends TestCase {
	private const POT = <<<'POT'
msgid ""
msgstr ""
"Project-Id-Version: RedeemID\n"

#: blocks/member-account/index.php:12
#: src/Admin/Overview.php:40
msgid "Account"
msgstr ""

#. translators: %s: customer name
#: src/Admin/CustomersTable.php:131
#, php-format
msgid "Select %s"
msgstr ""

#: src/Access/Meter.php:170
#, php-format
msgid "Sign in to read %d members-only story free each week."
msgid_plural "Sign in to read %d members-only stories free each week."
msgstr[0] ""
msgstr[1] ""

#: blocks/access-badge/block.json
msgctxt "block title"
msgid "Access Badge"
msgstr ""

#: src/Admin/Debug.php:10
msgid ""
"A long string that wraps "
"onto a \"second\" line."
msgstr ""
POT;

	public function test_parse_reads_entries_contexts_plurals_and_comments(): void {
		$all = Catalog::parse( self::POT );

		$this->assertSame( [ 'Account', 'Select %s', 'Sign in to read %d members-only story free each week.', "block title\x04Access Badge", 'A long string that wraps onto a "second" line.' ], array_keys( $all ) );
		$this->assertSame( [ 'blocks/member-account/index.php', 'src/Admin/Overview.php' ], $all['Account']['refs'] );
		$this->assertSame( 'translators: %s: customer name', $all['Select %s']['comment'] );
		$this->assertTrue( $all['Select %s']['format'] );
		$this->assertFalse( $all['Account']['format'] );
		$this->assertSame( 'Sign in to read %d members-only stories free each week.', $all['Sign in to read %d members-only story free each week.']['plural'] );
		$this->assertSame( 'block title', $all["block title\x04Access Badge"]['context'] );
	}

	public function test_areas_and_audience_come_from_source_files(): void {
		$all = Catalog::parse( self::POT );

		$this->assertSame( 'blocks', $all['Account']['area'] );
		$this->assertSame( Catalog::MEMBERS, $all['Account']['audience'], 'Any member-facing file makes it member-facing.' );
		$this->assertSame( 'admin', $all['Select %s']['area'] );
		$this->assertSame( Catalog::STAFF, $all['Select %s']['audience'] );
		$this->assertSame( 'access', $all['Sign in to read %d members-only story free each week.']['area'] );
		$this->assertSame( 'editor', $all["block title\x04Access Badge"]['area'], 'block.json is the editor\'s title and description.' );
		$this->assertSame( 'access-badge', Catalog::block( $all["block title\x04Access Badge"]['refs'] ) );
	}

	public function test_the_bundled_template_lists_the_plugins_strings(): void {
		$all = Catalog::all();

		$this->assertArrayHasKey( 'Settings sections', $all, 'The .pot is current: the Settings page\'s tab bar label is in it.' );
		$this->assertGreaterThan( 1000, count( $all ) );
	}

	public function test_placeholders_map_arguments_to_types(): void {
		$this->assertSame( [ 1 => 's', 2 => 'd' ], Overrides::placeholders( 'Hi %s, you have %d' ) );
		$this->assertSame( [ 2 => 'd', 1 => 's' ], Overrides::placeholders( '%2$d for %1$s' ) );
		$this->assertSame( [], Overrides::placeholders( '100%% off' ) );
		$this->assertNull( Overrides::placeholders( 'Up 50%! %s' ), 'A % that would make sprintf() throw.' );
		$this->assertSame( [ 1 => 'o', 2 => 's' ], Overrides::placeholders( '50% off %s' ), 'sprintf() reads "% o" as a placeholder.' );
	}

	public function test_problem_allows_dropping_and_reordering_placeholders_but_not_new_ones(): void {
		$this->assertSame( '', Overrides::problem( 'Select %s', 'Pick %s' ) );
		$this->assertSame( '', Overrides::problem( 'Select %s', 'Pick one' ), 'Leaving a value out is safe.' );
		$this->assertSame( '', Overrides::problem( '%1$s of %2$d', '%2$d: %1$s' ) );
		$this->assertSame( 'placeholder', Overrides::problem( 'Select %s', 'Pick %s from %s' ) );
		$this->assertSame( 'placeholder', Overrides::problem( 'Select %s', 'Pick %d' ) );
		$this->assertSame( 'percent', Overrides::problem( 'Save %d%%', 'Save %d%' ) );
		$this->assertSame( '', Overrides::problem( 'Save %d%%', 'Save %d%%' ) );
		$this->assertSame( 'long', Overrides::problem( 'Account', str_repeat( 'a', Overrides::MAX + 1 ) ) );
	}

	public function test_plain_strings_take_any_text(): void {
		$this->assertSame( '', Overrides::problem( 'Account', '50% off your portal' ) );
		$this->assertSame( '', Overrides::problem( 'Account', '50% off %s', false ) );
		$this->assertSame( 'placeholder', Overrides::problem( 'Account', 'Hi %s', true ), 'The template says it is a format string.' );
	}

	public function test_normalize_and_value_keep_only_usable_entries(): void {
		$out = Overrides::normalize(
			[
				''       => [ 'Account' => ' Portal ', 'Empty' => '  ', 'Plural' => [ 'one', '' ], 'Bad' => 7 ],
				'fr_FR'  => [ 'Account' => 'Portail' ],
				'../etc' => [ 'Account' => 'x' ],
				'de_DE'  => 'nope',
			]
		);

		$this->assertSame( [ '', 'fr_FR' ], array_keys( $out ) );
		$this->assertSame( [ 'Account' => 'Portal', 'Plural' => [ 'one', 'one' ] ], $out[''] );
		$this->assertNull( Overrides::value( [ '', '' ] ) );
	}

	public function test_pick_prefers_the_language_then_every_language(): void {
		$all = [ '' => [ 'Account' => 'Portal', 'Cart' => 'Bag' ], 'fr_FR' => [ 'Account' => 'Portail' ] ];

		$this->assertSame( 'Portail', Overrides::pick( $all, 'fr_FR', 'Account' ) );
		$this->assertSame( 'Bag', Overrides::pick( $all, 'fr_FR', 'Cart' ) );
		$this->assertSame( 'Portal', Overrides::pick( $all, 'en_US', 'Account' ) );
		$this->assertNull( Overrides::pick( $all, 'en_US', 'Missing' ) );
	}

	public function test_form_picks_one_or_more(): void {
		$this->assertSame( 'Portal', Overrides::form( 'Portal', 3 ) );
		$this->assertSame( '%d story', Overrides::form( [ '%d story', '%d stories' ], 1 ) );
		$this->assertSame( '%d stories', Overrides::form( [ '%d story', '%d stories' ], 0 ) );
	}

	public function test_transfer_carries_strings_and_diffs_them_by_language(): void {
		$file = Transfer::normalize( [ 'strings' => [ '' => [ 'Account' => 'Portal' ], 'fr_FR' => [ "block title\x04Cart" => 'Panier' ] ] ] );

		$this->assertSame( [ '' => [ 'Account' => 'Portal' ], 'fr_FR' => [ "block title\x04Cart" => 'Panier' ] ], $file['strings'] );

		$diff = Transfer::diff( [ 'strings' => [ '' => [ 'Account' => 'Members area', 'Cart' => 'Bag' ] ] ], $file );

		$this->assertSame( [ 'fr_FR: block title | Cart' ], $diff['strings']['added'] );
		$this->assertSame( [ '*: Account' ], $diff['strings']['changed'] );
		$this->assertSame( [ '*: Cart' ], $diff['strings']['removed'] );
	}
}
