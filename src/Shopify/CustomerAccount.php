<?php

namespace RedeemID\Shopify;

/**
 * Customer Account API: the signed-in customer's own data, server-side only.
 */
final class CustomerAccount {
	/**
	 * Field names verified at build time against the pinned version.
	 */
	public const IDENTITY_QUERY = <<<'GRAPHQL'
query RedeemIDIdentity {
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

		return self::parse_identity( $data ) ?? new \WP_Error( 'shopify_no_customer', 'Shopify did not return a customer.' );
	}

	/**
	 * The identity query's data, or null when it holds no customer. Pure; the
	 * contract tests feed it a recorded response.
	 *
	 * @return array{id: string, first_name: string, last_name: string, email: string, tags: array}|null
	 */
	public static function parse_identity( array $data ): ?array {
		$customer = $data['customer'] ?? null;

		if ( ! is_array( $customer ) || empty( $customer['id'] ) ) {
			return null;
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
