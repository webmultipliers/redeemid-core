<?php

namespace RedeemID\Admin;

use RedeemID\Access\Context;
use RedeemID\Access\Gatekeeper;
use RedeemID\Access\ItemRule;
use RedeemID\Access\Level;
use RedeemID\Access\LevelStore;

/**
 * An Access column on every gated post list, showing each item's effective
 * rule and where it comes from, with quick edit and bulk edit of the item's
 * own mode and levels. Windows and the meter flag stay in the editor's
 * Access box; quick and bulk edit leave them as they are.
 */
final class AccessColumn {
	private const NONCE = 'redeemid_access_list';

	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'hook_types' ] );
		add_action( 'save_post', [ self::class, 'quick_save' ], 10, 2 );
		add_action( 'bulk_edit_posts', [ self::class, 'bulk_save' ], 10, 2 );
		add_action( 'admin_footer-edit.php', [ self::class, 'script' ] );
	}

	public static function hook_types(): void {
		foreach ( ItemRule::post_types() as $type ) {
			add_filter( 'manage_' . $type . '_posts_columns', [ self::class, 'columns' ] );
			add_action( 'manage_' . $type . '_posts_custom_column', [ self::class, 'column' ], 10, 2 );
		}

		add_action( 'quick_edit_custom_box', [ self::class, 'quick_box' ], 10, 2 );
		add_action( 'bulk_edit_custom_box', [ self::class, 'bulk_box' ], 10, 2 );
	}

	public static function columns( array $columns ): array {
		$columns['redeemid_access'] = __( 'Access', 'redeemid' );

		return $columns;
	}

	public static function column( string $column, int $post_id ): void {
		$post = get_post( $post_id );

		if ( 'redeemid_access' !== $column || ! $post instanceof \WP_Post ) {
			return;
		}

		$rule = ItemRule::get( $post );

		printf(
			'<span class="redeemid-access-explain">%s</span><span class="hidden redeemid-access-data" data-mode="%s" data-levels="%s"></span>',
			esc_html( Gatekeeper::rule( $post )->explain( LevelStore::levels() ) ),
			esc_attr( $rule['mode'] ),
			esc_attr( implode( ',', $rule['levels'] ) )
		);
	}

	public static function quick_box( string $column, string $type ): void {
		self::box( $column, $type, false );
	}

	public static function bulk_box( string $column, string $type ): void {
		self::box( $column, $type, true );
	}

	private static function box( string $column, string $type, bool $bulk ): void {
		if ( 'redeemid_access' !== $column || ! in_array( $type, ItemRule::post_types(), true ) ) {
			return;
		}

		$levels = array_filter( LevelStore::levels()->all(), static fn( Level $l ) => Level::PUBLIC !== $l->slug );
		$modes  = [
			Context::ITEM_INHERIT => __( 'Inherit', 'redeemid' ),
			Context::ITEM_PUBLIC  => __( 'Public', 'redeemid' ),
			Context::ITEM_LEVEL   => __( 'These levels instead', 'redeemid' ),
			Context::ITEM_ADD     => __( 'Inherited, plus these levels', 'redeemid' ),
		];

		if ( ! $bulk ) {
			wp_nonce_field( self::NONCE, self::NONCE );
		}
		?>
		<fieldset class="inline-edit-col-right redeemid-access-inline">
			<div class="inline-edit-col">
				<label class="inline-edit-group">
					<span class="title"><?php esc_html_e( 'Access', 'redeemid' ); ?></span>
					<select name="redeemid_access_mode">
						<?php if ( $bulk ) : ?>
							<option value=""><?php esc_html_e( '— No change —', 'redeemid' ); ?></option>
						<?php endif; ?>
						<?php foreach ( $modes as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<div class="inline-edit-group">
					<?php foreach ( $levels as $slug => $level ) : ?>
						<label class="alignleft" style="margin-right:10px"><input type="checkbox" name="redeemid_access_levels[]" value="<?php echo esc_attr( $slug ); ?>"> <?php echo esc_html( $level->label ); ?></label>
					<?php endforeach; ?>
				</div>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Quick edit posts through admin-ajax with its own nonce, so this box adds one.
	 */
	public static function quick_save( int $post_id, \WP_Post $post ): void {
		if ( ! wp_doing_ajax() || ! isset( $_POST[ self::NONCE ], $_POST['redeemid_access_mode'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || ! ItemRule::gated( $post ) ) {
			return;
		}

		self::apply( $post, sanitize_key( wp_unslash( $_POST['redeemid_access_mode'] ) ), self::posted_levels() );
	}

	/**
	 * Core checks the bulk-posts nonce and per-item edit rights before this runs.
	 *
	 * @param int[] $updated
	 */
	public static function bulk_save( array $updated, array $data ): void {
		$mode = sanitize_key( (string) ( $data['redeemid_access_mode'] ?? '' ) );

		if ( '' === $mode ) {
			return;
		}

		$levels = array_map( 'sanitize_key', (array) ( $data['redeemid_access_levels'] ?? [] ) );

		foreach ( $updated as $post_id ) {
			$post = get_post( (int) $post_id );

			if ( $post instanceof \WP_Post && current_user_can( 'edit_post', $post->ID ) && ItemRule::gated( $post ) ) {
				self::apply( $post, $mode, $levels );
			}
		}
	}

	private static function apply( \WP_Post $post, string $mode, array $levels ): void {
		$rule           = ItemRule::get( $post );
		$rule['mode']   = $mode;
		$rule['levels'] = $levels;

		ItemRule::save( $post->ID, $rule );
	}

	private static function posted_levels(): array {
		// phpcs:ignore WordPress.Security.NonceVerification -- checked in quick_save().
		return isset( $_POST['redeemid_access_levels'] ) && is_array( $_POST['redeemid_access_levels'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['redeemid_access_levels'] ) ) : [];
	}

	/**
	 * Fill quick edit with the row's saved mode and levels.
	 */
	public static function script(): void {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, ItemRule::post_types(), true ) ) {
			return;
		}
		?>
		<script>
		( function () {
			if ( ! window.inlineEditPost ) {
				return;
			}
			var edit = window.inlineEditPost.edit;
			window.inlineEditPost.edit = function ( id ) {
				edit.apply( this, arguments );
				var postId = typeof id === 'object' ? this.getId( id ) : id;
				var data = document.querySelector( '#post-' + postId + ' .redeemid-access-data' );
				var row = document.querySelector( '#edit-' + postId + ' .redeemid-access-inline' );
				if ( ! data || ! row ) {
					return;
				}
				var levels = ( data.dataset.levels || '' ).split( ',' );
				row.querySelector( 'select[name="redeemid_access_mode"]' ).value = data.dataset.mode || 'inherit';
				row.querySelectorAll( 'input[name="redeemid_access_levels[]"]' ).forEach( function ( box ) {
					box.checked = levels.indexOf( box.value ) !== -1;
				} );
			};
		} )();
		</script>
		<?php
	}
}
