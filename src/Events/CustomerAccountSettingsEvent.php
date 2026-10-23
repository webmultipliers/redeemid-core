<?php

namespace WP2_Shopify\Events;

class CustomerAccountSettingsEvent extends AbstractEvent {
	protected function process(): void {
		$user = $this->get_customer_user();

		if ( ! $user ) {
			return;
		}

		CustomerUpdater::save_meta(
			$user,
			'_wp2_shopify_account_settings',
			wp_json_encode( $this->payload )
		);

		CustomerUpdater::set_last_sync( $user );
		$this->mark_event( $user );
	}
}
