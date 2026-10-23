<?php

namespace WP2_Shopify\Events;

class CustomerDisabledEvent extends AbstractEvent {
	protected function process(): void {
		$user = $this->get_customer_user();

		if ( ! $user ) {
			return;
		}

		CustomerUpdater::save_meta( $user, '_wp2_shopify_customer_status', 'disabled' );
		CustomerUpdater::set_last_sync( $user );
		$this->mark_event( $user );
	}
}
