<?php

namespace WP2_Shopify\Events;

class CustomerPurchasingSummaryEvent extends AbstractEvent {
	protected function process(): void {
		$user = $this->get_customer_user();

		if ( $user ) {
			CustomerUpdater::save_meta(
				$user,
				'_wp2_shopify_purchasing_summary',
				wp_json_encode( $this->payload )
			);

			CustomerUpdater::set_last_sync( $user );
			$this->mark_event( $user );
		}

		$this->record_global_event( 'wp2_shopify_last_purchasing_summary_event' );
	}
}
