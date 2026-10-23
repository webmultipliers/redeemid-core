<div class="wp2-settings-wrapper">
	<div class="wrap wp2-admin-wrap">
		<div class="wp2-header">
			<h1>Shopify Identity</h1>
			<p class="wp2-lead">Connect WordPress Users to Shopify Customer Accounts.</p>
		</div>

		<div class="shape-decor shape-1"></div>
		<div class="shape-decor shape-2"></div>

		<div class="wp2-card">
			<h2 class="wp2-card-title">Connection Settings</h2>
			<p class="wp2-settings-note">
				These values are pulled straight from your <code>wp-config.php</code>. Update the constants there and refresh this
				page to see the new configuration.
			</p>

			<div class="wp2-constants-grid">
				<?php foreach ( $env_constants as $constant ) : ?>
					<div class="wp2-constant-card">
						<div class="wp2-constant-heading">
							<span class="wp2-constant-label"><?php echo esc_html( $constant['label'] ); ?></span>
							<span
								class="wp2-constant-value wp2-constant-value--<?php echo $constant['value_present'] ? 'defined' : 'missing'; ?>">
								<?php echo esc_html( $constant['value'] ); ?>
							</span>
						</div>
						<p class="wp2-constant-description"><?php echo esc_html( $constant['description'] ); ?></p>
						<?php if ( ! $constant['value_present'] ) : ?>
							<p class="wp2-constant-missing"><?php echo esc_html( $constant['missing_help'] ); ?></p>
						<?php endif; ?>
						<p class="wp2-constant-meta">
							<span class="wp2-constant-name"><?php echo esc_html( $constant['constant'] ); ?></span>
							<span class="wp2-constant-status wp2-constant-status--<?php echo esc_attr( $constant['status_class'] ); ?>">
								<?php echo esc_html( $constant['status_label'] ); ?>
							</span>
						</p>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( ! empty( $app_requirements ) ) : ?>
				<div class="wp2-app-requirements">
					<h3 class="wp2-setup-title">Shopify App Requirements</h3>
					<p class="wp2-setup-note">
						Add these values to your Shopify App to ensure the OAuth flow, JS assets, and logout behavior match the plugin.
					</p>
					<div class="wp2-setup-grid">
						<?php foreach ( $app_requirements as $requirement ) : ?>
							<div class="wp2-setup-card">
								<span class="wp2-setup-label"><?php echo esc_html( $requirement['label'] ); ?></span>
								<?php if ( ! empty( $requirement['description'] ) ) : ?>
									<p class="wp2-setup-description">
										<?php echo esc_html( $requirement['description'] ); ?>
									</p>
								<?php endif; ?>
								<?php if ( ! empty( $requirement['values'] ) ) : ?>
									<?php foreach ( $requirement['values'] as $value ) : ?>
										<code class="wp2-setup-uri"><?php echo esc_url( $value ); ?></code>
									<?php endforeach; ?>
								<?php elseif ( ! empty( $requirement['empty_text'] ) ) : ?>
									<p class="wp2-setup-empty">
										<?php echo esc_html( $requirement['empty_text'] ); ?>
									</p>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<div class="wp2-actions">
				<button type="button" id="btn-verify" class="button button-secondary wp2-verify-btn">Verify Config</button>
			</div>
		</div>
		<div id="connection-feedback" class="wp2-feedback"></div>
	</div>
</div>
