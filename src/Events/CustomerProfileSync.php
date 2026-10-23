<?php

namespace WP2_Shopify\Events;

use WP_User;

trait CustomerProfileSync {
	private function update_wp_user_data( WP_User $user ): void {
		$root = $this->get_payload_root();

		$first_name = sanitize_text_field( (string) ( $root['first_name'] ?? $root['firstName'] ?? '' ) );
		$last_name  = sanitize_text_field( (string) ( $root['last_name'] ?? $root['lastName'] ?? '' ) );

		$update = [ 'ID' => $user->ID ];

		if ( $first_name !== '' && $first_name !== $user->first_name ) {
			$update['first_name'] = $first_name;
		}

		if ( $last_name !== '' && $last_name !== $user->last_name ) {
			$update['last_name'] = $last_name;
		}

		if ( count( $update ) > 1 ) {
			wp_update_user( $update );
		}
	}

	private function update_marketing_consent( WP_User $user ): void {
		$root = $this->get_payload_root();

		$email_state = $root['marketing_consent']['email']['state'] ?? '';
		if ( '' !== $email_state ) {
			CustomerUpdater::save_meta( $user, '_wp2_shopify_email_marketing_consent', sanitize_text_field( (string) $email_state ) );
		} elseif ( isset( $root['accepts_marketing'] ) ) {
			$status = filter_var( $root['accepts_marketing'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			if ( null !== $status ) {
				CustomerUpdater::save_meta( $user, '_wp2_shopify_email_marketing_consent', $status ? 'subscribed' : 'unsubscribed' );
			}
		}

		$sms_state = $root['marketing_consent']['sms']['state'] ?? '';
		if ( '' !== $sms_state ) {
			CustomerUpdater::save_meta( $user, '_wp2_shopify_sms_marketing_consent', sanitize_text_field( (string) $sms_state ) );
		}
	}

	private function update_purchasing_summary( WP_User $user ): void {
		$root = $this->get_payload_root();

		if ( isset( $root['orders_count'] ) && isset( $root['total_spent'] ) ) {
			$currency = sanitize_text_field( (string) ( $root['currency'] ?? 'USD' ) );
			$summary  = [
				'orders_count' => (int) $root['orders_count'],
				'total_spent'  => (float) $root['total_spent'],
				'currency'     => $currency,
			];

			CustomerUpdater::save_meta( $user, '_wp2_shopify_purchasing_summary', wp_json_encode( $summary ) );
		}
	}

	private function get_payload_root(): array {
		$customer = $this->payload['customer'] ?? null;

		if ( is_array( $customer ) ) {
			return $customer;
		}

		return $this->payload;
	}
}
