<?php
/**
 * Shopify Identity summary that appears on user profile screens.
 *
 * Expected variables before include:
 * - string $customer_id
 * - array  $tags
 * - string $shop_status
 * - string $email_consent
 * - string $sms_consent
 * - string $last_sync
 * - array  $summary
 * - string $badge_style
 * - array  $account_settings
 * - string $green_badge
 * - string $gray_badge
 * - string $red_badge
 */
?>

<?php
if ( ! did_action( 'wp2_profile_stats_style' ) ) {
	?>
	<style>
		:root {
			--brand-cream: #f4eddc;
			--brand-purple: #9f9ffc;
			--brand-blue: #8ecdf4;
			--brand-acid: #bbff27;
			--brand-pink: #f37df0;
			--brand-dark: #2a2a2a;
			--font-head: 'Satoshi', sans-serif;
			--font-body: 'Lato', sans-serif;
			--border: 2px solid var(--brand-dark);
			--hard-shadow: 4px 4px 0 var(--brand-dark);
		}

		.wp-profile-mimic {
			max-width: 840px;
			margin: 2rem auto;
			background: #fff;
			padding: 2rem;
			border: var(--border);
			box-shadow: var(--hard-shadow);
			background-color: var(--brand-cream);
			background-image: radial-gradient(var(--brand-dark) 1px, transparent 1px);
			background-size: 30px 30px;
			font-family: var(--font-body);
		}

		.wp-profile-stats-title {
			font-family: var(--font-head);
			font-weight: 900;
			font-size: 2rem;
			margin-top: 0;
			margin-bottom: 1.5rem;
			padding-bottom: 1rem;
			border-bottom: var(--border);
			position: relative;
		}

		.wp-profile-stats-title::after {
			content: '';
			position: absolute;
			bottom: 10px;
			left: 0;
			width: 120px;
			height: 8px;
			background-color: var(--brand-purple);
			opacity: 0.8;
			transform: skewX(-12deg);
		}

		.wp-stats-table {
			width: 100%;
			border-collapse: collapse;
			background: white;
			border: var(--border);
			box-shadow: 4px 4px 0 rgba(0, 0, 0, 0.1);
			margin-top: 1rem;
		}

		.wp-stats-table th,
		.wp-stats-table td {
			padding: 1.25rem;
			text-align: left;
			border-bottom: 1px solid #ddd;
			vertical-align: top;
		}

		.wp-stats-table tr:last-child th,
		.wp-stats-table tr:last-child td {
			border-bottom: none;
		}

		.wp-stats-table th {
			width: 220px;
			font-family: var(--font-head);
			font-weight: 700;
			font-size: 0.95rem;
			background-color: #fafafa;
			border-right: 1px solid #ddd;
			color: #383838;
			text-transform: uppercase;
			letter-spacing: 0.05em;
		}

		.badge {
			display: inline-block;
			padding: 4px 12px;
			border: 2px solid var(--brand-dark);
			font-family: var(--font-head);
			font-weight: 700;
			font-size: 0.75rem;
			text-transform: uppercase;
			box-shadow: 2px 2px 0 var(--brand-dark);
			margin-right: 8px;
			margin-bottom: 4px;
		}

		.badge-linked {
			background-color: var(--brand-acid);
			color: var(--brand-dark);
		}

		.badge-enabled {
			background-color: var(--brand-blue);
			color: var(--brand-dark);
		}

		.badge-disabled {
			background-color: var(--brand-pink);
			color: var(--brand-dark);
		}

		.badge-highlight {
			background-color: white;
			border: 1px solid #aaa;
			box-shadow: 1px 1px 0 #aaa;
			font-size: 0.7rem;
			font-family: var(--font-body);
			text-transform: none;
		}

		.code-block {
			display: inline-block;
			background: #2a2a2a;
			color: var(--brand-acid);
			padding: 6px 12px;
			border: 2px solid var(--brand-dark);
			font-family: monospace;
			font-weight: 700;
			box-shadow: 3px 3px 0 var(--brand-purple);
		}

		.data-grid {
			display: flex;
			gap: 2rem;
			flex-wrap: wrap;
		}

		.data-item strong {
			font-family: var(--font-head);
			display: block;
			font-size: 0.65rem;
			text-transform: uppercase;
			color: #666;
			margin-bottom: 4px;
			letter-spacing: 0.08em;
		}

		.data-item span {
			font-weight: 700;
			font-size: 1.1rem;
		}

		.description {
			font-style: italic;
			color: #666;
			font-size: 0.9rem;
		}
	</style>
	<?php
	do_action( 'wp2_profile_stats_style' );
}
?>

<?php
$shopify_full_name = trim( sprintf( '%s %s', $shopify_first_name ?? '', $shopify_last_name ?? '' ) );
$default_address_lines = [];

if ( ! empty( $default_address ) && is_array( $default_address ) ) {
	$street = trim( ( $default_address['address1'] ?? '' ) . ' ' . ( $default_address['address2'] ?? '' ) );

	if ( '' !== $street ) {
		$default_address_lines[] = $street;
	}

	$local_parts = array_filter(
		[
			$default_address['city'] ?? '',
			$default_address['province'] ?? '',
			$default_address['zip'] ?? '',
		]
	);

	if ( ! empty( $local_parts ) ) {
		$default_address_lines[] = implode( ', ', $local_parts );
	}

	if ( ! empty( $default_address['country'] ) ) {
		$default_address_lines[] = $default_address['country'];
	}
}

$default_address_html = $default_address_lines ? implode( '<br>', array_map( 'esc_html', $default_address_lines ) ) : '';

$format_setting_label = function ( $setting ) {
	$clean = str_replace( [ '_', '-' ], ' ', (string) $setting );
	$clean = trim( preg_replace( '/\s+/', ' ', $clean ) );

	return ucwords( $clean );
};

$render_setting_value = function ( $value ) {
	if ( is_bool( $value ) ) {
		return $value ? 'Yes' : 'No';
	}

	if ( is_scalar( $value ) ) {
		return (string) $value;
	}

	$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	return $json ? trim( $json ) : '—';
};
?>

<div class="wp-profile-mimic">
	<h2 class="wp-profile-stats-title">Shopify Identity</h2>
	<table class="wp-stats-table" role="presentation">
		<tr>
			<th><label>Connection Status</label></th>
			<td>
				<?php if ( $customer_id ) : ?>
					<span class="badge badge-linked">Linked</span>
					<?php if ( $shop_status ) : ?>
						<span class="badge <?php echo 'enabled' === $shop_status ? 'badge-enabled' : 'badge-disabled'; ?>">
							<?php echo esc_html( ucfirst( $shop_status ) ); ?>
						</span>
					<?php endif; ?>
				<?php else : ?>
					<span class="badge badge-disabled">Not Connected</span>
				<?php endif; ?>
			</td>
		</tr>

		<?php if ( $customer_id ) : ?>
			<tr>
				<th><label>Customer ID</label></th>
				<td>
					<code class="code-block"><?php echo esc_html( $customer_id ); ?></code>
				</td>
			</tr>

			<tr>
				<th><label>Tags</label></th>
				<td>
					<?php if ( ! empty( $tags ) ) : ?>
						<?php foreach ( $tags as $tag ) : ?>
							<span class="badge badge-highlight"><?php echo esc_html( $tag ); ?></span>
						<?php endforeach; ?>
					<?php else : ?>
						<span class="description">No tags synced.</span>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th><label>Contact Snapshot</label></th>
				<td>
					<div class="data-grid">
						<div class="data-item">
							<strong>Name</strong>
							<span><?php echo $shopify_full_name ? esc_html( $shopify_full_name ) : '—'; ?></span>
						</div>
						<div class="data-item">
							<strong>Email</strong>
							<span><?php echo $shopify_email ? esc_html( $shopify_email ) : '—'; ?></span>
						</div>
						<div class="data-item">
							<strong>Phone</strong>
							<span><?php echo $shopify_phone ? esc_html( $shopify_phone ) : '—'; ?></span>
						</div>
					</div>
				</td>
			</tr>

				<tr>
					<th><label>Marketing</label></th>
					<td>
						<div class="data-grid">
							<div class="data-item">
								<strong>Email</strong>
								<span><?php echo $email_consent ? esc_html( ucfirst( str_replace( '_', ' ', $email_consent ) ) ) : '—'; ?></span>
							</div>
							<div class="data-item">
								<strong>SMS</strong>
								<span><?php echo $sms_consent ? esc_html( ucfirst( str_replace( '_', ' ', $sms_consent ) ) ) : '—'; ?></span>
							</div>
						</div>
					</td>
				</tr>

				<?php if ( ! empty( $account_settings ) ) : ?>
					<tr>
						<th><label>Account Settings</label></th>
						<td>
							<div class="data-grid">
								<?php foreach ( $account_settings as $setting => $value ) : ?>
									<div class="data-item">
										<strong><?php echo esc_html( $format_setting_label( $setting ) ); ?></strong>
										<span><?php echo esc_html( $render_setting_value( $value ) ); ?></span>
									</div>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>
				<?php endif; ?>

			<tr>
				<th><label>Lifetime Value</label></th>
				<td>
					<div class="data-grid">
						<div class="data-item">
							<strong>Total Spent</strong>
							<span>
								<?php
								if ( isset( $summary['total_spent'], $summary['currency'] ) ) {
									echo esc_html( $summary['total_spent'] . ' ' . $summary['currency'] );
								} else {
									echo esc_html( '—' );
								}
								?>
							</span>
						</div>
						<div class="data-item">
							<strong>Orders</strong>
							<span><?php echo isset( $summary['orders_count'] ) ? esc_html( $summary['orders_count'] ) : esc_html( '0' ); ?></span>
						</div>
					</div>
				</td>
			</tr>

			<tr>
				<th><label>Default Address</label></th>
				<td>
					<?php if ( $default_address_html ) : ?>
						<span><?php echo $default_address_html; ?></span>
					<?php else : ?>
						<span class="description">No default address synced.</span>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th><label>Last Synced</label></th>
				<td>
					<span class="description"><?php echo $last_sync ? esc_html( $last_sync ) : 'Never'; ?></span>
				</td>
			</tr>
		<?php endif; ?>
	</table>
</div>
