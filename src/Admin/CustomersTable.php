<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Access\LevelStore;
use WP2_Shopify\Access\Membership;
use WP2_Shopify\Auth\ShadowUsers;
use WP2_Shopify\Config;
use WP2_Shopify\Sync\Mirror;

if ( ! class_exists( \WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WordPress users linked to a Shopify customer.
 */
final class CustomersTable extends \WP_List_Table {
	public function __construct() {
		parent::__construct( [ 'singular' => 'customer', 'plural' => 'customers', 'ajax' => false ] );
	}

	public function get_columns(): array {
		return [
			'cb'        => '<input type="checkbox">',
			'name'      => __( 'Customer', 'wp2-shopify' ),
			'shopify'   => __( 'Shopify customer', 'wp2-shopify' ),
			'levels'    => __( 'Levels', 'wp2-shopify' ),
			'tags'      => __( 'Tags', 'wp2-shopify' ),
			'state'     => __( 'State', 'wp2-shopify' ),
			'last_sync' => __( 'Last sync', 'wp2-shopify' ),
			'linked'    => __( 'Linked', 'wp2-shopify' ),
		];
	}

	protected function get_sortable_columns(): array {
		return [ 'name' => [ 'display_name', false ], 'last_sync' => [ 'last_sync', true ], 'linked' => [ 'linked', true ] ];
	}

	protected function get_bulk_actions(): array {
		return [
			'unlink' => __( 'Disconnect from Shopify', 'wp2-shopify' ),
		];
	}

	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns(), 'name' ];

		$per_page       = $this->get_items_per_page( 'wp2_shopify_per_page', 50 );
		$args           = CustomerQuery::args( CustomerQuery::filters() );
		$args['number'] = $per_page;
		$args['paged']  = $this->get_pagenum();

		$query       = new \WP_User_Query( $args );
		$this->items = $query->get_results();

		$this->set_pagination_args( [ 'total_items' => $query->get_total(), 'per_page' => $per_page ] );
	}

	protected function get_views(): array {
		// phpcs:ignore WordPress.Security.NonceVerification
		$current = sanitize_key( $_REQUEST['state'] ?? '' );
		$views   = [];

		foreach ( [ '' => __( 'All', 'wp2-shopify' ), 'disabled' => __( 'Disabled in Shopify', 'wp2-shopify' ), 'failed' => __( 'Last sync failed', 'wp2-shopify' ) ] as $key => $label ) {
			$views[ $key ?: 'all' ] = sprintf( '<a href="%s"%s>%s</a>', esc_url( Menu::url( Customers::PAGE, $key ? [ 'state' => $key ] : [] ) ), $current === $key ? ' class="current" aria-current="page"' : '', esc_html( $label ) );
		}

		return $views;
	}

	/**
	 * The level filter and the CSV export, above the table.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$f      = CustomerQuery::filters();
		$levels = array_filter( LevelStore::levels()->all(), static fn( $level ) => ! $level->builtin() );
		?>
		<div class="alignleft actions">
			<?php if ( $levels ) : ?>
				<label class="screen-reader-text" for="wp2s-level-filter"><?php esc_html_e( 'Filter by level', 'wp2-shopify' ); ?></label>
				<select name="level" id="wp2s-level-filter" form="wp2s-customer-filters">
					<option value=""><?php esc_html_e( 'All levels', 'wp2-shopify' ); ?></option>
					<?php foreach ( $levels as $slug => $level ) : ?>
						<option value="<?php echo esc_attr( (string) $slug ); ?>" <?php selected( $f['level'], (string) $slug ); ?>><?php echo esc_html( $level->label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button" form="wp2s-customer-filters"><?php esc_html_e( 'Filter', 'wp2-shopify' ); ?></button>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( Customers::export_url( $f ) ); ?>"><?php esc_html_e( 'Export CSV', 'wp2-shopify' ); ?></a>
		</div>
		<?php
	}

	public function no_items(): void {
		esc_html_e( 'No WordPress users are linked to a Shopify customer yet. Customers appear here after they sign in with Shopify or connect their account.', 'wp2-shopify' );
	}

	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="users[]" value="%d">', (int) $item->ID );
	}

	protected function column_name( \WP_User $user ): string {
		$view    = Menu::url( Customers::PAGE, [ 'user' => $user->ID ] );
		$id      = Mirror::customer_id( $user->ID );
		$fixture = Mirror::is_fixture( $user->ID );
		$badges  = (array) apply_filters( 'wp2_shopify_customer_badges', [], $user->ID );

		if ( ShadowUsers::is_shadow( $user->ID ) ) {
			array_unshift( $badges, __( 'Shopify-only account', 'wp2-shopify' ) );
		}

		$actions = [
			'view' => sprintf( '<a href="%s">%s</a>', esc_url( $view ), esc_html__( 'Details & tags', 'wp2-shopify' ) ),
			'edit' => sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_user_link( $user->ID ) ), esc_html__( 'Edit user', 'wp2-shopify' ) ),
		];

		if ( $id && ! $fixture && '' !== Config::admin_url() ) {
			$actions['shopify'] = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( Config::admin_url( 'customers/' . $id->numeric ) ), esc_html__( 'Open in Shopify', 'wp2-shopify' ) );
		}

		$actions['sync'] = sprintf( '<a href="%s">%s</a>', esc_url( Customers::action_url( 'sync', $user->ID ) ), esc_html__( 'Resync', 'wp2-shopify' ) );

		if ( function_exists( 'switch_to_user' ) && class_exists( 'user_switching' ) && method_exists( 'user_switching', 'maybe_switch_url' ) ) {
			$switch = \user_switching::maybe_switch_url( $user );

			if ( $switch ) {
				$actions['switch'] = sprintf( '<a href="%s">%s</a>', esc_url( $switch ), esc_html__( 'Switch to', 'wp2-shopify' ) );
			}
		}

		$badge_html = '';

		foreach ( $badges as $badge ) {
			$badge_html .= ' <span class="wp2s-chip">' . esc_html( (string) $badge ) . '</span>';
		}

		return sprintf(
			'%s<strong><a href="%s">%s</a></strong>%s<br><span class="description">%s</span>%s',
			get_avatar( $user->ID, 32, '', '', [ 'class' => 'wp2s-avatar' ] ),
			esc_url( $view ),
			esc_html( $user->display_name ?: $user->user_login ),
			$badge_html,
			esc_html( $user->user_email ),
			$this->row_actions( $actions )
		);
	}

	protected function column_shopify( \WP_User $user ): string {
		$id = Mirror::customer_id( $user->ID );

		return $id ? '<code>' . esc_html( $id->numeric ) . '</code>' : '—';
	}

	protected function column_levels( \WP_User $user ): string {
		$levels = LevelStore::levels();
		$top    = Membership::top( $levels, Membership::held( $levels, Mirror::member( $user->ID ), time() ) );

		return $top ? esc_html( implode( ', ', array_map( static fn( string $slug ): string => $levels->get( $slug )?->label ?? $slug, $top ) ) ) : '—';
	}

	protected function column_tags( \WP_User $user ): string {
		$tags = Mirror::tags( $user->ID );

		return $tags ? implode( ' ', array_map( static fn( $tag ) => '<code class="wp2s-chip">' . esc_html( $tag ) . '</code>', $tags ) ) : '—';
	}

	protected function column_state( \WP_User $user ): string {
		$status = (string) get_user_meta( $user->ID, Mirror::STATUS, true );

		return Menu::dot( 'disabled' !== $status ) . esc_html( $status ?: __( 'unknown', 'wp2-shopify' ) );
	}

	protected function column_last_sync( \WP_User $user ): string {
		$last   = get_user_meta( $user->ID, Mirror::LAST_SYNC, true );
		$failed = get_user_meta( $user->ID, Mirror::SYNC_FAILED, true );
		$source = (string) get_user_meta( $user->ID, Mirror::SYNC_SOURCE, true );

		return Menu::ago( $last ) . ( $source ? '<br><span class="description">' . esc_html( $source ) . '</span>' : '' ) . ( $failed ? '<br><strong class="wp2s-error-text">' . esc_html__( 'Last attempt failed', 'wp2-shopify' ) . '</strong>' : '' );
	}

	protected function column_linked( \WP_User $user ): string {
		return Menu::ago( get_user_meta( $user->ID, Mirror::LINKED_AT, true ) );
	}
}
