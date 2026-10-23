<?php

namespace WP2_Shopify\Events;

class CustomerTagsRemovedEvent extends AbstractEvent {
	protected function process(): void {
		$user = $this->get_customer_user();

		if ( ! $user ) {
			return;
		}

		$tags = $this->resolve_tags();

		if ( ! empty( $tags ) ) {
			$current = (array) get_user_meta( $user->ID, '_wp2_shopify_tags', true );
			$remaining = array_values( array_diff( $current, $tags ) );

			CustomerUpdater::update_tags( $user, $remaining );
		}

		CustomerUpdater::set_last_sync( $user );
		$this->mark_event( $user );
	}
}
