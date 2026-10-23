<?php

namespace WP2_Shopify\Membership;

use WP2_Shopify\Entitlement\Evaluator;

/**
 * Pure purchase-to-tag computation (owner decision on review point 1).
 *
 * Given which products grant which tags, and the customer's orders, work out
 * the tags the customer should hold. Only tags that appear in the map are
 * managed: tags the merchant or other apps added are never removed.
 */
final class TagPlan {
	/** Financial states that count as a live purchase. */
	private const GRANTING_STATUSES = [ 'PAID', 'PARTIALLY_REFUNDED', 'PARTIALLY_PAID' ];

	/**
	 * @param array<string, string[]> $map Numeric product ID => tags it grants.
	 */
	public function __construct( private readonly array $map ) {}

	/**
	 * Parse the settings text: one "product ID: tag, tag" per line. Product IDs
	 * may be numeric or gid://shopify/Product/... form.
	 *
	 * @return array<string, string[]>
	 */
	public static function parse_map( string $text ): array {
		$map = [];

		foreach ( preg_split( '/\R/', $text ) ?: [] as $line ) {
			if ( ! preg_match( '#^\s*(?:gid://shopify/Product/)?(\d+)\s*:\s*(.+)$#', $line, $m ) ) {
				continue;
			}

			[ , $product, $tags ] = $m;

			$list = array_values( array_filter( array_map( 'trim', explode( ',', $tags ) ) ) );

			if ( $list ) {
				$map[ $product ] = array_values( array_unique( array_merge( $map[ $product ] ?? [], $list ) ) );
			}
		}

		return $map;
	}

	/**
	 * @return string[] Every tag this plugin manages.
	 */
	public function managed(): array {
		return array_values( array_unique( array_merge( [], ...array_values( $this->map ) ) ) );
	}

	/**
	 * @param array<int, array{cancelled: bool, financial_status: string, products: array<string, int>}> $orders
	 * @return string[]
	 */
	public function desired( array $orders ): array {
		$tags = [];

		foreach ( $orders as $order ) {
			if ( $order['cancelled'] || ! in_array( strtoupper( $order['financial_status'] ), self::GRANTING_STATUSES, true ) ) {
				continue;
			}

			foreach ( $order['products'] as $product => $quantity ) {
				$numeric = preg_replace( '#^gid://shopify/Product/#', '', (string) $product );

				if ( $quantity > 0 && isset( $this->map[ $numeric ] ) ) {
					$tags = array_merge( $tags, $this->map[ $numeric ] );
				}
			}
		}

		return array_values( array_unique( $tags ) );
	}

	/**
	 * Tags with evidence of a lost purchase in these orders: the product appears
	 * in an order that no longer grants (cancelled, refunded, unpaid, or a line
	 * refunded to zero). Used when the order history is partial, so a tag is
	 * only removed for an order the plugin can actually see.
	 *
	 * @param array<int, array{cancelled: bool, financial_status: string, products: array<string, int>}> $orders
	 * @return string[]
	 */
	public function revoked( array $orders ): array {
		$tags = [];

		foreach ( $orders as $order ) {
			$granting = ! $order['cancelled'] && in_array( strtoupper( $order['financial_status'] ), self::GRANTING_STATUSES, true );

			foreach ( $order['products'] as $product => $quantity ) {
				$numeric = preg_replace( '#^gid://shopify/Product/#', '', (string) $product );

				if ( ( ! $granting || $quantity <= 0 ) && isset( $this->map[ $numeric ] ) ) {
					$tags = array_merge( $tags, $this->map[ $numeric ] );
				}
			}
		}

		return array_values( array_unique( $tags ) );
	}

	/**
	 * @param string[]      $current   Tags the customer holds now.
	 * @param string[]      $desired   Output of desired().
	 * @param string[]|null $removable With partial order history, only these managed tags may be removed (output of revoked()). Null when the full history is visible.
	 * @return array{add: string[], remove: string[]}
	 */
	public function diff( array $current, array $desired, ?array $removable = null ): array {
		$have    = array_map( [ Evaluator::class, 'normalize_tag' ], $current );
		$want    = array_map( [ Evaluator::class, 'normalize_tag' ], $desired );
		$managed = array_map( [ Evaluator::class, 'normalize_tag' ], null === $removable ? $this->managed() : array_intersect( $this->managed(), $removable ) );

		$add = array_values( array_filter( $desired, static fn( $t ) => ! in_array( Evaluator::normalize_tag( $t ), $have, true ) ) );

		$remove = array_values(
			array_filter(
				$current,
				static fn( $t ) => in_array( Evaluator::normalize_tag( $t ), $managed, true ) && ! in_array( Evaluator::normalize_tag( $t ), $want, true )
			)
		);

		return [ 'add' => array_values( array_unique( $add ) ), 'remove' => array_values( array_unique( $remove ) ) ];
	}

	public function is_empty(): bool {
		return ! $this->map;
	}
}
