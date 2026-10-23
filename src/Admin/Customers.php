<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Auth\TokenVault;
use WP2_Shopify\Config;
use WP2_Shopify\Shopify\Admin as AdminApi;
use WP2_Shopify\Support\EventLog;
use WP2_Shopify\Support\Logger;
use WP2_Shopify\Sync\Mirror;
use WP2_Shopify\Sync\Reconciler;
use WP2_Shopify\Webhooks\WpdbInboxStore;

/**
 * Shopify → Customers: the linked customers, one customer's details, and the
 * controls an admin needs while testing: resync, edit tags, disconnect, and a
 * link to the customer in the Shopify admin.
 *
 * Tag edits go to Shopify (tagsAdd / tagsRemove) and are then read back, so the
 * mirror never holds a tag Shopify does not. Test fixtures (see
 * Mirror::is_fixture) are edited locally instead.
 */
final class Customers {
	public const PAGE   = 'wp2-shopify-customers';
	public const ACTION = 'wp2_shopify_customers';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'action' ] );
	}

	/**
	 * @param bool $to_detail Come back to the customer's detail view, not the list.
	 */
	public static function action_url( string $do, int $user_id, bool $to_detail = false ): string {
		$args = [ 'action' => self::ACTION, 'do' => $do, 'user' => $user_id ];

		if ( $to_detail ) {
			$args['back'] = 'detail';
		}

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	/**
	 * Bulk actions post back to the list page, because WP_List_Table names its
	 * select "action", which admin-post.php also uses.
	 */
	public static function load(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['users'] ) ) {
			return;
		}

		check_admin_referer( 'bulk-customers' );

		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'wp2-shopify' ), 403 );
		}

		$do    = sanitize_key( wp_unslash( '-1' !== ( $_POST['action'] ?? '-1' ) ? $_POST['action'] : ( $_POST['action2'] ?? '' ) ) );
		$users = array_filter( array_map( 'intval', (array) wp_unslash( $_POST['users'] ) ) );

		self::finish( self::apply( $do, $users ), count( $users ) );
	}

	public static function action(): void {
		Menu::guard( self::ACTION );

		$user_id = (int) ( $_REQUEST['user'] ?? 0 );
		$do      = sanitize_key( wp_unslash( $_REQUEST['do'] ?? '' ) );

		if ( 'sync_all' === $do ) {
			$stats = Reconciler::run();
			self::finish( $stats['error'] ? 'sync_failed' : 'synced_all', (int) $stats['checked'] );
		}

		if ( 'tags' === $do ) {
			self::finish( self::edit_tags( $user_id ), 1, $user_id );
		}

		$detail = 'detail' === ( $_REQUEST['back'] ?? '' ) && 'unlink' !== $do;

		self::finish( self::apply( $do, [ $user_id ] ), 1, $detail ? $user_id : 0 );
	}

	/**
	 * @param int[] $users
	 */
	private static function apply( string $do, array $users ): string {
		$users = array_values( array_filter( $users, static fn( int $id ) => null !== Mirror::customer_id( $id ) ) );

		if ( ! $users ) {
			return 'none';
		}

		if ( 'sync' === $do ) {
			if ( ! AdminApi::available() ) {
				return 'no_admin';
			}

			return is_wp_error( Reconciler::sync_users( $users, 'admin' ) ) ? 'sync_failed' : 'synced';
		}

		if ( 'unlink' === $do ) {
			foreach ( $users as $user_id ) {
				TokenVault::forget_all( $user_id );
				Mirror::unlink( $user_id );
				Logger::log( 'admin.unlinked', [ 'user' => $user_id ] );
			}

			return 'unlinked';
		}

		return 'none';
	}

	private static function edit_tags( int $user_id ): string {
		$id = Mirror::customer_id( $user_id );

		if ( ! $id ) {
			return 'none';
		}

		$current = Mirror::tags( $user_id );
		$remove  = array_values( array_intersect( $current, array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['remove'] ?? [] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in action().
		$add     = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['add'] ?? '' ) ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$remove  = array_values( array_udiff( $remove, $add, 'strcasecmp' ) );
		$add     = array_values( array_udiff( $add, $current, 'strcasecmp' ) );

		if ( ! $add && ! $remove ) {
			return 'none';
		}

		if ( Mirror::is_fixture( $user_id ) ) {
			Mirror::set_tags( $user_id, array_merge( array_diff( $current, $remove ), $add ) );
			Mirror::stamp( $user_id, 'admin' );
			Logger::log( 'admin.tags_edited', [ 'user' => $user_id, 'added' => implode( ',', $add ), 'removed' => implode( ',', $remove ), 'status' => 'fixture' ] );

			return 'tags_saved';
		}

		if ( ! AdminApi::available() ) {
			return 'no_admin';
		}

		$added   = AdminApi::add_tags( $id, $add );
		$removed = AdminApi::remove_tags( $id, $remove );
		$error   = is_wp_error( $added ) ? $added : ( is_wp_error( $removed ) ? $removed : null );

		Logger::log( 'admin.tags_edited', [ 'user' => $user_id, 'customer' => $id->numeric, 'added' => implode( ',', $add ), 'removed' => implode( ',', $remove ), 'status' => $error ? $error->get_error_code() : 'ok' ] );

		// Read back what Shopify now holds, whatever happened.
		Reconciler::sync_user( $user_id, 'admin' );

		return $error ? 'tags_failed' : 'tags_saved';
	}

	private static function finish( string $result, int $count, int $user_id = 0 ): never {
		$args = [ 'result' => $result, 'n' => $count ];

		if ( $user_id ) {
			$args['user'] = $user_id;
		}

		wp_safe_redirect( Menu::url( self::PAGE, $args ) );
		exit;
	}

	public static function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification
		$user_id = (int) ( $_GET['user'] ?? 0 );

		echo '<div class="wrap">';

		Menu::result_notice(
			[
				'synced'      => [ 'success', __( 'Resynced %d customer(s) from Shopify.', 'wp2-shopify' ) ],
				'synced_all'  => [ 'success', __( 'Reconciliation checked %d customer(s).', 'wp2-shopify' ) ],
				'sync_failed' => [ 'error', __( 'Shopify could not be reached. See App events for the failed call.', 'wp2-shopify' ) ],
				'no_admin'    => [ 'error', __( 'The Admin API is not configured, so the plugin cannot read from or write to Shopify. See Debug.', 'wp2-shopify' ) ],
				'unlinked'    => [ 'success', __( 'Disconnected %d user(s) from Shopify. Their WordPress accounts were kept.', 'wp2-shopify' ) ],
				'tags_saved'  => [ 'success', __( 'Tags saved.', 'wp2-shopify' ) ],
				'tags_failed' => [ 'error', __( 'Shopify rejected the tag change. The tags below are what Shopify holds now.', 'wp2-shopify' ) ],
			]
		);

		$user = $user_id ? get_userdata( $user_id ) : null;

		if ( $user && Mirror::customer_id( $user->ID ) ) {
			self::detail( $user );
		} else {
			self::list();
		}

		echo '</div>';
	}

	private static function list(): void {
		$table = new CustomersTable();
		$table->prepare_items();
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Shopify customers', 'wp2-shopify' ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( self::action_url( 'sync_all', 0 ) ); ?>"><?php esc_html_e( 'Resync everyone now', 'wp2-shopify' ); ?></a>
		<?php if ( '' !== Config::admin_url() ) : ?>
			<a class="page-title-action" href="<?php echo esc_url( Config::admin_url( 'customers' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Customers in Shopify', 'wp2-shopify' ); ?></a>
		<?php endif; ?>
		<hr class="wp-header-end">
		<?php $table->views(); ?>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<?php $table->search_box( __( 'Search name, email or customer ID', 'wp2-shopify' ), 'wp2s-customer' ); ?>
		</form>
		<form method="post">
			<?php $table->display(); ?>
		</form>
		<?php
	}

	private static function detail( \WP_User $user ): void {
		$id      = Mirror::customer_id( $user->ID );
		$fixture = Mirror::is_fixture( $user->ID );
		$tags    = Mirror::tags( $user->ID );
		?>
		<h1 class="wp-heading-inline"><?php echo esc_html( $user->display_name ?: $user->user_login ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( Menu::url( self::PAGE ) ); ?>"><?php esc_html_e( 'All customers', 'wp2-shopify' ); ?></a>
		<hr class="wp-header-end">

		<p>
			<a class="button" href="<?php echo esc_url( (string) get_edit_user_link( $user->ID ) ); ?>"><?php esc_html_e( 'Edit WordPress user', 'wp2-shopify' ); ?></a>
			<?php if ( ! $fixture && '' !== Config::admin_url() ) : ?>
				<a class="button" href="<?php echo esc_url( Config::admin_url( 'customers/' . $id->numeric ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open in Shopify admin', 'wp2-shopify' ); ?></a>
			<?php endif; ?>
			<a class="button button-primary" href="<?php echo esc_url( self::action_url( 'sync', $user->ID, true ) ); ?>"><?php esc_html_e( 'Resync from Shopify', 'wp2-shopify' ); ?></a>
			<a class="button" href="<?php echo esc_url( self::action_url( 'unlink', $user->ID ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Disconnect this user from Shopify? Their WordPress account stays.', 'wp2-shopify' ) ); ?>')"><?php esc_html_e( 'Disconnect', 'wp2-shopify' ); ?></a>
		</p>

		<?php if ( $fixture ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Test fixture: this customer does not exist in Shopify. Tag edits and resyncs stay in WordPress.', 'wp2-shopify' ); ?></p></div>
		<?php endif; ?>

		<div style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start">
			<div style="flex:1 1 420px;max-width:640px">
				<h2><?php esc_html_e( 'Synced data', 'wp2-shopify' ); ?></h2>
				<table class="widefat striped">
					<?php foreach ( UserProfile::rows( $user ) as $label => $value ) : ?>
						<tr><th style="width:40%"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( (string) $value ); ?></td></tr>
					<?php endforeach; ?>
				</table>
			</div>

			<div style="flex:1 1 320px;max-width:480px">
				<h2><?php esc_html_e( 'Tags', 'wp2-shopify' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="do" value="tags">
					<input type="hidden" name="user" value="<?php echo (int) $user->ID; ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<?php if ( $tags ) : ?>
						<p class="description"><?php esc_html_e( 'Tick a tag to remove it.', 'wp2-shopify' ); ?></p>
						<?php foreach ( $tags as $tag ) : ?>
							<label style="display:block"><input type="checkbox" name="remove[]" value="<?php echo esc_attr( $tag ); ?>"> <code><?php echo esc_html( $tag ); ?></code></label>
						<?php endforeach; ?>
					<?php else : ?>
						<p><?php esc_html_e( 'No tags.', 'wp2-shopify' ); ?></p>
					<?php endif; ?>
					<p>
						<label for="wp2s-add-tags"><?php esc_html_e( 'Add tags (comma separated)', 'wp2-shopify' ); ?></label><br>
						<input id="wp2s-add-tags" name="add" class="regular-text" placeholder="gold-member">
					</p>
					<?php submit_button( $fixture ? __( 'Save tags', 'wp2-shopify' ) : __( 'Save tags to Shopify', 'wp2-shopify' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>

		<h2><?php esc_html_e( 'Recent activity', 'wp2-shopify' ); ?></h2>
		<?php
		$events = EventLog::query( [ 'user' => $user->ID ], 15, 1 )['rows'];
		$hooks  = ( new WpdbInboxStore() )->query( [ 'customer' => $id->numeric ], 15, 1 )['rows'];
		?>
		<div style="display:flex;flex-wrap:wrap;gap:24px">
			<div style="flex:1 1 420px">
				<h3><?php esc_html_e( 'App events', 'wp2-shopify' ); ?> <a href="<?php echo esc_url( Menu::url( Events::PAGE, [ 'user' => $user->ID ] ) ); ?>" style="font-size:13px;font-weight:400"><?php esc_html_e( 'All', 'wp2-shopify' ); ?></a></h3>
				<table class="widefat striped">
					<?php foreach ( $events as $row ) : ?>
						<tr><td><?php echo Menu::ago( $row['created_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td><code><?php echo esc_html( $row['event'] ); ?></code></td><td><?php echo esc_html( trim( $row['label'] . ' ' . $row['status'] ) ); ?></td></tr>
					<?php endforeach; ?>
					<?php if ( ! $events ) : ?>
						<tr><td><?php esc_html_e( 'Nothing logged yet.', 'wp2-shopify' ); ?></td></tr>
					<?php endif; ?>
				</table>
			</div>
			<div style="flex:1 1 420px">
				<h3><?php esc_html_e( 'Webhooks', 'wp2-shopify' ); ?> <a href="<?php echo esc_url( Menu::url( Webhooks::PAGE, [ 'customer' => $id->numeric ] ) ); ?>" style="font-size:13px;font-weight:400"><?php esc_html_e( 'All', 'wp2-shopify' ); ?></a></h3>
				<table class="widefat striped">
					<?php foreach ( $hooks as $row ) : ?>
						<tr><td><?php echo Menu::ago( $row['received_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td><code><?php echo esc_html( $row['topic'] ); ?></code></td><td><?php echo esc_html( $row['status'] ); ?></td></tr>
					<?php endforeach; ?>
					<?php if ( ! $hooks ) : ?>
						<tr><td><?php esc_html_e( 'No webhooks for this customer.', 'wp2-shopify' ); ?></td></tr>
					<?php endif; ?>
				</table>
			</div>
		</div>
		<?php
	}
}
