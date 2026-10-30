<?php

namespace RedeemID\Shopify;

use RedeemID\Config;
use RedeemID\Support\CustomerId;

/**
 * Admin API client, server-side only. Used for reconciliation, resync, tag
 * editing and webhook registration. Never called with credentials from the browser.
 */
final class Admin {
	public const RECONCILE_QUERY = 'query RedeemIDReconcile($ids: [ID!]!) { nodes(ids: $ids) { ... on Customer { id tags since: metafield(namespace: "membership", key: "member_since") { value } renews: metafield(namespace: "membership", key: "renews_on") { value } } } }';

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

		// A token can expire or be revoked early: fetch a fresh one once.
		if ( $retry && is_wp_error( $data ) && 'shopify_http_401' === $data->get_error_code()  ) {
			AdminToken::forget();

			return self::query( $query, $variables, $label, false );
		}

		return $data;
	}

	/**
	 * Gating fields for up to 50 customers in one call.
	 *
	 * @param CustomerId[] $ids
	 * Also reads the optional membership.member_since and membership.renews_on
	 * customer metafields, for the member's account card.
	 *
	 * @return array<string, array{tags: string[], since: ?string, renews: ?string}>|\WP_Error Keyed by numeric ID; missing keys were not found.
	 */
	public static function customers( array $ids ): array|\WP_Error {
		$data = self::query(
			self::RECONCILE_QUERY,
			[ 'ids' => array_map( static fn( CustomerId $id ) => $id->gid(), array_slice( $ids, 0, 50 ) ) ],
			'admin.customers'
		);

		return is_wp_error( $data ) ? $data : self::parse_customers( $data );
	}

	/**
	 * The reconcile query's data, keyed by numeric ID. Pure; the contract
	 * tests feed it a recorded response.
	 *
	 * Customer.state is deliberately not read: Shopify documents it as
	 * meaningful only under classic customer accounts, and sign-in needs new
	 * customer accounts, where it can say DISABLED for a customer in good
	 * standing. Status comes from the customers/disable and customers/enable
	 * webhooks instead (Webhooks\Extractor).
	 *
	 * @return array<string, array{tags: string[], since: ?string, renews: ?string}>
	 */
	public static function parse_customers( array $data ): array {
		$out = [];

		foreach ( (array) ( $data['nodes'] ?? [] ) as $node ) {
			$id = is_array( $node ) ? CustomerId::parse( $node['id'] ?? null ) : null;

			if ( $id ) {
				$out[ $id->numeric ] = [
					'tags'   => array_values( array_filter( (array) ( $node['tags'] ?? [] ), 'is_string' ) ),
					'since'  => isset( $node['since']['value'] ) ? (string) $node['since']['value'] : null,
					'renews' => isset( $node['renews']['value'] ) ? (string) $node['renews']['value'] : null,
				];
			}
		}

		return $out;
	}

	/**
	 * Customer counts for several searches in one call ('' counts everyone).
	 * Shopify stops counting at 10,000; a capped count is returned as that.
	 *
	 * @param array<string, string> $queries Keyed by any label.
	 * @return array<string, int>|\WP_Error Keyed like $queries.
	 */
	public static function count_customers( array $queries ): array|\WP_Error {
		$fields = [];
		$vars   = [];
		$keys   = array_keys( $queries );

		foreach ( $keys as $i => $key ) {
			$fields[]       = "c{$i}: customersCount(query: \$q{$i}) { count }";
			$vars[ "q{$i}" ] = (string) $queries[ $key ];
		}

		if ( ! $fields ) {
			return [];
		}

		$params = implode( ', ', array_map( static fn( string $v ): string => "\${$v}: String", array_keys( $vars ) ) );
		$data   = self::query( "query RedeemIDCounts({$params}) { " . implode( ' ', $fields ) . ' }', $vars, 'admin.customersCount' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$out = [];

		foreach ( $keys as $i => $key ) {
			if ( isset( $data[ "c{$i}" ]['count'] ) ) {
				$out[ $key ] = (int) $data[ "c{$i}" ]['count'];
			}
		}

		return $out;
	}

	/**
	 * Tags in use on the store's customers, up to 250, for checking that a
	 * level's tags exist in Shopify.
	 *
	 * @return string[]|\WP_Error
	 */
	public static function customer_tags(): array|\WP_Error {
		$data = self::query( 'query RedeemIDCustomerTags { shop { customerTags(first: 250) { nodes } } }', [], 'admin.customerTags' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array_values( array_filter( (array) ( $data['shop']['customerTags']['nodes'] ?? [] ), 'is_string' ) );
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
			"mutation RedeemIDTags(\$id: ID!, \$tags: [String!]!) { {$mutation}(id: \$id, tags: \$tags) { userErrors { field message } } }",
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
