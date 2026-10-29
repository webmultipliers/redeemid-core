<?php

namespace RedeemID\Admin;

use RedeemID\Access\Level;
use RedeemID\Access\LevelStore;
use RedeemID\Entitlement\Rule;
use RedeemID\Features\Feature;
use RedeemID\Features\Registry;

/**
 * RedeemID → Access: the named access levels that gating rules point at.
 *
 * Each level lists its tags with a health check (tags no synced customer
 * carries, usually a tag renamed in Shopify) and a dry run of how many linked
 * customers hold it today. The Features tab (AccessFeatures) maps registered
 * features to levels and tags; a level's form shows the same mapping from
 * the other side. The Content rules tab (AccessRules) holds the
 * site default and the post type and taxonomy controls, and the Test tab
 * (AccessTest) explains how one item resolves for one viewer.
 */
final class Access {
	public const PAGE   = 'redeemid-access';
	public const ACTION = 'redeemid_access';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'action' ] );
	}

	public static function action(): void {
		Menu::guard( self::ACTION );
		// phpcs:disable WordPress.Security.NonceVerification -- checked by Menu::guard().

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
						'home_url'  => esc_url_raw( wp_unslash( $_POST['home_url'] ?? '' ) ),
					]
				);
				LevelStore::known_tags( true );

				if ( isset( $_POST['features_present'] ) ) {
					// The level-side view of the one feature condition store.
					Registry::assign_level( $slug, array_map( [ Feature::class, 'slug' ], (array) wp_unslash( $_POST['features'] ?? [] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned by Feature::slug().
				}

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
		// phpcs:enable WordPress.Security.NonceVerification
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

	/**
	 * Labels of the features with a route that names this level.
	 *
	 * @return string[]
	 */
	private static function level_features( string $slug ): array {
		$out = [];

		foreach ( Registry::features() as $feature ) {
			foreach ( $feature->routes as $route ) {
				if ( in_array( $slug, $route->levels(), true ) ) {
					$out[] = $feature->label;
					break;
				}
			}
		}

		return $out;
	}

	public static function render(): void {
		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$tab = in_array( $tab, [ 'rules', 'features', 'test', 'transfer' ], true ) ? $tab : 'levels';
		$intros = [
			'levels'   => __( 'A level is a named condition on Shopify customer tags. Gating rules point at levels, not tags, so renaming a tag means editing one level, not every post. A level can include others: Gold including Silver means Gold members read Silver content too.', 'redeemid' ),
			'rules'    => __( 'The rule an item gets, from strongest to weakest: the item\'s own setting, then its terms, then its post type, then the site default. Leave every level unticked for "no rule here". Blocks inside an item can only narrow its rule.', 'redeemid' ),
			'features' => __( 'A feature is a site behaviour a customer unlocks because of what they bought in Shopify: ad-free reading, downloads, a member badge. Features are registered in code by a theme or plugin; here you choose who gets each one. Each line is one route; any route grants the feature, and it drops only when every route is lost.', 'redeemid' ),
			'transfer' => __( 'Move a set-up site\'s access settings to another site, such as staging to production, or keep a copy before a big change.', 'redeemid' ),
			'test'     => __( 'Pick an item and who is looking, or just who is looking to see their features. The trace shows every layer, the rule they resolve to, what that visitor gets, and which blocks they see. Nothing is saved, and the editor bypass is never used, so this is what that visitor would really get.', 'redeemid' ),
		];

		Menu::page_start( __( 'Access', 'redeemid' ), __( 'Access', 'redeemid' ) );
		?>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Access sections', 'redeemid' ); ?>">
				<a class="nav-tab <?php echo 'levels' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( Menu::url( self::PAGE ) ); ?>"><?php esc_html_e( 'Levels', 'redeemid' ); ?></a>
				<a class="nav-tab <?php echo 'rules' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( Menu::url( self::PAGE, [ 'tab' => 'rules' ] ) ); ?>"><?php esc_html_e( 'Content rules', 'redeemid' ); ?></a>
				<a class="nav-tab <?php echo 'features' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( Menu::url( self::PAGE, [ 'tab' => 'features' ] ) ); ?>"><?php esc_html_e( 'Features', 'redeemid' ); ?></a>
				<a class="nav-tab <?php echo 'test' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( Menu::url( self::PAGE, [ 'tab' => 'test' ] ) ); ?>"><?php esc_html_e( 'Test', 'redeemid' ); ?></a>
				<a class="nav-tab <?php echo 'transfer' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( Menu::url( self::PAGE, [ 'tab' => 'transfer' ] ) ); ?>"><?php esc_html_e( 'Export / import', 'redeemid' ); ?></a>
			</nav>
			<?php
			echo '<p class="redeemid-intro">' . esc_html( $intros[ $tab ] ) . '</p>';

			Menu::result_notice(
				[
					'saved'             => [ 'success', __( 'Level saved.', 'redeemid' ) ],
					'deleted'           => [ 'success', __( 'Level deleted. Content that still names it now fails closed.', 'redeemid' ) ],
					'refreshed'         => [ 'success', __( 'Tag list refreshed from synced customers.', 'redeemid' ) ],
					'bad_slug'          => [ 'error', __( 'Give the level a slug other than public or signed-in.', 'redeemid' ) ],
					'managed'           => [ 'warning', __( 'These settings are managed in code, so nothing was changed.', 'redeemid' ) ],
					'rules_saved'       => [ 'success', __( 'Content rules saved. Every item picks them up on its next view.', 'redeemid' ) ],
					'feature_saved'     => [ 'success', __( 'Feature mapping saved. It applies on every page from the next request.', 'redeemid' ) ],
					'feature_forgotten' => [ 'success', __( 'Stored condition removed.', 'redeemid' ) ],
				] + AccessTransfer::messages()
			);

			match ( $tab ) {
				'rules'    => AccessRules::render(),
				'features' => AccessFeatures::render(),
				'test'     => AccessTest::render(),
				'transfer' => AccessTransfer::render(),
				default    => self::render_levels(),
			};

		Menu::page_end();
	}

	private static function render_levels(): void {
		$levels  = LevelStore::levels();
		$known   = LevelStore::known_tags();
		$counts  = LevelStore::dry_run();
		$managed = LevelStore::managed_in_code();
		$edit    = $levels->get( sanitize_key( wp_unslash( $_GET['edit'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$edit    = $edit && ! $edit->builtin() ? $edit : null;
		$words   = [
			'no_condition'    => __( 'No tags, so it grants nobody.', 'redeemid' ),
			'missing_tags'    => __( 'Some tags are not on any synced customer. Was a tag renamed or deleted in Shopify?', 'redeemid' ),
			'unknown_include' => __( 'Includes a level that does not exist.', 'redeemid' ),
		];
		?>
		<?php if ( $managed ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Managed in code: the redeemid_access_levels filter sets these levels, so they are read-only here.', 'redeemid' ); ?></p></div>
		<?php endif; ?>

		<?php Menu::panel_start( __( 'Levels', 'redeemid' ), '', $managed ? '' : sprintf( '<a class="button button-primary" href="%s">%s</a>', esc_url( Menu::url( self::PAGE ) . '#level-form' ), esc_html__( 'Add a level', 'redeemid' ) ) ); ?>
			<div class="redeemid-panel__body redeemid-panel__body--flush redeemid-table-scroll">
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Level', 'redeemid' ); ?></th>
							<th><?php esc_html_e( 'Customer tags', 'redeemid' ); ?></th>
							<th><?php esc_html_e( 'Includes', 'redeemid' ); ?></th>
							<th><?php esc_html_e( 'Health', 'redeemid' ); ?></th>
							<th><?php esc_html_e( 'Would match today', 'redeemid' ); ?></th>
							<th><?php esc_html_e( 'Features', 'redeemid' ); ?></th>
							<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'redeemid' ); ?></span></th>
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
								<?php if ( $level->badge ) : ?>
									<span class="redeemid-chip redeemid-chip--solid" style="background:<?php echo esc_attr( $level->badge ); ?>"><?php echo esc_html( $level->label ); ?></span>
								<?php else : ?>
									<strong><?php echo esc_html( $level->label ); ?></strong>
								<?php endif; ?>
								<br><code class="redeemid-code-small"><?php echo esc_html( $slug ); ?></code>
							</td>
							<td>
								<?php
								if ( Level::PUBLIC === $slug ) {
									esc_html_e( 'Everyone', 'redeemid' );
								} elseif ( Level::SIGNED_IN === $slug ) {
									esc_html_e( 'Any signed-in visitor', 'redeemid' );
								} else {
									foreach ( $level->groups as $i => $group ) {
										echo $i ? '<br>' . esc_html__( 'or ', 'redeemid' ) : '';
										echo esc_html( Rule::MATCH_ALL === $group->match_mode ? __( 'all of ', 'redeemid' ) : __( 'any of ', 'redeemid' ) );
										echo implode( ' ', array_map( static fn( $tag ) => '<code class="redeemid-chip">' . esc_html( $tag ) . '</code>', $group->required_tags ) ); // phpcs:ignore WordPress.Security.EscapeOutput
									}
								}
								?>
							</td>
							<td><?php echo esc_html( implode( ', ', array_map( static fn( $s ) => $levels->get( $s )?->label ?? $s, $level->includes ) ) ?: '—' ); ?></td>
							<td>
								<?php if ( ! $problems ) : ?>
									<?php echo Menu::status( true, __( 'OK', 'redeemid' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php else : ?>
									<?php foreach ( $problems as $problem ) : ?>
										<p class="redeemid-problem">
											<?php echo Menu::dot( false ) . esc_html( $words[ $problem ] ?? $problem ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
											<?php if ( 'missing_tags' === $problem ) : ?>
												<code><?php echo esc_html( implode( ', ', $missing ) ); ?></code>
											<?php endif; ?>
										</p>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
							<td><?php echo $level->builtin() ? '—' : esc_html( sprintf( /* translators: %d: number of customers */ _n( '%d customer', '%d customers', $counts[ $slug ] ?? 0, 'redeemid' ), $counts[ $slug ] ?? 0 ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', self::level_features( $slug ) ) ?: '—' ); ?></td>
							<td class="redeemid-row-actions">
								<?php if ( ! $level->builtin() && ! $managed ) : ?>
									<a class="button button-small" href="<?php echo esc_url( Menu::url( self::PAGE, [ 'edit' => $slug ] ) . '#level-form' ); ?>"><?php esc_html_e( 'Edit', 'redeemid' ); ?></a>
									<?php Menu::action_button( self::ACTION, 'delete_level', __( 'Delete', 'redeemid' ), [ 'slug' => $slug ], 'delete small' ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="redeemid-panel__footer">
				<?php Menu::action_button( self::ACTION, 'refresh', __( 'Refresh tag list now', 'redeemid' ) ); ?>
				<span class="description">
					<?php
					printf(
						/* translators: %d: number of distinct tags */
						esc_html__( 'Health and counts use the tags on synced customers (%d distinct tags), refreshed every 10 minutes. Counts show who would match, not who is signed in now.', 'redeemid' ),
						count( $known )
					);
					?>
				</span>
			</div>
		<?php Menu::panel_end(); ?>

		<?php if ( ! $managed ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="do" value="save_level">
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php
				Menu::panel_start(
					/* translators: %s: level label */
					$edit ? sprintf( __( 'Edit %s', 'redeemid' ), $edit->label ) : __( 'Add a level', 'redeemid' ),
					__( 'Name the level, say which customer tags grant it, and what locked visitors see.', 'redeemid' ),
					$edit ? sprintf( '<a class="button" href="%s">%s</a>', esc_url( Menu::url( self::PAGE ) . '#level-form' ), esc_html__( 'Cancel', 'redeemid' ) ) : '',
					'level-form'
				);
				?>
				<div class="redeemid-panel__body redeemid-panel__body--flush">
				<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="redeemid-level-slug"><?php esc_html_e( 'Slug', 'redeemid' ); ?></label></th>
							<td>
								<input type="text" id="redeemid-level-slug" name="slug" class="regular-text" required value="<?php echo esc_attr( $edit->slug ?? '' ); ?>" <?php echo $edit ? 'readonly' : ''; ?>>
								<p class="description"><?php esc_html_e( 'Rules store the slug, so it never changes after saving.', 'redeemid' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="redeemid-level-label"><?php esc_html_e( 'Label', 'redeemid' ); ?></label></th>
							<td><input type="text" id="redeemid-level-label" name="label" class="regular-text" value="<?php echo esc_attr( $edit->label ?? '' ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="redeemid-level-groups"><?php esc_html_e( 'Customer tags', 'redeemid' ); ?></label></th>
							<td>
								<textarea id="redeemid-level-groups" name="groups" rows="3" class="large-text code"><?php echo esc_textarea( $edit ? self::format_groups( $edit ) : '' ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One group per line, tags separated by commas. Any one tag in a line passes; start the line with "all:" to need every tag. Any one line passing grants the level.', 'redeemid' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="redeemid-level-includes"><?php esc_html_e( 'Includes', 'redeemid' ); ?></label></th>
							<td>
								<input type="text" id="redeemid-level-includes" name="includes" class="regular-text" value="<?php echo esc_attr( implode( ', ', $edit->includes ?? [] ) ); ?>">
								<p class="description"><?php esc_html_e( 'Slugs of levels this one also opens, separated by commas. Gold including silver means Gold members read Silver content.', 'redeemid' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="redeemid-level-badge"><?php esc_html_e( 'Badge colour', 'redeemid' ); ?></label></th>
							<td>
								<input type="text" id="redeemid-level-badge" name="badge" class="regular-text redeemid-input-short code" placeholder="#c9a227" value="<?php echo esc_attr( $edit->badge ?? '' ); ?>">
								<p class="description"><?php esc_html_e( 'Badges always show the label too, never colour alone.', 'redeemid' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="redeemid-level-message"><?php esc_html_e( 'Wall message', 'redeemid' ); ?></label></th>
							<td><textarea id="redeemid-level-message" name="message" rows="2" class="large-text"><?php echo esc_textarea( $edit->message ?? '' ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="redeemid-level-cta-label"><?php esc_html_e( 'Call to action', 'redeemid' ); ?></label></th>
							<td>
								<input type="text" id="redeemid-level-cta-label" name="cta_label" class="regular-text" placeholder="<?php esc_attr_e( 'Become a Gold member', 'redeemid' ); ?>" value="<?php echo esc_attr( $edit->cta_label ?? '' ); ?>">
								<br><input type="text" name="cta_url" class="regular-text redeemid-stack" aria-label="<?php esc_attr_e( 'Call to action URL', 'redeemid' ); ?>" placeholder="/join/gold/" value="<?php echo esc_attr( $edit->cta_url ?? '' ); ?>">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="redeemid-level-home"><?php esc_html_e( 'Members\' home', 'redeemid' ); ?></label></th>
							<td>
								<input type="text" id="redeemid-level-home" name="home_url" class="regular-text" placeholder="/members/gold/" value="<?php echo esc_attr( $edit->home_url ?? '' ); ?>">
								<p class="description"><?php esc_html_e( 'Where members of this level land after logging in with Shopify from the home page or a plain login link. Logging in from an article returns them to it.', 'redeemid' ); ?></p>
							</td>
						</tr>
						<?php $redeemid_features = array_filter( Registry::features(), static fn( Feature $f ) => ! $f->managed ); ?>
						<?php if ( $redeemid_features ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Features', 'redeemid' ); ?></th>
								<td>
									<input type="hidden" name="features_present" value="1">
									<?php foreach ( $redeemid_features as $redeemid_slug => $redeemid_feature ) : ?>
										<label class="redeemid-block-label">
											<input type="checkbox" name="features[]" value="<?php echo esc_attr( $redeemid_slug ); ?>" <?php checked( $edit && (bool) array_filter( $redeemid_feature->routes, static fn( $r ) => $r->is_level_only( $edit->slug ) ) ); ?>>
											<?php echo esc_html( $redeemid_feature->label ); ?>
										</label>
									<?php endforeach; ?>
									<p class="description"><?php esc_html_e( 'Holders of this level get these features. The same mapping as Access → Features: a box here adds or removes the route "this level" there, and leaves other routes alone. Features managed in code are not listed.', 'redeemid' ); ?></p>
								</td>
							</tr>
						<?php endif; ?>
				</table>
				</div>
				<div class="redeemid-panel__footer">
					<?php submit_button( $edit ? __( 'Save level', 'redeemid' ) : __( 'Add level', 'redeemid' ), 'primary', 'submit', false ); ?>
				</div>
				<?php Menu::panel_end(); ?>
			</form>
			<?php endif; ?>
		<?php
	}
}
