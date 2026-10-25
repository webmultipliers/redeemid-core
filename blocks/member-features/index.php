<?php
/**
 * Membership Features, rendered as a Blockstudio dynamic island.
 *
 * The page carries the same neutral placeholder for everyone; the island
 * runtime fills in the visitor's own list in a never-cached request. The
 * list comes from wp2_shopify_granted_features(): labels and provenance,
 * never tags.
 *
 * @var array $a                Block attributes.
 * @var bool  $isEditor         Rendering inside the block editor.
 * @var bool  $isIslandFragment Rendering the visitor's own fragment.
 */

defined( 'ABSPATH' ) || exit;

$wp2_title   = '' !== trim( (string) ( $a['title'] ?? '' ) ) ? (string) $a['title'] : __( 'Your membership features', 'wp2-shopify' );
$wp2_display = $a['display'] ?? 'list';
$wp2_display = is_array( $wp2_display ) ? ( $wp2_display['value'] ?? 'list' ) : $wp2_display;
$wp2_menu    = 'menu' === $wp2_display;
$wp2_empty   = '' !== trim( (string) ( $a['emptyText'] ?? '' ) ) ? (string) $a['emptyText'] : __( 'No membership features yet.', 'wp2-shopify' );
$wp2_items   = [];
$wp2_note    = $wp2_menu ? '' : __( 'Checking your membership…', 'wp2-shopify' );

if ( $isEditor ) {
	$wp2_note = __( 'Fills in on the live page with each visitor\'s own features.', 'wp2-shopify' );
} elseif ( $isIslandFragment ) {
	$wp2_note = '';
	$wp2_held = $wp2_menu ? wp2_shopify_menu_features() : wp2_shopify_granted_features();

	foreach ( $wp2_held as $wp2_slug => $wp2_feature ) {
		$wp2_items[] = [
			'slug'  => $wp2_slug,
			'label' => $wp2_feature['label'],
			/* translators: %s: route labels */
			'from'  => $wp2_feature['provenance'] ? sprintf( __( 'from %s', 'wp2-shopify' ), implode( __( ' and ', 'wp2-shopify' ), $wp2_feature['routes'] ) ) : '',
			'held'  => true,
		];
	}

	if ( ! $wp2_menu && ! empty( $a['showMissing'] ) ) {
		foreach ( \WP2_Shopify\Features\Registry::features() as $wp2_slug => $wp2_feature ) {
			if ( ! isset( $wp2_held[ $wp2_slug ] ) && $wp2_feature->routes ) {
				$wp2_items[] = [ 'slug' => $wp2_slug, 'label' => $wp2_feature->label, 'from' => __( 'not included', 'wp2-shopify' ), 'held' => false ];
			}
		}
	}

	if ( ! $wp2_items && ! $wp2_menu ) {
		$wp2_note = is_user_logged_in() ? $wp2_empty : __( 'Sign in to see your membership features.', 'wp2-shopify' );
	}
}
?>
<div useBlockProps class="wp2-shopify-member-features<?php echo $wp2_menu ? ' is-menu' : ''; ?>">
	<?php if ( ! $wp2_menu ) : ?>
		<p class="wp2-shopify-member-features__title"><?php echo esc_html( $wp2_title ); ?></p>
	<?php endif; ?>
	<?php if ( $wp2_items ) : ?>
		<ul class="wp2-shopify-member-features__list">
			<?php foreach ( $wp2_items as $wp2_item ) : ?>
				<li class="wp2-shopify-member-features__item <?php echo $wp2_item['held'] ? 'is-held' : 'is-missing'; ?>" data-feature="<?php echo esc_attr( $wp2_item['slug'] ); ?>">
					<span class="wp2-shopify-member-features__label"><?php echo esc_html( $wp2_item['label'] ); ?></span>
					<?php if ( '' !== $wp2_item['from'] && ! $wp2_menu ) : ?>
						<span class="wp2-shopify-member-features__from"><?php echo esc_html( $wp2_item['from'] ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ( '' !== $wp2_note ) : ?>
		<p class="wp2-shopify-member-features__note" aria-live="polite"><?php echo esc_html( $wp2_note ); ?></p>
	<?php endif; ?>
</div>
