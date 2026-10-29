<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Access\Level;
use RedeemID\Access\Levels;
use RedeemID\Commerce\PlanState;

final class PlanStateTest extends TestCase {
	private function levels(): Levels {
		return Levels::from_config(
			[
				'silver' => [ 'label' => 'Silver', 'groups' => [ [ 'tags' => [ 'silver' ] ] ], 'products' => [ 'silver-membership' ] ],
				'gold'   => [ 'label' => 'Gold', 'groups' => [ [ 'tags' => [ 'gold' ] ] ], 'includes' => [ 'silver' ], 'products' => 'gold-membership, Gold-Annual' ],
				'puzzle' => [ 'label' => 'Puzzles', 'groups' => [ [ 'tags' => [ 'puzzles' ] ] ], 'products' => [ 'puzzle-pack' ] ],
			]
		);
	}

	private function state( string $handle, array $held, array $direct ): string {
		$levels = $this->levels();

		return PlanState::state( $levels, PlanState::level_for( $levels, $handle ), $held, $direct )['state'];
	}

	public function test_products_are_clean_handles(): void {
		$this->assertSame( [ 'gold-membership', 'gold-annual' ], Level::from_array( 'gold', [ 'products' => 'gold-membership, Gold-Annual,, gold-membership' ] )->products );
		$this->assertSame( 'gold', PlanState::level_for( $this->levels(), 'gold-annual' ) );
		$this->assertNull( PlanState::level_for( $this->levels(), 'tote-bag' ) );
	}

	public function test_guest_or_unmapped_product_gets_the_plain_card(): void {
		$this->assertSame( '', $this->state( 'gold-membership', [], [] ) );
		$this->assertSame( '', $this->state( 'tote-bag', [ 'gold', 'silver' ], [ 'gold' ] ) );
	}

	public function test_your_plan(): void {
		$this->assertSame( PlanState::CURRENT, $this->state( 'gold-membership', [ 'gold', 'silver' ], [ 'gold' ] ) );
	}

	public function test_included_in_a_higher_level(): void {
		$levels = $this->levels();
		$out    = PlanState::state( $levels, 'silver', [ 'gold', 'silver' ], [ 'gold' ] );

		$this->assertSame( [ 'state' => PlanState::INCLUDED, 'via' => 'gold' ], $out );
	}

	public function test_upgrade_from_an_included_level(): void {
		$out = PlanState::state( $this->levels(), 'gold', [ 'silver' ], [ 'silver' ] );

		$this->assertSame( [ 'state' => PlanState::UPGRADE, 'via' => 'silver' ], $out );
	}

	public function test_add_on_for_an_unrelated_level(): void {
		$this->assertSame( PlanState::ADD, $this->state( 'puzzle-pack', [ 'gold', 'silver' ], [ 'gold' ] ) );
	}
}
