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

		if ( 'clear' === ( $_REQUEST['do'] ?? '' ) ) {
			EventLog::clear();
		}

		wp_safe_redirect( Menu::url( self::PAGE, [ 'result' => 'cleared' ] ) );
		exit;
	}

	public static function render(): void {
		$table = new EventsTable();
		$table->prepare_items();
		$f = EventsTable::filters();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Shopify app events', 'wp2-shopify' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => self::ACTION, 'do' => 'clear' ], admin_url( 'admin-post.php' ) ), self::ACTION ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete every logged event?', 'wp2-shopify' ) ); ?>')"><?php esc_html_e( 'Clear log', 'wp2-shopify' ); ?></a>
			<hr class="wp-header-end">
			<?php Menu::result_notice( [ 'cleared' => [ 'success', __( 'Event log cleared.', 'wp2-shopify' ) ] ] ); ?>
			<p class="description"><?php echo esc_html( sprintf( /* translators: %d: days */ __( 'Kept for %d days. No request or response bodies, tokens or secrets are recorded.', 'wp2-shopify' ), EventLog::RETENTION / DAY_IN_SECONDS ) ); ?></p>
			<style>.wp-list-table.events .column-created_at{width:10em}.wp-list-table.events .column-event{width:13em}.wp-list-table.events .column-ms,.wp-list-table.events .column-origin{width:6em}.wp-list-table.events .column-context{width:28%;word-break:break-all}</style>
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
		</div>
		<?php
	}
}
