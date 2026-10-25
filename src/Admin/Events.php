<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Support\EventLog;

/**
 * Shopify → App events: every call the plugin made to Shopify (Admin API,
 * Customer Account API, token requests) with its result and timing, and what
 * the plugin did with it: syncs, sign-ins, webhook outcomes, admin actions.
 */
final class Events {
	public const PAGE   = 'wp2-shopify-events';
	public const ACTION = 'wp2_shopify_events';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'action' ] );
	}

	public static function action(): void {
		Menu::guard( self::ACTION );
		// phpcs:disable WordPress.Security.NonceVerification -- checked by Menu::guard().

		if ( 'clear' === sanitize_key( wp_unslash( $_REQUEST['do'] ?? '' ) ) ) {
			EventLog::clear();
		}

		wp_safe_redirect( Menu::url( self::PAGE, [ 'result' => 'cleared' ] ) );
		exit;
		// phpcs:enable WordPress.Security.NonceVerification
	}

	public static function render(): void {
		$table = new EventsTable();
		$table->prepare_items();
		$f = EventsTable::filters();
		Menu::page_start(
			__( 'App events', 'wp2-shopify' ),
			__( 'Shopify app events', 'wp2-shopify' ),
			sprintf( '<a class="page-title-action" href="%s" onclick="return confirm(\'%s\')">%s</a>', esc_url( wp_nonce_url( add_query_arg( [ 'action' => self::ACTION, 'do' => 'clear' ], admin_url( 'admin-post.php' ) ), self::ACTION ) ), esc_js( __( 'Delete every logged event?', 'wp2-shopify' ) ), esc_html__( 'Clear log', 'wp2-shopify' ) ),
			sprintf( /* translators: %d: days */ __( 'Every call the plugin made to Shopify, and what it did with the answer. Kept for %d days. No request or response bodies, tokens or secrets are recorded.', 'wp2-shopify' ), EventLog::RETENTION / DAY_IN_SECONDS )
		);
		Menu::result_notice( [ 'cleared' => [ 'success', __( 'Event log cleared.', 'wp2-shopify' ) ] ] );
		?>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php foreach ( [ 'group' => $f['prefix'], 'user' => $f['user'] ?: '' ] as $name => $value ) : ?>
					<?php if ( '' !== $value ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
					<?php endif; ?>
				<?php endforeach; ?>
				<?php $table->search_box( __( 'Search events', 'wp2-shopify' ), 'wp2s-events' ); ?>
				<?php $table->display(); ?>
			</form>
		<?php
		Menu::page_end();
	}
}
