<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Support\Transfer;

final class TransferTest extends TestCase {
	private function file( array $sections = [] ): array {
		return [ 'format' => Transfer::FORMAT, 'version' => Transfer::VERSION, 'site' => 'https://staging.example.com/' ] + $sections;
	}

	public function test_validate_accepts_a_file_with_one_section(): void {
		$this->assertSame( [], Transfer::validate( $this->file( [ 'levels' => [] ] ) ) );
	}

	public function test_validate_rejects_other_json(): void {
		$this->assertSame( [ 'not_json' ], Transfer::validate( null ) );
		$this->assertSame( [ 'wrong_format' ], Transfer::validate( [ 'levels' => [] ] ) );
		$this->assertSame( [ 'newer_version' ], Transfer::validate( [ 'version' => Transfer::VERSION + 1 ] + $this->file( [ 'levels' => [] ] ) ) );
		$this->assertSame( [ 'bad_levels' ], Transfer::validate( $this->file( [ 'levels' => 'gold' ] ) ) );
		$this->assertSame( [ 'empty' ], Transfer::validate( $this->file() ) );
	}

	public function test_normalize_cleans_like_the_stores_and_drops_builtins(): void {
		$out = Transfer::normalize(
			[
				'levels'   => [
					'Gold'      => [ 'label' => 'Gold', 'groups' => [ [ 'tags' => [ 'gold' ] ] ], 'extra' => 'x' ],
					'public'    => [ 'label' => 'Everyone' ],
					'signed-in' => [ 'label' => 'Readers' ],
				],
				'features' => [ 'Ad-Free' => [ 'routes' => [ [ 'tests' => [ [ 'type' => 'level', 'value' => 'Gold' ] ] ] ] ], 'broken' => 'x' ],
				'settings' => [ 'subscription_url' => 'https://shop.example.com/account', 'secret' => 'nope' ],
			]
		);

		$this->assertSame( [ 'gold' ], array_keys( $out['levels'] ) );
		$this->assertArrayNotHasKey( 'extra', $out['levels']['gold'] );
		$this->assertSame( [ 'ad-free' ], array_keys( $out['features'] ) );
		$this->assertSame( 'gold', $out['features']['ad-free']['routes'][0]['tests'][0]['value'] );
		$this->assertSame( [ 'subscription_url' ], array_keys( $out['settings'] ) );
		$this->assertArrayNotHasKey( 'rules', $out, 'a section missing from the file stays out, so import never touches it' );
	}

	public function test_normalize_keeps_only_term_rules_that_do_something(): void {
		$out = Transfer::normalize(
			[
				'term_rules' => [
					'category' => [
						'investigations' => [ 'kind' => 'requires', 'levels' => [ 'Gold' ] ],
						'news'           => [ 'kind' => '' ],
					],
				],
			]
		);

		$this->assertSame( [ 'investigations' ], array_keys( $out['term_rules']['category'] ) );
		$this->assertSame( [ 'gold' ], $out['term_rules']['category']['investigations']['levels'] );
	}

	public function test_rebase_moves_only_urls_on_the_exporting_site(): void {
		$sections = [
			'levels'   => [
				'gold' => [ 'cta_url' => 'https://staging.example.com/join?plan=gold', 'home_url' => 'https://staging.example.com/members/' ],
				'vip'  => [ 'cta_url' => 'https://shop.example.com/products/vip', 'home_url' => 'https://staging.example.com.evil.test/' ],
			],
			'settings' => [ 'subscription_url' => 'https://staging.example.com' ],
		];

		$out = Transfer::rebase( $sections, 'https://staging.example.com/', 'https://www.example.com/' );

		$this->assertSame( 'https://www.example.com/join?plan=gold', $out['levels']['gold']['cta_url'] );
		$this->assertSame( 'https://www.example.com/members/', $out['levels']['gold']['home_url'] );
		$this->assertSame( 'https://shop.example.com/products/vip', $out['levels']['vip']['cta_url'] );
		$this->assertSame( 'https://staging.example.com.evil.test/', $out['levels']['vip']['home_url'] );
		$this->assertSame( 'https://www.example.com', $out['settings']['subscription_url'] );
	}

	public function test_diff_lists_added_changed_and_removed_per_section(): void {
		$current  = [
			'levels'     => [ 'gold' => [ 'label' => 'Gold' ], 'silver' => [ 'label' => 'Silver' ] ],
			'term_rules' => [ 'category' => [ 'news' => [ 'kind' => 'requires' ] ] ],
			'features'   => [ 'ad-free' => [ 'routes' => [] ] ],
		];
		$incoming = [
			'levels'     => [ 'gold' => [ 'label' => 'Gold+' ], 'bronze' => [ 'label' => 'Bronze' ] ],
			'term_rules' => [ 'category' => [ 'news' => [ 'kind' => 'requires' ] ] ],
		];

		$diff = Transfer::diff( $current, $incoming );

		$this->assertSame( [ 'added' => [ 'bronze' ], 'changed' => [ 'gold' ], 'removed' => [ 'silver' ] ], $diff['levels'] );
		$this->assertSame( [ 'added' => [], 'changed' => [], 'removed' => [] ], $diff['term_rules'] );
		$this->assertArrayNotHasKey( 'features', $diff, 'a section not in the file is not part of the import' );
	}
}
