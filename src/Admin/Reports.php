<?php

namespace RedeemID\Admin;

use RedeemID\Access\LevelStore;
use RedeemID\Features\Registry;
use RedeemID\Support\Stats;

/**
 * RedeemID → Reports: members gained and lost, sign-ins and purchases, from
 * the daily counters in Support\Stats. Counting starts when the plugin is
 * installed; nothing is reconstructed from the past.
 */
final class Reports {
	public const PAGE = 'redeemid-reports';

	private const WEEKS = 12;

	public static function render(): void {
		$stats  = get_option( Stats::OPTION, [] );
		$stats  = is_array( $stats ) ? $stats : [];
		$now    = time();
		$levels = array_filter( LevelStore::levels()->all(), static fn( $level ) => ! $level->builtin() );
		$since  = $stats ? min( array_keys( $stats ) ) : '';
		$tiles  = [
			[ __( 'Joined a level', 'redeemid' ), Stats::total( $stats, 'join:', 30, $now ) ],
			[ __( 'Left a level', 'redeemid' ), Stats::total( $stats, 'leave:', 30, $now ) ],
			[ __( 'Purchases that unlocked access', 'redeemid' ), Stats::total( $stats, 'unlocked', 30, $now ) ],
			[ __( 'Plan card checkouts', 'redeemid' ), Stats::total( $stats, 'checkout', 30, $now ) ],
			[ __( 'Shopify sign-ins', 'redeemid' ), Stats::total( $stats, 'sign_in', 30, $now ) ],
			[ __( 'Metered reads', 'redeemid' ), Stats::total( $stats, 'meter', 30, $now ) ],
			[ __( 'Readers who used up the meter', 'redeemid' ), Stats::total( $stats, 'meter_out', 30, $now ) ],
		];
		$joins  = Stats::weekly( $stats, 'join:', self::WEEKS, $now );
		$leaves = Stats::weekly( $stats, 'leave:', self::WEEKS, $now );
		$latest = self::latest_members( $stats );

		Menu::page_start(
			__( 'Reports', 'redeemid' ),
			__( 'Membership reports', 'redeemid' ),
			'',
			$since
				/* translators: %s: date */
				? sprintf( __( 'Counted since %s. A level is joined or left when Shopify adds or removes its tag; "unlocked access" is a purchase whose tag reached WordPress while the buyer was signed in.', 'redeemid' ), $since )
				: __( 'Nothing counted yet. Counting starts now: levels joined and left, sign-ins and purchases.', 'redeemid' )
		);
		?>
		<h2 class="redeemid-section-title"><?php esc_html_e( 'Last 30 days', 'redeemid' ); ?></h2>
		<div class="redeemid-tiles">
			<?php foreach ( $tiles as [ $label, $value ] ) : ?>
				<div class="redeemid-tile">
					<span class="redeemid-tile__value"><?php echo esc_html( number_format_i18n( $value ) ); ?></span>
					<span class="redeemid-tile__label"><?php echo esc_html( $label ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<?php Menu::panel_start( __( 'Joined and left, by week', 'redeemid' ), __( 'All levels together, for the last 12 weeks. Each week ends on the date shown.', 'redeemid' ) ); ?>
			<div class="redeemid-panel__body">
				<?php self::chart( $joins, $leaves ); ?>
			</div>
		<?php Menu::panel_end(); ?>

		<?php Menu::panel_start( __( 'By level', 'redeemid' ), __( 'Members are linked customers holding the level, counted once a day.', 'redeemid' ) ); ?>
			<div class="redeemid-panel__body redeemid-panel__body--flush">
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Level', 'redeemid' ); ?></th>
						<th class="num"><?php esc_html_e( 'Members', 'redeemid' ); ?></th>
						<th class="num"><?php esc_html_e( 'Joined, 30 days', 'redeemid' ); ?></th>
						<th class="num"><?php esc_html_e( 'Left, 30 days', 'redeemid' ); ?></th>
						<th class="num"><?php esc_html_e( 'Joined, 12 months', 'redeemid' ); ?></th>
						<th class="num"><?php esc_html_e( 'Left, 12 months', 'redeemid' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $levels as $slug => $level ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( Menu::url( Customers::PAGE, [ 'level' => $slug ] ) ); ?>"><?php echo esc_html( $level->label ); ?></a></td>
								<td class="num"><?php echo isset( $latest[ $slug ] ) ? esc_html( number_format_i18n( $latest[ $slug ] ) ) : '—'; ?></td>
								<?php foreach ( [ [ 'join:', 30 ], [ 'leave:', 30 ], [ 'join:', 365 ], [ 'leave:', 365 ] ] as [ $kind, $days ] ) : ?>
									<td class="num"><?php echo esc_html( number_format_i18n( Stats::total( $stats, $kind . $slug, $days, $now ) ) ); ?></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php Menu::panel_end(); ?>

		<?php if ( Registry::features() ) : ?>
			<?php Menu::panel_start( __( 'By feature', 'redeemid' ), __( 'Customers who gained or lost a feature when Shopify changed their tags.', 'redeemid' ) ); ?>
				<div class="redeemid-panel__body redeemid-panel__body--flush">
					<table class="widefat striped">
						<thead><tr>
							<th><?php esc_html_e( 'Feature', 'redeemid' ); ?></th>
							<th class="num"><?php esc_html_e( 'Gained, 30 days', 'redeemid' ); ?></th>
							<th class="num"><?php esc_html_e( 'Lost, 30 days', 'redeemid' ); ?></th>
							<th class="num"><?php esc_html_e( 'Gained, 12 months', 'redeemid' ); ?></th>
							<th class="num"><?php esc_html_e( 'Lost, 12 months', 'redeemid' ); ?></th>
						</tr></thead>
						<tbody>
							<?php foreach ( Registry::features() as $slug => $feature ) : ?>
								<tr>
									<td><?php echo esc_html( $feature->label ); ?></td>
									<?php foreach ( [ [ 'gain:', 30 ], [ 'lose:', 30 ], [ 'gain:', 365 ], [ 'lose:', 365 ] ] as [ $kind, $days ] ) : ?>
										<td class="num"><?php echo esc_html( number_format_i18n( Stats::total( $stats, $kind . $slug, $days, $now ) ) ); ?></td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php Menu::panel_end(); ?>
		<?php endif; ?>
		<?php
		Menu::page_end();
	}

	/**
	 * The most recent daily member count for each level.
	 *
	 * @param array<string, array<string, int>> $stats
	 * @return array<string, int>
	 */
	private static function latest_members( array $stats ): array {
		krsort( $stats );
		$out = [];

		foreach ( $stats as $row ) {
			foreach ( $row as $key => $n ) {
				if ( str_starts_with( (string) $key, 'members:' ) ) {
					$out[ substr( (string) $key, 8 ) ] ??= (int) $n;
				}
			}
		}

		return $out;
	}

	/**
	 * Paired weekly bars, joined beside left, with a legend, native hover
	 * titles and the same numbers as a table.
	 *
	 * @param array<int, array{end: string, value: int}> $joins
	 * @param array<int, array{end: string, value: int}> $leaves
	 */
	private static function chart( array $joins, array $leaves ): void {
		$max   = max( 1, ...array_column( $joins, 'value' ), ...array_column( $leaves, 'value' ) );
		$step  = self::nice_step( $max );
		$top   = $step * 4;
		$w     = 720;
		$h     = 220;
		$left  = 36;
		$base  = $h - 24;
		$plot  = $base - 8;
		$band  = ( $w - $left ) / count( $joins );
		$bar   = min( 18, ( $band - 12 ) / 2 );
		$y     = static fn( int $v ): float => $base - $plot * $v / $top;
		$shape = static function ( float $x, int $v ) use ( $y, $base, $bar ): string {
			if ( $v <= 0 ) {
				return '';
			}

			$top = $y( $v );
			$r   = min( 4, $bar / 2, $base - $top );

			return sprintf( 'M%1$.1f %2$.1fV%3$.1fQ%1$.1f %4$.1f %5$.1f %4$.1fH%6$.1fQ%7$.1f %4$.1f %7$.1f %3$.1fV%2$.1fZ', $x, $base, $top + $r, $top, $x + $r, $x + $bar - $r, $x + $bar );
		};
		$label = __( 'Joined', 'redeemid' );
		$lost  = __( 'Left', 'redeemid' );
		?>
		<div class="redeemid-legend">
			<span><i class="redeemid-legend__swatch redeemid-series-1"></i><?php echo esc_html( $label ); ?></span>
			<span><i class="redeemid-legend__swatch redeemid-series-2"></i><?php echo esc_html( $lost ); ?></span>
		</div>
		<svg class="redeemid-chart" viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" role="img" aria-label="<?php esc_attr_e( 'Members who joined and left a level each week', 'redeemid' ); ?>">
			<?php for ( $i = 0; $i <= 4; $i++ ) : ?>
				<line class="redeemid-chart__grid<?php echo 0 === $i ? ' is-base' : ''; ?>" x1="<?php echo (int) $left; ?>" x2="<?php echo (int) $w; ?>" y1="<?php echo esc_attr( (string) $y( $i * $step ) ); ?>" y2="<?php echo esc_attr( (string) $y( $i * $step ) ); ?>"></line>
				<text class="redeemid-chart__tick" x="<?php echo (int) ( $left - 6 ); ?>" y="<?php echo esc_attr( (string) ( $y( $i * $step ) + 4 ) ); ?>" text-anchor="end"><?php echo esc_html( number_format_i18n( $i * $step ) ); ?></text>
			<?php endfor; ?>
			<?php foreach ( $joins as $i => $week ) : ?>
				<?php
				$x0 = $left + $i * $band + ( $band - 2 * $bar - 2 ) / 2;
				?>
				<g class="redeemid-chart__week">
					<title><?php echo esc_html( sprintf( '%s · %s %d · %s %d', wp_date( 'M j', strtotime( $week['end'] ) ), $label, $week['value'], $lost, $leaves[ $i ]['value'] ) ); ?></title>
					<rect class="redeemid-chart__hit" x="<?php echo esc_attr( (string) ( $left + $i * $band ) ); ?>" y="0" width="<?php echo esc_attr( (string) $band ); ?>" height="<?php echo (int) $base; ?>"></rect>
					<path class="redeemid-series-1" d="<?php echo esc_attr( $shape( $x0, $week['value'] ) ); ?>"></path>
					<path class="redeemid-series-2" d="<?php echo esc_attr( $shape( $x0 + $bar + 2, $leaves[ $i ]['value'] ) ); ?>"></path>
					<?php if ( 0 === $i % 2 || count( $joins ) - 1 === $i ) : ?>
						<text class="redeemid-chart__tick" x="<?php echo esc_attr( (string) ( $left + ( $i + 0.5 ) * $band ) ); ?>" y="<?php echo (int) ( $h - 6 ); ?>" text-anchor="middle"><?php echo esc_html( wp_date( 'M j', strtotime( $week['end'] ) ) ); ?></text>
					<?php endif; ?>
				</g>
			<?php endforeach; ?>
		</svg>
		<details class="redeemid-chart__table">
			<summary><?php esc_html_e( 'Show as a table', 'redeemid' ); ?></summary>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Week ending', 'redeemid' ); ?></th><th class="num"><?php echo esc_html( $label ); ?></th><th class="num"><?php echo esc_html( $lost ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $joins as $i => $week ) : ?>
						<tr><td><?php echo esc_html( $week['end'] ); ?></td><td class="num"><?php echo (int) $week['value']; ?></td><td class="num"><?php echo (int) $leaves[ $i ]['value']; ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php
	}

	/**
	 * A round gridline step so four steps cover $max: 1, 2, 5, 10, 20, 50...
	 */
	public static function nice_step( int $max ): int {
		$raw  = max( 1, $max ) / 4;
		$base = 10 ** (int) floor( log10( $raw ) );

		foreach ( [ 1, 2, 5, 10 ] as $m ) {
			if ( $m * $base >= $raw ) {
				return (int) max( 1, $m * $base );
			}
		}

		return (int) ( 10 * $base );
	}
}
