<?php

namespace WP2_Shopify\Events;

class CustomerGroupUpdatedEvent extends AbstractEvent {
	protected function process(): void {
		$this->record_global_event( 'wp2_shopify_last_customer_group_event' );
	}
}
