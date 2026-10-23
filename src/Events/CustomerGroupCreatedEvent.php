<?php

namespace WP2_Shopify\Events;

class CustomerGroupCreatedEvent extends AbstractEvent {
	protected function process(): void {
		$this->record_global_event( 'wp2_shopify_last_customer_group_event' );
	}
}
