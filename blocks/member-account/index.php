<?php
/**
 * Member Account, rendered as a Blockstudio dynamic island: the page carries
 * a neutral placeholder for everyone, and each visitor's own fragment (never
 * cached) fills in their membership from RedeemID\Blocks\Account::view().
 *
 * @var array $a                Block attributes.
 * @var bool  $isEditor         Rendering inside the block editor.
 * @var bool  $isIslandFragment Rendering the visitor's own fragment.
 */

defined( 'ABSPATH' ) || exit;

use RedeemID\Blocks\Account;
use RedeemID\Blocks\Gate;

$redeemid_title = '' !== trim( (string) ( $a['title'] ?? '' ) ) ? (string) $a['title'] : __( 'Your membership', 'redeemid' );
$redeemid_here  = $isIslandFragment ? wp_validate_redirect( (string) wp_get_raw_referer(), home_url( '/' ) ) : Gate::current_url();
$redeemid_join  = Gate::url( $a['joinUrl'] ?? '' );
$redeemid_view  = ! $isEditor && $isIslandFragment ? Account::view( get_current_user_id() ) : null;

$redeemid_form = static function ( string $action, string $label, string $class = 'wp-element-button is-style-outline' ) use ( $redeemid_here ): void {
	?>
	<form class="redeemid-account__form" method="post" action="<?php echo esc_url( \RedeemID\Auth\Flow::action_url() ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
		<?php wp_nonce_field( $action, '_wpnonce', false ); ?>
		<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redeemid_here ); ?>">
		<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
	</form>
	<?php
};
?>
<section useBlockProps class="redeemid-account<?php echo $redeemid_view ? ' is-' . esc_attr( $redeemid_view['state'] ) : ''; ?>" aria-live="polite">
	<h2 class="redeemid-account__title"><?php echo esc_html( $redeemid_title ); ?></h2>

	<?php if ( $isEditor ) : ?>
		<p><?php esc_html_e( 'Each customer sees their own levels, dates, features and account links here.', 'redeemid' ); ?></p>
	<?php elseif ( ! $redeemid_view ) : ?>
		<p class="redeemid-account__loading"><?php esc_html_e( 'Loading your membership…', 'redeemid' ); ?></p>
	<?php elseif ( 'guest' === $redeemid_view['state'] ) : ?>
		<p><?php esc_html_e( 'Log in with Shopify to see your membership.', 'redeemid' ); ?></p>
		<p><a class="wp-element-button" href="<?php echo esc_url( \RedeemID\Auth\Flow::login_url( $redeemid_here ) ); ?>" rel="nofollow"><?php esc_html_e( 'Log in with Shopify', 'redeemid' ); ?></a></p>
	<?php elseif ( 'not_linked' === $redeemid_view['state'] ) : ?>
		<p><?php esc_html_e( 'Your account here isn\'t connected to a Shopify customer yet.', 'redeemid' ); ?></p>
		<?php $redeemid_form( 'redeemid_connect', __( 'Connect your Shopify account', 'redeemid' ), 'wp-element-button' ); ?>
	<?php elseif ( 'syncing' === $redeemid_view['state'] ) : ?>
		<p><?php esc_html_e( 'Your membership details are on their way from Shopify.', 'redeemid' ); ?></p>
	<?php else : ?>
		<?php if ( 'disabled' === $redeemid_view['state'] ) : ?>
			<p class="redeemid-account__alert"><?php esc_html_e( 'Your account is disabled. Contact the store for help.', 'redeemid' ); ?></p>
		<?php endif; ?>

		<dl class="redeemid-account__details">
			<dt><?php esc_html_e( 'Signed in as', 'redeemid' ); ?></dt>
			<dd><?php echo esc_html( trim( $redeemid_view['name'] . ( '' !== $redeemid_view['email'] ? ' · ' . $redeemid_view['email'] : '' ) ) ); ?></dd>

			<dt><?php esc_html_e( 'Membership', 'redeemid' ); ?></dt>
			<dd>
				<?php if ( $redeemid_view['levels'] ) : ?>
					<ul class="redeemid-account__levels">
						<?php foreach ( $redeemid_view['levels'] as $redeemid_level ) : ?>
							<li>
								<strong><?php echo esc_html( $redeemid_level['label'] ); ?></strong>
								<?php if ( '' !== $redeemid_level['since'] ) : ?>
									<?php /* translators: %s: date */ ?>
									<span><?php echo esc_html( sprintf( __( 'since %s', 'redeemid' ), Account::date( $redeemid_level['since'] ) ) ); ?></span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php elseif ( $redeemid_view['checking_out'] ) : ?>
					<?php esc_html_e( 'Finishing setting up your membership…', 'redeemid' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'No membership yet.', 'redeemid' ); ?>
					<?php if ( '' !== $redeemid_join ) : ?>
						<a href="<?php echo esc_url( $redeemid_join ); ?>"><?php esc_html_e( 'See the plans', 'redeemid' ); ?></a>
					<?php endif; ?>
				<?php endif; ?>
				<?php foreach ( $redeemid_view['ended'] as $redeemid_ended ) : ?>
					<?php /* translators: 1: access level label 2: date */ ?>
					<p class="redeemid-account__ended"><?php echo esc_html( sprintf( __( 'Your %1$s membership ended on %2$s.', 'redeemid' ), $redeemid_ended['label'], Account::date( $redeemid_ended['until'] ) ) ); ?></p>
				<?php endforeach; ?>
			</dd>

			<?php if ( '' !== $redeemid_view['renews'] ) : ?>
				<dt><?php esc_html_e( 'Renews', 'redeemid' ); ?></dt>
				<dd><?php echo esc_html( Account::date( $redeemid_view['renews'] ) ); ?></dd>
			<?php endif; ?>

			<?php if ( ! empty( $a['showFeatures'] ) && $redeemid_view['features'] ) : ?>
				<dt><?php esc_html_e( 'Includes', 'redeemid' ); ?></dt>
				<dd><?php echo esc_html( implode( ', ', $redeemid_view['features'] ) ); ?></dd>
			<?php endif; ?>

			<dt><?php esc_html_e( 'Last updated from Shopify', 'redeemid' ); ?></dt>
			<dd>
				<?php
				echo esc_html(
					$redeemid_view['synced']
						/* translators: %s: human time difference */
						? sprintf( __( '%s ago', 'redeemid' ), human_time_diff( $redeemid_view['synced'] ) )
						: __( 'Not yet', 'redeemid' )
				);
				?>
			</dd>
		</dl>

		<div class="redeemid-account__actions">
			<?php $redeemid_form( 'redeemid_sync', __( 'Refresh membership', 'redeemid' ) ); ?>
			<?php if ( '' !== $redeemid_view['subscription_url'] ) : ?>
				<a class="wp-element-button is-style-outline" href="<?php echo esc_url( $redeemid_view['subscription_url'] ); ?>"><?php esc_html_e( 'Manage subscription', 'redeemid' ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $redeemid_view['account_url'] ) : ?>
				<a class="wp-element-button is-style-outline" href="<?php echo esc_url( $redeemid_view['account_url'] ); ?>"><?php esc_html_e( 'Orders and account in Shopify', 'redeemid' ); ?></a>
			<?php endif; ?>
			<?php $redeemid_form( 'redeemid_logout', __( 'Log out', 'redeemid' ), 'redeemid-link-button' ); ?>
		</div>
	<?php endif; ?>
</section>
