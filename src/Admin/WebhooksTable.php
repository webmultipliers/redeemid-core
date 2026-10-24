<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Support\CustomerId;
use WP2_Shopify\Sync\Mirror;
use WP2_Shopify\Webhooks\InboxStore;
use WP2_Shopify\Webhooks\WpdbInboxStore;

if ( ! class_exists( \WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Every webhook delivery the endpoint accepted, from the inbox table.
 */
final class WebhooksTable extends \WP_List_Table {
	private WpdbInboxStore $store;

	public function __construct() {
		parent::__construct( [ 'singular' => 'webhook', 'plural' => 'webhooks', 'ajax' => false ] );
		$this->store = new WpdbInboxStore();
	}

	public function get_columns(): array {
		return [
			'received_at'  => __( 'Received', 'wp2-shopify' ),
			'topic'        => __( 'Topic', 'wp2-shopify' ),
			'status'       => __( 'Outcome', 'wp2-shopify' ),
			'customer'     => __( 'Customer', 'wp2-shopify' ),
			'attempts'     => __( 'Attempts', 'wp2-shopify' ),
			'processed_at' => __( 'Processed', 'wp2-shopify' ),
			'webhook_id'   => __( 'Shopify webhook ID', 'wp2-shopify' ),
		];
	}

	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], [], 'topic' ];

		$per_page = $this->get_items_per_page( 'wp2_shopify_per_page', 50 );
		$result   = $this->store->query( self::filters(), $per_page, $this->get_pagenum() );

		$this->items = $result['rows'];
		$this->set_pagination_args( [ 'total_items' => $result['total'], 'per_page' => $per_page ] );
	}

	/**
	 * @return array{status: string, topic: string, customer: string}
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification -- list display.
		return [
			'status'   => sanitize_key( $_GET['status'] ?? '' ),
			'topic'    => sanitize_text_field( wp_unslash( $_GET['topic'] ?? '' ) ),
			'customer' => preg_replace( '/\D/', '', (string) ( $_GET['customer'] ?? '' ) ),
		];
		// phpcs:enable
	}

	protected function get_views(): array {
		$current = self::filters()['status'];
		$counts  = $this->store->counts();
		$views   = [ 'all' => sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( Menu::url( Webhooks::PAGE ) ), '' === $current ? ' class="current"' : '', esc_html__( 'All', 'wp2-shopify' ), array_sum( $counts ) ) ];

		foreach ( [ InboxStore::QUEUED, InboxStore::PROCESSED, InboxStore::UNMATCHED, InboxStore::IGNORED, InboxStore::DEAD ] as $status ) {
			$views[ $status ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( Menu::url( Webhooks::PAGE, [ 'status' => $status ] ) ), $current === $status ? ' class="current"' : '', esc_html( ucfirst( $status ) ), $counts[ $status ] ?? 0 );
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
			<select name="topic">
				<option value=""><?php esc_html_e( 'All topics', 'wp2-shopify' ); ?></option>
				<?php foreach ( $this->store->topics() as $topic ) : ?>
					<option value="<?php echo esc_attr( $topic ); ?>" <?php selected( $filters['topic'], $topic ); ?>><?php echo esc_html( $topic ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" name="customer" placeholder="<?php esc_attr_e( 'Customer ID', 'wp2-shopify' ); ?>" value="<?php echo esc_attr( $filters['customer'] ); ?>" class="wp2s-filter-customer">
			<?php submit_button( __( 'Filter', 'wp2-shopify' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	public function no_items(): void {
		esc_html_e( 'No webhooks received yet.', 'wp2-shopify' );
	}

	protected function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	protected function column_received_at( array $item ): string {
		return Menu::time( $item['received_at'] );
	}

	protected function column_processed_at( array $item ): string {
		return Menu::time( $item['processed_at'] );
	}

	protected function column_topic( array $item ): string {
		return '<code>' . esc_html( $item['topic'] ) . '</code>';
	}

	protected function column_status( array $item ): string {
		$ok   = in_array( $item['status'], [ InboxStore::PROCESSED, InboxStore::IGNORED ], true );
		$html = Menu::dot( $ok || InboxStore::QUEUED === $item['status'] ) . esc_html( $item['status'] );

		if ( '' !== $item['error'] ) {
			$html .= '<br><span class="description">' . esc_html( $item['error'] ) . '</span>';
		}

		if ( InboxStore::DEAD === $item['status'] && 'unknown_topic' !== $item['error'] ) {
			$html .= $this->row_actions( [ 'retry' => sprintf( '<a href="%s">%s</a>', esc_url( Webhooks::retry_url( (int) $item['id'] ) ), esc_html__( 'Retry', 'wp2-shopify' ) ) ], true );
		}

		return $html;
	}

	protected function column_customer( array $item ): string {
		$id = CustomerId::parse( (string) $item['customer'] );

		if ( ! $id ) {
			return '—';
		}

		$user = Mirror::find_user( $id );

		return $user
			? sprintf( '<a href="%s">%s</a><br><code>%s</code>', esc_url( Menu::url( Customers::PAGE, [ 'user' => $user->ID ] ) ), esc_html( $user->display_name ?: $user->user_login ), esc_html( $id->numeric ) )
			: '<code>' . esc_html( $id->numeric ) . '</code>';
	}
}
