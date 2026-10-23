<?php

namespace WP2_Shopify\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP2_Shopify\Support\CustomerId;

final class CustomerIdTest extends TestCase {
	public static function equivalent(): array {
		return [
			'gid'               => [ 'gid://shopify/Customer/706405506930370084' ],
			'numeric string'    => [ '706405506930370084' ],
			'integer'           => [ 706405506930370084 ],
			'padded'            => [ '  706405506930370084 ' ],
			'gid with query'    => [ 'gid://shopify/Customer/706405506930370084?legacy=1' ],
			'leading zeros'     => [ '000706405506930370084' ],
		];
	}

	#[DataProvider( 'equivalent' )]
	public function test_every_form_normalises_to_both_ids( mixed $input ): void {
		$id = CustomerId::parse( $input );

		$this->assertNotNull( $id );
		$this->assertSame( '706405506930370084', $id->numeric );
		$this->assertSame( 'gid://shopify/Customer/706405506930370084', $id->gid() );
	}

	public static function invalid(): array {
		return [ [ '' ], [ '0' ], [ 0 ], [ -5 ], [ 'gid://shopify/Order/123' ], [ 'abc' ], [ null ], [ [ 1 ] ], [ 1.5 ], [ '12a' ] ];
	}

	#[DataProvider( 'invalid' )]
	public function test_rejects_non_customer_values( mixed $input ): void {
		$this->assertNull( CustomerId::parse( $input ) );
	}

	public function test_customer_topic_payload_uses_root_id(): void {
		$payload = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/customers-update.json' ), true );

		$this->assertSame( '706405506930370084', CustomerId::from_payload( $payload, 'customers/update' )?->numeric );
	}

	public function test_event_style_payload_uses_customer_gid(): void {
		$payload = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/customer-tags-added.json' ), true );

		$this->assertSame( '706405506930370084', CustomerId::from_payload( $payload, 'customer.tags_added' )?->numeric );
	}

	public function test_order_payload_uses_nested_customer_not_order_id(): void {
		$payload = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/orders-paid.json' ), true );

		$this->assertSame( '706405506930370084', CustomerId::from_payload( $payload, 'orders/paid' )?->numeric );
	}

	public function test_refund_payload_has_no_customer(): void {
		$payload = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/refunds-create.json' ), true );

		$this->assertNull( CustomerId::from_payload( $payload, 'refunds/create' ) );
	}
}
