<?php

namespace WP2_Shopify\Admin;

use WP2_Shopify\Config;
use WP2_Shopify\Settings;

/**
 * The top-level Shopify menu:
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
 * add_submenu_page( WP2_SHOPIFY_ADMIN_MENU, ... ) on admin_menu after priority 10.
 */
final class Menu {
	public const SLUG = 'wp2-shopify';
	public const CAP  = 'manage_options';

	public static function register(): void {
		if ( ! defined( 'WP2_SHOPIFY_ADMIN_MENU' ) ) {
			define( 'WP2_SHOPIFY_ADMIN_MENU', self::SLUG );
		}

		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_init', [ self::class, 'settings' ] );
		add_action( 'admin_init', [ self::class, 'redirect_old_page' ] );
		add_action( 'admin_notices', [ self::class, 'notices' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		// Runs before admin_menu, so it is added here.
		add_filter( 'set_screen_option_wp2_shopify_per_page', static fn( $keep, $option, $value ) => max( 10, min( 500, (int) $value ) ), 10, 3 );

		SettingsPage::register();
		Customers::register();
		Webhooks::register();
		Events::register();
		Access::register();
		AccessRules::register();
		AccessFeatures::register();
		Debug::register();
	}

	public static function menu(): void {
		add_menu_page( __( 'Shopify', 'wp2-shopify' ), __( 'Shopify', 'wp2-shopify' ), self::CAP, self::SLUG, [ Overview::class, 'render' ], 'dashicons-cart', 58 );
		add_submenu_page( self::SLUG, __( 'Shopify overview', 'wp2-shopify' ), __( 'Overview', 'wp2-shopify' ), self::CAP, self::SLUG, [ Overview::class, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Shopify settings', 'wp2-shopify' ), __( 'Settings', 'wp2-shopify' ), self::CAP, SettingsPage::PAGE, [ SettingsPage::class, 'render' ] );

		$customers = add_submenu_page( self::SLUG, __( 'Shopify customers', 'wp2-shopify' ), __( 'Customers', 'wp2-shopify' ), self::CAP, Customers::PAGE, [ Customers::class, 'render' ] );
		$webhooks  = add_submenu_page( self::SLUG, __( 'Shopify webhooks', 'wp2-shopify' ), __( 'Webhooks', 'wp2-shopify' ), self::CAP, Webhooks::PAGE, [ Webhooks::class, 'render' ] );
		$events    = add_submenu_page( self::SLUG, __( 'Shopify app events', 'wp2-shopify' ), __( 'App events', 'wp2-shopify' ), self::CAP, Events::PAGE, [ Events::class, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Shopify access', 'wp2-shopify' ), __( 'Access', 'wp2-shopify' ), self::CAP, Access::PAGE, [ Access::class, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Shopify debug', 'wp2-shopify' ), __( 'Debug', 'wp2-shopify' ), self::CAP, Debug::PAGE, [ Debug::class, 'render' ] );

		if ( $customers ) {
			add_action( 'load-' . $customers, [ Customers::class, 'load' ] );
		}

		// The list tables read the per-page screen option.
		foreach ( [ $customers, $webhooks, $events ] as $hook ) {
			if ( $hook ) {
				add_action( 'load-' . $hook, static fn() => add_screen_option( 'per_page', [ 'default' => 50, 'option' => 'wp2_shopify_per_page' ] ) );
			}
		}
	}

	/**
	 * The plugin's own menu pages. Pages other code adds under the menu are not in it.
	 *
	 * @return string[]
	 */
	public static function pages(): array {
		return [ self::SLUG, SettingsPage::PAGE, Customers::PAGE, Webhooks::PAGE, Events::PAGE, Access::PAGE, Debug::PAGE ];
	}

	public static function enqueue(): void {
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( in_array( $page, self::pages(), true ) ) {
			wp_enqueue_style( 'wp2-shopify-admin', WP2_SHOPIFY_URL . 'assets/admin.css', [], WP2_SHOPIFY_VERSION );
		}
	}

	public static function settings(): void {
		register_setting( 'wp2_shopify', Settings::OPTION, [ 'type' => 'array', 'sanitize_callback' => [ Settings::class, 'sanitize' ] ] );
	}

	public static function url( string $page = self::SLUG, array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => $page ], $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Settings → Shopify moved to the top-level menu; keep old bookmarks working.
	 */
	public static function redirect_old_page(): void {
		global $pagenow;

		if ( 'options-general.php' === $pagenow && self::SLUG === ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( self::url() );
			exit;
		}
	}

	public static function notices(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		if ( Config::uses_deprecated_secret_name() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'WP2 Shopify: WP2_SHOPIFY_PRIVATE_ACCESS_TOKEN is deprecated. Rename it to WP2_SHOPIFY_CLIENT_SECRET in wp-config.php.', 'wp2-shopify' ) . '</p></div>';
		}

		$retired = array_filter( array_keys( Config::retired() ), 'defined' );

		if ( $retired ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html( sprintf( /* translators: %s: constant names */ __( 'WP2 Shopify no longer reads %s from wp-config.php.', 'wp2-shopify' ), implode( ', ', $retired ) ) ),
				esc_url( self::url( Debug::PAGE ) ),
				esc_html__( 'See Debug.', 'wp2-shopify' )
			);
		}

		$upgrade = get_option( 'wp2_shopify_upgrade_notice' );

		if ( is_array( $upgrade ) ) {
			// Dismissal is stored by Debug's dismiss_upgrade action; a script-only dismiss would come back on the next page.
			printf(
				'<div class="notice notice-info"><p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of linked users */
						__( 'WP2 Shopify 2.0 upgrade: stored Shopify sessions were discarded for security, so linked customers sign in once more. Re-register your Shopify webhooks from Shopify → Webhooks (the old /iwebhooks path is gone). %d linked users were updated.', 'wp2-shopify' ),
						(int) $upgrade['backfilled']
					)
				)
			);
			self::action_button( Debug::ACTION, 'dismiss_upgrade', __( 'Dismiss', 'wp2-shopify' ) );
			echo '</div>';
		}
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
			wp_die( esc_html__( 'Not allowed.', 'wp2-shopify' ), 403 );
		}

		check_admin_referer( $nonce_action );
	}

	public static function action_button( string $action, string $do, string $label, array $fields = [], string $class = 'secondary' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wp2s-action-form">
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
			? '<span class="wp2s-dot" role="img" aria-label="' . esc_attr__( 'OK', 'wp2-shopify' ) . '"></span>'
			: '<span class="wp2s-dot is-error" role="img" aria-label="' . esc_attr__( 'Problem', 'wp2-shopify' ) . '"></span>';
	}

	/**
	 * A status label: a coloured pill with a word in it. Escaped HTML.
	 *
	 * @param bool|string $state true / 'ok', false / 'error', 'warn' or 'neutral'.
	 */
	public static function status( bool|string $state, string $label ): string {
		$state = is_bool( $state ) ? ( $state ? 'ok' : 'error' ) : $state;

		return '<span class="wp2s-status is-' . esc_attr( $state ) . '">' . esc_html( $label ) . '</span>';
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
		<div class="wp2s-topbar">
			<a class="wp2s-topbar__brand" href="<?php echo esc_url( self::url() ); ?>"><span class="dashicons dashicons-cart" aria-hidden="true"></span><?php esc_html_e( 'Shopify', 'wp2-shopify' ); ?></a>
			<?php if ( self::SLUG !== ( $_GET['page'] ?? '' ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<span class="wp2s-topbar__sep" aria-hidden="true">/</span>
				<span class="wp2s-topbar__page"><?php echo esc_html( $section ); ?></span>
			<?php endif; ?>
			<span class="wp2s-topbar__store">
				<?php if ( '' !== $domain && '' !== Config::admin_url() ) : ?>
					<a href="<?php echo esc_url( Config::admin_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $domain ); ?></a>
				<?php elseif ( '' !== $domain ) : ?>
					<?php echo esc_html( $domain ); ?>
				<?php endif; ?>
				<a class="wp2s-topbar__status" href="<?php echo esc_url( self::url( SettingsPage::PAGE ) ); ?>">
					<?php echo $missing ? self::status( 'warn', __( 'Setup incomplete', 'wp2-shopify' ) ) : self::status( 'ok', __( 'Configured', 'wp2-shopify' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</a>
			</span>
		</div>
		<div class="wrap wp2s">
			<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
			<?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller. ?>
			<hr class="wp-header-end">
			<?php if ( '' !== $intro ) : ?>
				<p class="wp2s-intro"><?php echo esc_html( $intro ); ?></p>
			<?php endif; ?>
		<?php
	}

	public static function page_end(): void {
		echo '</div>';
	}

	/**
	 * Open a panel: a titled white box. The caller adds the body
	 * (.wp2s-panel__body) and footer, then closes it with panel_end().
	 *
	 * @param string $actions Escaped HTML shown at the right of the title.
	 */
	public static function panel_start( string $title, string $description = '', string $actions = '', string $id = '' ): void {
		?>
		<section class="wp2s-panel"<?php echo '' !== $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?>>
			<div class="wp2s-panel__header">
				<div>
					<h2><?php echo esc_html( $title ); ?></h2>
					<?php if ( '' !== $description ) : ?>
						<p><?php echo esc_html( $description ); ?></p>
					<?php endif; ?>
				</div>
				<?php if ( '' !== $actions ) : ?>
					<div class="wp2s-panel__actions"><?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller. ?></div>
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
		return $stamp ? sprintf( '<span title="%s">%s</span>', esc_attr( wp_date( 'Y-m-d H:i:s', (int) $stamp ) ), esc_html( sprintf( /* translators: %s: human time difference */ __( '%s ago', 'wp2-shopify' ), human_time_diff( (int) $stamp ) ) ) ) : '—';
	}
}
