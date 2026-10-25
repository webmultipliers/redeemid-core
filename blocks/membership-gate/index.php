<?php
/**
 * Membership gate, structured as parent + two child blocks
 * because a block can only have one InnerBlocks area.
 *
 * Which child reaches the page is decided before rendering, in
 * RedeemID\Blocks\Gate::filter_children(): the Members only child is
 * removed from the block tree for non-entitled visitors, so its content is
 * never rendered, let alone sent.
 *
 * @var array  $a            Block attributes.
 * @var bool   $isEditor     Rendering inside the block editor.
 * @var string $innerBlocks  Rendered children (already filtered).
 */

defined( 'ABSPATH' ) || exit;

$redeemid_template = [
	[ 'redeemid/membership-gate-content', [] ],
	[ 'redeemid/membership-gate-fallback', [] ],
];

if ( $isEditor ) {
	$redeemid_rule = \RedeemID\Entitlement\Rule::from_attributes( $a );
	$redeemid_source = \RedeemID\Blocks\Gate::option( $a['ruleSource'] ?? '', \RedeemID\Blocks\Gate::RULE_CUSTOM );
	$redeemid_item   = \RedeemID\Blocks\Gate::RULE_ITEM === $redeemid_source;
	$redeemid_level  = \RedeemID\Blocks\Gate::RULE_LEVEL === $redeemid_source ? \RedeemID\Access\LevelStore::levels()->get( \RedeemID\Blocks\Gate::option( $a['level'] ?? '', '' ) ) : null;
	$redeemid_post   = get_post();
	?>
	<div useBlockProps class="redeemid-gate is-editor">
		<p class="redeemid-gate__summary">
			<?php
			if ( $redeemid_item ) {
				$redeemid_effective = $redeemid_post instanceof \WP_Post ? \RedeemID\Access\Gatekeeper::rule( $redeemid_post ) : null;
				echo esc_html(
					$redeemid_effective && ! $redeemid_effective->open()
						/* translators: %s: the item's rule and where it comes from */
						? sprintf( __( 'Members only: this item\'s rule, %s.', 'redeemid' ), $redeemid_effective->explain( \RedeemID\Access\LevelStore::levels() ) )
						: __( 'This item\'s rule is open now, so everyone will see the Members only area. Set a rule in the Access box.', 'redeemid' )
				);
			} elseif ( \RedeemID\Blocks\Gate::RULE_LEVEL === $redeemid_source ) {
				echo esc_html(
					$redeemid_level
						/* translators: %s: access level label, such as Gold */
						? sprintf( __( 'Members only: visitors with the %s level.', 'redeemid' ), $redeemid_level->label )
						: __( 'Choose an access level. Until then nobody sees the Members only area.', 'redeemid' )
				);
			} elseif ( $redeemid_rule->required_tags ) {
				printf(
					/* translators: 1: "any" or "all", 2: tag list */
					esc_html__( 'Members only: needs %1$s of: %2$s', 'redeemid' ),
					esc_html( 'all' === $redeemid_rule->match_mode ? __( 'all', 'redeemid' ) : __( 'any', 'redeemid' ) ),
					esc_html( implode( ', ', $redeemid_rule->required_tags ) )
				);
			} elseif ( $redeemid_rule->require_login ) {
				esc_html_e( 'Members only: any signed-in visitor.', 'redeemid' );
			} else {
				esc_html_e( 'No rule set: everyone will see the Members only area.', 'redeemid' );
			}
			?>
		</p>
		<InnerBlocks template="<?php echo esc_attr( wp_json_encode( $redeemid_template ) ); ?>" templateLock="all" />
	</div>
	<?php
	return;
}

$redeemid_decision = \RedeemID\Blocks\Gate::decide( $a );
$redeemid_mode     = \RedeemID\Blocks\Gate::option( $a['fallbackMode'] ?? '', 'message' );
?>
<div useBlockProps class="redeemid-gate <?php echo $redeemid_decision->granted ? 'is-granted' : 'is-locked'; ?>">
	<InnerBlocks template="<?php echo esc_attr( wp_json_encode( $redeemid_template ) ); ?>" templateLock="all" />
	<?php if ( ! $redeemid_decision->granted && 'teaser-blocks' !== $redeemid_mode ) : ?>
		<?php echo \RedeemID\Blocks\Gate::fallback_panel( $a, $redeemid_decision ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
	<?php endif; ?>
</div>
