<?php

namespace RedeemID\Admin;

use RedeemID\Access\BlockAccess;
use RedeemID\Access\Explain;
use RedeemID\Access\ItemRule;
use RedeemID\Access\Level;
use RedeemID\Access\LevelStore;
use RedeemID\Entitlement\Decision;
use RedeemID\Features\Registry;

/**
 * RedeemID → Access → Test: pick an item and a viewer, and see the whole
 * resolution trace (Access\Explain). A read-only GET form; it changes
 * nothing and never uses the editor bypass.
 */
final class AccessTest {
	public static function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only.
		$post_id = absint( $_GET['post'] ?? 0 );
		$as      = sanitize_key( wp_unslash( $_GET['as'] ?? Explain::AS_GUEST ) );
		$tags    = sanitize_text_field( wp_unslash( $_GET['tags'] ?? '' ) );
		$user_id = absint( $_GET['user'] ?? 0 );
		// phpcs:enable

		$levels = array_filter( LevelStore::levels()->all(), static fn( Level $l ) => ! $l->builtin() );
		$recent = get_posts(
			[
				'post_type'        => ItemRule::post_types() ?: [ 'post' ],
				'post_status'      => [ 'publish', 'future', 'draft', 'private' ],
				'posts_per_page'   => 100,
				'orderby'          => 'modified',
				'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams -- admin tool lists items ungated.
			]
		);
		$viewers = [
			Explain::AS_GUEST     => __( 'A guest', 'redeemid' ),
			Explain::AS_SIGNED_IN => __( 'A signed-in reader with no membership', 'redeemid' ),
		];

		foreach ( $levels as $slug => $level ) {
			/* translators: %s: level label */
			$viewers[ $slug ] = sprintf( __( 'A member holding %s', 'redeemid' ), $level->label );
		}

		$viewers[ Explain::AS_TAGS ] = __( 'A synced customer with these tags', 'redeemid' );
		$viewers[ Explain::AS_USER ] = __( 'A real user, from their synced record', 'redeemid' );
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( Access::PAGE ); ?>">
			<input type="hidden" name="tab" value="test">
			<?php Menu::panel_start( __( 'Who can read this, and why', 'redeemid' ) ); ?>
			<div class="redeemid-panel__body redeemid-panel__body--flush">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="redeemid-test-post"><?php esc_html_e( 'Item', 'redeemid' ); ?></label></th>
					<td>
						<select id="redeemid-test-post" name="post">
							<option value="0"><?php esc_html_e( 'Choose a recently edited item', 'redeemid' ); ?></option>
							<?php foreach ( $recent as $item ) : ?>
								<option value="<?php echo esc_attr( (string) $item->ID ); ?>" <?php selected( $post_id, $item->ID ); ?>><?php echo esc_html( get_the_title( $item ) . ' (' . $item->post_type . ' #' . $item->ID . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Not listed? Add &post=<ID> to this page\'s address.', 'redeemid' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="redeemid-test-as"><?php esc_html_e( 'Viewer', 'redeemid' ); ?></label></th>
					<td>
						<select id="redeemid-test-as" name="as">
							<?php foreach ( $viewers as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $as, $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="redeemid-inline-form">
							<label><?php esc_html_e( 'Tags', 'redeemid' ); ?> <input type="text" name="tags" class="regular-text" placeholder="gold, vip" value="<?php echo esc_attr( $tags ); ?>"></label>
							<label><?php esc_html_e( 'User ID', 'redeemid' ); ?> <input name="user" type="number" min="0" class="small-text" value="<?php echo esc_attr( $user_id ? (string) $user_id : '' ); ?>"></label>
						</p>
						<p class="description"><?php esc_html_e( 'Tags are used with "A synced customer with these tags", the user ID with "A real user". A real user is decided from their synced record, as on the site.', 'redeemid' ); ?></p>
					</td>
				</tr>
			</table>
			</div>
			<div class="redeemid-panel__footer">
				<?php submit_button( __( 'Check', 'redeemid' ), 'primary', '', false ); ?>
			</div>
			<?php Menu::panel_end(); ?>
		</form>
		<?php
		$post   = $post_id ? get_post( $post_id ) : null;
		$member = Explain::member( $as, explode( ',', $tags ), $user_id );

		if ( $post instanceof \WP_Post ) {
			self::render_trace( Explain::trace( $post, $member ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification -- read-only.
		if ( isset( $_GET['as'] ) && Registry::features() ) {
			self::render_features( Explain::features( $member, Explain::AS_USER === $as ? $user_id : 0 ) );
		}
	}

	/**
	 * The same granted-feature set the live check returns for this viewer,
	 * with which route passed and which test inside it.
	 */
	private static function render_features( array $rows ): void {
		?>
		<?php Menu::panel_start( __( 'Features for this viewer', 'redeemid' ) ); ?>
		<div class="redeemid-panel__body redeemid-panel__body--flush">
		<table class="widefat striped redeemid-kv">
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $row['label'] ); ?><br><code class="redeemid-code-small"><?php echo esc_html( $row['slug'] ); ?></code></th>
						<td>
							<?php echo Menu::dot( $row['granted'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<strong>
								<?php
								echo $row['granted']
									/* translators: %s: route labels */
									? esc_html( sprintf( __( 'Granted, from %s', 'redeemid' ), implode( __( ' and ', 'redeemid' ), $row['routes'] ) ) )
									: esc_html__( 'Not granted', 'redeemid' );
								?>
							</strong>
							<?php if ( ! $row['granted'] ) : ?>
								(<code><?php echo esc_html( $row['reason'] ); ?></code>)
							<?php endif; ?>
							<?php if ( $row['trace'] ) : ?>
								<ol class="redeemid-trace-list">
									<?php foreach ( $row['trace'] as $route ) : ?>
										<li>
											<?php echo Menu::dot( $route['passed'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
											<?php echo esc_html( $route['when'] ?: $route['label'] ); ?>:
											<?php echo esc_html( $route['passed'] ? __( 'passes', 'redeemid' ) : __( 'does not pass', 'redeemid' ) ); ?>
											<span class="description">(<?php echo esc_html( implode( '; ', $route['tests'] ) ); ?>)</span>
										</li>
									<?php endforeach; ?>
								</ol>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
		Menu::panel_end();
	}

	private static function render_trace( array $t ): void {
		$shows = [
			BlockAccess::EVERYONE => __( 'everyone', 'redeemid' ),
			BlockAccess::READERS => __( 'readers', 'redeemid' ),
			BlockAccess::LEVELS  => __( 'readers holding', 'redeemid' ),
			BlockAccess::LOCKED  => __( 'locked visitors', 'redeemid' ),
			BlockAccess::WITHOUT => __( 'visitors without', 'redeemid' ),
		];
		?>
		<?php
		Menu::panel_start(
			/* translators: 1: item title, 2: post type, 3: status */
			sprintf( __( '%1$s (%2$s, %3$s)', 'redeemid' ), $t['post']['title'], $t['post']['type'], $t['post']['status'] ),
			'',
			sprintf( '<a class="button" href="%s">%s</a>', esc_url( (string) get_edit_post_link( $t['post']['id'] ) ), esc_html__( 'Edit item', 'redeemid' ) )
			. sprintf( '<a class="button" href="%s">%s</a>', esc_url( add_query_arg( 'redeemid_preview_as', 'locked', (string) get_permalink( $t['post']['id'] ) ) ), esc_html__( 'View as a locked visitor', 'redeemid' ) )
		);
		?>

		<?php if ( ! $t['gated'] ) : ?>
			<div class="redeemid-panel__body"><div class="notice notice-warning inline"><p><?php esc_html_e( 'This post type is not gated, so rules on it are ignored and it is always open.', 'redeemid' ); ?></p></div></div>
		<?php endif; ?>

		<div class="redeemid-panel__body redeemid-panel__body--flush">
		<table class="widefat striped redeemid-kv">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Layers', 'redeemid' ); ?></th>
					<td><ol class="redeemid-trace-list"><?php foreach ( $t['layers'] as $line ) : ?><li><?php echo esc_html( $line ); ?></li><?php endforeach; ?></ol></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Who can read it', 'redeemid' ); ?></th>
					<td>
						<strong><?php echo esc_html( $t['rule'] ); ?></strong>
						<?php if ( $t['meter_eligible'] ) : ?>
							<br><span class="description"><?php echo esc_html( \RedeemID\Access\Meter::on() ? __( 'Metered: a signed-in reader without access can open it as one of their free stories.', 'redeemid' ) : __( 'Meter-eligible: it would count if you set a meter on Content rules.', 'redeemid' ) ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Next change', 'redeemid' ); ?></th>
					<td><?php echo $t['next_change'] ? esc_html( Menu::time( $t['next_change'] ) ) : esc_html__( 'No date changes this rule.', 'redeemid' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'This viewer', 'redeemid' ); ?></th>
					<td>
						<?php echo Menu::dot( $t['granted'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<strong><?php echo $t['granted'] ? esc_html__( 'Reads it', 'redeemid' ) : esc_html__( 'Locked out', 'redeemid' ); ?></strong>
						<?php if ( ! $t['granted'] ) : ?>
							(<code><?php echo esc_html( $t['reason'] ); ?></code><?php echo $t['offer'] ? esc_html( sprintf( /* translators: %s: level label */ __( '; the wall offers %s', 'redeemid' ), $t['offer'] ) ) : ''; ?>)
						<?php endif; ?>
						<br><?php echo esc_html( $t['sees'] ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Blocks with their own Access', 'redeemid' ); ?></th>
					<td>
						<?php if ( ! $t['blocks'] ) : ?>
							<?php esc_html_e( 'None.', 'redeemid' ); ?>
						<?php else : ?>
							<ul class="redeemid-trace-list">
								<?php foreach ( $t['blocks'] as $b ) : ?>
									<li>
										<?php echo Menu::dot( $b['visible'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										<code><?php echo esc_html( $b['name'] ); ?></code>:
										<?php echo esc_html( ( $shows[ $b['show'] ] ?? $b['show'] ) . ( $b['levels'] ? ' ' . implode( ', ', array_map( static fn( $s ) => LevelStore::levels()->get( $s )?->label ?? $s, $b['levels'] ) ) : '' ) ); ?><?php echo '' !== ( $b['feature'] ?? '' ) ? esc_html( sprintf( /* translators: 1: holding / not holding, 2: feature label */ __( ', %1$s %2$s', 'redeemid' ), BlockAccess::LACKS === $b['when'] ? __( 'not holding', 'redeemid' ) : __( 'holding', 'redeemid' ), Registry::get( $b['feature'] )?->label ?? $b['feature'] ) ) : ''; ?>,
										<?php echo $b['visible'] ? esc_html__( 'shown', 'redeemid' ) : esc_html__( 'hidden', 'redeemid' ); ?>
									</li>
								<?php endforeach; ?>
							</ul>
							<p class="description"><?php esc_html_e( 'Only blocks the visitor reaches render at all: for a locked visitor, blocks after the teaser are cut first.', 'redeemid' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Other surfaces', 'redeemid' ); ?></th>
					<td><?php echo wp_kses( implode( '<br>', array_map( 'esc_html', $t['surfaces'] ) ), [ 'br' => [] ] ); ?></td>
				</tr>
			</tbody>
		</table>
		</div>
		<?php
		Menu::panel_end();
	}
}
