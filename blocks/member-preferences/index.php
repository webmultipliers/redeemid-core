<?php
/**
 * Member Preferences, rendered as a Blockstudio dynamic island: the page
 * carries a neutral placeholder for everyone, and each visitor's own fragment
 * (never cached) carries their form, values and nonce. See
 * RedeemID\Blocks\PreferencesForm.
 *
 * @var array $a                Block attributes.
 * @var bool  $isEditor         Rendering inside the block editor.
 * @var bool  $isIslandFragment Rendering the visitor's own fragment.
 */

defined( 'ABSPATH' ) || exit;

use RedeemID\Blocks\Gate;
use RedeemID\Blocks\PreferencesForm;
use RedeemID\Preferences\Preferences;

$redeemid_title  = '' !== trim( (string) ( $a['title'] ?? '' ) ) ? (string) $a['title'] : __( 'Your preferences', 'redeemid' );
$redeemid_intro  = trim( (string) ( $a['intro'] ?? '' ) );
$redeemid_button = '' !== trim( (string) ( $a['button'] ?? '' ) ) ? (string) $a['button'] : __( 'Save preferences', 'redeemid' );
$redeemid_guests = ! empty( $a['askGuests'] );
$redeemid_tag    = Gate::heading( $a['headingLevel'] ?? '', 'h2' );
$redeemid_here   = $isIslandFragment ? Gate::fragment_url() : Gate::current_url();
$redeemid_query  = [];
wp_parse_str( (string) wp_parse_url( $redeemid_here, PHP_URL_QUERY ), $redeemid_query );
$redeemid_said   = sanitize_key( (string) ( $redeemid_query[ PreferencesForm::NOTICE ] ?? '' ) );
$redeemid_here   = remove_query_arg( PreferencesForm::NOTICE, $redeemid_here );
$redeemid_msgs   = PreferencesForm::messages();

// script.view.js holds a guest's answers through the browser client (redeemid.holdPreferences).
if ( ! $isEditor && ! $isIslandFragment ) {
	wp_enqueue_script( \RedeemID\Api\Contract::CLIENT_HANDLE );
}

// Who is looking, decided only in the visitor's own fragment.
$redeemid_state  = 'placeholder';
$redeemid_fields = [];

if ( ! $isEditor && $isIslandFragment ) {
	$redeemid_user = get_current_user_id();

	if ( $redeemid_user && \RedeemID\Sync\Mirror::customer_id( $redeemid_user ) ) {
		$redeemid_read   = Preferences::read( $redeemid_user );
		$redeemid_state  = is_wp_error( $redeemid_read ) ? 'error' : 'member';
		$redeemid_fields = is_wp_error( $redeemid_read ) ? [] : $redeemid_read;
	} elseif ( $redeemid_user ) {
		$redeemid_state = 'not_linked';
	} elseif ( $redeemid_guests ) {
		$redeemid_list   = Preferences::public_fields();
		$redeemid_state  = is_wp_error( $redeemid_list ) ? 'error' : 'guest';
		$redeemid_fields = is_wp_error( $redeemid_list ) ? [] : array_map( static fn( $f ) => $f + [ 'editable' => true, 'value' => null ], $redeemid_list );
	} else {
		$redeemid_state = 'guest_off';
	}
}

$redeemid_editable = array_filter( $redeemid_fields, static fn( $f ) => ! empty( $f['editable'] ) );
?>
<section useBlockProps id="redeemid-preferences" class="redeemid-preferences is-<?php echo esc_attr( $redeemid_state ); ?>"
	data-redeemid-preferences="<?php echo esc_attr( $redeemid_state ); ?>"
	data-redeemid-messages="<?php echo esc_attr( (string) wp_json_encode( $redeemid_msgs ) ); ?>"
	<?php if ( 'member' === $redeemid_state ) : ?>
		data-redeemid-endpoint="<?php echo esc_url( rest_url( \RedeemID\Rest\Routes::NS . '/account/preferences' ) ); ?>"
		data-redeemid-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
	<?php endif; ?>>
	<<?php echo esc_attr( $redeemid_tag ); ?> class="redeemid-preferences__title"><?php echo esc_html( $redeemid_title ); ?></<?php echo esc_attr( $redeemid_tag ); ?>>

	<?php if ( '' !== $redeemid_intro ) : ?>
		<p class="redeemid-preferences__intro"><?php echo esc_html( $redeemid_intro ); ?></p>
	<?php endif; ?>

	<p class="redeemid-preferences__status" role="status" data-redeemid-notice><?php echo isset( $redeemid_msgs[ $redeemid_said ] ) && 'field' !== $redeemid_said ? esc_html( $redeemid_msgs[ $redeemid_said ] ) : ''; ?></p>

	<?php if ( $isEditor ) : ?>
		<p><?php esc_html_e( 'Each member sees the fields you set to Members can see or Members can change under RedeemID → Settings → Members, with their own answers. Guests see the fields they can change.', 'redeemid' ); ?></p>
	<?php elseif ( 'placeholder' === $redeemid_state ) : ?>
		<p class="redeemid-preferences__loading"><?php esc_html_e( 'Loading your preferences…', 'redeemid' ); ?></p>
	<?php elseif ( 'not_linked' === $redeemid_state ) : ?>
		<p><?php esc_html_e( 'Confirm your email on your account page to set your preferences.', 'redeemid' ); ?></p>
	<?php elseif ( 'error' === $redeemid_state ) : ?>
		<p><?php esc_html_e( 'Your preferences can\'t be loaded right now. Try again later.', 'redeemid' ); ?></p>
	<?php elseif ( 'guest_off' === $redeemid_state ) : ?>
		<p><a class="wp-element-button" href="<?php echo esc_url( PreferencesForm::login_url( $redeemid_here ) ); ?>" rel="nofollow"><?php esc_html_e( 'Sign in to set your preferences', 'redeemid' ); ?></a></p>
	<?php elseif ( ! $redeemid_fields ) : ?>
		<p><?php esc_html_e( 'There is nothing to set here yet.', 'redeemid' ); ?></p>
	<?php else : ?>
		<form class="redeemid-preferences__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo 'guest' === $redeemid_state ? ' hidden' : ''; ?>>
			<?php if ( 'member' === $redeemid_state ) : ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( PreferencesForm::ACTION ); ?>">
				<?php wp_nonce_field( PreferencesForm::ACTION, '_wpnonce', false ); ?>
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redeemid_here ); ?>">
			<?php endif; ?>
			<?php foreach ( $redeemid_fields as $redeemid_field ) : ?>
				<?php PreferencesForm::control( $redeemid_field, $redeemid_field['value'] ?? null, ! empty( $redeemid_field['editable'] ), 'redeemid-pref-' ); ?>
			<?php endforeach; ?>
			<?php if ( $redeemid_editable ) : ?>
				<p class="redeemid-preferences__actions">
					<button type="submit" class="wp-element-button"><?php echo esc_html( $redeemid_button ); ?></button>
				</p>
			<?php endif; ?>
		</form>
		<?php if ( 'guest' === $redeemid_state ) : ?>
			<p class="redeemid-preferences__login">
				<?php esc_html_e( 'Your answers are saved to your account.', 'redeemid' ); ?>
				<a href="<?php echo esc_url( PreferencesForm::login_url( $redeemid_here ) ); ?>" rel="nofollow"><?php esc_html_e( 'Sign in', 'redeemid' ); ?></a>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</section>
