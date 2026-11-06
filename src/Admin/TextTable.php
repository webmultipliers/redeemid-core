<?php

namespace RedeemID\Admin;

use RedeemID\Strings\Catalog;
use RedeemID\Strings\Overrides;
use RedeemID\Strings\Text;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \WP_List_Table::class ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * RedeemID → Settings → Text: every string, with a field for the site's own text.
 */
final class TextTable extends \WP_List_Table {
	/** @var array<string, string|array{0: string, 1: string}> This language's saved replacements. */
	private array $saved = [];

	/** @var array<string, string> Key => the string as translated now, where it differs from the original. */
	private array $translated = [];

	/** @var array<string, array{error: string, value: mixed}> */
	private array $errors;

	/**
	 * @param array<string, array{error: string, value: mixed}> $errors What the last save refused.
	 */
	public function __construct( array $errors = [] ) {
		parent::__construct( [ 'singular' => 'string', 'plural' => 'strings', 'ajax' => false ] );
		$this->errors = $errors;
	}

	public function get_columns(): array {
		return [
			'original' => __( 'Original', 'redeemid' ),
			'text'     => __( 'Your text', 'redeemid' ),
			'where'    => __( 'Where it appears', 'redeemid' ),
		];
	}

	public function prepare_items(): void {
		$this->_column_headers = [ $this->get_columns(), [], [], 'original' ];

		$f           = TextPage::filters();
		$all         = Text::saved();
		$this->saved = $all[ $f['lang'] ] ?? [];
		$rows        = array_filter( self::rows( $this->saved ), static fn( array $row ): bool => self::matches( $row, $f, $all[ $f['lang'] ] ?? [] ) );
		$per_page    = $this->get_items_per_page( 'redeemid_per_page', 50 );
		$this->items = array_slice( array_values( $rows ), ( $this->get_pagenum() - 1 ) * $per_page, $per_page );

		$this->set_pagination_args( [ 'total_items' => count( $rows ), 'per_page' => $per_page ] );
		$this->translated = self::translations( $this->items, '' !== $f['lang'] ? $f['lang'] : determine_locale() );
	}

	/**
	 * Every string in the template, then saved replacements for strings
	 * this version no longer has (marked unused).
	 *
	 * @param array<string, mixed> $saved
	 * @return array<string, array>
	 */
	public static function rows( array $saved ): array {
		$order = array_flip( array_keys( TextPage::areas() ) );
		$rows  = Catalog::all();
		// What visitors and members see first, in the areas' order; the template's order within each.
		uasort( $rows, static fn( array $a, array $b ): int => [ Catalog::MEMBERS !== $a['audience'], $order[ $a['area'] ] ?? 99 ] <=> [ Catalog::MEMBERS !== $b['audience'], $order[ $b['area'] ] ?? 99 ] );

		foreach ( array_diff_key( $saved, $rows ) as $key => $value ) {
			[ $context, $single ] = Overrides::split( (string) $key );
			$rows[ $key ]         = [
				'key'      => (string) $key,
				'single'   => $single,
				'plural'   => is_array( $value ) ? $single : '',
				'context'  => $context,
				'comment'  => '',
				'refs'     => [],
				'format'   => Overrides::is_format( $single ),
				'area'     => '',
				'audience' => '',
				'unused'   => true,
			];
		}

		return $rows;
	}

	/**
	 * @param array{s: string, audience: string, area: string, view: string} $f
	 */
	private static function matches( array $row, array $f, array $saved ): bool {
		$replaced = isset( $saved[ $row['key'] ] );

		if ( ( 'changed' === $f['view'] && ! $replaced ) || ( 'unused' === $f['view'] && empty( $row['unused'] ) ) ) {
			return false;
		}

		if ( ( '' !== $f['audience'] && $row['audience'] !== $f['audience'] ) || ( '' !== $f['area'] && $row['area'] !== $f['area'] ) ) {
			return false;
		}

		if ( '' === $f['s'] ) {
			return true;
		}

		$haystack = implode( "\n", [ $row['single'], $row['plural'], $row['context'], implode( ' ', (array) ( $saved[ $row['key'] ] ?? '' ) ) ] );

		return false !== mb_stripos( $haystack, $f['s'] );
	}

	/**
	 * The strings on this page as $locale translates them, unreplaced,
	 * where that differs from the English original. A plural string's
	 * "more than one" form is keyed key . "\0more".
	 *
	 * @return array<string, string>
	 */
	private static function translations( array $items, string $locale ): array {
		if ( ! $items || 'en_US' === $locale ) {
			return [];
		}

		$switched    = $locale !== determine_locale() && switch_to_locale( $locale );
		$bypass      = Text::$bypass;
		Text::$bypass = true;
		$out         = [];

		foreach ( $items as $row ) {
			// phpcs:disable WordPress.WP.I18n -- reading the translation of each listed string.
			$text = '' !== $row['context'] ? translate_with_gettext_context( $row['single'], $row['context'], 'redeemid' ) : translate( $row['single'], 'redeemid' );
			// phpcs:enable WordPress.WP.I18n

			if ( $text !== $row['single'] ) {
				$out[ $row['key'] ] = $text;
			}

			if ( '' !== $row['plural'] ) {
				// phpcs:disable WordPress.WP.I18n -- as above.
				$more = '' !== $row['context'] ? _nx( $row['single'], $row['plural'], 2, $row['context'], 'redeemid' ) : _n( $row['single'], $row['plural'], 2, 'redeemid' );
				// phpcs:enable WordPress.WP.I18n

				if ( $more !== $row['plural'] ) {
					$out[ $row['key'] . "\0more" ] = $more;
				}
			}
		}

		Text::$bypass = $bypass;

		if ( $switched ) {
			restore_previous_locale();
		}

		return $out;
	}

	protected function get_views(): array {
		$f      = TextPage::filters();
		$saved  = Text::saved()[ $f['lang'] ] ?? [];
		$unused = count( array_diff_key( $saved, Catalog::all() ) );
		$base   = array_filter( [ 'lang' => $f['lang'] ] );
		$views  = [
			''        => __( 'All', 'redeemid' ),
			/* translators: %d: number of replaced strings */
			'changed' => sprintf( __( 'Replaced (%d)', 'redeemid' ), count( $saved ) ),
		];

		if ( $unused ) {
			/* translators: %d: number of replacements for strings this version doesn't have */
			$views['unused'] = sprintf( __( 'No longer used (%d)', 'redeemid' ), $unused );
		}

		$out = [];

		foreach ( $views as $key => $label ) {
			$out[ $key ?: 'all' ] = sprintf( '<a href="%s"%s>%s</a>', esc_url( TextPage::url( $base + array_filter( [ 'view' => $key ] ) ) ), $f['view'] === $key ? ' class="current" aria-current="page"' : '', esc_html( $label ) );
		}

		return $out;
	}

	/**
	 * The audience and area filters, above the table. They submit the filter form.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$f = TextPage::filters();
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="redeemid-text-audience"><?php esc_html_e( 'Filter by who sees it', 'redeemid' ); ?></label>
			<select name="audience" id="redeemid-text-audience" form="redeemid-text-filters">
				<option value=""><?php esc_html_e( 'Everyone', 'redeemid' ); ?></option>
				<option value="<?php echo esc_attr( Catalog::MEMBERS ); ?>" <?php selected( $f['audience'], Catalog::MEMBERS ); ?>><?php esc_html_e( 'Visitors and members', 'redeemid' ); ?></option>
				<option value="<?php echo esc_attr( Catalog::STAFF ); ?>" <?php selected( $f['audience'], Catalog::STAFF ); ?>><?php esc_html_e( 'Staff only', 'redeemid' ); ?></option>
			</select>
			<label class="screen-reader-text" for="redeemid-text-area"><?php esc_html_e( 'Filter by where it appears', 'redeemid' ); ?></label>
			<select name="area" id="redeemid-text-area" form="redeemid-text-filters">
				<option value=""><?php esc_html_e( 'All areas', 'redeemid' ); ?></option>
				<?php foreach ( TextPage::areas() as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $f['area'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button" form="redeemid-text-filters"><?php esc_html_e( 'Filter', 'redeemid' ); ?></button>
		</div>
		<?php
	}

	public function no_items(): void {
		esc_html_e( 'No strings match. Try another search or filter.', 'redeemid' );
	}

	protected function column_original( array $row ): string {
		$html = '<span class="redeemid-text-original" lang="en">' . esc_html( $row['single'] ) . '</span>';

		if ( '' !== $row['plural'] && $row['plural'] !== $row['single'] ) {
			$html .= '<br><span class="redeemid-text-original" lang="en">' . esc_html( $row['plural'] ) . '</span>';
		}

		$meta = [];

		if ( isset( $this->translated[ $row['key'] ] ) ) {
			/* translators: %s: the string as the site's translation shows it */
			$meta[] = sprintf( esc_html__( 'Translated: %s', 'redeemid' ), '<span>' . esc_html( $this->translated[ $row['key'] ] ) . '</span>' );
		}

		if ( '' !== $row['context'] ) {
			/* translators: %s: gettext context, e.g. "block title" */
			$meta[] = esc_html( sprintf( __( 'Used as: %s', 'redeemid' ), $row['context'] ) );
		}

		if ( '' !== $row['comment'] ) {
			$meta[] = esc_html( $row['comment'] );
		}

		return $html . ( $meta ? '<span class="redeemid-text-notes">' . implode( '<br>', $meta ) . '</span>' : '' );
	}

	protected function column_text( array $row ): string {
		$id     = 'redeemid-text-' . md5( $row['key'] );
		$error  = $this->errors[ $row['key'] ] ?? null;
		$value  = $error ? $error['value'] : ( $this->saved[ $row['key'] ] ?? '' );
		$plural = '' !== $row['plural'];
		$name   = 'text[' . md5( $row['key'] ) . ']';
		$hint   = Overrides::is_format( $row['single'], $row['format'] ) ? Overrides::hint( $row['single'] . ' ' . $row['plural'] ) : '';
		$notes  = [];

		if ( $error ) {
			$notes[] = '<span class="redeemid-error-text" id="' . esc_attr( $id ) . '-error">' . esc_html( TextPage::problem_text( $error['error'], $hint ) ) . '</span>';
		}

		if ( '' !== $hint ) {
			/* translators: %s: placeholders such as %s or %1$d */
			$notes[] = '<span class="description" id="' . esc_attr( $id ) . '-hint">' . esc_html( sprintf( __( 'Keep %s: each is filled in when the text shows.', 'redeemid' ), $hint ) ) . '</span>';
		}

		$described = trim( ( $error ? $id . '-error ' : '' ) . ( '' !== $hint ? $id . '-hint' : '' ) );
		$html      = '<input type="hidden" name="keys[' . esc_attr( md5( $row['key'] ) ) . ']" value="' . esc_attr( $row['key'] ) . '">';

		if ( $plural ) {
			$forms = is_array( $value ) ? $value : [ (string) $value, '' ];
			$html .= $this->field( $id, $name . '[0]', (string) ( $forms[0] ?? '' ), __( 'One', 'redeemid' ), $row['single'], $this->translated[ $row['key'] ] ?? $row['single'], $described, (bool) $error );
			$html .= $this->field( $id . '-more', $name . '[1]', (string) ( $forms[1] ?? '' ), __( 'More than one', 'redeemid' ), $row['plural'], $this->translated[ $row['key'] . "\0more" ] ?? $row['plural'], $described, (bool) $error );
		} else {
			$html .= $this->field( $id, $name, is_array( $value ) ? (string) ( $value[0] ?? '' ) : (string) $value, '', $row['single'], $this->translated[ $row['key'] ] ?? $row['single'], $described, (bool) $error );
		}

		return $html . ( $notes ? '<span class="redeemid-text-notes">' . implode( '<br>', $notes ) . '</span>' : '' );
	}

	/**
	 * One text field, labelled for screen readers with the string it
	 * replaces. Its placeholder is the text the string shows now (the
	 * translation, else the original), so an empty field reads as what it is for.
	 */
	private function field( string $id, string $name, string $value, string $form, string $original, string $current, string $described, bool $invalid ): string {
		/* translators: %s: the original string */
		$label = sprintf( __( 'Replace “%s”', 'redeemid' ), wp_html_excerpt( $original, 80, '…' ) );
		$label = '' !== $form ? $form . ': ' . $label : $label;
		$attrs = sprintf(
			'id="%s" name="%s" placeholder="%s"%s%s',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $current ),
			'' !== $described ? ' aria-describedby="' . esc_attr( $described ) . '"' : '',
			$invalid ? ' aria-invalid="true"' : ''
		);
		$out   = '' !== $form
			? '<label class="redeemid-text-form" for="' . esc_attr( $id ) . '"><span aria-hidden="true">' . esc_html( $form ) . '</span><span class="screen-reader-text">' . esc_html( $label ) . '</span></label>'
			: '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';

		if ( mb_strlen( $original ) > 90 || str_contains( $original, "\n" ) ) {
			return $out . '<textarea ' . $attrs . ' class="large-text" rows="3">' . esc_textarea( $value ) . '</textarea>';
		}

		return $out . '<input type="text" ' . $attrs . ' class="large-text" value="' . esc_attr( $value ) . '">';
	}

	protected function column_where( array $row ): string {
		if ( ! empty( $row['unused'] ) ) {
			return Menu::status( 'warn', __( 'Not in this version', 'redeemid' ) );
		}

		$areas = TextPage::areas();
		$label = $areas[ $row['area'] ] ?? $row['area'];
		$block = Catalog::block( $row['refs'] );
		$files = array_slice( $row['refs'], 0, 3 );
		$more  = count( $row['refs'] ) - count( $files );

		return '<span class="redeemid-chip">' . esc_html( $label ) . '</span>'
			. ( '' !== $block ? ' <span class="redeemid-chip">' . esc_html( $block ) . '</span>' : '' )
			. ( Catalog::MEMBERS === $row['audience'] ? '' : ' ' . Menu::status( 'neutral', __( 'Staff', 'redeemid' ) ) )
			. '<span class="redeemid-text-notes"><code class="redeemid-code-small">' . implode( '</code><br><code class="redeemid-code-small">', array_map( 'esc_html', $files ) ) . '</code>'
			/* translators: %d: number of other source files */
			. ( $more > 0 ? '<br>' . esc_html( sprintf( _n( 'and %d more file', 'and %d more files', $more, 'redeemid' ), $more ) ) : '' )
			. '</span>';
	}
}
