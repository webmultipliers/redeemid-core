<?php

namespace WP2_Shopify\Events;

class CustomerTagsAddedEvent extends AbstractEvent {
	protected function process(): void {
		$user = $this->get_customer_user();

		if ( ! $user ) {
			return;
		}

		$tags = $this->resolve_tags();

		if ( ! empty( $tags ) ) {
			$current = (array) get_user_meta( $user->ID, '_wp2_shopify_tags', true );
			$updated = array_values( array_unique( array_merge( $current, $tags ) ) );

			CustomerUpdater::update_tags( $user, $updated );
		}

		CustomerUpdater::set_last_sync( $user );
		$this->mark_event( $user );
	}
}
