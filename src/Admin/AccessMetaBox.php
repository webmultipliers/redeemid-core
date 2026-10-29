<?php

namespace RedeemID\Admin;

use RedeemID\Access\Context;
use RedeemID\Access\Gatekeeper;
use RedeemID\Access\Index;
use RedeemID\Access\ItemRule;
use RedeemID\Access\Level;
use RedeemID\Access\LevelStore;

/**
 * The Access box in the post editor's sidebar: the item's mode, levels and
 * windows, plus the effective rule and where it comes from.
 *
 * A server-rendered meta box, so it works in the block editor and the
 * classic editor with no build step.
 */
final class AccessMetaBox {
	private const NONCE = 'redeemid_access_item';

	public static function register(): void {
		add_action( 'add_meta_boxes', [ self::class, 'add' ] );
		add_action( 'save_post', [ self::class, 'save' ], 10, 2 );
	}

	public static function add(): void {
		foreach ( ItemRule::post_types() as $type ) {
			add_meta_box( 'redeemid-access', __( 'Who can read this', 'redeemid' ), [ self::class, 'render' ], $type, 'side', 'high' );
		}
	}

	public static function render( \WP_Post $post ): void {
		$rule   = ItemRule::get( $post );
		$levels = LevelStore::levels();
		$custom = array_filter( $levels->all(), static fn( Level $l ) => Level::PUBLIC !== $l->slug );
		$modes  = [
			Context::ITEM_INHERIT => __( 'Follow the site rules (category, then post type)', 'redeemid' ),
			Context::ITEM_PUBLIC  => __( 'Free to everyone', 'redeemid' ),
			Context::ITEM_LEVEL   => __( 'Only the levels ticked below', 'redeemid' ),
			Context::ITEM_ADD     => __( 'The site rules, and also a level ticked below', 'redeemid' ),
		];
		$local  = static fn( ?int $t ) => $t ? wp_date( 'Y-m-d\TH:i', $t ) : '';

		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<?php $effective = Gatekeeper::rule( $post ); ?>
		<p>
			<strong>
				<?php
				echo esc_html(
					$effective->open()
						? __( 'Right now: free to everyone.', 'redeemid' )
						/* translators: %s: levels, such as "Gold or Silver" */
						: sprintf( __( 'Right now: readers need %s.', 'redeemid' ), $effective->readers( $levels ) )
				);
				?>
			</strong>
			<br>
			<?php
			/* translators: %s: where the rule comes from, such as "Category: Investigations" */
			echo esc_html( sprintf( __( 'Set by: %s', 'redeemid' ), $effective->source ) );
			?>
		</p>
		<?php $next = Index::next_change( Gatekeeper::context( $post ), time() ); ?>
		<?php if ( $next ) : ?>
			<p>
				<?php
				/* translators: %s: date and time */
				echo esc_html( sprintf( __( 'A date changes this on %s.', 'redeemid' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( current_user_can( Menu::CAP ) ) : ?>
			<p><a href="<?php echo esc_url( Menu::url( Access::PAGE, [ 'tab' => 'test', 'post' => $post->ID ] ) ); ?>"><?php esc_html_e( 'Test who can read it', 'redeemid' ); ?></a></p>
		<?php endif; ?>
		<?php foreach ( Gatekeeper::context( $post )->terms as $term ) : ?>
			<?php if ( $term->opens_at( time() ) && Context::ITEM_INHERIT === $rule['mode'] ) : ?>
				<p class="notice notice-warning inline" style="padding:6px 8px">
					<?php
					/* translators: 1: term, such as "Category: Culture", 2: date */
					echo esc_html( sprintf( __( '%1$s makes this item free to everyone until %2$s. To keep it locked, give it its own levels below.', 'redeemid' ), $term->label(), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $term->opens_until ) ) );
					?>
				</p>
			<?php endif; ?>
		<?php endforeach; ?>

		<p>
			<label for="redeemid-access-mode"><?php esc_html_e( 'Who can read it', 'redeemid' ); ?></label><br>
			<select id="redeemid-access-mode" name="redeemid_access[mode]" style="width:100%">
				<?php foreach ( $modes as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $rule['mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>

		<fieldset>
			<legend><?php esc_html_e( 'Levels (a reader needs any one)', 'redeemid' ); ?></legend>
			<?php foreach ( $custom as $slug => $level ) : ?>
				<label style="display:block"><input type="checkbox" name="redeemid_access[levels][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $rule['levels'], true ) ); ?>> <?php echo esc_html( $level->label ); ?></label>
			<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Used by the last two choices. A members-only block inside the post can hide more of it, never open it to more readers.', 'redeemid' ); ?></p>
		</fieldset>

		<p>
			<label for="redeemid-access-locked-until"><?php esc_html_e( 'Locked until, then free to everyone', 'redeemid' ); ?></label>
			<input id="redeemid-access-locked-until" type="datetime-local" name="redeemid_access[locked_until]" value="<?php echo esc_attr( $local( $rule['locked_until'] ) ); ?>" style="width:100%">
		</p>
		<p>
			<label for="redeemid-access-public-until"><?php esc_html_e( 'Free until, then locked', 'redeemid' ); ?></label>
			<input id="redeemid-access-public-until" type="datetime-local" name="redeemid_access[public_until]" value="<?php echo esc_attr( $local( $rule['public_until'] ) ); ?>" style="width:100%">
			<span class="description"><?php esc_html_e( 'Fixed dates, the same for every reader, in the site time zone.', 'redeemid' ); ?></span>
		</p>
		<p>
			<label for="redeemid-access-drip"><?php esc_html_e( 'Members can read it after', 'redeemid' ); ?></label>
			<input id="redeemid-access-drip" type="number" min="0" max="3650" step="1" name="redeemid_access[drip_days]" value="<?php echo (int) $rule['drip_days']; ?>" style="width:6em"> <?php esc_html_e( 'days of membership', 'redeemid' ); ?>
			<span class="description"><?php esc_html_e( 'Counted from when each member joined. 0 means straight away.', 'redeemid' ); ?></span>
		</p>
		<p>
			<label><input type="checkbox" name="redeemid_access[meter_exempt]" value="1" <?php checked( $rule['meter_exempt'] ); ?>> <?php esc_html_e( 'Never count toward a meter', 'redeemid' ); ?></label>
		</p>
		<p class="description"><?php esc_html_e( 'This locks the post\'s text. Files linked from it, such as PDFs and images, can still be opened by anyone with the link.', 'redeemid' ); ?></p>
		<?php
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! ItemRule::gated( $post ) ) {
			return;
		}

		$raw  = isset( $_POST['redeemid_access'] ) && is_array( $_POST['redeemid_access'] ) ? wp_unslash( $_POST['redeemid_access'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in ItemRule::sanitize().
		$time = static function ( $value ): ?int {
			$value = is_string( $value ) ? trim( $value ) : '';

			if ( '' === $value ) {
				return null;
			}

			$date = date_create_immutable( $value, wp_timezone() );

			return $date ? $date->getTimestamp() : null;
		};

		ItemRule::save(
			$post_id,
			[
				'mode'         => sanitize_key( (string) ( $raw['mode'] ?? '' ) ),
				'levels'       => array_map( 'sanitize_key', (array) ( $raw['levels'] ?? [] ) ),
				'locked_until' => $time( $raw['locked_until'] ?? '' ),
				'public_until' => $time( $raw['public_until'] ?? '' ),
				'meter_exempt' => ! empty( $raw['meter_exempt'] ),
				'drip_days'    => absint( $raw['drip_days'] ?? 0 ),
			]
		);
	}
}
