<?php

namespace RedeemID\Admin;

use RedeemID\Access\Explain;
use RedeemID\Features\Feature;
use RedeemID\Features\Grant;
use RedeemID\Features\Registry;
use RedeemID\Features\Route;

/**
 * RedeemID → Access → Features: every registered feature, who gets it in
 * plain language, how it is enforced and cached, and the mapping form.
 *
 * Features are registered in code; this screen only maps who gets them.
 * One stored condition per feature: the level screen's "Features" boxes
 * edit the same store. A feature whose registration sets its condition is
 * managed in code and read-only here.
 */
final class AccessFeatures {
	public const ACTION = 'redeemid_features';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'action' ] );
	}

	public static function action(): void {
		Menu::guard( self::ACTION );
		// phpcs:disable WordPress.Security.NonceVerification -- checked by Menu::guard().

		$do     = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$slug   = Feature::slug( sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) ) );
		$result = '';

		switch ( $do ) {
			case 'save_feature':
				$result = Registry::save( $slug, Route::parse( sanitize_textarea_field( wp_unslash( $_POST['routes'] ?? '' ) ) ) ) ? 'feature_saved' : 'managed';
				break;
			case 'forget_feature':
				Registry::forget( $slug );
				$result = 'feature_forgotten';
				break;
		}

		wp_safe_redirect( Menu::url( Access::PAGE, [ 'tab' => 'features', 'result' => $result ] ) );
		exit;
		// phpcs:enable WordPress.Security.NonceVerification
	}

	public static function render(): void {
		$features = Registry::features();
		$resolver = Registry::resolver();
		// phpcs:disable WordPress.Security.NonceVerification -- read-only.
		$edit    = Registry::get( Feature::slug( sanitize_text_field( wp_unslash( $_GET['edit'] ?? '' ) ) ) );
		$tags    = sanitize_text_field( wp_unslash( $_GET['preview_tags'] ?? '' ) );
		$user_id = absint( $_GET['preview_user'] ?? 0 );
		// phpcs:enable
		$words = [
			'no_cache'        => __( 'No caching option declared, so pages that use it are treated as private.', 'redeemid' ),
			'bad_enforcement' => __( 'No known enforcement point declared; treated as a PHP check.', 'redeemid' ),
			'unknown_level'   => __( 'Names a level that does not exist, so that route grants nobody.', 'redeemid' ),
			'unknown_test'    => __( 'Names a custom test nobody registered, so that route grants nobody.', 'redeemid' ),
			'bad_test'        => __( 'Has a test of an unknown type, so that route grants nobody.', 'redeemid' ),
		];
		$points = [
			Feature::PHP       => __( 'PHP check', 'redeemid' ),
			Feature::BLOCK     => __( 'block visibility', 'redeemid' ),
			Feature::BODY      => __( 'body class', 'redeemid' ),
			Feature::REST      => __( 'REST field', 'redeemid' ),
			Feature::SHORTCODE => __( 'shortcode', 'redeemid' ),
		];
		$caches = [
			Feature::CACHE_PRIVATE  => __( 'private: pages that use it are not shared-cached', 'redeemid' ),
			Feature::CACHE_VARY     => __( 'vary: cache varies on the granted-set cookie', 'redeemid' ),
			Feature::CACHE_FRAGMENT => __( 'fragment: only a per-visitor fragment varies', 'redeemid' ),
		];
		$preview = null;

		if ( '' !== $tags || $user_id ) {
			$member  = $user_id ? Explain::member( Explain::AS_USER, [], $user_id ) : Explain::member( Explain::AS_TAGS, explode( ',', $tags ) );
			$preview = Registry::resolve( $member, $user_id );
		}
		?>
		<div class="notice notice-info inline">
			<p>
				<?php esc_html_e( 'Features are granted by Shopify entitlement, never by hand in WordPress: the next sync would undo it. To give one customer a feature, give them the level\'s tag or the add-on tag in Shopify.', 'redeemid' ); ?>
				<a href="<?php echo esc_url( Menu::url( Customers::PAGE ) ); ?>"><?php esc_html_e( 'RedeemID → Customers edits tags in Shopify.', 'redeemid' ); ?></a>
			</p>
		</div>

		<?php if ( ! $features ) : ?>
			<p><strong><?php esc_html_e( 'No features are registered yet.', 'redeemid' ); ?></strong> <?php esc_html_e( 'A theme or plugin registers them with the redeemid_features filter; see docs/features.md.', 'redeemid' ); ?></p>
		<?php else : ?>
			<?php Menu::panel_start( __( 'Features', 'redeemid' ), __( 'Every feature registered by the theme or plugins, who gets it, and how it is enforced.', 'redeemid' ) ); ?>
			<div class="redeemid-panel__body redeemid-panel__body--flush redeemid-table-scroll">
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Feature', 'redeemid' ); ?></th>
						<th><?php esc_html_e( 'Who gets it', 'redeemid' ); ?></th>
						<th><?php esc_html_e( 'Enforcement and caching', 'redeemid' ); ?></th>
						<th><?php esc_html_e( 'Health', 'redeemid' ); ?></th>
						<?php if ( $preview ) : ?>
							<th><?php esc_html_e( 'Preview', 'redeemid' ); ?></th>
						<?php endif; ?>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $features as $slug => $feature ) : ?>
					<?php
					$problems = $feature->problems;

					foreach ( $feature->routes as $route ) {
						$problems = array_merge( $problems, $resolver->problems( $route ) );
					}

					$problems = array_values( array_unique( $problems ) );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $feature->label ); ?></strong><br>
							<code class="redeemid-code-small"><?php echo esc_html( $slug ); ?></code>
							<?php if ( $feature->description ) : ?>
								<p class="description redeemid-tight"><?php echo esc_html( $feature->description ); ?></p>
							<?php endif; ?>
							<p class="redeemid-tight">
								<?php
								$flags = array_filter(
									[
										$feature->managed ? __( 'managed in code', 'redeemid' ) : '',
										$feature->sensitive ? __( 'sensitive', 'redeemid' ) : '',
										$feature->primary ? __( 'in member menu', 'redeemid' ) : '',
										$feature->js ? __( 'JS signal', 'redeemid' ) : '',
									]
								);
								echo implode( ' ', array_map( static fn( $flag ) => '<span class="redeemid-chip">' . esc_html( $flag ) . '</span>', $flags ) ); // phpcs:ignore WordPress.Security.EscapeOutput
								?>
							</p>
						</td>
						<td>
							<?php if ( ! $feature->routes ) : ?>
								<?php esc_html_e( 'Nobody yet: no condition mapped.', 'redeemid' ); ?>
							<?php else : ?>
								<?php foreach ( $feature->routes as $i => $route ) : ?>
									<?php echo $i ? esc_html__( 'or ', 'redeemid' ) : ''; ?><?php echo esc_html( $resolver->describe( $route ) ); ?><br>
								<?php endforeach; ?>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( implode( ', ', array_map( static fn( $p ) => $points[ $p ] ?? $p, $feature->enforcement ) ) ); ?><br>
							<span class="description"><?php echo esc_html( $caches[ $feature->cache ] ?? $feature->cache ); ?></span>
						</td>
						<td>
							<?php if ( ! $problems ) : ?>
								<?php echo Menu::status( true, __( 'OK', 'redeemid' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php else : ?>
								<?php foreach ( $problems as $problem ) : ?>
									<p class="redeemid-problem"><?php echo Menu::dot( false ) . esc_html( $words[ $problem ] ?? $problem ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
								<?php endforeach; ?>
							<?php endif; ?>
						</td>
						<?php if ( $preview ) : ?>
							<td>
								<?php $grant = $preview[ $slug ] ?? null; ?>
								<?php echo Menu::dot( (bool) $grant?->granted ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php
								echo $grant?->granted
									/* translators: %s: route labels */
									? esc_html( sprintf( __( 'Granted, from %s', 'redeemid' ), implode( __( ' and ', 'redeemid' ), $grant->routes ) ) )
									/* translators: %s: reason code */
									: esc_html( sprintf( __( 'Not granted (%s)', 'redeemid' ), $grant?->reason ?? Grant::UNREGISTERED ) );
								?>
							</td>
						<?php endif; ?>
						<td>
							<?php if ( ! $feature->managed ) : ?>
								<a class="button button-small" href="<?php echo esc_url( Menu::url( Access::PAGE, [ 'tab' => 'features', 'edit' => $slug ] ) . '#feature-form' ); ?>"><?php esc_html_e( 'Map', 'redeemid' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php Menu::panel_end(); ?>
		<?php endif; ?>

		<?php foreach ( Registry::orphans() as $orphan ) : ?>
			<p>
				<?php echo Menu::dot( false ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php
				/* translators: %s: feature slug */
				echo esc_html( sprintf( __( 'A condition is stored for %s, which is no longer registered. It grants nothing.', 'redeemid' ), $orphan ) );
				?>
				<?php Menu::action_button( self::ACTION, 'forget_feature', __( 'Forget it', 'redeemid' ), [ 'slug' => $orphan ] ); ?>
			</p>
		<?php endforeach; ?>

		<?php Menu::panel_start( __( 'Preview', 'redeemid' ), __( 'Shows which route grants each feature. The Test tab prints the full trace for any viewer.', 'redeemid' ) ); ?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="redeemid-panel__body redeemid-inline-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( Access::PAGE ); ?>">
			<input type="hidden" name="tab" value="features">
			<label><?php esc_html_e( 'A synced customer with these tags', 'redeemid' ); ?> <input type="text" name="preview_tags" class="regular-text" placeholder="silver, ad-free-addon" value="<?php echo esc_attr( $tags ); ?>"></label>
			<label><?php esc_html_e( 'or a real user ID', 'redeemid' ); ?> <input name="preview_user" type="number" min="0" class="small-text" value="<?php echo esc_attr( $user_id ? (string) $user_id : '' ); ?>"></label>
			<?php submit_button( __( 'Preview', 'redeemid' ), 'secondary', '', false ); ?>
		</form>
		<?php Menu::panel_end(); ?>

		<?php if ( $edit && ! $edit->managed ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php
				/* translators: %s: feature label */
				Menu::panel_start( sprintf( __( 'Who gets %s', 'redeemid' ), $edit->label ), '', sprintf( '<a class="button" href="%s">%s</a>', esc_url( Menu::url( Access::PAGE, [ 'tab' => 'features' ] ) ), esc_html__( 'Cancel', 'redeemid' ) ), 'feature-form' );
				?>
				<div class="redeemid-panel__body">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="do" value="save_feature">
				<input type="hidden" name="slug" value="<?php echo esc_attr( $edit->slug ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<textarea name="routes" rows="4" class="large-text code" placeholder="gold&#10;Add-on purchase = tag:ad-free-addon"><?php echo esc_textarea( Route::format( $edit->routes ) ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'One route per line; every test in a line must pass, and any line grants the feature. A test is a level slug (gold), tag:<tag>, or test:<name> for a custom test. Start a line with "Label = " to name the route on the account card ("Add-on purchase = tag:ad-free-addon"); otherwise it is named after its level. public and signed-in are levels too. Empty: nobody gets it.', 'redeemid' ); ?>
				</p>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Features are site-wide: saving changes behaviour on every page that uses this feature, and caches are told to purge the whole site.', 'redeemid' ); ?></p></div>
				</div>
				<div class="redeemid-panel__footer">
					<?php submit_button( __( 'Save', 'redeemid' ), 'primary', 'submit', false ); ?>
				</div>
				<?php Menu::panel_end(); ?>
			</form>
		<?php endif; ?>
		<?php
	}
}
