<?php

namespace RedeemID\Admin;

use RedeemID\Support\Logger;
use RedeemID\Support\Transfer;

/**
 * RedeemID → Access → Export / import: download this site's settings as
 * JSON, or load a file, see what it would change (the dry run), and import
 * the sections you tick. See Support\Transfer for what a file holds.
 *
 * A loaded file waits in a short-lived transient for the person who loaded
 * it, so the preview and the import read exactly the same data.
 */
final class AccessTransfer {
	public const ACTION = 'redeemid_transfer';

	private const PENDING = 'redeemid_transfer_';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'action' ] );
	}

	public static function action(): void {
		Menu::guard( self::ACTION );
		// phpcs:disable WordPress.Security.NonceVerification -- checked by Menu::guard().

		$do   = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$args = [ 'tab' => 'transfer' ];

		if ( 'export' === $do ) {
			self::download();
		}

		switch ( $do ) {
			case 'load':
				$raw = '';

				if ( isset( $_FILES['file']['tmp_name'] ) && is_uploaded_file( (string) $_FILES['file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- a temp path from PHP, checked by is_uploaded_file().
					$raw = (string) file_get_contents( (string) $_FILES['file']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPressVIPMinimum.Performance.FetchingRemoteData -- a local upload.
				}

				if ( '' === trim( $raw ) ) {
					$raw = (string) wp_unslash( $_POST['json'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON, decoded and cleaned by Transfer::normalize().
				}

				$data     = json_decode( $raw, true );
				$problems = Transfer::validate( $data );

				if ( $problems ) {
					$args['result'] = 'transfer_' . $problems[0];
					break;
				}

				set_transient(
					self::PENDING . get_current_user_id(),
					[
						'sections' => Transfer::rebase( Transfer::normalize( $data ), (string) ( $data['site'] ?? '' ), home_url( '/' ) ),
						'from'     => sanitize_text_field( (string) ( $data['site'] ?? '' ) ),
						'at'       => sanitize_text_field( (string) ( $data['exported_at'] ?? '' ) ),
					],
					30 * MINUTE_IN_SECONDS
				);
				$args['loaded'] = 1;
				break;
			case 'import':
				$pending = get_transient( self::PENDING . get_current_user_id() );

				if ( ! is_array( $pending ) ) {
					$args['result'] = 'transfer_expired';
					break;
				}

				$report = Transfer::apply( (array) $pending['sections'], array_map( 'sanitize_key', (array) wp_unslash( $_POST['sections'] ?? [] ) ) );
				delete_transient( self::PENDING . get_current_user_id() );
				set_transient( self::PENDING . 'report_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );
				$args['result'] = $report['written'] ? 'transfer_done' : 'transfer_nothing';
				break;
			case 'discard':
				delete_transient( self::PENDING . get_current_user_id() );
				break;
		}

		wp_safe_redirect( Menu::url( Access::PAGE, $args ) );
		exit;
		// phpcs:enable WordPress.Security.NonceVerification
	}

	private static function download(): never {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		Logger::log( 'admin.settings_exported', [ 'user' => get_current_user_id() ] );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="redeemid-settings-' . sanitize_file_name( $host ) . '-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( Transfer::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput -- a JSON download.
		exit;
	}

	/**
	 * Notices for Access::render(): result code => [ type, text ].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function messages(): array {
		return [
			'transfer_not_json'      => [ 'error', __( 'That isn\'t a JSON file.', 'redeemid' ) ],
			'transfer_wrong_format'  => [ 'error', __( 'That file isn\'t a RedeemID settings export.', 'redeemid' ) ],
			'transfer_newer_version' => [ 'error', __( 'That file comes from a newer version of RedeemID. Update this site first.', 'redeemid' ) ],
			'transfer_empty'         => [ 'error', __( 'That file has no settings in it.', 'redeemid' ) ],
			'transfer_expired'       => [ 'error', __( 'The loaded file expired. Load it again.', 'redeemid' ) ],
			'transfer_nothing'       => [ 'warning', __( 'No section was ticked, so nothing was imported.', 'redeemid' ) ],
			'transfer_done'          => [ 'success', __( 'Settings imported.', 'redeemid' ) ],
		];
	}

	public static function render(): void {
		$pending = get_transient( self::PENDING . get_current_user_id() );
		$report  = get_transient( self::PENDING . 'report_' . get_current_user_id() );
		$names   = [
			Transfer::LEVELS     => __( 'Levels', 'redeemid' ),
			Transfer::RULES      => __( 'Content rules: site default, post types and taxonomies', 'redeemid' ),
			Transfer::TERM_RULES => __( 'Term rules', 'redeemid' ),
			Transfer::FEATURES   => __( 'Feature mappings', 'redeemid' ),
			Transfer::SETTINGS   => __( 'Membership settings', 'redeemid' ),
		];

		if ( is_array( $report ) ) {
			delete_transient( self::PENDING . 'report_' . get_current_user_id() );

			if ( $report['skipped_terms'] ) {
				/* translators: %s: comma-separated taxonomy/term pairs */
				echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'These term rules were skipped because the term doesn\'t exist on this site: %s. Create the terms and import again.', 'redeemid' ), implode( ', ', $report['skipped_terms'] ) ) ) . '</p></div>';
			}

			if ( $report['managed'] ) {
				/* translators: %s: comma-separated section names */
				echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'Imported, but code on this site manages %s, so the code\'s values still win.', 'redeemid' ), implode( ', ', array_map( static fn( $s ) => $names[ $s ] ?? $s, $report['managed'] ) ) ) ) . '</p></div>';
			}
		}

		if ( is_array( $pending ) ) {
			self::render_preview( $pending, $names );
		}

		Menu::panel_start( __( 'Export', 'redeemid' ), __( 'Download levels, content rules, term rules, feature mappings and membership settings as one JSON file. Customers, item settings, Shopify credentials and tokens are never included.', 'redeemid' ) );
		echo '<div class="redeemid-panel__body">';
		Menu::action_button( self::ACTION, 'export', __( 'Download settings', 'redeemid' ), [], 'primary' );
		echo '</div>';
		Menu::panel_end();

		Menu::panel_start( __( 'Import', 'redeemid' ), __( 'Load a file exported from another site. Nothing changes until you have seen what it would change and ticked the sections to import. Each ticked section replaces this site\'s.', 'redeemid' ) );
		?>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="redeemid-panel__body">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="do" value="load">
			<?php wp_nonce_field( self::ACTION ); ?>
			<p><label><?php esc_html_e( 'File', 'redeemid' ); ?> <input type="file" name="file" accept="application/json,.json"></label></p>
			<p><label for="redeemid-transfer-json"><?php esc_html_e( 'Or paste the JSON', 'redeemid' ); ?></label><br>
				<textarea id="redeemid-transfer-json" name="json" rows="6" class="large-text code"></textarea></p>
			<?php submit_button( __( 'Load and preview', 'redeemid' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
		Menu::panel_end();
	}

	/**
	 * @param array{sections: array, from: string, at: string} $pending
	 * @param array<string, string>                             $names
	 */
	private static function render_preview( array $pending, array $names ): void {
		$diff = Transfer::diff( Transfer::current(), (array) $pending['sections'] );
		/* translators: 1: site address, 2: date */
		$from = sprintf( __( 'From %1$s, exported %2$s.', 'redeemid' ), '' !== $pending['from'] ? $pending['from'] : __( 'an unknown site', 'redeemid' ), '' !== $pending['at'] ? $pending['at'] : '?' );

		Menu::panel_start( __( 'What this file would change', 'redeemid' ), $from . ' ' . __( 'Addresses on that site are rewritten to this one.', 'redeemid' ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="do" value="import">
			<?php wp_nonce_field( self::ACTION ); ?>
			<div class="redeemid-panel__body redeemid-panel__body--flush redeemid-table-scroll">
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Import', 'redeemid' ); ?></th><th><?php esc_html_e( 'Section', 'redeemid' ); ?></th><th><?php esc_html_e( 'Added', 'redeemid' ); ?></th><th><?php esc_html_e( 'Changed', 'redeemid' ); ?></th><th><?php esc_html_e( 'Removed', 'redeemid' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $diff as $section => $change ) : ?>
						<?php $any = $change['added'] || $change['changed'] || $change['removed']; ?>
						<tr>
							<td><input type="checkbox" name="sections[]" value="<?php echo esc_attr( $section ); ?>" <?php checked( $any ); ?> aria-label="<?php echo esc_attr( $names[ $section ] ?? $section ); ?>"></td>
							<td><?php echo esc_html( $names[ $section ] ?? $section ); ?><?php echo $any ? '' : ' <span class="description">' . esc_html__( '(no change)', 'redeemid' ) . '</span>'; ?></td>
							<td><?php echo esc_html( implode( ', ', $change['added'] ) ?: '—' ); ?></td>
							<td><?php echo esc_html( implode( ', ', $change['changed'] ) ?: '—' ); ?></td>
							<td><?php echo esc_html( implode( ', ', $change['removed'] ) ?: '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="redeemid-panel__footer">
				<?php submit_button( __( 'Import ticked sections', 'redeemid' ), 'primary', 'submit', false ); ?>
			</div>
		</form>
		<div class="redeemid-panel__footer"><?php Menu::action_button( self::ACTION, 'discard', __( 'Discard the loaded file', 'redeemid' ) ); ?></div>
		<?php
		Menu::panel_end();
	}
}
