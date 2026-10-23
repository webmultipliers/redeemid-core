<?php

namespace WP2_Shopify\Events;

class CustomerUpdatedEvent extends AbstractEvent {
	use CustomerProfileSync;

	protected function process(): void {
		$user = $this->get_customer_user();

		if ( ! $user ) {
			return;
		}

		CustomerUpdater::sync_profile_snapshot( $user, $this->payload );

		CustomerUpdater::save_meta(
			$user,
			'_wp2_shopify_customer_payload',
			wp_json_encode( $this->payload )
		);

		$this->update_wp_user_data( $user );
		$this->update_marketing_consent( $user );
		$this->update_purchasing_summary( $user );

		if ( isset( $this->payload['tags'] ) || isset( $this->payload['customer']['tags'] ) ) {
			$tags = $this->resolve_tags();

			if ( ! empty( $tags ) ) {
				CustomerUpdater::update_tags( $user, $tags );
			}
		}

		CustomerUpdater::set_last_sync( $user );
		$this->mark_event( $user );
	}
}
