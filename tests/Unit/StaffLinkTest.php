<?php

namespace RedeemID\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedeemID\Admin\StaffLink;

final class StaffLinkTest extends TestCase {
	public function test_different_emails_link_and_flag(): void {
		$this->assertSame( [ 'error' => '', 'flag' => true ], StaffLink::check( 7, false, false, null, 'ann@old.example', 'ann@new.example' ) );
	}

	public function test_matching_emails_link_without_a_flag(): void {
		$this->assertSame( [ 'error' => '', 'flag' => false ], StaffLink::check( 7, false, false, 7, 'Ann@Example.com ', 'ann@example.com' ) );
	}

	public function test_refusals(): void {
		$this->assertSame( 'staff_link_no_user', StaffLink::check( 0, false, false, null, '', 'a@b.c' )['error'] );
		$this->assertSame( 'staff_link_privileged', StaffLink::check( 7, true, false, null, 'a@b.c', 'x@y.z' )['error'], 'Staff never link to Shopify' );
		$this->assertSame( 'staff_link_already', StaffLink::check( 7, false, true, null, 'a@b.c', 'x@y.z' )['error'] );
		$this->assertSame( 'staff_link_taken', StaffLink::check( 7, false, false, 9, 'a@b.c', 'x@y.z' )['error'], 'One customer, one user' );
		$this->assertSame( 'staff_link_no_customer', StaffLink::check( 7, false, false, null, 'a@b.c', '' )['error'] );
		$this->assertFalse( StaffLink::check( 7, true, false, null, 'a@b.c', 'x@y.z' )['flag'] );
	}
}
