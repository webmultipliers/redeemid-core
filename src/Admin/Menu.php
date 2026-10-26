<?php

namespace RedeemID\Admin;

use RedeemID\Config;
use RedeemID\Settings;

/**
 * The top-level RedeemID menu:
 *
 * - Overview: connection summary.
 * - Settings: the Shopify credentials (wp-config.php constants win) and the
 *   membership settings.
 * - Customers: linked customers, with resync, tag editing and Shopify admin links.
 * - Webhooks: every delivery Shopify sent, and webhook registration.
 * - App events: every call the plugin made to Shopify, plus syncs and sign-ins.
 * - Access: the named access levels gating rules point at, with a health
 *   check, and the content rules for the site, post types and taxonomies.
 * - Debug: where each config value comes from, platform checks, maintenance actions, dry run.
 *
 * Other code can add pages under the menu with
 * add_submenu_page( REDEEMID_ADMIN_MENU, ... ) on admin_menu after priority 10.
 */
final class Menu {
	public const SLUG = 'redeemid';
	public const CAP  = 'manage_options';

	public static function register(): void {
		if ( ! defined( 'REDEEMID_ADMIN_MENU' ) ) {
			define( 'REDEEMID_ADMIN_MENU', self::SLUG );
		}

		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_init', [ self::class, 'settings' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		// Runs before admin_menu, so it is added here.
		add_filter( 'set_screen_option_redeemid_per_page', static fn( $keep, $option, $value ) => max( 10, min( 500, (int) $value ) ), 10, 3 );

		SettingsPage::register();
		Customers::register();
		Webhooks::register();
		Setup::register();
		Events::register();
		Access::register();
		AccessRules::register();
		AccessFeatures::register();
		Debug::register();
	}

	public static function menu(): void { // phpcs:ignore Generic.NamingConventions.ConstructorName -- not a constructor in a namespaced class.
		add_menu_page( __( 'RedeemID', 'redeemid' ), __( 'RedeemID', 'redeemid' ), self::CAP, self::SLUG, [ Overview::class, 'render' ], 'dashicons-cart', 58 );
		add_submenu_page( self::SLUG, __( 'RedeemID overview', 'redeemid' ), __( 'Overview', 'redeemid' ), self::CAP, self::SLUG, [ Overview::class, 'render' ] );
		add_submenu_page( self::SLUG, __( 'RedeemID settings', 'redeemid' ), __( 'Settings', 'redeemid' ), self::CAP, SettingsPage::PAGE, [ SettingsPage::class, 'render' ] );

		$customers = add_submenu_page( self::SLUG, __( 'Shopify customers', 'redeemid' ), __( 'Customers', 'redeemid' ), self::CAP, Customers::PAGE, [ Customers::class, 'render' ] );
		$webhooks  = add_submenu_page( self::SLUG, __( 'Shopify webhooks', 'redeemid' ), __( 'Webhooks', 'redeemid' ), self::CAP, Webhooks::PAGE, [ Webhooks::class, 'render' ] );
		$events    = add_submenu_page( self::SLUG, __( 'Shopify app events', 'redeemid' ), __( 'App events', 'redeemid' ), self::CAP, Events::PAGE, [ Events::class, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Membership reports', 'redeemid' ), __( 'Reports', 'redeemid' ), self::CAP, Reports::PAGE, [ Reports::class, 'render' ] );
		add_submenu_page( self::SLUG, __( 'RedeemID access', 'redeemid' ), __( 'Access', 'redeemid' ), self::CAP, Access::PAGE, [ Access::class, 'render' ] );
		add_submenu_page( self::SLUG, __( 'RedeemID debug', 'redeemid' ), __( 'Debug', 'redeemid' ), self::CAP, Debug::PAGE, [ Debug::class, 'render' ] );

		if ( $customers ) {
			add_action( 'load-' . $customers, [ Customers::class, 'load' ] );
		}

		// The list tables read the per-page screen option.
		foreach ( [ $customers, $webhooks, $events ] as $hook ) {
			if ( $hook ) {
				add_action( 'load-' . $hook, static fn() => add_screen_option( 'per_page', [ 'default' => 50, 'option' => 'redeemid_per_page' ] ) );
			}
		}
	}

	/**
	 * The plugin's own menu pages. Pages other code adds under the menu are not in it.
	 *
	 * @return string[]
	 */
	public static function pages(): array {
		return [ self::SLUG, SettingsPage::PAGE, Customers::PAGE, Webhooks::PAGE, Events::PAGE, Reports::PAGE, Access::PAGE, Debug::PAGE ];
	}

	public static function enqueue(): void {
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( in_array( $page, self::pages(), true ) ) {
			wp_enqueue_style( 'redeemid-admin', REDEEMID_URL . 'assets/admin.css', [], REDEEMID_VERSION );
		}
	}

	public static function settings(): void {
		register_setting( 'redeemid', Settings::OPTION, [ 'type' => 'array', 'sanitize_callback' => [ Settings::class, 'sanitize' ] ] );
	}

	public static function url( string $page = self::SLUG, array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => $page ], $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * A notice for the result code an action redirected back with.
	 *
	 * @param array<string, array{0: string, 1: string}> $messages code => [ type, text ].
	 */
	public static function result_notice( array $messages ): void {
		$code = sanitize_key( wp_unslash( $_GET['result'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( isset( $messages[ $code ] ) ) {
			[ $type, $text ] = $messages[ $code ];
			$count           = isset( $_GET['n'] ) ? (int) $_GET['n'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( str_replace( '%d', (string) $count, $text ) ) );
		}
	}

	/**
	 * Stop an admin-post handler unless the request is an allowed, nonced one.
	 */
	public static function guard( string $nonce_action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'redeemid' ), 403 );
		}

		check_admin_referer( $nonce_action );
	}

	public static function action_button( string $action, string $do, string $label, array $fields = [], string $class = 'secondary' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="redeemid-action-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>">
			<?php foreach ( $fields as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
			<?php endforeach; ?>
			<?php wp_nonce_field( $action ); ?>
			<?php submit_button( $label, $class, 'submit', false ); ?>
		</form>
		<?php
	}

	public static function dot( bool $pass ): string {
		return $pass
			? '<span class="redeemid-dot" role="img" aria-label="' . esc_attr__( 'OK', 'redeemid' ) . '"></span>'
			: '<span class="redeemid-dot is-error" role="img" aria-label="' . esc_attr__( 'Problem', 'redeemid' ) . '"></span>';
	}

	/**
	 * A status label: a coloured pill with a word in it. Escaped HTML.
	 *
	 * @param bool|string $state true / 'ok', false / 'error', 'warn' or 'neutral'.
	 */
	public static function status( bool|string $state, string $label ): string {
		$state = is_bool( $state ) ? ( $state ? 'ok' : 'error' ) : $state;

		return '<span class="redeemid-status is-' . esc_attr( $state ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Open a Shopify admin page: the header bar, the title, any title actions
	 * (escaped HTML, usually page-title-action links) and an intro line.
	 * Close it with page_end().
	 */
	public static function page_start( string $section, string $title, string $actions = '', string $intro = '' ): void {
		$domain  = Config::store_domain();
		$missing = array_filter( Config::definitions(), static fn( $def, $name ) => $def['required'] && ! Config::has( $name ), ARRAY_FILTER_USE_BOTH );
		?>
		<div class="redeemid-topbar">
			<a class="redeemid-topbar__brand" href="<?php echo esc_url( self::url() ); ?>"><span class="dashicons dashicons-cart" aria-hidden="true"></span><?php esc_html_e( 'RedeemID', 'redeemid' ); ?></a>
			<?php if ( self::SLUG !== sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<span class="redeemid-topbar__sep" aria-hidden="true">/</span>
				<span class="redeemid-topbar__page"><?php echo esc_html( $section ); ?></span>
			<?php endif; ?>
			<span class="redeemid-topbar__store">
				<?php if ( '' !== $domain && '' !== Config::admin_url() ) : ?>
					<a href="<?php echo esc_url( Config::admin_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $domain ); ?></a>
				<?php elseif ( '' !== $domain ) : ?>
					<?php echo esc_html( $domain ); ?>
				<?php endif; ?>
				<a class="redeemid-topbar__status" href="<?php echo esc_url( self::url( SettingsPage::PAGE ) ); ?>">
					<?php echo $missing ? self::status( 'warn', __( 'Setup incomplete', 'redeemid' ) ) : self::status( 'ok', __( 'Configured', 'redeemid' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</a>
			</span>
		</div>
		<div class="wrap redeemid">
			<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
			<?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller. ?>
			<hr class="wp-header-end">
			<?php if ( '' !== $intro ) : ?>
				<p class="redeemid-intro"><?php echo esc_html( $intro ); ?></p>
			<?php endif; ?>
		<?php
	}

	public static function page_end(): void {
		echo '</div>';
	}

	/**
	 * Open a panel: a titled white box. The caller adds the body
	 * (.redeemid-panel__body) and footer, then closes it with panel_end().
	 *
	 * @param string $actions Escaped HTML shown at the right of the title.
	 */
	public static function panel_start( string $title, string $description = '', string $actions = '', string $id = '' ): void {
		?>
		<section class="redeemid-panel"<?php echo '' !== $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?>>
			<div class="redeemid-panel__header">
				<div>
					<h2><?php echo esc_html( $title ); ?></h2>
					<?php if ( '' !== $description ) : ?>
						<p><?php echo esc_html( $description ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( '' !== $actions ) : ?>
					<div class="redeemid-panel__actions"><?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller. ?></div>
				<?php endif; ?>
			</div>
		<?php
	}

	public static function panel_end(): void {
		echo '</section>';
	}

	/**
	 * Menu::action_button(), captured as a string for panel and title actions.
	 */
	public static function action_button_html( string $action, string $do, string $label, array $fields = [], string $class = 'secondary' ): string {
		ob_start();
		self::action_button( $action, $do, $label, $fields, $class );

		return (string) ob_get_clean();
	}

	public static function time( mixed $stamp ): string {
		return $stamp ? esc_html( wp_date( 'Y-m-d H:i:s', (int) $stamp ) ) : '—';
	}

	public static function ago( mixed $stamp ): string {
		return $stamp ? sprintf( '<span title="%s">%s</span>', esc_attr( wp_date( 'Y-m-d H:i:s', (int) $stamp ) ), esc_html( sprintf( /* translators: %s: human time difference */ __( '%s ago', 'redeemid' ), human_time_diff( (int) $stamp ) ) ) ) : '—';
	}
}
