<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Access\Level;
use WP2_Shopify\Access\LevelStore;
use WP2_Shopify\Entitlement\Rule;

/**
 * Shopify → Access: the named access levels that gating rules point at.
 *
 * Each level lists its tags with a health check (tags no synced customer
 * carries, usually a tag renamed in Shopify) and a dry run of how many linked
 * customers hold it today. The Content rules tab (AccessRules) holds the
 * site default and the post type and taxonomy controls.
 */
final class Access {
	public const PAGE   = 'wp2-shopify-access';
	public const ACTION = 'wp2_shopify_access';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'action' ] );
	}

	public static function action(): void {
		Menu::guard( self::ACTION );

		$do     = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$slug   = Level::slug( sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) ) );
		$result = '';

		if ( LevelStore::managed_in_code() ) {
			$do     = '';
			$result = 'managed';
		}

		switch ( $do ) {
			case 'save_level':
				if ( '' === $slug || in_array( $slug, [ Level::PUBLIC, Level::SIGNED_IN ], true ) ) {
					$result = 'bad_slug';
					break;
				}

				LevelStore::save(
					$slug,
					[
						'label'     => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
						'groups'    => self::parse_groups( sanitize_textarea_field( wp_unslash( $_POST['groups'] ?? '' ) ) ),
						'includes'  => array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['includes'] ?? '' ) ) ) ),
						'badge'     => sanitize_hex_color( wp_unslash( $_POST['badge'] ?? '' ) ) ?? '',
						'message'   => sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ),
						'cta_label' => sanitize_text_field( wp_unslash( $_POST['cta_label'] ?? '' ) ),
						'cta_url'   => esc_url_raw( wp_unslash( $_POST['cta_url'] ?? '' ) ),
					]
				);
				LevelStore::known_tags( true );
				$result = 'saved';
				break;
			case 'delete_level':
				LevelStore::delete( $slug );
				$result = 'deleted';
				break;
			case 'refresh':
				LevelStore::known_tags( true );
				$result = 'refreshed';
				break;
		}

		wp_safe_redirect( Menu::url( self::PAGE, [ 'result' => $result ] ) );
		exit;
	}

	/**
	 * One group per line; tags separated by commas; a line starting "all:"
	 * needs every tag, otherwise any one tag passes.
	 *
	 * @return array<int, array{tags: string[], match: string}>
	 */
	public static function parse_groups( string $text ): array {
		$groups = [];

		foreach ( preg_split( '/\R/', $text ) ?: [] as $line ) {
			$line  = trim( $line );
			$match = Rule::MATCH_ANY;

			if ( preg_match( '/^(all|any)\s*:/i', $line, $m ) ) {
				$match = strtolower( $m[1] );
				$line  = trim( substr( $line, strlen( $m[0] ) ) );
			}

			$tags = array_values( array_filter( array_map( 'trim', explode( ',', $line ) ), 'strlen' ) );

			if ( $tags ) {
				$groups[] = [ 'tags' => $tags, 'match' => $match ];
			}
		}

		return $groups;
	}

	public static function format_groups( Level $level ): string {
		return implode(
			"\n",
			array_map( static fn( Rule $r ) => ( Rule::MATCH_ALL === $r->match_mode ? 'all: ' : '' ) . implode( ', ', $r->required_tags ), $level->groups )
		);
	}

	public static function render(): void {
		$tab = 'rules' === ( $_GET['tab'] ?? '' ) ? 'rules' : 'levels'; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Access', 'wp2-shopify' ); ?></h1>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Access sections', 'wp2-shopify' ); ?>">
				<a class="nav-tab <?php echo 'levels' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( Menu::url( self::PAGE ) ); ?>"><?php esc_html_e( 'Levels', 'wp2-shopify' ); ?></a>
				<a class="nav-tab <?php echo 'rules' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( Menu::url( self::PAGE, [ 'tab' => 'rules' ] ) ); ?>"><?php esc_html_e( 'Content rules', 'wp2-shopify' ); ?></a>
			</nav>
			<?php
			Menu::result_notice(
				[
					'saved'       => [ 'success', __( 'Level saved.', 'wp2-shopify' ) ],
					'deleted'     => [ 'success', __( 'Level deleted. Content that still names it now fails closed.', 'wp2-shopify' ) ],
					'refreshed'   => [ 'success', __( 'Tag list refreshed from synced customers.', 'wp2-shopify' ) ],
					'bad_slug'    => [ 'error', __( 'Give the level a slug other than public or signed-in.', 'wp2-shopify' ) ],
					'managed'     => [ 'warning', __( 'These settings are managed in code, so nothing was changed.', 'wp2-shopify' ) ],
					'rules_saved' => [ 'success', __( 'Content rules saved. Every item picks them up on its next view.', 'wp2-shopify' ) ],
				]
			);

			'rules' === $tab ? AccessRules::render() : self::render_levels();
			?>
		</div>
		<?php
	}

	private static function render_levels(): void {
		$levels  = LevelStore::levels();
		$known   = LevelStore::known_tags();
		$counts  = LevelStore::dry_run();
		$managed = LevelStore::managed_in_code();
		$edit    = $levels->get( sanitize_key( wp_unslash( $_GET['edit'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$edit    = $edit && ! $edit->builtin() ? $edit : null;
		$words   = [
			'no_condition'    => __( 'No tags, so it grants nobody.', 'wp2-shopify' ),
			'missing_tags'    => __( 'Some tags are not on any synced customer. Was a tag renamed or deleted in Shopify?', 'wp2-shopify' ),
			'unknown_include' => __( 'Includes a level that does not exist.', 'wp2-shopify' ),
		];
		?>
			<p style="max-width:760px"><?php esc_html_e( 'A level is a named condition on Shopify customer tags. Gating rules point at levels, not tags, so renaming a tag means editing one level, not every post. A level can include others: Gold including Silver means Gold members read Silver content too.', 'wp2-shopify' ); ?></p>

			<?php if ( $managed ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Managed in code: the wp2_shopify_access_levels filter sets these levels, so they are read-only here.', 'wp2-shopify' ); ?></p></div>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:1100px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Level', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Customer tags', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Includes', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Health', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Would match today', 'wp2-shopify' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $levels->all() as $slug => $level ) : ?>
					<?php
					$problems = $levels->health( $slug, $known );
					$missing  = $levels->missing_tags( $slug, $known );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $level->label ); ?></strong>
							<?php if ( $level->badge ) : ?>
								<span style="display:inline-block;padding:0 6px;border-radius:3px;background:<?php echo esc_attr( $level->badge ); ?>;color:#fff;font-size:11px"><?php echo esc_html( $level->label ); ?></span>
							<?php endif; ?>
							<br><code><?php echo esc_html( $slug ); ?></code>
						</td>
						<td>
							<?php
							if ( Level::PUBLIC === $slug ) {
								esc_html_e( 'Everyone', 'wp2-shopify' );
							} elseif ( Level::SIGNED_IN === $slug ) {
								esc_html_e( 'Any signed-in visitor', 'wp2-shopify' );
							} else {
								foreach ( $level->groups as $i => $group ) {
									echo $i ? '<br>' . esc_html__( 'or ', 'wp2-shopify' ) : '';
									echo esc_html( ( Rule::MATCH_ALL === $group->match_mode ? __( 'all of ', 'wp2-shopify' ) : __( 'any of ', 'wp2-shopify' ) ) . implode( ', ', $group->required_tags ) );
								}
							}
							?>
						</td>
						<td><?php echo esc_html( implode( ', ', array_map( static fn( $s ) => $levels->get( $s )?->label ?? $s, $level->includes ) ) ?: '—' ); ?></td>
						<td>
							<?php if ( ! $problems ) : ?>
								<?php echo Menu::dot( true ) . esc_html__( 'OK', 'wp2-shopify' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php else : ?>
								<?php foreach ( $problems as $problem ) : ?>
									<?php echo Menu::dot( false ) . esc_html( $words[ $problem ] ?? $problem ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<?php if ( 'missing_tags' === $problem ) : ?>
										<code><?php echo esc_html( implode( ', ', $missing ) ); ?></code>
									<?php endif; ?>
									<br>
								<?php endforeach; ?>
							<?php endif; ?>
						</td>
						<td><?php echo $level->builtin() ? '—' : esc_html( sprintf( /* translators: %d: number of customers */ _n( '%d customer', '%d customers', $counts[ $slug ] ?? 0, 'wp2-shopify' ), $counts[ $slug ] ?? 0 ) ); ?></td>
						<td>
							<?php if ( ! $level->builtin() && ! $managed ) : ?>
								<a class="button" href="<?php echo esc_url( Menu::url( self::PAGE, [ 'edit' => $slug ] ) . '#level-form' ); ?>"><?php esc_html_e( 'Edit', 'wp2-shopify' ); ?></a>
								<?php Menu::action_button( self::ACTION, 'delete_level', __( 'Delete', 'wp2-shopify' ), [ 'slug' => $slug ], 'delete' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p>
				<?php
				printf(
					/* translators: %d: number of distinct tags */
					esc_html__( 'Health and counts use the tags on synced customers (%d distinct tags), refreshed every 10 minutes. Counts ignore sync freshness, so they show who would match, not who is signed in now.', 'wp2-shopify' ),
					count( $known )
				);
				?>
			</p>
			<?php Menu::action_button( self::ACTION, 'refresh', __( 'Refresh tag list now', 'wp2-shopify' ) ); ?>

			<?php if ( ! $managed ) : ?>
				<h2 id="level-form"><?php echo $edit ? esc_html( sprintf( /* translators: %s: level label */ __( 'Edit %s', 'wp2-shopify' ), $edit->label ) ) : esc_html__( 'Add a level', 'wp2-shopify' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="do" value="save_level">
					<?php wp_nonce_field( self::ACTION ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wp2-level-slug"><?php esc_html_e( 'Slug', 'wp2-shopify' ); ?></label></th>
							<td>
								<input id="wp2-level-slug" name="slug" class="regular-text" required value="<?php echo esc_attr( $edit->slug ?? '' ); ?>" <?php echo $edit ? 'readonly' : ''; ?>>
								<p class="description"><?php esc_html_e( 'Rules store the slug, so it never changes after saving.', 'wp2-shopify' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wp2-level-label"><?php esc_html_e( 'Label', 'wp2-shopify' ); ?></label></th>
							<td><input id="wp2-level-label" name="label" class="regular-text" value="<?php echo esc_attr( $edit->label ?? '' ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wp2-level-groups"><?php esc_html_e( 'Customer tags', 'wp2-shopify' ); ?></label></th>
							<td>
								<textarea id="wp2-level-groups" name="groups" rows="3" class="large-text code"><?php echo esc_textarea( $edit ? self::format_groups( $edit ) : '' ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One group per line, tags separated by commas. Any one tag in a line passes; start the line with "all:" to need every tag. Any one line passing grants the level.', 'wp2-shopify' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wp2-level-includes"><?php esc_html_e( 'Includes', 'wp2-shopify' ); ?></label></th>
							<td>
								<input id="wp2-level-includes" name="includes" class="regular-text" value="<?php echo esc_attr( implode( ', ', $edit->includes ?? [] ) ); ?>">
								<p class="description"><?php esc_html_e( 'Slugs of levels this one also opens, separated by commas. Gold including silver means Gold members read Silver content.', 'wp2-shopify' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wp2-level-badge"><?php esc_html_e( 'Badge colour', 'wp2-shopify' ); ?></label></th>
							<td>
								<input id="wp2-level-badge" name="badge" class="small-text" placeholder="#c9a227" value="<?php echo esc_attr( $edit->badge ?? '' ); ?>">
								<p class="description"><?php esc_html_e( 'Badges always show the label too, never colour alone.', 'wp2-shopify' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wp2-level-message"><?php esc_html_e( 'Wall message', 'wp2-shopify' ); ?></label></th>
							<td><textarea id="wp2-level-message" name="message" rows="2" class="large-text"><?php echo esc_textarea( $edit->message ?? '' ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="wp2-level-cta-label"><?php esc_html_e( 'Call to action', 'wp2-shopify' ); ?></label></th>
							<td>
								<input id="wp2-level-cta-label" name="cta_label" class="regular-text" placeholder="<?php esc_attr_e( 'Become a Gold member', 'wp2-shopify' ); ?>" value="<?php echo esc_attr( $edit->cta_label ?? '' ); ?>">
								<input name="cta_url" class="regular-text" aria-label="<?php esc_attr_e( 'Call to action URL', 'wp2-shopify' ); ?>" placeholder="/join/gold/" value="<?php echo esc_attr( $edit->cta_url ?? '' ); ?>">
							</td>
						</tr>
					</table>
					<?php submit_button( $edit ? __( 'Save level', 'wp2-shopify' ) : __( 'Add level', 'wp2-shopify' ) ); ?>
				</form>
			<?php endif; ?>
		<?php
	}
}
