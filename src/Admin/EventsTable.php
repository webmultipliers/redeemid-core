<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Support\EventLog;

if ( ! class_exists( \WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The event log: calls to Shopify, syncs, sign-ins, webhook outcomes.
 */
final class EventsTable extends \WP_List_Table {
	/** Event-name prefixes offered as views. */
	public const GROUPS = [
		'shopify.'    => 'Calls to Shopify',
		'sync.'       => 'Resyncs',
		'reconcile.'  => 'Reconciliation',
		'membership.' => 'Purchase to tag',
		'webhook.'    => 'Webhooks',
		'auth.'       => 'Sign-in',
		'link.'       => 'Account linking',
		'admin.'      => 'Admin actions',
	];

	public function __construct() {
		parent::__construct( [ 'singular' => 'event', 'plural' => 'events', 'ajax' => false ] );
	}

	public function get_columns(): array {
		return [
			'created_at' => __( 'Time', 'wp2-shopify' ),
			'event'      => __( 'Event', 'wp2-shopify' ),
			'label'      => __( 'What', 'wp2-shopify' ),
			'status'     => __( 'Result', 'wp2-shopify' ),
			'ms'         => __( 'Duration', 'wp2-shopify' ),
			'user_id'    => __( 'User', 'wp2-shopify' ),
			'origin'     => __( 'From', 'wp2-shopify' ),
			'context'    => __( 'Details', 'wp2-shopify' ),
		];
	}

	/**
	 * @return array{event: string, prefix: string, user: int, search: string}
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification -- list display.
		$prefix = sanitize_text_field( wp_unslash( $_GET['group'] ?? '' ) );

		return [
			'event'  => sanitize_text_field( wp_unslash( $_GET['event'] ?? '' ) ),
			'prefix' => isset( self::GROUPS[ $prefix ] ) ? $prefix : '',
			'user'   => (int) ( $_GET['user'] ?? 0 ),
			'search' => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
		];
		// phpcs:enable
	}

	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], [], 'event' ];

		$per_page = $this->get_items_per_page( 'wp2_shopify_per_page', 50 );
		$result   = EventLog::query( self::filters(), $per_page, $this->get_pagenum() );

		$this->items = $result['rows'];
		$this->set_pagination_args( [ 'total_items' => $result['total'], 'per_page' => $per_page ] );
	}

	protected function get_views(): array {
		$current = self::filters()['prefix'];
		$views   = [ 'all' => sprintf( '<a href="%s"%s>%s</a>', esc_url( Menu::url( Events::PAGE ) ), '' === $current ? ' class="current"' : '', esc_html__( 'All', 'wp2-shopify' ) ) ];

		foreach ( self::GROUPS as $prefix => $label ) {
			$views[ rtrim( $prefix, '.' ) ] = sprintf( '<a href="%s"%s>%s</a>', esc_url( Menu::url( Events::PAGE, [ 'group' => $prefix ] ) ), $current === $prefix ? ' class="current"' : '', esc_html( __( $label, 'wp2-shopify' ) ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}

		return $views;
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$filters = self::filters();
		?>
		<div class="alignleft actions">
			<select name="event">
				<option value=""><?php esc_html_e( 'All events', 'wp2-shopify' ); ?></option>
				<?php foreach ( EventLog::events() as $event ) : ?>
					<option value="<?php echo esc_attr( $event ); ?>" <?php selected( $filters['event'], $event ); ?>><?php echo esc_html( $event ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'wp2-shopify' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	public function no_items(): void {
		esc_html_e( 'No events logged yet.', 'wp2-shopify' );
	}

	protected function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	protected function column_created_at( array $item ): string {
		return Menu::time( $item['created_at'] );
	}

	protected function column_event( array $item ): string {
		return '<code>' . esc_html( $item['event'] ) . '</code>';
	}

	protected function column_status( array $item ): string {
		$status = (string) $item['status'];
		$ok     = '' === $status || in_array( $status, [ 'ok', 'synced', 'processed', 'queued', 'duplicate', 'ignored', 'fixture' ], true ) || ( ctype_digit( $status ) && (int) $status < 400 );

		return '' === $status ? '' : Menu::dot( $ok && ! str_ends_with( $item['event'], '_failed' ) && 'webhook.rejected' !== $item['event'] ) . esc_html( $status );
	}

	protected function column_ms( array $item ): string {
		return null === $item['ms'] ? '' : esc_html( $item['ms'] . ' ms' );
	}

	protected function column_user_id( array $item ): string {
		$user = $item['user_id'] ? get_userdata( (int) $item['user_id'] ) : null;

		return $user ? sprintf( '<a href="%s">%s</a>', esc_url( Menu::url( Events::PAGE, [ 'user' => $user->ID ] ) ), esc_html( $user->display_name ?: $user->user_login ) ) : '—';
	}

	protected function column_context( array $item ): string {
		$context = json_decode( (string) $item['context'], true );

		if ( ! is_array( $context ) ) {
			return '';
		}

		unset( $context['label'], $context['status'], $context['ms'] );
		$parts = [];

		foreach ( $context as $key => $value ) {
			$parts[] = esc_html( $key ) . '=<code>' . esc_html( is_scalar( $value ) || null === $value ? ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value ) : '…' ) . '</code>';
		}

		return '<span style="font-size:12px">' . implode( ' ', $parts ) . '</span>';
	}
}
