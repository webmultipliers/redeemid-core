<?php
/**
 * Membership Features, rendered as a Blockstudio dynamic island.
 *
 * The page carries the same neutral placeholder for everyone; the island
 * runtime fills in the visitor's own list in a never-cached request. The
 * list comes from redeemid_granted_features(): labels and provenance,
 * never tags.
 *
 * @var array $a                Block attributes.
 * @var bool  $isEditor         Rendering inside the block editor.
 * @var bool  $isIslandFragment Rendering the visitor's own fragment.
 */

defined( 'ABSPATH' ) || exit;

$redeemid_title   = '' !== trim( (string) ( $a['title'] ?? '' ) ) ? (string) $a['title'] : __( 'Your membership features', 'redeemid' );
$redeemid_display = $a['display'] ?? 'list';
$redeemid_display = is_array( $redeemid_display ) ? ( $redeemid_display['value'] ?? 'list' ) : $redeemid_display;
$redeemid_menu    = 'menu' === $redeemid_display;
$redeemid_empty   = '' !== trim( (string) ( $a['emptyText'] ?? '' ) ) ? (string) $a['emptyText'] : __( 'No membership features yet.', 'redeemid' );
$redeemid_items   = [];
$redeemid_note    = $redeemid_menu ? '' : __( 'Checking your membership…', 'redeemid' );

if ( $isEditor ) {
	$redeemid_note = __( 'Fills in on the live page with each visitor\'s own features.', 'redeemid' );
} elseif ( $isIslandFragment ) {
	$redeemid_note = '';
	$redeemid_held = $redeemid_menu ? redeemid_menu_features() : redeemid_granted_features();

	foreach ( $redeemid_held as $redeemid_slug => $redeemid_feature ) {
		$redeemid_items[] = [
			'slug'  => $redeemid_slug,
			'label' => $redeemid_feature['label'],
			/* translators: %s: route labels */
			'from'  => $redeemid_feature['provenance'] ? sprintf( __( 'from %s', 'redeemid' ), implode( __( ' and ', 'redeemid' ), $redeemid_feature['routes'] ) ) : '',
			'held'  => true,
		];
	}

	if ( ! $redeemid_menu && ! empty( $a['showMissing'] ) ) {
		foreach ( \RedeemID\Features\Registry::features() as $redeemid_slug => $redeemid_feature ) {
			if ( ! isset( $redeemid_held[ $redeemid_slug ] ) && $redeemid_feature->routes ) {
				$redeemid_items[] = [ 'slug' => $redeemid_slug, 'label' => $redeemid_feature->label, 'from' => __( 'not included', 'redeemid' ), 'held' => false ];
			}
		}
	}

	if ( ! $redeemid_items && ! $redeemid_menu ) {
		$redeemid_note = is_user_logged_in() ? $redeemid_empty : __( 'Sign in to see your membership features.', 'redeemid' );
	}
}
?>
<div useBlockProps class="redeemid-member-features<?php echo $redeemid_menu ? ' is-menu' : ''; ?>">
	<?php if ( ! $redeemid_menu ) : ?>
		<p class="redeemid-member-features__title"><?php echo esc_html( $redeemid_title ); ?></p>
	<?php endif; ?>
	<?php if ( $redeemid_items ) : ?>
		<ul class="redeemid-member-features__list">
			<?php foreach ( $redeemid_items as $redeemid_item ) : ?>
				<li class="redeemid-member-features__item <?php echo $redeemid_item['held'] ? 'is-held' : 'is-missing'; ?>" data-feature="<?php echo esc_attr( $redeemid_item['slug'] ); ?>">
					<span class="redeemid-member-features__label"><?php echo esc_html( $redeemid_item['label'] ); ?></span>
					<?php if ( '' !== $redeemid_item['from'] && ! $redeemid_menu ) : ?>
						<span class="redeemid-member-features__from"><?php echo esc_html( $redeemid_item['from'] ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ( '' !== $redeemid_note ) : ?>
		<p class="redeemid-member-features__note" aria-live="polite"><?php echo esc_html( $redeemid_note ); ?></p>
	<?php endif; ?>
</div>
