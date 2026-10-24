<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Access\Level;
use WP2_Shopify\Access\LevelStore;
use WP2_Shopify\Access\TermRule;
use WP2_Shopify\Access\TermRules;

/**
 * The Access fields on a term's add and edit screens, and an Access column
 * in the term list, for every taxonomy whose term rules count.
 *
 * An "open until" rule without a future end date is refused, not saved: the
 * previous rule stays and the editor is told why.
 */
final class AccessTermFields {
	private const NONCE  = 'wp2_shopify_access_term';
	private const NOTICE = 'wp2_shopify_term_rule_problem_';

	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'hook_taxonomies' ] );
		add_action( 'admin_notices', [ self::class, 'notice' ] );
	}

	public static function hook_taxonomies(): void {
		foreach ( array_keys( TermRules::all_taxonomies() ) as $taxonomy ) {
			add_action( $taxonomy . '_add_form_fields', [ self::class, 'add_fields' ] );
			add_action( $taxonomy . '_edit_form_fields', [ self::class, 'edit_fields' ] );
			add_action( 'created_' . $taxonomy, [ self::class, 'save' ] );
			add_action( 'edited_' . $taxonomy, [ self::class, 'save' ] );
			add_filter( 'manage_edit-' . $taxonomy . '_columns', [ self::class, 'columns' ] );
			add_filter( 'manage_' . $taxonomy . '_custom_column', [ self::class, 'column' ], 10, 3 );
		}
	}

	public static function add_fields(): void {
		echo '<div class="form-field">';
		self::fields( TermRules::sanitize( [] ) );
		echo '</div>';
	}

	public static function edit_fields( \WP_Term $term ): void {
		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Access', 'wp2-shopify' ) . '</th><td>';
		self::fields( TermRules::get( $term->term_id ) );
		echo '</td></tr>';
	}

	private static function fields( array $rule ): void {
		$levels = array_filter( LevelStore::levels()->all(), static fn( Level $l ) => Level::PUBLIC !== $l->slug );
		$until  = $rule['opens_until'] ? wp_date( 'Y-m-d\TH:i', $rule['opens_until'] ) : '';

		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<fieldset>
			<legend class="screen-reader-text"><?php esc_html_e( 'Access', 'wp2-shopify' ); ?></legend>
			<label style="display:block"><input type="radio" name="wp2_access[kind]" value="" <?php checked( $rule['kind'], TermRules::NONE ); ?>> <?php esc_html_e( 'No rule of its own (a child term takes its parent\'s when the taxonomy inherits)', 'wp2-shopify' ); ?></label>
			<label style="display:block"><input type="radio" name="wp2_access[kind]" value="<?php echo esc_attr( TermRule::REQUIRES ); ?>" <?php checked( $rule['kind'], TermRule::REQUIRES ); ?>> <?php esc_html_e( 'Items here need one of these levels:', 'wp2-shopify' ); ?></label>
			<p style="margin:4px 0 8px 24px">
				<?php foreach ( $levels as $slug => $level ) : ?>
					<label style="display:inline-block;margin-right:10px"><input type="checkbox" name="wp2_access[levels][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $rule['levels'], true ) ); ?>> <?php echo esc_html( $level->label ); ?></label>
				<?php endforeach; ?>
			</p>
			<label style="display:block"><input type="radio" name="wp2_access[kind]" value="<?php echo esc_attr( TermRule::OPENS ); ?>" <?php checked( $rule['kind'], TermRule::OPENS ); ?>> <?php esc_html_e( 'Items here are free to everyone until:', 'wp2-shopify' ); ?></label>
			<p style="margin:4px 0 8px 24px">
				<input type="datetime-local" name="wp2_access[opens_until]" value="<?php echo esc_attr( $until ); ?>" aria-label="<?php esc_attr_e( 'Free until', 'wp2-shopify' ); ?>" style="width:auto">
				<br><span class="description" style="color:#b32d2e"><?php esc_html_e( 'This opens every item in this term to everyone, including items another term or the post type would lock. An end date is required, and the items lock again on it by themselves.', 'wp2-shopify' ); ?></span>
			</p>
			<label><?php esc_html_e( 'Priority', 'wp2-shopify' ); ?> <input type="number" min="-100" max="100" class="small-text" name="wp2_access[priority]" value="<?php echo esc_attr( (string) $rule['priority'] ); ?>" style="width:auto"></label>
			<p class="description"><?php esc_html_e( 'Used only when the site resolves term conflicts by priority. Child terms with no rule of their own take this one when the taxonomy inherits. Site time zone.', 'wp2-shopify' ); ?></p>
		</fieldset>
		<?php
	}

	public static function save( int $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		$raw   = isset( $_POST['wp2_access'] ) && is_array( $_POST['wp2_access'] ) ? wp_unslash( $_POST['wp2_access'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in TermRules::sanitize().
		$until = trim( (string) ( $raw['opens_until'] ?? '' ) );
		$date  = '' !== $until ? date_create_immutable( $until, wp_timezone() ) : false;
		$rule  = [
			'kind'        => sanitize_key( (string) ( $raw['kind'] ?? '' ) ),
			'levels'      => array_map( 'sanitize_key', (array) ( $raw['levels'] ?? [] ) ),
			'opens_until' => $date ? $date->getTimestamp() : null,
			'priority'    => (int) ( $raw['priority'] ?? 0 ),
		];

		$problem = TermRules::problem( $rule, time() );

		if ( null !== $problem ) {
			// Keep the previous rule; the next admin page says why.
			set_transient( self::NOTICE . get_current_user_id(), $problem, MINUTE_IN_SECONDS );
			return;
		}

		TermRules::save( $term_id, $rule );
	}

	public static function notice(): void {
		$key     = self::NOTICE . get_current_user_id();
		$problem = get_transient( $key );

		if ( ! is_string( $problem ) ) {
			return;
		}

		delete_transient( $key );
		$words = [
			'requires_needs_level' => __( 'The term\'s access rule was not saved: tick at least one level.', 'wp2-shopify' ),
			'opens_needs_expiry'   => __( 'The term\'s access rule was not saved: "free until" needs an end date.', 'wp2-shopify' ),
			'opens_expiry_past'    => __( 'The term\'s access rule was not saved: the "free until" date has already passed.', 'wp2-shopify' ),
		];

		printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $words[ $problem ] ?? $problem ) );
	}

	public static function columns( array $columns ): array {
		$columns['wp2_access'] = __( 'Access', 'wp2-shopify' );

		return $columns;
	}

	public static function column( mixed $out, mixed $column, mixed $term_id ): mixed {
		if ( 'wp2_access' !== $column ) {
			return $out;
		}

		return esc_html( self::describe( TermRules::get( (int) $term_id ) ) );
	}

	public static function describe( array $rule ): string {
		$levels = LevelStore::levels();

		return match ( $rule['kind'] ) {
			TermRule::REQUIRES => implode( ' or ', array_map( static fn( $s ) => $levels->get( $s )?->label ?? $s, $rule['levels'] ) ),
			TermRule::OPENS => $rule['opens_until'] > time()
				/* translators: %s: date */
				? sprintf( __( 'Free until %s', 'wp2-shopify' ), wp_date( get_option( 'date_format' ), $rule['opens_until'] ) )
				: __( 'Free window ended', 'wp2-shopify' ),
			default => '—',
		};
	}
}
