<?php
/**
 * Member Account, rendered as a Blockstudio dynamic island: the page carries
 * a neutral placeholder for everyone, and each visitor's own fragment (never
 * cached) fills in their membership from WP2_Shopify\Blocks\Account::view().
 *
 * @var array $a                Block attributes.
 * @var bool  $isEditor         Rendering inside the block editor.
 * @var bool  $isIslandFragment Rendering the visitor's own fragment.
 */

defined( 'ABSPATH' ) || exit;

use WP2_Shopify\Blocks\Account;
use WP2_Shopify\Blocks\Gate;

$wp2_title = '' !== trim( (string) ( $a['title'] ?? '' ) ) ? (string) $a['title'] : __( 'Your membership', 'wp2-shopify' );
$wp2_here  = $isIslandFragment ? wp_validate_redirect( (string) wp_get_raw_referer(), home_url( '/' ) ) : Gate::current_url();
$wp2_join  = Gate::url( $a['joinUrl'] ?? '' );
$wp2_view  = ! $isEditor && $isIslandFragment ? Account::view( get_current_user_id() ) : null;

$wp2_form = static function ( string $action, string $label, string $class = 'wp-element-button is-style-outline' ) use ( $wp2_here ): void {
	?>
	<form class="wp2-shopify-account__form" method="post" action="<?php echo esc_url( \WP2_Shopify\Auth\Flow::action_url() ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
		<?php wp_nonce_field( $action, '_wpnonce', false ); ?>
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $wp2_here ); ?>">
		<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
};
?>
<section useBlockProps class="wp2-shopify-account<?php echo $wp2_view ? ' is-' . esc_attr( $wp2_view['state'] ) : ''; ?>" aria-live="polite">
	<h2 class="wp2-shopify-account__title"><?php echo esc_html( $wp2_title ); ?></h2>

	<?php if ( $isEditor ) : ?>
		<p><?php esc_html_e( 'Each customer sees their own levels, dates, features and account links here.', 'wp2-shopify' ); ?></p>
	<?php elseif ( ! $wp2_view ) : ?>
		<p class="wp2-shopify-account__loading"><?php esc_html_e( 'Loading your membership…', 'wp2-shopify' ); ?></p>
	<?php elseif ( 'guest' === $wp2_view['state'] ) : ?>
		<p><?php esc_html_e( 'Log in with Shopify to see your membership.', 'wp2-shopify' ); ?></p>
		<p><a class="wp-element-button" href="<?php echo esc_url( \WP2_Shopify\Auth\Flow::login_url( $wp2_here ) ); ?>" rel="nofollow"><?php esc_html_e( 'Log in with Shopify', 'wp2-shopify' ); ?></a></p>
	<?php elseif ( 'not_linked' === $wp2_view['state'] ) : ?>
		<p><?php esc_html_e( 'Your account here isn\'t connected to a Shopify customer yet.', 'wp2-shopify' ); ?></p>
		<?php $wp2_form( 'wp2_shopify_connect', __( 'Connect your Shopify account', 'wp2-shopify' ), 'wp-element-button' ); ?>
	<?php elseif ( 'syncing' === $wp2_view['state'] ) : ?>
		<p><?php esc_html_e( 'Your membership details are on their way from Shopify.', 'wp2-shopify' ); ?></p>
	<?php else : ?>
		<?php if ( 'disabled' === $wp2_view['state'] ) : ?>
			<p class="wp2-shopify-account__alert"><?php esc_html_e( 'Your account is disabled. Contact the store for help.', 'wp2-shopify' ); ?></p>
		<?php endif; ?>

		<dl class="wp2-shopify-account__details">
			<dt><?php esc_html_e( 'Signed in as', 'wp2-shopify' ); ?></dt>
			<dd><?php echo esc_html( trim( $wp2_view['name'] . ( '' !== $wp2_view['email'] ? ' · ' . $wp2_view['email'] : '' ) ) ); ?></dd>

			<dt><?php esc_html_e( 'Membership', 'wp2-shopify' ); ?></dt>
			<dd>
				<?php if ( $wp2_view['levels'] ) : ?>
					<ul class="wp2-shopify-account__levels">
						<?php foreach ( $wp2_view['levels'] as $wp2_level ) : ?>
							<li>
								<strong><?php echo esc_html( $wp2_level['label'] ); ?></strong>
								<?php if ( '' !== $wp2_level['since'] ) : ?>
									<?php /* translators: %s: date */ ?>
									<span><?php echo esc_html( sprintf( __( 'since %s', 'wp2-shopify' ), Account::date( $wp2_level['since'] ) ) ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php elseif ( $wp2_view['checking_out'] ) : ?>
					<?php esc_html_e( 'Finishing setting up your membership…', 'wp2-shopify' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'No membership yet.', 'wp2-shopify' ); ?>
					<?php if ( '' !== $wp2_join ) : ?>
						<a href="<?php echo esc_url( $wp2_join ); ?>"><?php esc_html_e( 'See the plans', 'wp2-shopify' ); ?></a>
					<?php endif; ?>
				<?php endif; ?>
				<?php foreach ( $wp2_view['ended'] as $wp2_ended ) : ?>
					<?php /* translators: 1: access level label 2: date */ ?>
					<p class="wp2-shopify-account__ended"><?php echo esc_html( sprintf( __( 'Your %1$s membership ended on %2$s.', 'wp2-shopify' ), $wp2_ended['label'], Account::date( $wp2_ended['until'] ) ) ); ?></p>
				<?php endforeach; ?>
			</dd>

			<?php if ( '' !== $wp2_view['renews'] ) : ?>
				<dt><?php esc_html_e( 'Renews', 'wp2-shopify' ); ?></dt>
				<dd><?php echo esc_html( Account::date( $wp2_view['renews'] ) ); ?></dd>
			<?php endif; ?>

			<?php if ( ! empty( $a['showFeatures'] ) && $wp2_view['features'] ) : ?>
				<dt><?php esc_html_e( 'Includes', 'wp2-shopify' ); ?></dt>
				<dd><?php echo esc_html( implode( ', ', $wp2_view['features'] ) ); ?></dd>
			<?php endif; ?>

			<dt><?php esc_html_e( 'Last updated from Shopify', 'wp2-shopify' ); ?></dt>
			<dd>
				<?php
				echo esc_html(
					$wp2_view['synced']
						/* translators: %s: how long ago, such as "5 mins" */
						? sprintf( __( '%s ago', 'wp2-shopify' ), human_time_diff( $wp2_view['synced'] ) )
						: __( 'Not yet', 'wp2-shopify' )
				);
				?>
			</dd>
		</dl>

		<div class="wp2-shopify-account__actions">
			<?php $wp2_form( 'wp2_shopify_sync', __( 'Refresh membership', 'wp2-shopify' ) ); ?>
			<?php if ( '' !== $wp2_view['subscription_url'] ) : ?>
				<a class="wp-element-button is-style-outline" href="<?php echo esc_url( $wp2_view['subscription_url'] ); ?>"><?php esc_html_e( 'Manage subscription', 'wp2-shopify' ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $wp2_view['account_url'] ) : ?>
				<a class="wp-element-button is-style-outline" href="<?php echo esc_url( $wp2_view['account_url'] ); ?>"><?php esc_html_e( 'Orders and account in Shopify', 'wp2-shopify' ); ?></a>
			<?php endif; ?>
			<?php $wp2_form( 'wp2_shopify_logout', __( 'Log out', 'wp2-shopify' ), 'wp2-shopify-link-button' ); ?>
		</div>
	<?php endif; ?>
</section>
