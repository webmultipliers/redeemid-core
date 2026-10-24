<?php

namespace WP2_Shopify\Shopify;

/**
 * Customer Account API: the signed-in customer's own data, server-side only.
 */
final class CustomerAccount {
	/**
	 * Field names verified at build time against the pinned version.
	 */
	private const IDENTITY_QUERY = <<<'GRAPHQL'
query WP2Identity {
  customer {
    id
    firstName
    lastName
    emailAddress { emailAddress }
    tags
  }
}
GRAPHQL;

	public static function identity( string $access_token ): array|\WP_Error {
		$url = Discovery::endpoint( 'graphql_api' );

		if ( '' === $url ) {
			return new \WP_Error( 'shopify_unconfigured', 'Customer Account API endpoint is unknown.' );
		}

		$data = Http::graphql( $url, [ 'Authorization' => $access_token ], self::IDENTITY_QUERY, [], 'customer_account.identity' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$customer = $data['customer'] ?? null;

		if ( ! is_array( $customer ) || empty( $customer['id'] ) ) {
			return new \WP_Error( 'shopify_no_customer', 'Shopify did not return a customer.' );
		}

		return [
			'id'         => (string) $customer['id'],
			'first_name' => (string) ( $customer['firstName'] ?? '' ),
			'last_name'  => (string) ( $customer['lastName'] ?? '' ),
			'email'      => (string) ( $customer['emailAddress']['emailAddress'] ?? '' ),
			'tags'       => is_array( $customer['tags'] ?? null ) ? $customer['tags'] : [],
		];
	}
}
