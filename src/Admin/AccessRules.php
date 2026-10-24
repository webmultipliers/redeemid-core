<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Access\Context;
use WP2_Shopify\Access\Index;
use WP2_Shopify\Access\Level;
use WP2_Shopify\Access\LevelStore;
use WP2_Shopify\Access\RuleStore;
use WP2_Shopify\Access\Rules;
use WP2_Shopify\Access\Teaser;
use WP2_Shopify\Access\TermRules;

/**
 * Shopify → Access → Content rules: the site default, how several term
 * rules combine, and the controls for each post type and taxonomy.
 */
final class AccessRules {
	public const ACTION = 'wp2_shopify_access_rules';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'action' ] );
	}

	public static function action(): void {
		Menu::guard( self::ACTION );

		if ( RuleStore::managed_in_code() ) {
			wp_safe_redirect( Menu::url( Access::PAGE, [ 'tab' => 'rules', 'result' => 'managed' ] ) );
			exit;
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- every value is cleaned by Rules::from_array() and the casts below.
		$post       = wp_unslash( $_POST );
		$types      = [];
		$taxonomies = [];

		foreach ( self::post_types() as $type => $object ) {
			$row            = (array) ( $post['types'][ $type ] ?? [] );
			$types[ $type ] = [
				'enabled'           => ! empty( $row['enabled'] ),
				'levels'            => array_map( 'sanitize_key', (array) ( $row['levels'] ?? [] ) ),
				'teaser'            => [ 'mode' => sanitize_key( (string) ( $row['teaser_mode'] ?? '' ) ), 'count' => (int) ( $row['teaser_count'] ?? 2 ) ],
				'public_after_days' => (int) ( $row['public_after_days'] ?? 0 ),
				'comments'          => sanitize_key( (string) ( $row['comments'] ?? '' ) ),
				'noindex'           => ! empty( $row['noindex'] ),
			];
		}

		foreach ( self::taxonomies() as $name => $object ) {
			$row                 = (array) ( $post['taxonomies'][ $name ] ?? [] );
			$taxonomies[ $name ] = [
				'enabled'  => ! empty( $row['enabled'] ),
				'inherit'  => ! empty( $row['inherit'] ),
				'priority' => (int) ( $row['priority'] ?? 0 ),
			];
		}

		RuleStore::save(
			Rules::from_array(
				[
					'site_levels' => array_map( 'sanitize_key', (array) ( $post['site_levels'] ?? [] ) ),
					'conflict'    => sanitize_key( (string) ( $post['conflict'] ?? '' ) ),
					'types'       => $types,
					'taxonomies'  => $taxonomies,
				]
			)
		);
		// phpcs:enable

		wp_safe_redirect( Menu::url( Access::PAGE, [ 'tab' => 'rules', 'result' => 'rules_saved' ] ) );
		exit;
	}

	/**
	 * Every public post type except media, switched off ones included, so they can be switched back on.
	 *
	 * @return array<string, \WP_Post_Type>
	 */
	public static function post_types(): array {
		$types = get_post_types( [ 'public' => true ], 'objects' );
		unset( $types['attachment'] );

		return $types;
	}

	/**
	 * Every taxonomy shown in the admin on those post types, switched off ones included.
	 *
	 * @return array<string, \WP_Taxonomy>
	 */
	public static function taxonomies(): array {
		$out = [];

		foreach ( array_keys( self::post_types() ) as $type ) {
			foreach ( get_object_taxonomies( $type, 'objects' ) as $name => $taxonomy ) {
				if ( 'post_format' !== $name && $taxonomy->show_ui ) {
					$out[ $name ] = $taxonomy;
				}
			}
		}

		return $out;
	}

	public static function render(): void {
		$rules    = RuleStore::rules();
		$levels   = array_filter( LevelStore::levels()->all(), static fn( Level $l ) => Level::PUBLIC !== $l->slug );
		$managed  = RuleStore::managed_in_code();
		$disabled = $managed ? 'disabled' : '';
		$modes    = [
			Teaser::MODE_BLOCKS  => __( 'First blocks', 'wp2-shopify' ),
			Teaser::MODE_WORDS   => __( 'First words', 'wp2-shopify' ),
			Teaser::MODE_EXCERPT => __( 'Excerpt only', 'wp2-shopify' ),
			Teaser::MODE_NONE    => __( 'Nothing', 'wp2-shopify' ),
		];
		$checks   = static function ( string $name, array $chosen ) use ( $levels, $disabled ): void {
			foreach ( $levels as $slug => $level ) {
				printf(
					'<label style="display:inline-block;margin-right:10px"><input type="checkbox" name="%s[]" value="%s" %s %s> %s</label>',
					esc_attr( $name ),
					esc_attr( $slug ),
					checked( in_array( $slug, $chosen, true ), true, false ),
					esc_attr( $disabled ),
					esc_html( $level->label )
				);
			}
		};
		?>
		<p style="max-width:760px"><?php esc_html_e( 'The rule an item gets, from strongest to weakest: the item\'s own setting, then its terms, then its post type, then the site default. Leave every level unticked for "no rule here". Blocks inside an item can only narrow its rule.', 'wp2-shopify' ); ?></p>

		<?php if ( $managed ) : ?>
			<div class="notice notice-info inline"><p><?php esc_html_e( 'Managed in code: the wp2_shopify_access_rules filter sets these rules, so they are read-only here.', 'wp2-shopify' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Site default', 'wp2-shopify' ); ?></th>
					<td>
						<?php $checks( 'site_levels', $rules->site_levels ); ?>
						<p class="description"><?php esc_html_e( 'Applies to items in any gated post type with no rule from their terms or post type. Usually left empty.', 'wp2-shopify' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'When terms disagree', 'wp2-shopify' ); ?></th>
					<td>
						<?php
						$conflicts = [
							Context::CONFLICT_MOST     => __( 'Most restrictive: the visitor must pass every term\'s rule (recommended)', 'wp2-shopify' ),
							Context::CONFLICT_LEAST    => __( 'Least restrictive: passing any one term\'s rule is enough', 'wp2-shopify' ),
							Context::CONFLICT_PRIORITY => __( 'Priority: the term with the highest priority decides', 'wp2-shopify' ),
						];
						foreach ( $conflicts as $value => $label ) :
							?>
							<label style="display:block"><input type="radio" name="conflict" value="<?php echo esc_attr( $value ); ?>" <?php checked( $rules->conflict, $value ); ?> <?php echo esc_attr( $disabled ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Level inclusion counts first: an item in a Silver term and a Gold term needs Gold when Gold includes Silver. An unexpired "open until" term always wins.', 'wp2-shopify' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Post types', 'wp2-shopify' ); ?></h2>
			<table class="widefat striped" style="max-width:1100px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post type', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Can be gated', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Every item needs', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Teaser for locked visitors', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Opens after (days)', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Comments on locked items', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Search engines', 'wp2-shopify' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( self::post_types() as $type => $object ) : ?>
					<?php
					$row  = $rules->type( $type );
					$base = 'types[' . $type . ']';
					?>
					<tr>
						<td><strong><?php echo esc_html( $object->labels->name ); ?></strong><br><code><?php echo esc_html( $type ); ?></code></td>
						<td><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[enabled]" value="1" aria-label="<?php esc_attr_e( 'Can be gated', 'wp2-shopify' ); ?>" <?php checked( $row['enabled'] ); ?> <?php echo esc_attr( $disabled ); ?>></td>
						<td><?php $checks( $base . '[levels]', $row['levels'] ); ?></td>
						<td>
							<select name="<?php echo esc_attr( $base ); ?>[teaser_mode]" aria-label="<?php esc_attr_e( 'Teaser', 'wp2-shopify' ); ?>" <?php echo esc_attr( $disabled ); ?>>
								<?php foreach ( $modes as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row['teaser']['mode'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<input type="number" min="0" max="1000" class="small-text" name="<?php echo esc_attr( $base ); ?>[teaser_count]" aria-label="<?php esc_attr_e( 'How many blocks or words', 'wp2-shopify' ); ?>" value="<?php echo esc_attr( (string) $row['teaser']['count'] ); ?>" <?php echo esc_attr( $disabled ); ?>>
						</td>
						<td>
							<input type="number" min="0" max="36500" class="small-text" name="<?php echo esc_attr( $base ); ?>[public_after_days]" aria-label="<?php esc_attr_e( 'Opens after days', 'wp2-shopify' ); ?>" value="<?php echo esc_attr( (string) $row['public_after_days'] ); ?>" <?php echo esc_attr( $disabled ); ?>>
						</td>
						<td>
							<select name="<?php echo esc_attr( $base ); ?>[comments]" aria-label="<?php esc_attr_e( 'Comments on locked items', 'wp2-shopify' ); ?>" <?php echo esc_attr( $disabled ); ?>>
								<option value="<?php echo esc_attr( Rules::COMMENTS_MEMBERS ); ?>" <?php selected( $row['comments'], Rules::COMMENTS_MEMBERS ); ?>><?php esc_html_e( 'Readers only', 'wp2-shopify' ); ?></option>
								<option value="<?php echo esc_attr( Rules::COMMENTS_EVERYONE ); ?>" <?php selected( $row['comments'], Rules::COMMENTS_EVERYONE ); ?>><?php esc_html_e( 'Everyone', 'wp2-shopify' ); ?></option>
							</select>
						</td>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[noindex]" value="1" <?php checked( $row['noindex'] ); ?> <?php echo esc_attr( $disabled ); ?>> <?php esc_html_e( 'Keep locked items out', 'wp2-shopify' ); ?></label>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description" style="max-width:1100px"><?php esc_html_e( 'A paywall break block in an item always sets its teaser. "Opens after" frees items that many days after they were published (0 for never), checked on every request. "Readers only" hides comments, the comment count and the form from visitors who cannot read the item. "Keep locked items out" adds noindex and removes them from the WordPress, Yoast and Rank Math sitemaps together; otherwise locked items stay listed and indexed by their teaser.', 'wp2-shopify' ); ?></p>

			<h2><?php esc_html_e( 'Taxonomies', 'wp2-shopify' ); ?></h2>
			<table class="widefat striped" style="max-width:1100px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Taxonomy', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Term rules count', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Child terms inherit', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Priority', 'wp2-shopify' ); ?></th>
						<th><?php esc_html_e( 'Terms with a rule', 'wp2-shopify' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( self::taxonomies() as $name => $object ) : ?>
					<?php
					$row  = $rules->taxonomy( $name, $object->hierarchical );
					$base = 'taxonomies[' . $name . ']';
					$used = self::terms_with_rules( $name );
					?>
					<tr>
						<td><strong><?php echo esc_html( $object->labels->name ); ?></strong><br><code><?php echo esc_html( $name ); ?></code></td>
						<td><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[enabled]" value="1" aria-label="<?php esc_attr_e( 'Term rules count', 'wp2-shopify' ); ?>" <?php checked( $row['enabled'] ); ?> <?php echo esc_attr( $disabled ); ?>></td>
						<td>
							<?php if ( $object->hierarchical ) : ?>
								<input type="checkbox" name="<?php echo esc_attr( $base ); ?>[inherit]" value="1" aria-label="<?php esc_attr_e( 'Child terms inherit', 'wp2-shopify' ); ?>" <?php checked( $row['inherit'] ); ?> <?php echo esc_attr( $disabled ); ?>>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><input type="number" min="-100" max="100" class="small-text" name="<?php echo esc_attr( $base ); ?>[priority]" aria-label="<?php esc_attr_e( 'Priority', 'wp2-shopify' ); ?>" value="<?php echo esc_attr( (string) $row['priority'] ); ?>" <?php echo esc_attr( $disabled ); ?>></td>
						<td>
							<?php echo esc_html( (string) $used ); ?>
							<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . $name ) ); ?>"><?php esc_html_e( 'Edit terms', 'wp2-shopify' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description" style="max-width:1100px"><?php esc_html_e( 'Rules are set on each term\'s edit screen. In the priority mode the taxonomy priority counts first, then the term\'s own.', 'wp2-shopify' ); ?></p>

			<p class="description" style="max-width:1100px">
				<?php
				echo esc_html(
					Index::pending()
						? __( 'Search index: updating in the background after a rule change. Until it finishes, search still checks every result against public text.', 'wp2-shopify' )
						: __( 'Search index: up to date. Search matches locked items on their public text only.', 'wp2-shopify' )
				);
				?>
			</p>

			<?php if ( ! $managed ) : ?>
				<?php submit_button( __( 'Save content rules', 'wp2-shopify' ) ); ?>
			<?php endif; ?>
		</form>
		<?php
	}

	private static function terms_with_rules( string $taxonomy ): int {
		$count = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'count',
				'meta_key'   => TermRules::META, // phpcs:ignore WordPress.DB.SlowDBQuery
			]
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}
}
