<?php

namespace WP2_Shopify\Shopify;

use WP2_Shopify\Config;
use WP2_Shopify\Support\CustomerId;

/**
 * Admin API client, server-side only. Used for the plugin-owned purchase-to-tag
 * loop and for reconciliation (owner decision on review point 1). Never called
 * with credentials from the browser.
 */
final class Admin {
	public static function available(): bool {
		return AdminToken::configured() && '' !== Config::store_domain();
	}

	public static function query( string $query, array $variables, string $label, bool $retry = true ): array|\WP_Error {
		if ( ! self::available() ) {
			return new \WP_Error( 'shopify_admin_unconfigured', 'No Admin API credentials are defined.' );
		}

		$token = AdminToken::get();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$data = Http::graphql(
			'https://' . Config::store_domain() . '/admin/api/' . Config::api_version() . '/graphql.json',
			[ 'X-Shopify-Access-Token' => $token ],
			$query,
			$variables,
			$label
		);

		// A client-credentials token can expire or be revoked early: fetch a fresh one once.
		if ( $retry && is_wp_error( $data ) && 'shopify_http_401' === $data->get_error_code() && AdminToken::uses_client_credentials() ) {
			AdminToken::forget();

			return self::query( $query, $variables, $label, false );
		}

		return $data;
	}

	/**
	 * Gating fields for up to 50 customers in one call.
	 *
	 * @param CustomerId[] $ids
	 * @return array<string, array{tags: string[], state: string}>|\WP_Error Keyed by numeric ID; missing keys were not found.
	 */
	public static function customers( array $ids ): array|\WP_Error {
		$data = self::query(
			'query WP2Reconcile($ids: [ID!]!) { nodes(ids: $ids) { ... on Customer { id tags state } } }',
			[ 'ids' => array_map( static fn( CustomerId $id ) => $id->gid(), array_slice( $ids, 0, 50 ) ) ],
			'admin.customers'
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$out = [];

		foreach ( (array) ( $data['nodes'] ?? [] ) as $node ) {
			$id = is_array( $node ) ? CustomerId::parse( $node['id'] ?? null ) : null;

			if ( $id ) {
				$out[ $id->numeric ] = [
					'tags'  => array_values( array_filter( (array) ( $node['tags'] ?? [] ), 'is_string' ) ),
					'state' => (string) ( $node['state'] ?? '' ),
				];
			}
		}

		return $out;
	}

	/**
	 * Orders that can grant membership, newest first (enough for membership products).
	 *
	 * @return array{tags: string[], orders: array<int, array{cancelled: bool, financial_status: string, products: array<string, int>}>}|\WP_Error
	 */
	public static function membership_orders( CustomerId $id ): array|\WP_Error {
		$data = self::query(
			'query WP2Orders($id: ID!) { customer(id: $id) { tags orders(first: 100, reverse: true) { nodes { cancelledAt displayFinancialStatus lineItems(first: 100) { nodes { currentQuantity product { id } } } } } } }',
			[ 'id' => $id->gid() ],
			'admin.membership_orders'
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! is_array( $data['customer'] ?? null ) ) {
			return new \WP_Error( 'shopify_no_customer', 'Customer not found.' );
		}

		$orders = [];

		foreach ( (array) ( $data['customer']['orders']['nodes'] ?? [] ) as $order ) {
			$products = [];

			foreach ( (array) ( $order['lineItems']['nodes'] ?? [] ) as $line ) {
				$product = (string) ( $line['product']['id'] ?? '' );

				if ( '' !== $product ) {
					$products[ $product ] = ( $products[ $product ] ?? 0 ) + (int) ( $line['currentQuantity'] ?? 0 );
				}
			}

			$orders[] = [
				'cancelled'        => ! empty( $order['cancelledAt'] ),
				'financial_status' => (string) ( $order['displayFinancialStatus'] ?? '' ),
				'products'         => $products,
			];
		}

		return [ 'tags' => (array) ( $data['customer']['tags'] ?? [] ), 'orders' => $orders ];
	}

	public static function order_customer( string $order_id ): ?CustomerId {
		if ( ! ctype_digit( $order_id ) ) {
			return null;
		}

		$data = self::query( 'query WP2OrderCustomer($id: ID!) { order(id: $id) { customer { id } } }', [ 'id' => 'gid://shopify/Order/' . $order_id ], 'admin.order_customer' );

		return is_wp_error( $data ) ? null : CustomerId::parse( $data['order']['customer']['id'] ?? null );
	}

	/**
	 * @param string[] $tags
	 */
	public static function add_tags( CustomerId $id, array $tags ): true|\WP_Error {
		return self::tag_mutation( 'tagsAdd', $id, $tags );
	}

	/**
	 * @param string[] $tags
	 */
	public static function remove_tags( CustomerId $id, array $tags ): true|\WP_Error {
		return self::tag_mutation( 'tagsRemove', $id, $tags );
	}

	private static function tag_mutation( string $mutation, CustomerId $id, array $tags ): true|\WP_Error {
		if ( ! $tags ) {
			return true;
		}

		$data = self::query(
			"mutation WP2Tags(\$id: ID!, \$tags: [String!]!) { {$mutation}(id: \$id, tags: \$tags) { userErrors { field message } } }",
			[ 'id' => $id->gid(), 'tags' => array_values( $tags ) ],
			'admin.' . $mutation
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$errors = $data[ $mutation ]['userErrors'] ?? [];

		return $errors ? new \WP_Error( 'shopify_user_error', (string) ( $errors[0]['message'] ?? 'Tag update rejected.' ) ) : true;
	}
}
