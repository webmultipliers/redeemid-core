<?php

namespace WP2_Shopify\Events;

class Registry {
	private const TOPIC_MAP = [
		'customers/marketing_consent/sms/update'   => CustomerMarketingConsentSmsEvent::class,
		'customers/marketing_consent/email/update' => CustomerMarketingConsentEmailEvent::class,
		'customers/add_tags'                       => CustomerTagsAddedEvent::class,
		'customer.tags_added'                      => CustomerTagsAddedEvent::class,
		'customers/remove_tags'                    => CustomerTagsRemovedEvent::class,
		'customers/disable'                        => CustomerDisabledEvent::class,
		'customers/enable'                         => CustomerEnabledEvent::class,
		'customer_groups/create'                   => CustomerGroupCreatedEvent::class,
		'customer_groups/update'                   => CustomerGroupUpdatedEvent::class,
		'customer_groups/delete'                   => CustomerGroupDeletedEvent::class,
		'customers/purchasing_summary/update'      => CustomerPurchasingSummaryEvent::class,
		'customers/update'                         => CustomerUpdatedEvent::class,
		'customers/account_settings/update'        => CustomerAccountSettingsEvent::class,
		'customers/create'                         => CustomerCreatedEvent::class,
		'customers/delete'                         => CustomerDeletedEvent::class,
	];

	public static function resolve( string $topic ): ?string {
		$normalized = strtolower( trim( $topic ) );

		return self::TOPIC_MAP[ $normalized ] ?? NULL;
	}
}
