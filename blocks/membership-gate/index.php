<?php
/**
 * Membership gate, structured as parent + two child blocks
 * because a block can only have one InnerBlocks area.
 *
 * Which child reaches the page is decided before rendering, in
 * WP2_Shopify\Blocks\Gate::filter_children(): the Members only child is
 * removed from the block tree for non-entitled visitors, so its content is
 * never rendered, let alone sent.
 *
 * @var array  $a            Block attributes.
 * @var bool   $isEditor     Rendering inside the block editor.
 * @var string $innerBlocks  Rendered children (already filtered).
 */

defined( 'ABSPATH' ) || exit;

$wp2_template = [
	[ 'wp2-shopify/membership-gate-content', [] ],
	[ 'wp2-shopify/membership-gate-fallback', [] ],
];

if ( $isEditor ) {
	$wp2_rule = \WP2_Shopify\Entitlement\Rule::from_attributes( $a );
	$wp2_source = \WP2_Shopify\Blocks\Gate::option( $a['ruleSource'] ?? '', \WP2_Shopify\Blocks\Gate::RULE_CUSTOM );
	$wp2_item   = \WP2_Shopify\Blocks\Gate::RULE_ITEM === $wp2_source;
	$wp2_level  = \WP2_Shopify\Blocks\Gate::RULE_LEVEL === $wp2_source ? \WP2_Shopify\Access\LevelStore::levels()->get( \WP2_Shopify\Blocks\Gate::option( $a['level'] ?? '', '' ) ) : null;
	$wp2_post   = get_post();
	?>
	<div useBlockProps class="wp2-shopify-gate is-editor">
		<p class="wp2-shopify-gate__summary">
			<?php
			if ( $wp2_item ) {
				$wp2_effective = $wp2_post instanceof \WP_Post ? \WP2_Shopify\Access\Gatekeeper::rule( $wp2_post ) : null;
				echo esc_html(
					$wp2_effective && ! $wp2_effective->open()
						/* translators: %s: the item's rule and where it comes from */
						? sprintf( __( 'Members only: this item\'s rule, %s.', 'wp2-shopify' ), $wp2_effective->explain( \WP2_Shopify\Access\LevelStore::levels() ) )
						: __( 'This item\'s rule is open now, so everyone will see the Members only area. Set a rule in the Access box.', 'wp2-shopify' )
				);
			} elseif ( \WP2_Shopify\Blocks\Gate::RULE_LEVEL === $wp2_source ) {
				echo esc_html(
					$wp2_level
						/* translators: %s: access level label, such as Gold */
						? sprintf( __( 'Members only: visitors with the %s level.', 'wp2-shopify' ), $wp2_level->label )
						: __( 'Choose an access level. Until then nobody sees the Members only area.', 'wp2-shopify' )
				);
			} elseif ( $wp2_rule->required_tags ) {
				printf(
					/* translators: 1: "any" or "all", 2: tag list */
					esc_html__( 'Members only: needs %1$s of: %2$s', 'wp2-shopify' ),
					esc_html( 'all' === $wp2_rule->match_mode ? __( 'all', 'wp2-shopify' ) : __( 'any', 'wp2-shopify' ) ),
					esc_html( implode( ', ', $wp2_rule->required_tags ) )
				);
			} elseif ( $wp2_rule->require_login ) {
				esc_html_e( 'Members only: any signed-in visitor.', 'wp2-shopify' );
			} else {
				esc_html_e( 'No rule set: everyone will see the Members only area.', 'wp2-shopify' );
			}
			?>
		</p>
		<InnerBlocks template="<?php echo esc_attr( wp_json_encode( $wp2_template ) ); ?>" templateLock="all" />
	</div>
	<?php
	return;
}

$wp2_decision = \WP2_Shopify\Blocks\Gate::decide( $a );
$wp2_mode     = \WP2_Shopify\Blocks\Gate::option( $a['fallbackMode'] ?? '', 'message' );
?>
<div useBlockProps class="wp2-shopify-gate <?php echo $wp2_decision->granted ? 'is-granted' : 'is-locked'; ?>">
	<InnerBlocks template="<?php echo esc_attr( wp_json_encode( $wp2_template ) ); ?>" templateLock="all" />
	<?php if ( ! $wp2_decision->granted && 'teaser-blocks' !== $wp2_mode ) : ?>
		<?php echo \WP2_Shopify\Blocks\Gate::fallback_panel( $a, $wp2_decision ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
	<?php endif; ?>
</div>
