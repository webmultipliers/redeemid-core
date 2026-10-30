<?php

namespace RedeemID\Admin;

use RedeemID\Commerce\Pages;
use RedeemID\Config;

/**
 * A Shopify menu in the admin bar for staff: the store's products,
 * collections, orders and customers in the Shopify admin, and on a
 * storefront page, a link that edits that product or collection there.
 * Links only; nothing is read from Shopify to build them.
 */
final class AdminBar {
	public const NODE = 'redeemid-shopify';

	public static function register(): void {
		add_action( 'admin_bar_menu', [ self::class, 'menu' ], 80 );
	}

	/**
	 * The Shopify admin URL for a path under the store, or '' when the
	 * store domain isn't a myshopify.com one. Pure.
	 */
	public static function admin_url( string $store_domain, string $path = '' ): string {
		if ( ! preg_match( '/^([a-z0-9][a-z0-9-]*)\.myshopify\.com$/', strtolower( $store_domain ), $m ) ) {
			return '';
		}

		return 'https://admin.shopify.com/store/' . $m[1] . ( '' !== $path ? '/' . ltrim( $path, '/' ) : '' );
	}

	/**
	 * The numeric ID at the end of a Shopify GID, or ''. Pure.
	 */
	public static function numeric_id( string $gid ): string {
		return preg_match( '#/(\d+)$#', $gid, $m ) ? $m[1] : '';
	}

	public static function menu( \WP_Admin_Bar $bar ): void {
		$store = Config::store_domain();

		if ( ! current_user_can( Menu::CAP ) || '' === self::admin_url( $store ) ) {
			return;
		}

		$bar->add_node(
			[
				'id'    => self::NODE,
				'title' => __( 'Shopify', 'redeemid' ),
				'href'  => self::admin_url( $store ),
				'meta'  => [ 'target' => '_blank', 'rel' => 'noopener' ],
			]
		);

		$request = is_admin() ? null : Pages::request();
		$entity  = $request['entity'] ?? null;
		$id      = is_array( $entity ) ? self::numeric_id( (string) ( $entity['id'] ?? '' ) ) : '';

		if ( '' !== $id ) {
			$bar->add_node(
				[
					'parent' => self::NODE,
					'id'     => self::NODE . '-edit',
					'title'  => Pages::PRODUCT === $request['kind'] ? __( 'Edit this product in Shopify', 'redeemid' ) : __( 'Edit this collection in Shopify', 'redeemid' ),
					'href'   => self::admin_url( $store, ( Pages::PRODUCT === $request['kind'] ? 'products/' : 'collections/' ) . $id ),
					'meta'   => [ 'target' => '_blank', 'rel' => 'noopener' ],
				]
			);
		}

		$links = [
			'products'    => __( 'Products', 'redeemid' ),
			'collections' => __( 'Collections', 'redeemid' ),
			'orders'      => __( 'Orders', 'redeemid' ),
			'customers'   => __( 'Customers', 'redeemid' ),
		];

		foreach ( $links as $path => $title ) {
			$bar->add_node(
				[
					'parent' => self::NODE,
					'id'     => self::NODE . '-' . $path,
					'title'  => $title,
					'href'   => self::admin_url( $store, $path ),
					'meta'   => [ 'target' => '_blank', 'rel' => 'noopener' ],
				]
			);
		}
	}
}
