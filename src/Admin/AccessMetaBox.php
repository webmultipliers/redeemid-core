<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Access\Context;
use WP2_Shopify\Access\Gatekeeper;
use WP2_Shopify\Access\ItemRule;
use WP2_Shopify\Access\Level;
use WP2_Shopify\Access\LevelStore;

/**
 * The Access box in the post editor's sidebar: the item's mode, levels and
 * windows, plus the effective rule and where it comes from.
 *
 * A server-rendered meta box, so it works in the block editor and the
 * classic editor with no build step.
 */
final class AccessMetaBox {
	private const NONCE = 'wp2_shopify_access_item';

	public static function register(): void {
		add_action( 'add_meta_boxes', [ self::class, 'add' ] );
		add_action( 'save_post', [ self::class, 'save' ], 10, 2 );
	}

	public static function add(): void {
		foreach ( ItemRule::post_types() as $type ) {
			add_meta_box( 'wp2-shopify-access', __( 'Access', 'wp2-shopify' ), [ self::class, 'render' ], $type, 'side', 'high' );
		}
	}

	public static function render( \WP_Post $post ): void {
		$rule   = ItemRule::get( $post );
		$levels = LevelStore::levels();
		$custom = array_filter( $levels->all(), static fn( Level $l ) => Level::PUBLIC !== $l->slug );
		$modes  = [
			Context::ITEM_INHERIT => __( 'Inherit (terms, then post type)', 'wp2-shopify' ),
			Context::ITEM_PUBLIC  => __( 'Public: open this item', 'wp2-shopify' ),
			Context::ITEM_LEVEL   => __( 'Require these levels instead', 'wp2-shopify' ),
			Context::ITEM_ADD     => __( 'Inherited rule, plus these levels', 'wp2-shopify' ),
		];
		$local  = static fn( ?int $t ) => $t ? wp_date( 'Y-m-d\TH:i', $t ) : '';

		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<p><strong><?php esc_html_e( 'Now:', 'wp2-shopify' ); ?></strong> <?php echo esc_html( Gatekeeper::rule( $post )->explain( $levels ) ); ?></p>
		<?php foreach ( Gatekeeper::context( $post )->terms as $term ) : ?>
			<?php if ( $term->opens_at( time() ) && Context::ITEM_INHERIT === $rule['mode'] ) : ?>
				<p class="notice notice-warning inline" style="padding:6px 8px">
					<?php
					/* translators: 1: term, such as "Category: Culture", 2: date */
					echo esc_html( sprintf( __( '%1$s makes this item free to everyone until %2$s. To keep it locked, give it its own levels below.', 'wp2-shopify' ), $term->label(), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $term->opens_until ) ) );
					?>
				</p>
			<?php endif; ?>
		<?php endforeach; ?>

		<p>
			<label for="wp2-access-mode"><?php esc_html_e( 'This item', 'wp2-shopify' ); ?></label><br>
			<select id="wp2-access-mode" name="wp2_access[mode]" style="width:100%">
				<?php foreach ( $modes as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $rule['mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>

		<fieldset>
			<legend><?php esc_html_e( 'Levels (any one passes)', 'wp2-shopify' ); ?></legend>
			<?php foreach ( $custom as $slug => $level ) : ?>
				<label style="display:block"><input type="checkbox" name="wp2_access[levels][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $rule['levels'], true ) ); ?>> <?php echo esc_html( $level->label ); ?></label>
			<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Used by the last two choices. Blocks inside the item can only narrow its rule, never widen it.', 'wp2-shopify' ); ?></p>
		</fieldset>

		<p>
			<label for="wp2-access-locked-until"><?php esc_html_e( 'Embargo: locked until', 'wp2-shopify' ); ?></label>
			<input id="wp2-access-locked-until" type="datetime-local" name="wp2_access[locked_until]" value="<?php echo esc_attr( $local( $rule['locked_until'] ) ); ?>" style="width:100%">
		</p>
		<p>
			<label for="wp2-access-public-until"><?php esc_html_e( 'Free until, then locked', 'wp2-shopify' ); ?></label>
			<input id="wp2-access-public-until" type="datetime-local" name="wp2_access[public_until]" value="<?php echo esc_attr( $local( $rule['public_until'] ) ); ?>" style="width:100%">
			<span class="description"><?php esc_html_e( 'Dates are publish-date embargoes, not membership drip. Site time zone.', 'wp2-shopify' ); ?></span>
		</p>
		<p>
			<label><input type="checkbox" name="wp2_access[meter_exempt]" value="1" <?php checked( $rule['meter_exempt'] ); ?>> <?php esc_html_e( 'Never count toward a meter', 'wp2-shopify' ); ?></label>
		</p>
		<p class="description"><?php esc_html_e( 'Gating protects this item\'s text. Files linked from it, such as PDFs and images, keep public URLs.', 'wp2-shopify' ); ?></p>
		<?php
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! ItemRule::gated( $post ) ) {
			return;
		}

		$raw  = isset( $_POST['wp2_access'] ) && is_array( $_POST['wp2_access'] ) ? wp_unslash( $_POST['wp2_access'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in ItemRule::sanitize().
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
			]
		);
	}
}
