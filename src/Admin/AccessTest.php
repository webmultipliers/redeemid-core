<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Access\BlockAccess;
use WP2_Shopify\Access\Explain;
use WP2_Shopify\Access\ItemRule;
use WP2_Shopify\Access\Level;
use WP2_Shopify\Access\LevelStore;
use WP2_Shopify\Entitlement\Decision;

/**
 * Shopify → Access → Test: pick an item and a viewer, and see the whole
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
				'suppress_filters' => true,
			]
		);
		$viewers = [
			Explain::AS_GUEST     => __( 'A guest', 'wp2-shopify' ),
			Explain::AS_SIGNED_IN => __( 'A signed-in customer with no tags', 'wp2-shopify' ),
		];

		foreach ( $levels as $slug => $level ) {
			/* translators: %s: level label */
			$viewers[ $slug ] = sprintf( __( 'A member holding %s', 'wp2-shopify' ), $level->label );
		}

		$viewers[ Explain::AS_TAGS ] = __( 'A synced customer with these tags', 'wp2-shopify' );
		$viewers[ Explain::AS_USER ] = __( 'A real user, from their synced record', 'wp2-shopify' );
		?>
		<p style="max-width:760px"><?php esc_html_e( 'Pick an item and who is looking. The trace shows every layer, the rule they resolve to, what that visitor gets, and which blocks they see. Nothing is saved, and the editor bypass is never used, so this is what that visitor would really get.', 'wp2-shopify' ); ?></p>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( Access::PAGE ); ?>">
			<input type="hidden" name="tab" value="test">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wp2-test-post"><?php esc_html_e( 'Item', 'wp2-shopify' ); ?></label></th>
					<td>
						<select id="wp2-test-post" name="post">
							<option value="0"><?php esc_html_e( 'Choose a recently edited item', 'wp2-shopify' ); ?></option>
							<?php foreach ( $recent as $item ) : ?>
								<option value="<?php echo esc_attr( (string) $item->ID ); ?>" <?php selected( $post_id, $item->ID ); ?>><?php echo esc_html( get_the_title( $item ) . ' (' . $item->post_type . ' #' . $item->ID . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Not listed? Add &post=<ID> to this page\'s address.', 'wp2-shopify' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wp2-test-as"><?php esc_html_e( 'Viewer', 'wp2-shopify' ); ?></label></th>
					<td>
						<select id="wp2-test-as" name="as">
							<?php foreach ( $viewers as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $as, $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p>
							<label><?php esc_html_e( 'Tags', 'wp2-shopify' ); ?> <input name="tags" class="regular-text" placeholder="gold, vip" value="<?php echo esc_attr( $tags ); ?>"></label>
							<label><?php esc_html_e( 'User ID', 'wp2-shopify' ); ?> <input name="user" type="number" min="0" class="small-text" value="<?php echo esc_attr( $user_id ? (string) $user_id : '' ); ?>"></label>
						</p>
						<p class="description"><?php esc_html_e( 'Tags are used with "A synced customer with these tags", the user ID with "A real user". Test members are always freshly synced; a real user\'s record may be stale, which locks just as it does on the site.', 'wp2-shopify' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Explain', 'wp2-shopify' ), 'primary', '', false ); ?>
		</form>
		<?php
		$post = $post_id ? get_post( $post_id ) : null;

		if ( $post instanceof \WP_Post ) {
			self::render_trace( Explain::trace( $post, Explain::member( $as, explode( ',', $tags ), $user_id ) ) );
		}
	}

	private static function render_trace( array $t ): void {
		$shows = [
			BlockAccess::READERS => __( 'readers', 'wp2-shopify' ),
			BlockAccess::LEVELS  => __( 'readers holding', 'wp2-shopify' ),
			BlockAccess::LOCKED  => __( 'locked visitors', 'wp2-shopify' ),
			BlockAccess::WITHOUT => __( 'visitors without', 'wp2-shopify' ),
		];
		?>
		<h2>
			<?php
			/* translators: 1: item title, 2: post type, 3: status */
			echo esc_html( sprintf( __( '%1$s (%2$s, %3$s)', 'wp2-shopify' ), $t['post']['title'], $t['post']['type'], $t['post']['status'] ) );
			?>
		</h2>

		<?php if ( ! $t['gated'] ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'This post type is not gated, so rules on it are ignored and it is always open.', 'wp2-shopify' ); ?></p></div>
		<?php endif; ?>

		<table class="widefat striped" style="max-width:1100px">
			<tbody>
				<tr>
					<th scope="row" style="width:200px"><?php esc_html_e( 'Layers', 'wp2-shopify' ); ?></th>
					<td><ol style="margin:0 0 0 1.2em"><?php foreach ( $t['layers'] as $line ) : ?><li><?php echo esc_html( $line ); ?></li><?php endforeach; ?></ol></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Effective rule', 'wp2-shopify' ); ?></th>
					<td>
						<strong><?php echo esc_html( $t['rule'] ); ?></strong>
						<?php if ( $t['meter_eligible'] ) : ?>
							<br><span class="description"><?php esc_html_e( 'Meter-eligible: a future meter could open it.', 'wp2-shopify' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Next change', 'wp2-shopify' ); ?></th>
					<td><?php echo $t['next_change'] ? esc_html( Menu::time( $t['next_change'] ) ) : esc_html__( 'No date changes this rule.', 'wp2-shopify' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'This viewer', 'wp2-shopify' ); ?></th>
					<td>
						<?php echo Menu::dot( $t['granted'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<strong><?php echo $t['granted'] ? esc_html__( 'Reads it', 'wp2-shopify' ) : esc_html__( 'Locked out', 'wp2-shopify' ); ?></strong>
						<?php if ( ! $t['granted'] ) : ?>
							(<code><?php echo esc_html( $t['reason'] ); ?></code><?php echo $t['offer'] ? esc_html( sprintf( /* translators: %s: level label */ __( '; the wall offers %s', 'wp2-shopify' ), $t['offer'] ) ) : ''; ?>)
							<?php if ( Decision::STALE === $t['reason'] ) : ?>
								<br><span class="description"><?php esc_html_e( 'The member\'s record is older than the freshness window, so it fails closed until the next sync.', 'wp2-shopify' ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
						<br><?php echo esc_html( $t['sees'] ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Blocks with their own Access', 'wp2-shopify' ); ?></th>
					<td>
						<?php if ( ! $t['blocks'] ) : ?>
							<?php esc_html_e( 'None.', 'wp2-shopify' ); ?>
						<?php else : ?>
							<ul style="margin:0">
								<?php foreach ( $t['blocks'] as $b ) : ?>
									<li>
										<?php echo Menu::dot( $b['visible'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										<code><?php echo esc_html( $b['name'] ); ?></code>:
										<?php echo esc_html( ( $shows[ $b['show'] ] ?? $b['show'] ) . ( $b['levels'] ? ' ' . implode( ', ', array_map( static fn( $s ) => LevelStore::levels()->get( $s )?->label ?? $s, $b['levels'] ) ) : '' ) ); ?>,
										<?php echo $b['visible'] ? esc_html__( 'shown', 'wp2-shopify' ) : esc_html__( 'hidden', 'wp2-shopify' ); ?>
									</li>
								<?php endforeach; ?>
							</ul>
							<p class="description"><?php esc_html_e( 'Only blocks the visitor reaches render at all: for a locked visitor, blocks after the teaser are cut first.', 'wp2-shopify' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Other surfaces', 'wp2-shopify' ); ?></th>
					<td><?php echo wp_kses( implode( '<br>', array_map( 'esc_html', $t['surfaces'] ) ), [ 'br' => [] ] ); ?></td>
				</tr>
			</tbody>
		</table>
		<p>
			<a class="button" href="<?php echo esc_url( (string) get_edit_post_link( $t['post']['id'] ) ); ?>"><?php esc_html_e( 'Edit item', 'wp2-shopify' ); ?></a>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'wp2_preview_as', 'locked', (string) get_permalink( $t['post']['id'] ) ) ); ?>"><?php esc_html_e( 'View as a locked visitor', 'wp2-shopify' ); ?></a>
		</p>
		<?php
	}
}
