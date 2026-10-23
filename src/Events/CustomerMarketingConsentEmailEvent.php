<?php

namespace WP2_Shopify\Events;

class CustomerMarketingConsentEmailEvent extends AbstractEvent {
	protected function process(): void {
		$user = $this->get_customer_user();

		if ( ! $user ) {
			return;
		}

		$state = $this->payload['marketing_consent']['email']['state'] ?? $this->payload['state'] ?? '';

		if ( '' !== $state ) {
			CustomerUpdater::save_meta( $user, '_wp2_shopify_email_marketing_consent', $state );
		}

		CustomerUpdater::set_last_sync( $user );
		$this->mark_event( $user );
	}
}
