<?php

namespace WP2_Shopify\Events;

class CustomerDeletedEvent extends AbstractEvent {
	protected function process(): void {
		$user = $this->get_customer_user();

		if ( ! $user ) {
			return;
		}

		CustomerUpdater::save_meta( $user, '_wp2_shopify_deleted_at', current_time( 'mysql' ) );
		$this->mark_event( $user );
	}
}
