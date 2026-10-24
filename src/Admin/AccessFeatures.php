<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Access\Explain;
use WP2_Shopify\Features\Feature;
use WP2_Shopify\Features\Grant;
use WP2_Shopify\Features\Registry;
use WP2_Shopify\Features\Route;

/**
 * Shopify → Access → Features: every registered feature, who gets it in
 * plain language, how it is enforced and cached, and the mapping form.
 *
 * Features are registered in code; this screen only maps who gets them.
 * One stored condition per feature: the level screen's "Features" boxes
 * edit the same store. A feature whose registration sets its condition is
 * managed in code and read-only here.
 */
final class AccessFeatures {
	public const ACTION = 'wp2_shopify_features';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'action' ] );
	}

	public static function action(): void {
		Menu::guard( self::ACTION );

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
			'no_cache'        => __( 'No caching option declared, so pages that use it are treated as private.', 'wp2-shopify' ),
			'bad_enforcement' => __( 'No known enforcement point declared; treated as a PHP check.', 'wp2-shopify' ),
			'unknown_level'   => __( 'Names a level that does not exist, so that route grants nobody.', 'wp2-shopify' ),
			'unknown_test'    => __( 'Names a custom test nobody registered, so that route grants nobody.', 'wp2-shopify' ),
			'bad_test'        => __( 'Has a test of an unknown type, so that route grants nobody.', 'wp2-shopify' ),
		];
		$points = [
			Feature::PHP       => __( 'PHP check', 'wp2-shopify' ),
			Feature::BLOCK     => __( 'block visibility', 'wp2-shopify' ),
			Feature::BODY      => __( 'body class', 'wp2-shopify' ),
			Feature::REST      => __( 'REST field', 'wp2-shopify' ),
			Feature::SHORTCODE => __( 'shortcode', 'wp2-shopify' ),
		];
		$caches = [
			Feature::CACHE_PRIVATE  => __( 'private: pages that use it are not shared-cached', 'wp2-shopify' ),
			Feature::CACHE_VARY     => __( 'vary: cache varies on the granted-set cookie', 'wp2-shopify' ),
			Feature::CACHE_FRAGMENT => __( 'fragment: only a per-visitor fragment varies', 'wp2-shopify' ),
		];
		$preview = null;

		if ( '' !== $tags || $user_id ) {
			$member  = $user_id ? Explain::member( Explain::AS_USER, [], $user_id ) : Explain::member( Explain::AS_TAGS, explode( ',', $tags ) );
			$preview = Registry::resolve( $member, $user_id );
		}
		?>
		<p style="max-width:760px"><?php esc_html_e( 'A feature is a site behaviour a customer unlocks because of what they bought in Shopify: ad-free reading, downloads, a member badge. Features are registered in code by a theme or plugin; here you choose who gets each one. Each line is one route; any route grants the feature, and it drops only when every route is lost.', 'wp2-shopify' ); ?></p>
		<div class="notice notice-info inline" style="max-width:760px">
			<p>
				<?php esc_html_e( 'Features are granted by Shopify entitlement, never by hand in WordPress: the next sync would undo it. To give one customer a feature, give them the level\'s tag or the add-on tag in Shopify.', 'wp2-shopify' ); ?>
				<a href="<?php echo esc_url( Menu::url( Customers::PAGE ) ); ?>"><?php esc_html_e( 'Shopify → Customers edits tags in Shopify.', 'wp2-shopify' ); ?></a>
			</p>
		</div>

		<?php if ( ! $features ) : ?>
			<p><strong><?php esc_html_e( 'No features are registered yet.', 'wp2-shopify' ); ?></strong> <?php esc_html_e( 'A theme or plugin registers them with the wp2_shopify_features filter; see docs/features.md.', 'wp2-shopify' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:1200px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Feature', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Who gets it', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Enforcement and caching', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Health', 'wp2-shopify' ); ?></th>
						<?php if ( $preview ) : ?>
							<th><?php esc_html_e( 'Preview', 'wp2-shopify' ); ?></th>
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
							<code><?php echo esc_html( $slug ); ?></code>
							<?php if ( $feature->description ) : ?>
								<p class="description" style="margin:4px 0 0"><?php echo esc_html( $feature->description ); ?></p>
							<?php endif; ?>
							<p style="margin:4px 0 0">
								<?php
								$flags = array_filter(
									[
										$feature->managed ? __( 'managed in code', 'wp2-shopify' ) : '',
										$feature->sensitive ? __( 'sensitive', 'wp2-shopify' ) : '',
										$feature->primary ? __( 'in member menu', 'wp2-shopify' ) : '',
										$feature->js ? __( 'JS signal', 'wp2-shopify' ) : '',
									]
								);
								echo esc_html( implode( ' · ', $flags ) );
								?>
							</p>
						</td>
						<td>
							<?php if ( ! $feature->routes ) : ?>
								<?php esc_html_e( 'Nobody yet: no condition mapped.', 'wp2-shopify' ); ?>
							<?php else : ?>
								<?php foreach ( $feature->routes as $i => $route ) : ?>
									<?php echo $i ? esc_html__( 'or ', 'wp2-shopify' ) : ''; ?><?php echo esc_html( $resolver->describe( $route ) ); ?><br>
								<?php endforeach; ?>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( implode( ', ', array_map( static fn( $p ) => $points[ $p ] ?? $p, $feature->enforcement ) ) ); ?><br>
							<span class="description"><?php echo esc_html( $caches[ $feature->cache ] ?? $feature->cache ); ?></span>
						</td>
						<td>
							<?php if ( ! $problems ) : ?>
								<?php echo Menu::dot( true ) . esc_html__( 'OK', 'wp2-shopify' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php else : ?>
								<?php foreach ( $problems as $problem ) : ?>
									<?php echo Menu::dot( false ) . esc_html( $words[ $problem ] ?? $problem ); // phpcs:ignore WordPress.Security.EscapeOutput ?><br>
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
									? esc_html( sprintf( __( 'Granted, from %s', 'wp2-shopify' ), implode( __( ' and ', 'wp2-shopify' ), $grant->routes ) ) )
									/* translators: %s: reason code */
									: esc_html( sprintf( __( 'Not granted (%s)', 'wp2-shopify' ), $grant?->reason ?? Grant::UNREGISTERED ) );
								?>
							</td>
						<?php endif; ?>
						<td>
							<?php if ( ! $feature->managed ) : ?>
								<a class="button" href="<?php echo esc_url( Menu::url( Access::PAGE, [ 'tab' => 'features', 'edit' => $slug ] ) . '#feature-form' ); ?>"><?php esc_html_e( 'Map', 'wp2-shopify' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php foreach ( Registry::orphans() as $orphan ) : ?>
			<p>
				<?php echo Menu::dot( false ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php
				/* translators: %s: feature slug */
				echo esc_html( sprintf( __( 'A condition is stored for %s, which is no longer registered. It grants nothing.', 'wp2-shopify' ), $orphan ) );
				?>
				<?php Menu::action_button( self::ACTION, 'forget_feature', __( 'Forget it', 'wp2-shopify' ), [ 'slug' => $orphan ] ); ?>
			</p>
		<?php endforeach; ?>

		<h2><?php esc_html_e( 'Preview', 'wp2-shopify' ); ?></h2>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( Access::PAGE ); ?>">
			<input type="hidden" name="tab" value="features">
			<label><?php esc_html_e( 'A synced customer with these tags', 'wp2-shopify' ); ?> <input name="preview_tags" class="regular-text" placeholder="silver, ad-free-addon" value="<?php echo esc_attr( $tags ); ?>"></label>
			<label><?php esc_html_e( 'or a real user ID', 'wp2-shopify' ); ?> <input name="preview_user" type="number" min="0" class="small-text" value="<?php echo esc_attr( $user_id ? (string) $user_id : '' ); ?>"></label>
			<?php submit_button( __( 'Preview', 'wp2-shopify' ), 'secondary', '', false ); ?>
			<p class="description"><?php esc_html_e( 'Shows which route grants each feature. The Test tab prints the full trace for any viewer.', 'wp2-shopify' ); ?></p>
		</form>

		<?php if ( $edit && ! $edit->managed ) : ?>
			<h2 id="feature-form">
				<?php
				/* translators: %s: feature label */
				echo esc_html( sprintf( __( 'Who gets %s', 'wp2-shopify' ), $edit->label ) );
				?>
			</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="do" value="save_feature">
				<input type="hidden" name="slug" value="<?php echo esc_attr( $edit->slug ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<textarea name="routes" rows="4" class="large-text code" placeholder="gold&#10;Add-on purchase = tag:ad-free-addon"><?php echo esc_textarea( Route::format( $edit->routes ) ); ?></textarea>
				<p class="description" style="max-width:760px">
					<?php esc_html_e( 'One route per line; every test in a line must pass, and any line grants the feature. A test is a level slug (gold), tag:<tag>, or test:<name> for a custom test. Start a line with "Label = " to name the route on the account card ("Add-on purchase = tag:ad-free-addon"); otherwise it is named after its level. public and signed-in are levels too. Empty: nobody gets it.', 'wp2-shopify' ); ?>
				</p>
				<div class="notice notice-warning inline" style="max-width:760px"><p><?php esc_html_e( 'Features are site-wide: saving changes behaviour on every page that uses this feature, and caches are told to purge the whole site.', 'wp2-shopify' ); ?></p></div>
				<?php submit_button( __( 'Save', 'wp2-shopify' ) ); ?>
			</form>
		<?php endif; ?>
		<?php
	}
}
