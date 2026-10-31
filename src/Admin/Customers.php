<?php

namespace RedeemID\Admin;

use RedeemID\Access\LevelStore;
use RedeemID\Access\Membership;
use RedeemID\Auth\TokenVault;
use RedeemID\Config;
use RedeemID\Privacy\Requests;
use RedeemID\Privacy\ShopifyErasure;
use RedeemID\Shopify\Admin as AdminApi;
use RedeemID\Support\EventLog;
use RedeemID\Support\Logger;
use RedeemID\Sync\BulkJob;
use RedeemID\Sync\BulkResync;
use RedeemID\Sync\Mirror;
use RedeemID\Sync\Reconciler;
use RedeemID\Webhooks\WpdbInboxStore;

/**
 * RedeemID → Customers: the linked customers, one customer's details, and the
 * controls an admin needs while testing: resync, edit tags, disconnect, and a
 * link to the customer in the Shopify admin. "Resync all" starts a paced
 * background resync of everyone (BulkResync), which staff can pause, resume
 * or cancel; it never starts by itself.
 *
 * Tag edits go to Shopify (tagsAdd / tagsRemove) and are then read back, so the
 * mirror never holds a tag Shopify does not. Test fixtures (see
 * Mirror::is_fixture) are edited locally instead.
 */
final class Customers {
	public const PAGE   = 'redeemid-customers';
	public const ACTION = 'redeemid_customers';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'action' ] );
		add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'progress' ] );
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
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || empty( $_POST['users'] ) ) {
			return;
		}

		check_admin_referer( 'bulk-customers' );

		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'redeemid' ), 403 );
		}

		$do    = sanitize_key( wp_unslash( '-1' !== ( $_POST['action'] ?? '-1' ) ? $_POST['action'] : ( $_POST['action2'] ?? '' ) ) );
		$users = array_filter( array_map( 'intval', (array) wp_unslash( $_POST['users'] ) ) );

		// Resync is one customer at a time (row action or the customer's page), never in bulk.
		self::finish( 'unlink' === $do ? self::apply( $do, $users ) : 'none', count( $users ) );
	}

	public static function action(): void {
		Menu::guard( self::ACTION );
		// phpcs:disable WordPress.Security.NonceVerification -- checked by Menu::guard().

		$user_id = absint( $_REQUEST['user'] ?? 0 );
		$do      = sanitize_key( wp_unslash( $_REQUEST['do'] ?? '' ) );

		if ( 'tags' === $do ) {
			self::finish( self::edit_tags( $user_id ), 1, $user_id );
		}

		if ( 'export' === $do ) {
			self::export();
		}

		if ( 'activation_refresh' === $do ) {
			Activation::forget();
			self::finish( '', 0 );
		}

		if ( str_starts_with( $do, 'bulk_' ) ) {
			self::finish( self::bulk( $do ), 0 );
		}

		$detail = 'detail' === sanitize_key( wp_unslash( $_REQUEST['back'] ?? '' ) ) && 'unlink' !== $do;

		self::finish( self::apply( $do, [ $user_id ] ), 1, $detail ? $user_id : 0 );
		// phpcs:enable WordPress.Security.NonceVerification
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

	private static function bulk( string $do ): string {
		switch ( $do ) {
			case 'bulk_start':
				$started = BulkResync::start( get_current_user_id() );

				if ( is_wp_error( $started ) ) {
					return 'bulk_running' === $started->get_error_code() ? 'bulk_running' : 'no_admin';
				}

				return 'bulk_started';
			case 'bulk_pause':
				BulkResync::pause();
				return 'bulk_paused';
			case 'bulk_resume':
				BulkResync::resume();
				return 'bulk_resumed';
			case 'bulk_cancel':
				BulkResync::cancel();
				return 'bulk_cancelled';
		}

		return 'none';
	}

	/**
	 * The progress poll from the Resync all panel. It also moves the resync
	 * along when WP-Cron is late, so it finishes while the page is open.
	 */
	public static function progress(): void {
		if ( ! current_user_can( Menu::CAP ) || ! check_ajax_referer( self::ACTION, 'nonce', false ) ) {
			wp_send_json_error( null, 403 );
		}

		$job = BulkResync::nudge();

		wp_send_json_success( $job ? self::bulk_view( $job ) : null );
	}

	/**
	 * What the panel shows for a job, also sent to the progress poll.
	 *
	 * @return array{status: string, percent: int, line: string, error: string}
	 */
	private static function bulk_view( array $job ): array {
		$eta  = BulkJob::eta( $job );
		$line = sprintf(
			/* translators: 1: customers done 2: customers in total 3: customers whose tags or status changed 4: customers no longer in Shopify */
			__( '%1$d of %2$d customers re-read. %3$d changed, %4$d no longer in Shopify.', 'redeemid' ),
			$job['done'],
			$job['total'],
			$job['changed'],
			$job['missing']
		);

		if ( null !== $eta && $eta > 0 ) {
			/* translators: %s: human time difference, such as "5 mins" */
			$line .= ' ' . sprintf( __( 'About %s left.', 'redeemid' ), human_time_diff( time(), time() + $eta ) );
		}

		if ( BulkJob::RUNNING === $job['status'] && (int) $job['next_at'] > time() + 5 ) {
			/* translators: %s: human time difference */
			$line .= ' ' . sprintf( __( 'Waiting %s for Shopify before the next batch.', 'redeemid' ), human_time_diff( time(), (int) $job['next_at'] ) );
		}

		return [
			'status'  => (string) $job['status'],
			'percent' => BulkJob::percent( $job ),
			'line'    => $line,
			'error'   => (string) $job['error'],
		];
	}

	/**
	 * @param array<string, string> $filters From CustomerQuery::filters().
	 */
	public static function export_url( array $filters ): string {
		$args = array_filter( [ 'action' => self::ACTION, 'do' => 'export' ] + array_intersect_key( $filters, array_flip( [ 's', 'state', 'level' ] ) ) );

		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	/**
	 * The filtered list as CSV, read from the mirror: no Shopify call.
	 */
	private static function export(): never {
		$args           = CustomerQuery::args( CustomerQuery::filters() );
		$args['number'] = 500;
		$levels         = LevelStore::levels();
		$now            = time();
		$cell           = static fn( mixed $value ): string => preg_match( '/^[=+\-@\t\r]/', (string) $value ) ? "'" . $value : (string) $value;
		$date           = static fn( mixed $stamp ): string => (int) $stamp > 0 ? gmdate( 'Y-m-d H:i:s', (int) $stamp ) : '';

		Logger::log( 'admin.customers_exported', [ 'user' => get_current_user_id() ] );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="shopify-customers-' . gmdate( 'Y-m-d' ) . '.csv"' );

		// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- writes to php://output, not the file system.
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming a download.
		fputcsv( $out, [ 'user_id', 'login', 'wp_email', 'shopify_email', 'first_name', 'last_name', 'customer_id', 'state', 'levels', 'tags', 'member_since', 'renews_on', 'last_sync_utc', 'linked_utc' ] );

		for ( $page = 1; ; $page++ ) {
			$args['paged'] = $page;
			$users         = ( new \WP_User_Query( $args + [ 'count_total' => false ] ) )->get_results();

			foreach ( $users as $user ) {
				$member = Mirror::member( $user->ID );
				$top    = Membership::top( $levels, Membership::held( $levels, $member, $now ) );

				fputcsv(
					$out,
					array_map(
						$cell,
						[
							$user->ID,
							$user->user_login,
							$user->user_email,
							get_user_meta( $user->ID, Mirror::EMAIL, true ),
							get_user_meta( $user->ID, Mirror::FIRST_NAME, true ),
							get_user_meta( $user->ID, Mirror::LAST_NAME, true ),
							Mirror::customer_id( $user->ID )?->numeric,
							$member->status,
							implode( ', ', array_map( static fn( string $slug ): string => $levels->get( $slug )?->label ?? $slug, $top ) ),
							implode( ', ', $member->tags ),
							get_user_meta( $user->ID, Mirror::SINCE, true ),
							get_user_meta( $user->ID, Mirror::RENEWS, true ),
							$date( get_user_meta( $user->ID, Mirror::LAST_SYNC, true ) ),
							$date( get_user_meta( $user->ID, Mirror::LINKED_AT, true ) ),
						]
					)
				);
			}

			if ( count( $users ) < $args['number'] ) {
				break;
			}

			wp_cache_flush_runtime();
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		// phpcs:enable WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv
		exit;
	}

	/**
	 * Members per level: tagged in Shopify against signed in here.
	 */
	private static function activation_panel(): void {
		$stats = Activation::stats();

		if ( ! $stats['levels'] && null === $stats['shopify_total'] ) {
			return;
		}

		Menu::panel_start(
			__( 'Activation', 'redeemid' ),
			__( 'Customers Shopify tags for each level, and how many of them have signed in to this site. Members who haven\'t can be pointed at the sign-in page. Counted once an hour.', 'redeemid' ),
			self::bulk_button( 'activation_refresh', __( 'Count again', 'redeemid' ) )
		);
		?>
		<div class="redeemid-panel__body redeemid-panel__body--flush">
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Level', 'redeemid' ); ?></th><th><?php esc_html_e( 'In Shopify', 'redeemid' ); ?></th><th><?php esc_html_e( 'Signed in here', 'redeemid' ); ?></th><th><?php esc_html_e( 'Activated', 'redeemid' ); ?></th></tr></thead>
				<tbody>
					<?php
					$rows = [ [ __( 'All customers', 'redeemid' ), $stats['shopify_total'], $stats['linked_total'], '' ] ];

					foreach ( $stats['levels'] as $slug => $row ) {
						$rows[] = [ $row['label'], $row['shopify'], $row['linked'], $slug ];
					}

					foreach ( $rows as [ $label, $shopify, $linked, $slug ] ) :
						$percent = Activation::percent( (int) $linked, $shopify );
						?>
						<tr>
							<td><?php echo '' === $slug ? esc_html( $label ) : sprintf( '<a href="%s">%s</a>', esc_url( Menu::url( self::PAGE, [ 'level' => $slug ] ) ), esc_html( $label ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo null === $shopify ? '—' : esc_html( number_format_i18n( $shopify ) . ( $shopify >= 10000 ? '+' : '' ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $linked ) ); ?></td>
							<td><?php echo null === $percent ? '—' : esc_html( $percent . '%' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php if ( $stats['error'] ) : ?>
			<div class="redeemid-panel__footer"><span class="description"><?php esc_html_e( 'Shopify could not be asked for its counts; the WordPress side is shown.', 'redeemid' ); ?></span></div>
		<?php endif; ?>
		<?php
		Menu::panel_end();
	}

	private static function bulk_panel(): void {
		$job      = BulkResync::job();
		$open     = $job && BulkJob::is_open( $job );
		$view     = $job ? self::bulk_view( $job ) : null;
		$statuses = [
			BulkJob::RUNNING   => [ 'warn', __( 'Running', 'redeemid' ) ],
			BulkJob::PAUSED    => [ 'neutral', __( 'Paused', 'redeemid' ) ],
			BulkJob::DONE      => [ 'ok', __( 'Finished', 'redeemid' ) ],
			BulkJob::CANCELLED => [ 'neutral', __( 'Cancelled', 'redeemid' ) ],
			BulkJob::FAILED    => [ 'error', __( 'Stopped', 'redeemid' ) ],
		];
		$buttons  = '';

		if ( ! $open ) {
			$buttons .= self::bulk_button( 'bulk_start', $job ? __( 'Resync all again', 'redeemid' ) : __( 'Resync all customers', 'redeemid' ), 'primary', __( 'Re-read every linked customer from Shopify? It runs in the background and can be paused.', 'redeemid' ) );
		}

		if ( $job && BulkJob::RUNNING === $job['status'] ) {
			$buttons .= self::bulk_button( 'bulk_pause', __( 'Pause', 'redeemid' ) );
		}

		if ( $job && in_array( $job['status'], [ BulkJob::PAUSED, BulkJob::FAILED ], true ) ) {
			$buttons .= self::bulk_button( 'bulk_resume', __( 'Resume', 'redeemid' ), 'primary' );
		}

		if ( $job && ( $open || BulkJob::FAILED === $job['status'] ) ) {
			$buttons .= self::bulk_button( 'bulk_cancel', __( 'Cancel', 'redeemid' ) );
		}

		Menu::panel_start(
			__( 'Resync all customers', 'redeemid' ),
			__( 'Re-reads every linked customer\'s tags and status from Shopify, 50 per request, using only the spare half of the store\'s API budget so sign-ins and webhooks are never slowed. It runs in the background: you can leave this page. Nothing starts it on a schedule.', 'redeemid' ),
			$buttons,
			'redeemid-bulk'
		);

		if ( $job ) :
			[ $state, $label ] = $statuses[ $job['status'] ] ?? [ 'neutral', $job['status'] ];
			?>
			<div class="redeemid-panel__body" data-redeemid-bulk="<?php echo esc_attr( $job['status'] ); ?>">
				<p>
					<?php echo Menu::status( $state, $label ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php
					/* translators: %s: date and time */
					echo esc_html( sprintf( __( 'Started %s', 'redeemid' ), wp_date( 'Y-m-d H:i', (int) $job['started_at'] ) ) );
					?>
				</p>
				<progress class="redeemid-progress" max="100" aria-label="<?php esc_attr_e( 'Resync progress', 'redeemid' ); ?>" value="<?php echo (int) $view['percent']; ?>"><?php echo (int) $view['percent']; ?>%</progress>
				<p class="redeemid-bulk__line"><?php echo esc_html( $view['line'] ); ?></p>
				<p class="redeemid-bulk__error"<?php echo '' === $view['error'] ? ' hidden' : ''; ?>><strong><?php esc_html_e( 'Last error:', 'redeemid' ); ?></strong> <span><?php echo esc_html( $view['error'] ); ?></span></p>
			</div>
			<?php if ( BulkJob::RUNNING === $job['status'] ) : ?>
				<script>
				( function () {
					var box = document.querySelector( '[data-redeemid-bulk]' );
					var body = new URLSearchParams( { action: <?php echo wp_json_encode( self::ACTION ); ?>, nonce: <?php echo wp_json_encode( wp_create_nonce( self::ACTION ) ); ?> } );
					var poll = function () {
						fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', credentials: 'same-origin', body: body } )
							.then( function ( r ) { return r.json(); } )
							.then( function ( r ) {
								if ( ! r || ! r.success || ! r.data ) { return; }
								if ( r.data.status !== 'running' ) { window.location.reload(); return; }
								box.querySelector( 'progress' ).value = r.data.percent;
								box.querySelector( '.redeemid-bulk__line' ).textContent = r.data.line;
								var err = box.querySelector( '.redeemid-bulk__error' );
								err.hidden = ! r.data.error;
								err.querySelector( 'span' ).textContent = r.data.error;
								setTimeout( poll, 5000 );
							} )
							.catch( function () { setTimeout( poll, 15000 ); } );
					};
					setTimeout( poll, 3000 );
				}() );
				</script>
			<?php endif; ?>
			<?php
		endif;

		Menu::panel_end();
	}

	private static function bulk_button( string $do, string $label, string $class = 'secondary', string $confirm = '' ): string {
		return sprintf(
			'<a class="button button-%s" href="%s"%s>%s</a>',
			esc_attr( $class ),
			esc_url( wp_nonce_url( add_query_arg( [ 'action' => self::ACTION, 'do' => $do ], admin_url( 'admin-post.php' ) ), self::ACTION ) ),
			'' !== $confirm ? ' onclick="return confirm(\'' . esc_js( $confirm ) . '\')"' : '',
			esc_html( $label )
		);
	}

	private static function edit_tags( int $user_id ): string {
		$remove = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['remove'] ?? [] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in action().
		$add    = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['add'] ?? '' ) ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification

		return self::change_tags( $user_id, $add, $remove, 'admin' );
	}

	/**
	 * Add and remove a linked customer's tags in Shopify, then read back what
	 * Shopify holds. The grant lives in Shopify, never in WordPress. Also the
	 * redeemid/edit-member-tags ability. Returns a result code: tags_saved,
	 * tags_failed, no_admin or none.
	 *
	 * @param string[] $add
	 * @param string[] $remove Only tags the customer holds now are removed.
	 */
	public static function change_tags( int $user_id, array $add, array $remove, string $by ): string {
		$id = Mirror::customer_id( $user_id );

		if ( ! $id ) {
			return 'none';
		}

		$current = Mirror::tags( $user_id );
		$plan    = self::tag_plan( $current, $add, $remove );
		$add     = $plan['add'];
		$remove  = $plan['remove'];

		if ( ! $add && ! $remove ) {
			return 'none';
		}

		if ( Mirror::is_fixture( $user_id ) ) {
			Mirror::set_tags( $user_id, array_merge( array_diff( $current, $remove ), $add ) );
			Mirror::stamp( $user_id, $by );
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
		Reconciler::sync_user( $user_id, $by );

		return $error ? 'tags_failed' : 'tags_saved';
	}

	/**
	 * Which tags to add and remove: removes only tags held now, a tag in both
	 * lists is kept, and adds skip tags already held (case-insensitive). Pure.
	 *
	 * @param string[] $current
	 * @param string[] $add
	 * @param string[] $remove
	 * @return array{add: string[], remove: string[]}
	 */
	public static function tag_plan( array $current, array $add, array $remove ): array {
		$add    = array_values( array_filter( array_map( static fn( $t ) => trim( (string) $t ), $add ), 'strlen' ) );
		$remove = array_values( array_intersect( $current, array_map( 'strval', $remove ) ) );
		$remove = array_values( array_udiff( $remove, $add, 'strcasecmp' ) );
		$add    = array_values( array_udiff( $add, $current, 'strcasecmp' ) );

		return [ 'add' => $add, 'remove' => $remove ];
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
		$user_id = absint( $_GET['user'] ?? 0 );
		$user    = $user_id ? get_userdata( $user_id ) : null;

		if ( $user && Mirror::customer_id( $user->ID ) ) {
			self::detail( $user );
		} else {
			self::list();
		}

		Menu::page_end();
	}

	private static function notices(): void {
		Menu::result_notice(
			[
				/* translators: %d: number of customers */
				'synced'      => [ 'success', __( 'Resynced %d customer(s) from Shopify.', 'redeemid' ) ],
				/* translators: %d: number of customers */
				'sync_failed' => [ 'error', __( 'Shopify could not be reached. See App events for the failed call.', 'redeemid' ) ],
				'no_admin'    => [ 'error', __( 'The Admin API is not configured, so the plugin cannot read from or write to Shopify. See Debug.', 'redeemid' ) ],
				/* translators: %d: number of users */
				'unlinked'    => [ 'success', __( 'Disconnected %d user(s) from Shopify. Their WordPress accounts were kept.', 'redeemid' ) ],
				'tags_saved'  => [ 'success', __( 'Tags saved.', 'redeemid' ) ],
				'tags_failed' => [ 'error', __( 'Shopify rejected the tag change. The tags below are what Shopify holds now.', 'redeemid' ) ],
				'bulk_started'   => [ 'success', __( 'Resync started. It runs in the background; progress is below.', 'redeemid' ) ],
				'bulk_running'   => [ 'warning', __( 'A resync is already in progress.', 'redeemid' ) ],
				'bulk_paused'    => [ 'success', __( 'Resync paused. It stops after the batch in progress.', 'redeemid' ) ],
				'bulk_resumed'   => [ 'success', __( 'Resync resumed from where it stopped.', 'redeemid' ) ],
				'bulk_cancelled' => [ 'success', __( 'Resync cancelled. Customers already re-read keep their new data.', 'redeemid' ) ],
			]
		);
	}

	private static function list(): void {
		$table   = new CustomersTable();
		$actions = '';

		if ( '' !== Config::admin_url() ) {
			$actions .= sprintf( '<a class="page-title-action" href="%s" target="_blank" rel="noopener">%s</a>', esc_url( Config::admin_url( 'customers' ) ), esc_html__( 'Customers in Shopify', 'redeemid' ) );
		}

		$filters = CustomerQuery::filters();
		$table->prepare_items();
		Menu::page_start( __( 'Customers', 'redeemid' ), __( 'Shopify customers', 'redeemid' ), $actions, __( 'WordPress users linked to a Shopify customer. Tags are read from Shopify; edits are written back to Shopify, never kept only in WordPress.', 'redeemid' ) );
		self::notices();
		self::activation_panel();
		self::bulk_panel();
		?>
		<?php $table->views(); ?>
		<form method="get" id="redeemid-customer-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<?php foreach ( array_filter( [ 'state' => $filters['state'], 'orderby' => $filters['orderby'] ] ) as $key => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<?php $table->search_box( __( 'Search name, email or customer ID', 'redeemid' ), 'redeemid-customer' ); ?>
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
		$events  = EventLog::query( [ 'user' => $user->ID ], 15, 1 )['rows'];
		$hooks   = ( new WpdbInboxStore() )->query( [ 'customer' => $id->numeric ], 15, 1 )['rows'];
		$buttons = sprintf( '<a class="button" href="%s">%s</a>', esc_url( (string) get_edit_user_link( $user->ID ) ), esc_html__( 'Edit WordPress user', 'redeemid' ) );

		if ( ! $fixture && '' !== Config::admin_url() ) {
			$buttons .= sprintf( '<a class="button" href="%s" target="_blank" rel="noopener">%s</a>', esc_url( Config::admin_url( 'customers/' . $id->numeric ) ), esc_html__( 'Open in Shopify admin', 'redeemid' ) );
		}

		$buttons .= sprintf( '<a class="button" href="%s" onclick="return confirm(\'%s\')">%s</a>', esc_url( self::action_url( 'unlink', $user->ID ) ), esc_js( __( 'Disconnect this user from Shopify? Their WordPress account stays.', 'redeemid' ) ), esc_html__( 'Disconnect', 'redeemid' ) );
		$buttons .= sprintf( '<a class="button button-primary" href="%s">%s</a>', esc_url( self::action_url( 'sync', $user->ID, true ) ), esc_html__( 'Resync from Shopify', 'redeemid' ) );

		Menu::page_start(
			__( 'Customers', 'redeemid' ),
			$user->display_name ?: $user->user_login,
			sprintf( '<a class="page-title-action" href="%s">%s</a>', esc_url( Menu::url( self::PAGE ) ), esc_html__( 'All customers', 'redeemid' ) ),
			$user->user_email
		);
		self::notices();
		?>

		<?php if ( $fixture ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Test fixture: this customer does not exist in Shopify. Tag edits and resyncs stay in WordPress.', 'redeemid' ); ?></p></div>
		<?php endif; ?>

		<div class="redeemid-columns">
			<?php Menu::panel_start( __( 'Synced data', 'redeemid' ), __( 'What WordPress last read from Shopify for this customer.', 'redeemid' ), $buttons ); ?>
				<div class="redeemid-panel__body redeemid-panel__body--flush">
					<table class="widefat striped redeemid-kv">
						<?php foreach ( UserProfile::rows( $user ) as $label => $value ) : ?>
							<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( (string) $value ); ?></td></tr>
						<?php endforeach; ?>
					</table>
				</div>
			<?php Menu::panel_end(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="do" value="tags">
				<input type="hidden" name="user" value="<?php echo (int) $user->ID; ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php Menu::panel_start( __( 'Tags', 'redeemid' ), $fixture ? __( 'Saved in WordPress only, because this is a test fixture.', 'redeemid' ) : __( 'Saved to Shopify, then read back.', 'redeemid' ) ); ?>
					<div class="redeemid-panel__body">
						<?php if ( $tags ) : ?>
							<p class="description"><?php esc_html_e( 'Tick a tag to remove it.', 'redeemid' ); ?></p>
							<div class="redeemid-tag-list">
								<?php foreach ( $tags as $tag ) : ?>
									<label><input type="checkbox" name="remove[]" value="<?php echo esc_attr( $tag ); ?>"> <code><?php echo esc_html( $tag ); ?></code></label>
								<?php endforeach; ?>
							</div>
						<?php else : ?>
							<p><?php esc_html_e( 'No tags.', 'redeemid' ); ?></p>
						<?php endif; ?>
						<p>
							<label for="redeemid-add-tags"><?php esc_html_e( 'Add tags (comma separated)', 'redeemid' ); ?></label><br>
							<input type="text" id="redeemid-add-tags" name="add" class="regular-text" placeholder="gold-member">
						</p>
					</div>
					<div class="redeemid-panel__footer">
						<?php submit_button( $fixture ? __( 'Save tags', 'redeemid' ) : __( 'Save tags to Shopify', 'redeemid' ), 'secondary', 'submit', false ); ?>
					</div>
				<?php Menu::panel_end(); ?>
			</form>
		</div>

		<div class="redeemid-columns">
			<?php Menu::panel_start( __( 'Recent app events', 'redeemid' ), '', sprintf( '<a class="button button-small" href="%s">%s</a>', esc_url( Menu::url( Events::PAGE, [ 'user' => $user->ID ] ) ), esc_html__( 'View all', 'redeemid' ) ) ); ?>
				<div class="redeemid-panel__body redeemid-panel__body--flush">
					<table class="widefat striped">
						<?php foreach ( $events as $row ) : ?>
							<tr><td><?php echo Menu::ago( $row['created_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td><code><?php echo esc_html( $row['event'] ); ?></code></td><td><?php echo esc_html( trim( $row['label'] . ' ' . $row['status'] ) ); ?></td></tr>
						<?php endforeach; ?>
						<?php if ( ! $events ) : ?>
							<tr><td><?php esc_html_e( 'Nothing logged yet.', 'redeemid' ); ?></td></tr>
						<?php endif; ?>
					</table>
				</div>
			<?php Menu::panel_end(); ?>
			<?php Menu::panel_start( __( 'Recent webhooks', 'redeemid' ), '', sprintf( '<a class="button button-small" href="%s">%s</a>', esc_url( Menu::url( Webhooks::PAGE, [ 'customer' => $id->numeric ] ) ), esc_html__( 'View all', 'redeemid' ) ) ); ?>
				<div class="redeemid-panel__body redeemid-panel__body--flush">
					<table class="widefat striped">
						<?php foreach ( $hooks as $row ) : ?>
							<tr><td><?php echo Menu::ago( $row['received_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td><code><?php echo esc_html( $row['topic'] ); ?></code></td><td><?php echo esc_html( $row['status'] ); ?></td></tr>
						<?php endforeach; ?>
						<?php if ( ! $hooks ) : ?>
							<tr><td><?php esc_html_e( 'No webhooks for this customer.', 'redeemid' ); ?></td></tr>
						<?php endif; ?>
					</table>
				</div>
			<?php Menu::panel_end(); ?>
		</div>

		<?php self::privacy_panel( $user ); ?>
		<?php
	}

	/**
	 * Privacy requests for this person, from Tools → Export / Erase Personal
	 * Data, matched on their WordPress and Shopify emails.
	 */
	private static function privacy_panel( \WP_User $user ): void {
		$requests = Requests::for_emails( [ $user->user_email, (string) get_user_meta( $user->ID, Mirror::EMAIL, true ) ] );
		$actions  = sprintf(
			'<a class="button button-small" href="%s">%s</a> <a class="button button-small" href="%s">%s</a>',
			esc_url( Requests::screen_url( Requests::EXPORT ) ),
			esc_html__( 'Export Personal Data', 'redeemid' ),
			esc_url( Requests::screen_url( Requests::ERASE ) ),
			esc_html__( 'Erase Personal Data', 'redeemid' )
		);
		$statuses = [
			'request-pending'   => __( 'Waiting for confirmation', 'redeemid' ),
			'request-confirmed' => __( 'Confirmed', 'redeemid' ),
			'request-failed'    => __( 'Failed', 'redeemid' ),
			'request-completed' => __( 'Completed', 'redeemid' ),
		];

		Menu::panel_start( __( 'Privacy requests', 'redeemid' ), __( 'Handled in WordPress\'s privacy tools, with a Shopify column on each request.', 'redeemid' ), $actions );
		?>
			<div class="redeemid-panel__body redeemid-panel__body--flush">
				<table class="widefat striped">
					<?php foreach ( $requests as $request ) : ?>
						<?php $erasure = ShopifyErasure::state( $request->ID ); ?>
						<tr>
							<td><?php echo Menu::ago( $request->created_timestamp ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><a href="<?php echo esc_url( Requests::screen_url( $request->action_name ) ); ?>"><?php echo esc_html( Requests::EXPORT === $request->action_name ? __( 'Export', 'redeemid' ) : __( 'Erase', 'redeemid' ) ); ?></a></td>
							<td><?php echo esc_html( $statuses[ $request->status ] ?? $request->status ); ?></td>
							<td>
								<?php
								echo esc_html(
									implode(
										' · ',
										array_filter(
											[
												'shopify' === get_post_meta( $request->ID, Requests::SOURCE, true ) ? __( 'from Shopify', 'redeemid' ) : '',
												'member' === get_post_meta( $request->ID, Requests::SOURCE, true ) ? __( 'from the Member Account block', 'redeemid' ) : '',
												$erasure && ShopifyErasure::REQUESTED === $erasure['state'] ? __( 'erasure requested in Shopify', 'redeemid' ) : '',
											]
										)
									)
								);
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $requests ) : ?>
						<tr><td><?php esc_html_e( 'No privacy requests for this person.', 'redeemid' ); ?></td></tr>
					<?php endif; ?>
				</table>
			</div>
		<?php
		Menu::panel_end();
	}
}
