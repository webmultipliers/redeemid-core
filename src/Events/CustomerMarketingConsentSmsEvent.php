<?php

namespace WP2_Shopify\Events;

class CustomerMarketingConsentSmsEvent extends AbstractEvent {
	protected function process(): void {
		$user = $this->get_customer_user();

		if ( ! $user ) {
			return;
		}

		$state = $this->payload['marketing_consent']['sms']['state'] ?? $this->payload['state'] ?? '';

		if ( '' !== $state ) {
			CustomerUpdater::save_meta( $user, '_wp2_shopify_sms_marketing_consent', $state );
		}

		CustomerUpdater::set_last_sync( $user );
		$this->mark_event( $user );
	}
}
