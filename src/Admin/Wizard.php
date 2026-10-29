<?php

namespace RedeemID\Admin;

use RedeemID\Access\Level;
use RedeemID\Access\LevelStore;
use RedeemID\Access\Rules;
use RedeemID\Access\RuleStore;
use RedeemID\Access\TermRule;
use RedeemID\Access\TermRules;
use RedeemID\Auth\Flow;
use RedeemID\Config;
use RedeemID\Files\Probe;
use RedeemID\Shopify\Admin as AdminApi;
use RedeemID\Shopify\Storefront;
use RedeemID\Support\Logger;
use RedeemID\Webhooks\Registrar;

/**
 * RedeemID → Setup: the Overview checklist as a guided walk, one step at a
 * time, with what to paste into Shopify and a button for each thing the
 * plugin can do itself.
 *
 * Nothing about progress is stored. Each step's state comes from the site as
 * it is (Setup::facts() plus a few checks of its own), so the wizard and the
 * checklist always agree and opening it again resumes where things stand.
 *
 * It writes only what the plugin already lets staff write: connection
 * settings, a level, a content rule. It never grants a level or tags a
 * customer: Shopify's tags stay the only source of access. Locking content
 * waits until sign-in, the Admin API and webhooks all pass, so a half
 * set up site can't lock members out.
 *
 * steps(), current(), missing_scopes() and snippet() are pure.
 */
final class Wizard {
	public const PAGE     = 'redeemid-setup';
	public const ACTION   = 'redeemid_wizard';
	public const REDIRECT = 'redeemid_setup_redirect';

	/** What the Admin app needs: reconciliation and resync, tag editing, the merge webhook. */
	public const SCOPES = [ 'read_customers', 'write_customers', 'read_customer_merge' ];

	public const KEYS = [ 'site', 'sign_in', 'admin', 'storefront', 'webhooks', 'callback', 'level', 'lock', 'flow', 'try' ];

	/** Steps a site can do without. */
	private const OPTIONAL = [ 'storefront', 'lock' ];

	private const FIELDS = [
		'sign_in'    => [ 'REDEEMID_STORE_DOMAIN', 'REDEEMID_CLIENT_ID', 'REDEEMID_CLIENT_SECRET' ],
		'admin'      => [ 'REDEEMID_ADMIN_CLIENT_ID', 'REDEEMID_ADMIN_CLIENT_SECRET' ],
		'storefront' => [ 'REDEEMID_PUBLIC_ACCESS_TOKEN', 'REDEEMID_STOREFRONT_PRIVATE_TOKEN' ],
	];

	private const FLOW_DOCS = 'https://github.com/webmultipliers/redeemid-core/blob/main/docs/shopify-flow.md';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'action' ] );
		add_action( 'admin_init', [ self::class, 'maybe_redirect' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	/**
	 * Open the wizard once, right after the plugin is first activated on a
	 * site that isn't connected yet. Bulk and network activations stay put.
	 */
	public static function maybe_redirect(): void {
		if ( ! get_transient( self::REDIRECT ) ) {
			return;
		}

		delete_transient( self::REDIRECT );

		// phpcs:ignore WordPress.Security.NonceVerification -- core's own bulk-activation flag; nothing is changed.
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( Menu::CAP ) ) {
			return;
		}

		wp_safe_redirect( Menu::url( self::PAGE ) );
		exit;
	}

	public static function enqueue(): void {
		if ( self::PAGE === sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_enqueue_script( 'redeemid-wizard', REDEEMID_URL . 'assets/wizard.js', [], REDEEMID_VERSION, true );
		}
	}

	public static function url( string $step = '', array $args = [] ): string {
		return Menu::url( self::PAGE, array_merge( '' !== $step ? [ 'step' => $step ] : [], $args ) );
	}

	// ------------------------------------------------------------ actions

	public static function action(): void {
		Menu::guard( self::ACTION );
		// phpcs:disable WordPress.Security.NonceVerification -- checked by Menu::guard().

		$do   = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$step = sanitize_key( wp_unslash( $_POST['step'] ?? '' ) );
		$args = [];

		switch ( $do ) {
			case 'config':
				$saved = SettingsPage::store_posted();
				$test  = Setup::test();
				$args  = SettingsPage::result_args( $saved );

				if ( ! $saved['errors'] ) {
					$args['result'] = in_array( false, $test['results'], true ) ? 'test_failed' : 'test_ok';
				}
				break;
			case 'test':
				$test           = Setup::test();
				$args['result'] = in_array( false, $test['results'], true ) ? 'test_failed' : 'test_ok';
				break;
			case 'permalinks':
				$args['result'] = self::use_post_name_permalinks() ? 'permalinks_set' : 'permalinks_kept';
				break;
			case 'webhooks':
				$sync           = Registrar::sync();
				$args['result'] = $sync['error'] || $sync['failed'] ? 'register_failed' : 'registered';
				break;
			case 'callback':
				$args['result'] = Setup::create_callback_page() ? 'callback_created' : 'callback_failed';
				break;
			case 'level':
				$args['result'] = self::create_level(
					sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
					sanitize_text_field( wp_unslash( $_POST['tag'] ?? '' ) )
				);
				break;
			case 'lock':
				$args['result'] = self::lock(
					sanitize_key( wp_unslash( $_POST['what'] ?? '' ) ),
					sanitize_key( wp_unslash( $_POST['type'] ?? '' ) ),
					absint( $_POST['term'] ?? 0 ),
					Level::slug( sanitize_text_field( wp_unslash( $_POST['level'] ?? '' ) ) )
				);
				break;
			case 'recount':
				Activation::forget();
				Setup::forget_shop_tags();
				$args['result'] = 'recounted';
				break;
		}

		wp_safe_redirect( self::url( in_array( $step, self::KEYS, true ) ? $step : '', $args ) );
		exit;
		// phpcs:enable WordPress.Security.NonceVerification
	}

	/**
	 * Switch plain permalinks to /%postname%/. Never changes a structure the
	 * site already has.
	 */
	private static function use_post_name_permalinks(): bool {
		global $wp_rewrite;

		if ( '' !== (string) get_option( 'permalink_structure' ) || ! $wp_rewrite instanceof \WP_Rewrite ) {
			return false;
		}

		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		Flow::add_rewrite();
		flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules -- once, on a staff request, as Settings → Permalinks does.
		Logger::log( 'admin.permalinks_set', [ 'structure' => '/%postname%/' ] );

		return true;
	}

	/**
	 * A level any one of whose customers' tag $tag grants. Config only:
	 * nobody holds it until Shopify tags them.
	 */
	private static function create_level( string $label, string $tag ): string {
		$tag  = trim( $tag );
		$slug = Level::slug( '' !== $label ? $label : $tag );

		if ( LevelStore::managed_in_code() ) {
			return 'managed';
		}

		if ( '' === $tag || '' === $slug || in_array( $slug, [ Level::PUBLIC, Level::SIGNED_IN ], true ) ) {
			return 'level_invalid';
		}

		if ( null !== LevelStore::levels()->get( $slug ) ) {
			return 'level_exists';
		}

		LevelStore::save( $slug, [ 'label' => '' !== $label ? $label : $tag, 'groups' => [ [ 'tags' => [ $tag ], 'match' => 'any' ] ] ] );
		LevelStore::known_tags( true );
		Activation::forget();

		return 'level_created';
	}

	/**
	 * Lock a post type, or one category, to $level. Refused until the steps
	 * that let members in pass: locking first would shut paying members out.
	 */
	private static function lock( string $what, string $type, int $term, string $level ): string {
		if ( ! self::steps( self::facts() )['lock']['ready'] ) {
			return 'lock_not_ready';
		}

		$levels = LevelStore::levels();

		if ( null === $levels->get( $level ) || $levels->get( $level )->builtin() ) {
			return 'lock_invalid';
		}

		if ( 'term' === $what ) {
			$found = $term ? get_term( $term, 'category' ) : null;

			if ( ! $found instanceof \WP_Term ) {
				return 'lock_invalid';
			}

			TermRules::save( $found->term_id, [ 'kind' => TermRule::REQUIRES, 'levels' => array_merge( TermRules::get( $found->term_id )['levels'], [ $level ] ) ] );
			Logger::log( 'admin.wizard_lock', [ 'term' => $found->term_id, 'level' => $level ] );

			return 'locked';
		}

		if ( RuleStore::managed_in_code() ) {
			return 'managed';
		}

		if ( ! isset( AccessRules::post_types()[ $type ] ) ) {
			return 'lock_invalid';
		}

		$rules                     = RuleStore::rules()->to_array();
		$row                       = RuleStore::rules()->type( $type );
		$row['enabled']            = true;
		$row['levels']             = array_merge( $row['levels'], [ $level ] );
		$rules['types'][ $type ]   = $row;
		RuleStore::save( Rules::from_array( $rules ) );
		Logger::log( 'admin.wizard_lock', [ 'type' => $type, 'level' => $level ] );

		return 'locked';
	}

	// ------------------------------------------------------------ facts and steps

	/**
	 * Setup::facts() plus what only the wizard checks.
	 *
	 * @return array<string, mixed>
	 */
	public static function facts(): array {
		$rules  = RuleStore::rules();
		$locked = (bool) $rules->site_levels;

		foreach ( $rules->types as $row ) {
			$locked = $locked || ( $row['enabled'] && $row['levels'] );
		}

		foreach ( array_keys( TermRules::all_taxonomies() ) as $taxonomy ) {
			$locked = $locked || TermRules::in_use( $taxonomy );
		}

		$tagged = null;

		if ( AdminApi::available() ) {
			$counts = array_column( Activation::stats()['levels'], 'shopify' );
			$tagged = in_array( null, $counts, true ) && ! array_filter( $counts ) ? null : (int) array_sum( $counts );
		}

		return Setup::facts() + [
			'https'           => 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
			'cron'            => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			'files'           => Probe::check(),
			'sign_in_config'  => Config::has( 'REDEEMID_STORE_DOMAIN' ) && Config::has( 'REDEEMID_CLIENT_ID' ),
			'admin_config'    => AdminApi::available(),
			'storefront_conf' => Storefront::available(),
			'scopes'          => (string) get_option( 'redeemid_admin_token_scopes', '' ),
			'locked'          => $locked,
			'tagged'          => $tagged,
		];
	}

	/**
	 * Admin scopes the app lacks. Empty when nothing is missing, or when the
	 * granted scopes aren't known yet (no token requested). Pure.
	 *
	 * @return string[]
	 */
	public static function missing_scopes( string $granted ): array {
		if ( '' === trim( $granted ) ) {
			return [];
		}

		return array_values( array_diff( self::SCOPES, array_map( 'trim', explode( ',', $granted ) ) ) );
	}

	/**
	 * Each step: done, optional, and ready (whatever it needs first has passed). Pure.
	 *
	 * @param array<string, mixed> $facts From facts().
	 * @return array<string, array{key: string, done: bool, optional: bool, ready: bool}>
	 */
	public static function steps( array $facts ): array {
		$results = is_array( $facts['test'] ?? null ) ? (array) $facts['test']['results'] : [];
		$passed  = static fn( string $api ): bool => true === ( $results[ $api ] ?? null );
		$done    = [
			'site'       => $facts['https'] && $facts['permalinks'],
			'sign_in'    => $facts['sign_in_config'] && $passed( 'sign_in' ),
			'admin'      => $facts['admin_config'] && $passed( 'admin' ) && ! self::missing_scopes( (string) $facts['scopes'] ),
			'storefront' => $facts['storefront_conf'] && $passed( 'storefront' ),
			'webhooks'   => (bool) $facts['webhooks'],
			'callback'   => (bool) $facts['callback'],
			'level'      => $facts['levels'] > 0 && ! $facts['unknown'],
			'lock'       => (bool) $facts['locked'],
			'flow'       => $facts['tagged'] > 0 || $facts['members'] > 0,
			'try'        => $facts['members'] > 0,
		];
		$ready   = [
			'webhooks' => $done['admin'],
			'lock'     => $done['sign_in'] && $done['admin'] && $done['webhooks'] && $facts['levels'] > 0,
			'flow'     => $facts['levels'] > 0,
			'try'      => $done['site'] && $done['sign_in'] && $done['callback'] && $facts['levels'] > 0,
		];
		$out     = [];

		foreach ( self::KEYS as $key ) {
			$out[ $key ] = [
				'key'      => $key,
				'done'     => (bool) $done[ $key ],
				'optional' => in_array( $key, self::OPTIONAL, true ),
				'ready'    => $ready[ $key ] ?? true,
			];
		}

		return $out;
	}

	/**
	 * The step to show: the one asked for, else the first required step not
	 * done, else 'done'. Asking for 'done' early lands on the first gap. Pure.
	 *
	 * @param array<string, array{done: bool, optional: bool}> $steps From steps().
	 */
	public static function current( array $steps, string $asked ): string {
		if ( isset( $steps[ $asked ] ) ) {
			return $asked;
		}

		foreach ( $steps as $key => $step ) {
			if ( ! $step['done'] && ! $step['optional'] ) {
				return (string) $key;
			}
		}

		return 'done';
	}

	/**
	 * wp-config.php lines for $values, for owners who keep credentials out of
	 * the database. A secret, or a value not set yet, gets a placeholder. Pure.
	 *
	 * @param array<string, array{value: string, secret: bool}> $values Config name => current value.
	 */
	public static function snippet( array $values ): string {
		$lines = [];

		foreach ( $values as $name => $value ) {
			$text    = $value['secret'] || '' === $value['value'] ? 'paste-' . strtolower( str_replace( [ 'REDEEMID_', '_' ], [ '', '-' ], $name ) ) . '-here' : $value['value'];
			$lines[] = "define( '" . $name . "', '" . addcslashes( $text, "'\\" ) . "' );";
		}

		return implode( "\n", $lines );
	}

	// ------------------------------------------------------------ page

	/**
	 * @return array<string, string> Step key => title.
	 */
	private static function titles(): array {
		return [
			'site'       => __( 'Check this site', 'redeemid' ),
			'sign_in'    => __( 'Create the sign-in client', 'redeemid' ),
			'admin'      => __( 'Connect the Admin app', 'redeemid' ),
			'storefront' => __( 'Add Storefront tokens', 'redeemid' ),
			'webhooks'   => __( 'Register webhooks', 'redeemid' ),
			'callback'   => __( 'Publish the callback page', 'redeemid' ),
			'level'      => __( 'Create your first level', 'redeemid' ),
			'lock'       => __( 'Lock some content', 'redeemid' ),
			'flow'       => __( 'Tag members in Shopify', 'redeemid' ),
			'try'        => __( 'Try it', 'redeemid' ),
		];
	}

	public static function render(): void {
		$facts   = self::facts();
		$steps   = self::steps( $facts );
		$current = self::current( $steps, sanitize_key( wp_unslash( $_GET['step'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$titles  = self::titles();

		Menu::page_start(
			__( 'Setup', 'redeemid' ),
			__( 'Set up RedeemID', 'redeemid' ),
			sprintf( '<a class="page-title-action" href="%s">%s</a>', esc_url( Menu::url() ), esc_html__( 'Overview', 'redeemid' ) ),
			__( 'Ten short steps from a fresh install to a member reading locked content. Come back any time: each step checks the site as it is now.', 'redeemid' )
		);
		self::notices();
		?>
		<div class="redeemid-wizard">
			<nav class="redeemid-wizard__rail" aria-label="<?php esc_attr_e( 'Setup steps', 'redeemid' ); ?>">
				<ol>
					<?php foreach ( $steps as $key => $step ) : ?>
						<li class="<?php echo esc_attr( trim( ( $step['done'] ? 'is-done ' : '' ) . ( $key === $current ? 'is-current' : '' ) ) ); ?>">
							<a href="<?php echo esc_url( self::url( $key ) ); ?>"<?php echo $key === $current ? ' aria-current="step"' : ''; ?>>
								<?php echo $step['done'] ? Menu::dot( true ) : '<span class="redeemid-wizard__todo" aria-hidden="true"></span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<span><?php echo esc_html( $titles[ $key ] ); ?></span>
								<?php if ( $step['optional'] ) : ?>
									<span class="redeemid-wizard__optional"><?php esc_html_e( 'optional', 'redeemid' ); ?></span>
								<?php endif; ?>
								<?php if ( ! $step['done'] ) : ?>
									<span class="screen-reader-text"><?php esc_html_e( '(not done)', 'redeemid' ); ?></span>
								<?php endif; ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ol>
			</nav>
			<div class="redeemid-wizard__main">
				<?php
				if ( 'done' === $current ) {
					self::render_done();
				} else {
					self::render_step( $current, $steps, $facts, $titles );
				}
				?>
			</div>
		</div>
		<p class="screen-reader-text" id="redeemid-copy-status" aria-live="polite"></p>
		<?php
		Menu::page_end();
	}

	private static function notices(): void {
		Menu::result_notice(
			[
				'test_ok'          => [ 'success', __( 'Saved and tested: Shopify answered.', 'redeemid' ) ],
				'test_failed'      => [ 'error', __( 'Saved, but Shopify did not answer every test. See the step below and RedeemID → App events.', 'redeemid' ) ],
				'config_saved'     => [ 'success', __( 'Saved.', 'redeemid' ) ],
				'config_invalid'   => [ 'error', __( 'Some values were not saved. They are marked below.', 'redeemid' ) ],
				'permalinks_set'   => [ 'success', __( 'Permalinks now use the post name.', 'redeemid' ) ],
				'permalinks_kept'  => [ 'info', __( 'The site already has a permalink structure, so nothing changed.', 'redeemid' ) ],
				'registered'       => [ 'success', __( 'Webhook subscriptions are in place.', 'redeemid' ) ],
				'register_failed'  => [ 'error', __( 'Some webhook subscriptions could not be created. Details below.', 'redeemid' ) ],
				'callback_created' => [ 'success', __( 'Callback page published.', 'redeemid' ) ],
				'callback_failed'  => [ 'error', __( 'The callback page could not be created.', 'redeemid' ) ],
				'level_created'    => [ 'success', __( 'Level created.', 'redeemid' ) ],
				'level_exists'     => [ 'error', __( 'A level with that name already exists. Edit it under RedeemID → Access.', 'redeemid' ) ],
				'level_invalid'    => [ 'error', __( 'Give the level a Shopify customer tag.', 'redeemid' ) ],
				'managed'          => [ 'error', __( 'Levels or rules are set in code on this site, so they can\'t be changed here.', 'redeemid' ) ],
				'locked'           => [ 'success', __( 'Locked. Use the Try it step to check it from a member\'s side.', 'redeemid' ) ],
				'lock_invalid'     => [ 'error', __( 'Pick what to lock and a level.', 'redeemid' ) ],
				'lock_not_ready'   => [ 'error', __( 'Finish sign-in, the Admin app and webhooks first, so members aren\'t locked out.', 'redeemid' ) ],
				'recounted'        => [ 'success', __( 'Counted again from Shopify.', 'redeemid' ) ],
			]
		);
	}

	/**
	 * @param array<string, array{key: string, done: bool, optional: bool, ready: bool}> $steps
	 * @param array<string, mixed>                                                      $facts
	 * @param array<string, string>                                                     $titles
	 */
	private static function render_step( string $key, array $steps, array $facts, array $titles ): void {
		$index = (int) array_search( $key, self::KEYS, true );
		$prev  = self::KEYS[ $index - 1 ] ?? '';
		$next  = self::KEYS[ $index + 1 ] ?? 'done';
		$step  = $steps[ $key ];

		Menu::panel_start(
			/* translators: 1: step number, 2: number of steps, 3: step title */
			sprintf( __( 'Step %1$d of %2$d: %3$s', 'redeemid' ), $index + 1, count( self::KEYS ), $titles[ $key ] ),
			'',
			$step['done'] ? Menu::status( 'ok', __( 'Done', 'redeemid' ) ) : ( $step['optional'] ? Menu::status( 'neutral', __( 'Optional', 'redeemid' ) ) : Menu::status( 'warn', __( 'To do', 'redeemid' ) ) )
		);
		echo '<div class="redeemid-panel__body redeemid-wizard__body">';

		if ( ! $step['ready'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( self::not_ready( $key ) ) . '</p></div>';
		}

		match ( $key ) {
			'site'       => self::step_site( $facts ),
			'sign_in'    => self::step_sign_in( $facts ),
			'admin'      => self::step_admin( $facts ),
			'storefront' => self::step_storefront( $facts ),
			'webhooks'   => self::step_webhooks( $step ),
			'callback'   => self::step_callback( $facts ),
			'level'      => self::step_level( $facts ),
			'lock'       => self::step_lock( $step ),
			'flow'       => self::step_flow( $facts ),
			'try'        => self::step_try( $facts ),
		};

		echo '</div>';
		?>
		<div class="redeemid-panel__footer redeemid-wizard__nav">
			<?php if ( '' !== $prev ) : ?>
				<a class="button" href="<?php echo esc_url( self::url( $prev ) ); ?>"><?php esc_html_e( 'Back', 'redeemid' ); ?></a>
			<?php endif; ?>
			<a class="button <?php echo $step['done'] ? 'button-primary' : ''; ?>" href="<?php echo esc_url( self::url( $next ) ); ?>">
				<?php $step['done'] ? esc_html_e( 'Next', 'redeemid' ) : esc_html_e( 'Skip for now', 'redeemid' ); ?>
			</a>
		</div>
		<?php
		Menu::panel_end();
	}

	private static function not_ready( string $key ): string {
		return match ( $key ) {
			'webhooks' => __( 'Finish the Admin app step first: webhooks are registered through it.', 'redeemid' ),
			'lock'     => __( 'Finish sign-in, the Admin app, webhooks and a level first. Locking content before members can sign in would shut them out.', 'redeemid' ),
			'flow'     => __( 'Create a level first, so there is a tag to give members.', 'redeemid' ),
			default    => __( 'Finish the site check, sign-in, the callback page and a level first.', 'redeemid' ),
		};
	}

	/**
	 * A labelled read-only value with a copy button.
	 */
	private static function copy_field( string $id, string $label, string $value ): void {
		?>
		<div class="redeemid-copy">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			<span class="redeemid-copy__row">
				<input type="text" id="<?php echo esc_attr( $id ); ?>" class="large-text code" readonly value="<?php echo esc_attr( $value ); ?>">
				<button type="button" class="button" data-redeemid-copy="<?php echo esc_attr( $id ); ?>" data-copied="<?php esc_attr_e( 'Copied', 'redeemid' ); ?>"><?php esc_html_e( 'Copy', 'redeemid' ); ?></button>
			</span>
		</div>
		<?php
	}

	/**
	 * The step's settings fields, the same rows Settings shows, saved and tested in one go.
	 */
	private static function config_form( string $step ): void {
		$names   = self::FIELDS[ $step ];
		$invalid = SettingsPage::invalid_fields();
		$values  = [];

		foreach ( $names as $name ) {
			$def             = Config::definitions()[ $name ];
			$values[ $name ] = [ 'value' => ! empty( $def['secret'] ) ? '' : Config::get( $name ), 'secret' => ! empty( $def['secret'] ) ];
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="do" value="config">
			<input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( $names as $name ) : ?>
					<?php SettingsPage::field( $name, Config::definitions()[ $name ], $invalid[ $name ] ?? '' ); ?>
				<?php endforeach; ?>
			</table>
			<p><?php submit_button( __( 'Save and test', 'redeemid' ), 'primary', 'submit', false ); ?></p>
		</form>
		<details class="redeemid-wizard__snippet">
			<summary><?php esc_html_e( 'Rather keep these in wp-config.php?', 'redeemid' ); ?></summary>
			<p><?php esc_html_e( 'Constants keep the values out of the database and always win over what is saved here. Add these lines above "That\'s all, stop editing!" and replace the placeholders.', 'redeemid' ); ?></p>
			<textarea id="redeemid-snippet-<?php echo esc_attr( $step ); ?>" class="large-text code" rows="<?php echo count( $names ); ?>" readonly><?php echo esc_textarea( self::snippet( $values ) ); ?></textarea>
			<button type="button" class="button" data-redeemid-copy="redeemid-snippet-<?php echo esc_attr( $step ); ?>" data-copied="<?php esc_attr_e( 'Copied', 'redeemid' ); ?>"><?php esc_html_e( 'Copy', 'redeemid' ); ?></button>
		</details>
		<?php
	}

	/**
	 * The last connection test's line for $api.
	 *
	 * @param array<string, mixed> $facts
	 */
	private static function test_line( array $facts, string $api ): void {
		$test = $facts['test'];

		if ( ! is_array( $test ) || ! array_key_exists( $api, $test['results'] ) ) {
			return;
		}

		$ok = (bool) $test['results'][ $api ];
		?>
		<p class="redeemid-wizard__result">
			<?php echo Menu::status( $ok, $ok ? __( 'Shopify answered', 'redeemid' ) : __( 'Test failed', 'redeemid' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php
			/* translators: %s: time ago */
			echo wp_kses_post( sprintf( __( 'Last tested %s.', 'redeemid' ), Menu::ago( $test['at'] ) ) );

			if ( ! $ok && ! empty( $test['errors'][ $api ] ) ) {
				/* translators: %s: error code */
				echo ' ' . esc_html( sprintf( __( 'Error: %s.', 'redeemid' ), (string) $test['errors'][ $api ] ) );
			}
			?>
		</p>
		<?php
	}

	/**
	 * @param array<string, mixed> $facts
	 */
	private static function step_site( array $facts ): void {
		$checks = [
			[ $facts['https'], __( 'HTTPS', 'redeemid' ), $facts['https'] ? __( 'The site address uses https, which Shopify requires for the sign-in callback.', 'redeemid' ) : __( 'Shopify only returns sign-in to an https address. Turn on HTTPS for this site and set both addresses under Settings → General to https.', 'redeemid' ) ],
			[ $facts['permalinks'], __( 'Pretty permalinks', 'redeemid' ), $facts['permalinks'] ? __( 'On. Sign-in returns to a pretty URL.', 'redeemid' ) : __( 'Sign-in returns to /auth-callback/, which needs pretty permalinks. Shopify rejects a callback with a query string.', 'redeemid' ) ],
			[ $facts['cron'] ? true : 'warn', __( 'Scheduled tasks', 'redeemid' ), $facts['cron'] ? __( 'WP-Cron is on, so webhooks are processed and the daily check runs.', 'redeemid' ) : __( 'DISABLE_WP_CRON is set. Make sure a real cron job calls wp-cron.php every few minutes, or webhooks wait in the queue.', 'redeemid' ) ],
		];

		if ( null !== $facts['files'] ) {
			$open     = Probe::OPEN === $facts['files'];
			$checks[] = [ Probe::CLOSED === $facts['files'] ? true : 'warn', __( 'Protected files', 'redeemid' ), $open ? __( 'Files in the protected folder can be downloaded directly. Add the server rule from docs/protected-files.md.', 'redeemid' ) : __( 'The protected folder was checked with a loopback request.', 'redeemid' ) ];
		}
		?>
		<p><?php esc_html_e( 'What this site needs before Shopify can send members back to it. Red items block sign-in; amber ones are advice.', 'redeemid' ); ?></p>
		<ul class="redeemid-checks redeemid-wizard__checks">
			<?php foreach ( $checks as [ $ok, $label, $detail ] ) : ?>
				<li>
					<span class="redeemid-checks__label"><?php echo true === $ok ? Menu::dot( true ) : ( 'warn' === $ok ? Menu::status( 'warn', __( 'Advice', 'redeemid' ) ) . ' ' : Menu::dot( false ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo esc_html( $label ); ?></span>
					<span class="redeemid-checks__detail"><?php echo esc_html( $detail ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( ! $facts['permalinks'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="redeemid-action-form" data-redeemid-confirm="<?php esc_attr_e( 'This changes every URL on the site to /post-name/. Old ?p= links keep working. Continue?', 'redeemid' ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="do" value="permalinks">
				<input type="hidden" name="step" value="site">
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php submit_button( __( 'Use post name permalinks', 'redeemid' ), 'primary', 'submit', false ); ?>
			</form>
			<a class="button" href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>"><?php esc_html_e( 'Choose another structure', 'redeemid' ); ?></a>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( 'Moving from another RedeemID site?', 'redeemid' ); ?>
			<a href="<?php echo esc_url( Menu::url( Access::PAGE, [ 'tab' => 'transfer' ] ) ); ?>"><?php esc_html_e( 'Import its levels, rules and settings', 'redeemid' ); ?></a>
		</p>
		<?php
	}

	/**
	 * @param array<string, mixed> $facts
	 */
	private static function step_sign_in( array $facts ): void {
		?>
		<p><?php esc_html_e( 'Members sign in with their Shopify customer account. Shopify needs a Customer Account API client that knows this site.', 'redeemid' ); ?></p>
		<ol class="redeemid-wizard__howto">
			<li><?php esc_html_e( 'In your Shopify admin, add the Headless sales channel (free, from the Shopify App Store) and create a storefront in it.', 'redeemid' ); ?></li>
			<li><?php esc_html_e( 'Open Customer Account API settings. A public client is fine; copy its client ID.', 'redeemid' ); ?></li>
			<li><?php esc_html_e( 'Under Application setup, add these three values exactly:', 'redeemid' ); ?></li>
		</ol>
		<?php
		self::copy_field( 'redeemid-callback-uri', __( 'Callback URI', 'redeemid' ), Config::callback_url() );
		self::copy_field( 'redeemid-js-origin', __( 'JavaScript origin', 'redeemid' ), untrailingslashit( home_url() ) );
		self::copy_field( 'redeemid-logout-uri', __( 'Logout URI', 'redeemid' ), home_url( '/' ) );
		?>
		<p><?php esc_html_e( 'Then enter the store and client here. Saving tests sign-in discovery at once.', 'redeemid' ); ?></p>
		<?php
		self::config_form( 'sign_in' );
		self::test_line( $facts, 'sign_in' );
	}

	/**
	 * @param array<string, mixed> $facts
	 */
	private static function step_admin( array $facts ): void {
		$missing = self::missing_scopes( (string) $facts['scopes'] );
		?>
		<p><?php esc_html_e( 'An app on the store lets WordPress read customers\' tags, resync members, edit tags in Shopify for you and register webhooks. It never runs a scheduled bulk sync.', 'redeemid' ); ?></p>
		<ol class="redeemid-wizard__howto">
			<li><?php esc_html_e( 'In Shopify\'s Dev Dashboard, create an app and give it these Admin API scopes:', 'redeemid' ); ?> <code><?php echo esc_html( implode( ', ', self::SCOPES ) ); ?></code></li>
			<li><?php esc_html_e( 'Release a version and install the app on this store.', 'redeemid' ); ?></li>
			<li><?php esc_html_e( 'Copy the app\'s client ID and secret into the fields below.', 'redeemid' ); ?></li>
		</ol>
		<?php self::config_form( 'admin' ); ?>
		<?php self::test_line( $facts, 'admin' ); ?>
		<?php if ( $missing ) : ?>
			<div class="notice notice-error inline"><p>
				<?php
				/* translators: %s: comma-separated scope names */
				echo esc_html( sprintf( __( 'The app is missing these scopes: %s. Add them in the Dev Dashboard, release a new version, then test again.', 'redeemid' ), implode( ', ', $missing ) ) );
				?>
			</p></div>
			<?php Menu::action_button( self::ACTION, 'test', __( 'Test again', 'redeemid' ), [ 'step' => 'admin' ] ); ?>
		<?php elseif ( '' !== (string) $facts['scopes'] ) : ?>
			<p class="description">
				<?php
				/* translators: %s: comma-separated scope names */
				echo esc_html( sprintf( __( 'Granted scopes: %s.', 'redeemid' ), (string) $facts['scopes'] ) );
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array<string, mixed> $facts
	 */
	private static function step_storefront( array $facts ): void {
		?>
		<p><?php esc_html_e( 'Optional. Storefront tokens power plan cards and buy-now, with live prices and checkout. Sign-in and paywalls work without them.', 'redeemid' ); ?></p>
		<ol class="redeemid-wizard__howto">
			<li><?php esc_html_e( 'In the same Headless storefront, open Storefront API settings.', 'redeemid' ); ?></li>
			<li><?php esc_html_e( 'Copy the private access token (recommended) and, if you like, the public one.', 'redeemid' ); ?></li>
		</ol>
		<?php
		self::config_form( 'storefront' );
		self::test_line( $facts, 'storefront' );
	}

	/**
	 * @param array{ready: bool} $step
	 */
	private static function step_webhooks( array $step ): void {
		$hooks = get_option( Registrar::RESULT );
		?>
		<p><?php esc_html_e( 'Shopify tells WordPress the moment a customer\'s tags change, so access follows Shopify without any scheduled sync.', 'redeemid' ); ?></p>
		<?php if ( $step['ready'] ) : ?>
			<?php Menu::action_button( self::ACTION, 'webhooks', __( 'Register webhooks', 'redeemid' ), [ 'step' => 'webhooks' ], 'primary' ); ?>
		<?php endif; ?>
		<?php if ( is_array( $hooks ) ) : ?>
			<ul class="redeemid-checks redeemid-wizard__checks">
				<li>
					<span class="redeemid-checks__label"><?php echo Menu::dot( ! $hooks['error'] && ! $hooks['failed'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Last registration', 'redeemid' ); ?></span>
					<span class="redeemid-checks__detail">
						<?php
						echo wp_kses_post( Menu::ago( $hooks['at'] ) ) . ' · ';
						/* translators: 1: subscriptions created, 2: subscriptions already there */
						echo esc_html( sprintf( __( '%1$d created, %2$d already there.', 'redeemid' ), count( $hooks['created'] ), (int) $hooks['existing'] ) );

						if ( $hooks['error'] ) {
							echo ' ' . esc_html( (string) $hooks['error'] );
						}

						foreach ( $hooks['skipped'] as $topic => $scope ) {
							/* translators: 1: webhook topic, 2: scope */
							echo '<br>' . esc_html( sprintf( __( '%1$s skipped: the app needs the %2$s scope.', 'redeemid' ), $topic, $scope ) );
						}

						foreach ( $hooks['failed'] as $topic => $error ) {
							echo '<br>' . esc_html( $topic . ': ' . $error );
						}
						?>
					</span>
				</li>
			</ul>
		<?php endif; ?>
		<p class="description"><a href="<?php echo esc_url( Menu::url( Webhooks::PAGE ) ); ?>"><?php esc_html_e( 'Every delivery is listed under RedeemID → Webhooks.', 'redeemid' ); ?></a></p>
		<?php
	}

	/**
	 * @param array<string, mixed> $facts
	 */
	private static function step_callback( array $facts ): void {
		?>
		<p>
			<?php
			/* translators: %s: callback path */
			echo esc_html( sprintf( __( 'Sign-in results show on %s, inside your theme. Publish the page once; you can edit its text like any page.', 'redeemid' ), '/' . Config::callback_slug() . '/' ) );
			?>
		</p>
		<?php if ( $facts['callback'] ) : ?>
			<p><a class="button" href="<?php echo esc_url( Config::callback_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View the page', 'redeemid' ); ?></a></p>
		<?php else : ?>
			<?php Menu::action_button( self::ACTION, 'callback', __( 'Create the page', 'redeemid' ), [ 'step' => 'callback' ], 'primary' ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array<string, mixed> $facts
	 */
	private static function step_level( array $facts ): void {
		$levels = array_filter( LevelStore::levels()->all(), static fn( Level $level ) => ! $level->builtin() );
		$stats  = $levels && AdminApi::available() ? Activation::stats()['levels'] : [];
		$tags   = AdminApi::available() ? ( Setup::shop_tags() ?? [] ) : [];
		?>
		<p><?php esc_html_e( 'A level is a name for a Shopify customer tag. Anyone Shopify tags with it holds the level; nobody else does. Creating a level gives it to no one.', 'redeemid' ); ?></p>
		<?php if ( $levels ) : ?>
			<table class="widefat striped redeemid-wizard__table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Level', 'redeemid' ); ?></th><th scope="col"><?php esc_html_e( 'Tags', 'redeemid' ); ?></th><th scope="col" class="num"><?php esc_html_e( 'Customers tagged in Shopify', 'redeemid' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $levels as $slug => $level ) : ?>
						<?php $count = $stats[ $slug ]['shopify'] ?? null; ?>
						<tr>
							<td><?php echo esc_html( $level->label ); ?></td>
							<td><code><?php echo esc_html( implode( ', ', $level->tags() ) ); ?></code></td>
							<td class="num"><?php echo null === $count ? '—' : esc_html( number_format_i18n( (int) $count ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php if ( $facts['unknown'] ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				/* translators: %s: comma-separated tags */
				echo esc_html( sprintf( __( 'No customer in Shopify has these tags yet: %s. Check the spelling, or tag a test customer.', 'redeemid' ), implode( ', ', (array) $facts['unknown'] ) ) );
				?>
			</p></div>
		<?php endif; ?>
		<?php if ( LevelStore::managed_in_code() ) : ?>
			<p><?php esc_html_e( 'Levels are set in code on this site.', 'redeemid' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<h3><?php echo $levels ? esc_html__( 'Add another level', 'redeemid' ) : esc_html__( 'New level', 'redeemid' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="do" value="level">
			<input type="hidden" name="step" value="level">
			<?php wp_nonce_field( self::ACTION ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="redeemid-level-label"><?php esc_html_e( 'Name', 'redeemid' ); ?></label></th>
					<td><input type="text" id="redeemid-level-label" name="label" class="regular-text" placeholder="<?php esc_attr_e( 'Gold members', 'redeemid' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="redeemid-level-tag"><?php esc_html_e( 'Shopify customer tag', 'redeemid' ); ?></label></th>
					<td>
						<input type="text" id="redeemid-level-tag" name="tag" class="regular-text code" required list="redeemid-shop-tags" spellcheck="false" aria-describedby="redeemid-level-tag-help">
						<?php if ( $tags ) : ?>
							<datalist id="redeemid-shop-tags">
								<?php foreach ( $tags as $tag ) : ?>
									<option value="<?php echo esc_attr( $tag ); ?>"></option>
								<?php endforeach; ?>
							</datalist>
						<?php endif; ?>
						<p class="description" id="redeemid-level-tag-help">
							<?php
							echo $tags
								? esc_html__( 'Pick a tag your customers already have, or type a new one for Flow to add.', 'redeemid' )
								: esc_html__( 'Type the tag Flow or your subscription app will add, such as member-gold.', 'redeemid' );
							?>
						</p>
					</td>
				</tr>
			</table>
			<p><?php submit_button( __( 'Create level', 'redeemid' ), 'primary', 'submit', false ); ?></p>
		</form>
		<p class="description"><a href="<?php echo esc_url( Menu::url( Access::PAGE ) ); ?>"><?php esc_html_e( 'Several tags, levels that include others, badges and messages are under RedeemID → Access.', 'redeemid' ); ?></a></p>
		<?php
	}

	/**
	 * @param array{ready: bool, done: bool} $step
	 */
	private static function step_lock( array $step ): void {
		$levels     = array_filter( LevelStore::levels()->all(), static fn( Level $level ) => ! $level->builtin() );
		$types      = AccessRules::post_types();
		$categories = taxonomy_exists( 'category' ) ? get_terms( [ 'taxonomy' => 'category', 'hide_empty' => false, 'number' => 200 ] ) : [];
		$categories = is_array( $categories ) ? $categories : [];
		?>
		<p><?php esc_html_e( 'Optional here: you can lock single posts from the editor later. Pick a content type or a category and the level that can read it. Others see a teaser and a way to join.', 'redeemid' ); ?></p>
		<?php if ( ! $step['ready'] ) : ?>
			<?php return; ?>
		<?php endif; ?>
		<?php if ( RuleStore::managed_in_code() ) : ?>
			<p><?php esc_html_e( 'Content rules are set in code on this site.', 'redeemid' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="do" value="lock">
			<input type="hidden" name="step" value="lock">
			<?php wp_nonce_field( self::ACTION ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Lock', 'redeemid' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'What to lock', 'redeemid' ); ?></legend>
							<p>
								<label><input type="radio" name="what" value="type" checked> <?php esc_html_e( 'Every item of a content type:', 'redeemid' ); ?></label>
								<label class="screen-reader-text" for="redeemid-lock-type"><?php esc_html_e( 'Content type', 'redeemid' ); ?></label>
								<select id="redeemid-lock-type" name="type">
									<?php foreach ( $types as $name => $object ) : ?>
										<option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $object->labels->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<?php if ( $categories ) : ?>
								<p>
									<label><input type="radio" name="what" value="term"> <?php esc_html_e( 'Posts in one category:', 'redeemid' ); ?></label>
									<label class="screen-reader-text" for="redeemid-lock-term"><?php esc_html_e( 'Category', 'redeemid' ); ?></label>
									<select id="redeemid-lock-term" name="term">
										<?php foreach ( $categories as $term ) : ?>
											<option value="<?php echo (int) $term->term_id; ?>"><?php echo esc_html( $term->name ); ?></option>
										<?php endforeach; ?>
									</select>
								</p>
							<?php endif; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="redeemid-lock-level"><?php esc_html_e( 'Readable by', 'redeemid' ); ?></label></th>
					<td>
						<select id="redeemid-lock-level" name="level">
							<?php foreach ( $levels as $slug => $level ) : ?>
								<option value="<?php echo esc_attr( (string) $slug ); ?>"><?php echo esc_html( $level->label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<p><?php submit_button( __( 'Lock it', 'redeemid' ), 'primary', 'submit', false ); ?></p>
		</form>
		<p class="description"><a href="<?php echo esc_url( Menu::url( Access::PAGE, [ 'tab' => 'rules' ] ) ); ?>"><?php esc_html_e( 'Teasers, archive windows, the meter and every other rule are under RedeemID → Access → Content rules.', 'redeemid' ); ?></a></p>
		<?php
	}

	/**
	 * @param array<string, mixed> $facts
	 */
	private static function step_flow( array $facts ): void {
		?>
		<p><?php esc_html_e( 'RedeemID reads tags; it never adds them. A Shopify Flow workflow or your subscription app should add the level tag when someone buys and remove it when the membership ends.', 'redeemid' ); ?></p>
		<p><a class="button" href="<?php echo esc_url( self::FLOW_DOCS ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Flow recipes', 'redeemid' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'redeemid' ); ?></span></a></p>
		<p>
			<?php
			if ( null === $facts['tagged'] ) {
				esc_html_e( 'Shopify\'s customer counts can\'t be read until the Admin app is connected.', 'redeemid' );
			} else {
				/* translators: %d: number of customers */
				echo esc_html( sprintf( _n( '%d customer in Shopify holds a level tag.', '%d customers in Shopify hold a level tag.', (int) $facts['tagged'], 'redeemid' ), (int) $facts['tagged'] ) );
				echo ' ';
				esc_html_e( 'Tag yourself or a test customer to see it work.', 'redeemid' );
			}
			?>
		</p>
		<?php Menu::action_button( self::ACTION, 'recount', __( 'Count again', 'redeemid' ), [ 'step' => 'flow' ] ); ?>
		<?php
	}

	/**
	 * @param array<string, mixed> $facts
	 */
	private static function step_try( array $facts ): void {
		?>
		<p><?php esc_html_e( 'Check it from a member\'s side:', 'redeemid' ); ?></p>
		<ol class="redeemid-wizard__howto">
			<li><?php esc_html_e( 'Open a private window, so you aren\'t signed in to WordPress as yourself, and paste this sign-in link:', 'redeemid' ); ?></li>
		</ol>
		<?php self::copy_field( 'redeemid-try-login', __( 'Sign-in link', 'redeemid' ), Flow::login_url( home_url( '/' ) ) ); ?>
		<ol class="redeemid-wizard__howto" start="2">
			<li><?php esc_html_e( 'Sign in with a Shopify customer who has the level tag.', 'redeemid' ); ?></li>
			<li><?php esc_html_e( 'Open something you locked: it should open. Sign out, or use a customer without the tag, and you should see the teaser.', 'redeemid' ); ?></li>
		</ol>
		<p>
			<?php
			/* translators: %d: number of members */
			echo esc_html( sprintf( _n( '%d linked customer holds a level.', '%d linked customers hold a level.', (int) $facts['members'], 'redeemid' ), (int) $facts['members'] ) );
			?>
		</p>
		<p><a class="button" href="<?php echo esc_url( Menu::url( Access::PAGE, [ 'tab' => 'test' ] ) ); ?>"><?php esc_html_e( 'Explain why an item opens or not', 'redeemid' ); ?></a></p>
		<?php
	}

	private static function render_done(): void {
		Menu::panel_start( __( 'Setup is done', 'redeemid' ), __( 'Every required step passes. Optional ones can wait.', 'redeemid' ) );
		?>
		<div class="redeemid-panel__body">
			<p><?php esc_html_e( 'Members sign in with Shopify, their tags decide what they read, and webhooks keep WordPress current.', 'redeemid' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( Menu::url() ); ?>"><?php esc_html_e( 'Go to the overview', 'redeemid' ); ?></a>
				<a class="button" href="<?php echo esc_url( Menu::url( Access::PAGE ) ); ?>"><?php esc_html_e( 'Levels and rules', 'redeemid' ); ?></a>
				<a class="button" href="<?php echo esc_url( Menu::url( Reports::PAGE ) ); ?>"><?php esc_html_e( 'Reports', 'redeemid' ); ?></a>
			</p>
		</div>
		<?php
		Menu::panel_end();
	}
}
