<?php

namespace WP2_Shopify\Auth;

use WP2_Shopify\Blocks\Gate;
use WP2_Shopify\Config;
use WP2_Shopify\Settings;
use WP2_Shopify\Shopify\Discovery;

/**
 * "Log in with Shopify" outside the plugin's blocks:
 *
 * - a button on wp-login.php, above the login form, so comment forms and
 *   other plugins that send visitors to the WordPress login still offer it;
 * - nav menu items (Appearance → Menus → Shopify): Log in with Shopify, which
 *   hides once signed in, and Log out, which shows only then;
 * - the [wp2_shopify_login] shortcode, for classic themes and widgets;
 * - optionally, login_url() itself pointing visitors at Shopify sign-in.
 *   Staff keep wp-login.php: wp-admin's own redirects are never changed.
 */
final class SignInLinks {
	public const LOGIN_ITEM  = '#wp2s-login';
	public const LOGOUT_ITEM = '#wp2s-logout';
	public const SHORTCODE   = 'wp2_shopify_login';

	public static function register(): void {
		add_filter( 'login_message', [ self::class, 'login_button' ] );
		add_action( 'login_enqueue_scripts', [ self::class, 'login_style' ] );
		add_filter( 'login_url', [ self::class, 'login_url' ], 20, 3 );
		add_filter( 'wp_nav_menu_objects', [ self::class, 'menu_items' ] );
		add_action( 'admin_head-nav-menus.php', [ self::class, 'menu_meta_box' ] );
		add_shortcode( self::SHORTCODE, [ self::class, 'shortcode' ] );
	}

	public static function available(): bool {
		return Config::has( 'WP2_SHOPIFY_CLIENT_ID' ) && Discovery::available();
	}

	/**
	 * Where a "come back here" sign-in returns to, from wp-login.php's own redirect_to.
	 */
	private static function login_redirect(): string {
		$to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		// Shopify customers never land in wp-admin.
		return '' !== $to && ! str_starts_with( $to, admin_url() ) ? $to : home_url( '/' );
	}

	/**
	 * The button above the login form, on the log in screen only (not lost
	 * password or registration).
	 */
	public static function login_button( mixed $message ): mixed {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification

		if ( 'login' !== $action || ! Settings::get( 'login_button' ) || ! self::available() ) {
			return $message;
		}

		return (string) $message . sprintf(
			'<div class="wp2-shopify-login"><a class="button button-primary button-large" href="%s" rel="nofollow">%s</a><p class="wp2-shopify-login__or">%s</p></div>',
			esc_url( Flow::login_url( self::login_redirect() ) ),
			esc_html__( 'Log in with Shopify', 'wp2-shopify' ),
			esc_html__( 'or with your username and password', 'wp2-shopify' )
		);
	}

	public static function login_style(): void {
		if ( Settings::get( 'login_button' ) ) {
			wp_enqueue_style( 'wp2-shopify-front', WP2_SHOPIFY_URL . 'assets/front.css', [], WP2_SHOPIFY_VERSION );
		}
	}

	/**
	 * With "Send visitors to Shopify sign-in" on, front-end login links go to
	 * Shopify. wp-admin (auth_redirect) and re-authentication keep wp-login.php.
	 */
	public static function login_url( mixed $url, mixed $redirect = '', mixed $force_reauth = false ): mixed {
		if ( ! Settings::get( 'shopify_login_links' ) || $force_reauth || is_admin() || wp_doing_ajax() || ! self::available() ) {
			return $url;
		}

		$redirect = (string) $redirect;

		return Flow::login_url( '' !== $redirect && ! str_starts_with( $redirect, admin_url() ) ? $redirect : home_url( '/' ) );
	}

	/**
	 * Turn the placeholder menu items into real links for this visitor.
	 *
	 * @param \WP_Post[] $items
	 * @return \WP_Post[]
	 */
	public static function menu_items( array $items ): array {
		$signed_in = is_user_logged_in();
		$out       = [];

		foreach ( $items as $item ) {
			$url = (string) ( $item->url ?? '' );

			if ( self::LOGIN_ITEM === $url ) {
				if ( $signed_in || ! self::available() ) {
					continue;
				}

				$item->url = Flow::login_url( Gate::current_url() );
			} elseif ( self::LOGOUT_ITEM === $url ) {
				if ( ! $signed_in ) {
					continue;
				}

				$item->url = wp_nonce_url( add_query_arg( 'action', 'wp2_shopify_logout', Flow::action_url() ), 'wp2_shopify_logout' );
			}

			$out[] = $item;
		}

		return $out;
	}

	public static function menu_meta_box(): void {
		add_meta_box( 'wp2-shopify-menu-items', __( 'Shopify', 'wp2-shopify' ), [ self::class, 'render_meta_box' ], 'nav-menus', 'side', 'low' );
	}

	public static function render_meta_box(): void {
		$items = [
			self::LOGIN_ITEM  => __( 'Log in with Shopify', 'wp2-shopify' ),
			self::LOGOUT_ITEM => __( 'Log out', 'wp2-shopify' ),
		];
		$i     = -1;
		?>
		<div id="wp2-shopify-menu-items" class="posttypediv">
			<p class="description"><?php esc_html_e( 'Log in shows to guests only and returns them to the page they were on; Log out shows to signed-in visitors only.', 'wp2-shopify' ); ?></p>
			<div class="tabs-panel tabs-panel-active">
				<ul class="categorychecklist form-no-clear">
					<?php foreach ( $items as $url => $label ) : ?>
						<li>
							<label class="menu-item-title"><input type="checkbox" class="menu-item-checkbox" name="menu-item[<?php echo (int) $i; ?>][menu-item-object-id]" value="<?php echo (int) $i; ?>"> <?php echo esc_html( $label ); ?></label>
							<input type="hidden" class="menu-item-type" name="menu-item[<?php echo (int) $i; ?>][menu-item-type]" value="custom">
							<input type="hidden" class="menu-item-title" name="menu-item[<?php echo (int) $i; ?>][menu-item-title]" value="<?php echo esc_attr( $label ); ?>">
							<input type="hidden" class="menu-item-url" name="menu-item[<?php echo (int) $i; ?>][menu-item-url]" value="<?php echo esc_attr( $url ); ?>">
						</li>
						<?php --$i; ?>
					<?php endforeach; ?>
				</ul>
			</div>
			<p class="button-controls wp-clearfix">
				<span class="add-to-menu">
					<input type="submit" class="button submit-add-to-menu right" value="<?php esc_attr_e( 'Add to Menu', 'wp2-shopify' ); ?>" name="add-wp2-shopify-menu-item" id="submit-wp2-shopify-menu-items">
					<span class="spinner"></span>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * [wp2_shopify_login show="auto|login|logout|connect" login_label="" logout_label="" redirect=""]
	 * renders the Shopify Sign-in block, so it caches and behaves the same.
	 *
	 * @param array<string, string>|string $atts
	 */
	public static function shortcode( mixed $atts ): string {
		$atts = shortcode_atts(
			[
				'show'         => 'auto',
				'login_label'  => '',
				'logout_label' => '',
				'redirect'     => '',
			],
			is_array( $atts ) ? $atts : [],
			self::SHORTCODE
		);

		$data = [
			'mode'        => in_array( $atts['show'], [ 'auto', 'login', 'logout', 'connect' ], true ) ? $atts['show'] : 'auto',
			'loginLabel'  => sanitize_text_field( $atts['login_label'] ),
			'logoutLabel' => sanitize_text_field( $atts['logout_label'] ),
		];

		if ( '' !== $atts['redirect'] ) {
			$data['redirectAfterLogin'] = esc_url_raw( $atts['redirect'] );
		}

		return function_exists( 'bs_block' ) ? (string) bs_block( [ 'id' => 'wp2-shopify/auth-action', 'data' => $data ] ) : '';
	}
}
