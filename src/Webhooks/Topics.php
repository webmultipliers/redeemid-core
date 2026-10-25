<?php

namespace WP2_Shopify\Webhooks;

/**
 * Webhook topics verified against Shopify API 2026-10 on 2026-10-23
 * (Admin REST "Webhook" resource topic list).
 *
 * Note: tags left the customers/* payloads in 2025-01; tag changes arrive only
 * on customer.tags_added / customer.tags_removed, so those two must stay
 * subscribed. The POC's customers/add_tags and customers/remove_tags
 * do not exist and are not mapped.
 */
final class Topics {
	public const PROFILE    = 'profile';
	public const DELETE     = 'delete';
	public const STATUS     = 'status';
	public const MERGE      = 'merge';
	public const TAGS       = 'tags';
	public const CONSENT    = 'consent';
	public const SUMMARY    = 'summary';
	public const COMPLIANCE = 'compliance';
	public const UNINSTALL  = 'uninstalled';
	public const IGNORED    = 'ignored';

	private const MAP = [
		'customers/create'                          => self::PROFILE,
		'customers/update'                          => self::PROFILE,
		'customer_account_settings/update'          => self::IGNORED,
		'customers/delete'                          => self::DELETE,
		'customers/disable'                         => self::STATUS,
		'customers/enable'                          => self::STATUS,
		'customers/merge'                           => self::MERGE,
		'customer.tags_added'                       => self::TAGS,
		'customer.tags_removed'                     => self::TAGS,
		'customers_email_marketing_consent/update'  => self::CONSENT,
		'customers_marketing_consent/update'        => self::CONSENT,
		'customers/purchasing_summary'              => self::SUMMARY,
		// Customer groups are deprecated in favour of segments; acknowledged, never stored.
		'customer_groups/create'                    => self::IGNORED,
		'customer_groups/update'                    => self::IGNORED,
		'customer_groups/delete'                    => self::IGNORED,
		'customers/data_request'                    => self::COMPLIANCE,
		'customers/redact'                          => self::COMPLIANCE,
		'shop/redact'                               => self::COMPLIANCE,
		'app/uninstalled'                           => self::UNINSTALL,
		// Subscribed by earlier versions for purchase-to-tag, now done in Shopify Flow
		// or another app. Acknowledged so old subscriptions don't dead-letter.
		'orders/paid'                               => self::IGNORED,
		'orders/cancelled'                          => self::IGNORED,
		'refunds/create'                            => self::IGNORED,
	];

	public static function kind( string $topic ): ?string {
		return self::MAP[ strtolower( trim( $topic ) ) ] ?? null;
	}

	/**
	 * Topics the merchant should subscribe to, for the diagnostics page and docs.
	 *
	 * @return string[]
	 */
	public static function subscribable(): array {
		return array_keys( array_filter( self::MAP, static fn( $kind ) => self::IGNORED !== $kind ) );
	}
}
