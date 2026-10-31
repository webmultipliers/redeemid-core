<?php

namespace RedeemID\Access;

/**
 * Cuts a locked item's parsed blocks down to its teaser. Pure: no WordPress
 * calls, so every surface (page body, REST, feeds, excerpts) gets the same
 * answer from the same function, and the order is unit tested.
 *
 * Precedence, stated once: the paywall break beats the post type's teaser
 * setting, which beats the excerpt.
 *
 * - A top-level break keeps everything before it.
 * - A break nested inside another block keeps everything before that block:
 *   splitting a group would need its markup rewritten, so the whole block
 *   counts as locked. Failing short is safe; failing long would leak.
 * - With no break, the post type setting decides: the first N blocks, the
 *   blocks within the first N words (a block is never cut in half), or
 *   nothing. Classic (non-block) content has no block boundaries, so the
 *   blocks setting keeps none of it and the words setting keeps it only when
 *   the whole of it fits.
 * - If that leaves nothing and the item has a hand-written excerpt, the
 *   excerpt becomes the teaser.
 */
final class Teaser {
	public const BREAK_BLOCK = 'redeemid/paywall-break';

	public const MODE_BLOCKS  = 'blocks';
	public const MODE_WORDS   = 'words';
	public const MODE_EXCERPT = 'excerpt';
	public const MODE_NONE    = 'none';

	public const SOURCE_BREAK   = 'break';
	public const SOURCE_SETTING = 'setting';
	public const SOURCE_EXCERPT = 'excerpt';
	public const SOURCE_NONE    = 'none';

	public function __construct(
		public readonly string $mode = self::MODE_BLOCKS,
		public readonly int $count = 2,
	) {}

	public static function from_array( array $a ): self {
		$mode = (string) ( $a['mode'] ?? self::MODE_BLOCKS );
		$mode = in_array( $mode, [ self::MODE_BLOCKS, self::MODE_WORDS, self::MODE_EXCERPT, self::MODE_NONE ], true ) ? $mode : self::MODE_BLOCKS;

		return new self( $mode, max( 0, min( 1000, (int) ( $a['count'] ?? 2 ) ) ) );
	}

	/**
	 * @return array{mode: string, count: int}
	 */
	public function to_array(): array {
		return [ 'mode' => $this->mode, 'count' => $this->count ];
	}

	/**
	 * $blocks with every block named $drop (or every block $drop returns true
	 * for) removed, at any depth, along with its inner blocks. The parent's
	 * innerContent keeps one null placeholder per inner block, so the
	 * placeholder of a removed block goes too.
	 *
	 * @param array[]                $blocks Parsed blocks.
	 * @param string|callable(array): bool $drop
	 * @return array[]
	 */
	public static function strip( array $blocks, string|callable $drop ): array {
		$out   = [];
		$match = is_string( $drop ) ? static fn( array $b ) => $drop === ( $b['blockName'] ?? null ) : $drop;

		foreach ( $blocks as $block ) {
			if ( $match( $block ) ) {
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$kept    = [];
				$content = [];
				$i       = 0;

				foreach ( $block['innerContent'] ?? [] as $chunk ) {
					if ( null !== $chunk ) {
						$content[] = $chunk;
						continue;
					}

					$inner = $block['innerBlocks'][ $i++ ] ?? null;

					if ( null !== $inner && ! $match( $inner ) ) {
						$kept[]    = self::strip( [ $inner ], $match )[0];
						$content[] = null;
					}
				}

				$block['innerBlocks']  = $kept;
				$block['innerContent'] = $content;
			}

			$out[] = $block;
		}

		return $out;
	}

	/**
	 * @param array[] $blocks  Top-level parsed blocks (parse_blocks() output).
	 * @param string  $excerpt The item's hand-written excerpt, if any.
	 * @return array{blocks: array[], source: string}
	 */
	public function cut( array $blocks, string $excerpt = '' ): array {
		foreach ( $blocks as $i => $block ) {
			if ( self::contains_break( $block ) ) {
				return [ 'blocks' => self::trim_tail( array_slice( $blocks, 0, $i ) ), 'source' => self::SOURCE_BREAK ];
			}
		}

		$kept = match ( $this->mode ) {
			self::MODE_BLOCKS => $this->first_blocks( $blocks ),
			self::MODE_WORDS  => $this->first_words( $blocks ),
			default           => [],
		};

		if ( self::named( $kept ) ) {
			return [ 'blocks' => $kept, 'source' => self::SOURCE_SETTING ];
		}

		if ( self::MODE_NONE !== $this->mode && '' !== trim( $excerpt ) ) {
			return [ 'blocks' => [ self::paragraph( $excerpt ) ], 'source' => self::SOURCE_EXCERPT ];
		}

		return [ 'blocks' => [], 'source' => self::SOURCE_NONE ];
	}

	public static function contains_break( array $block ): bool {
		if ( self::BREAK_BLOCK === ( $block['blockName'] ?? null ) ) {
			return true;
		}

		foreach ( (array) ( $block['innerBlocks'] ?? [] ) as $inner ) {
			if ( is_array( $inner ) && self::contains_break( $inner ) ) {
				return true;
			}
		}

		return false;
	}

	private function first_blocks( array $blocks ): array {
		$kept  = [];
		$named = 0;

		foreach ( $blocks as $block ) {
			$freeform = null === ( $block['blockName'] ?? null );

			// Classic (non-block) content arrives as one freeform chunk with no
			// block boundaries, so it is never counted as "a block": stop there.
			if ( $freeform && '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				break;
			}

			if ( ! $freeform && ++$named > $this->count ) {
				break;
			}

			$kept[] = $block;
		}

		return self::trim_tail( $kept );
	}

	private function first_words( array $blocks ): array {
		$kept  = [];
		$words = 0;

		foreach ( $blocks as $block ) {
			$words += self::word_count( $block );

			if ( $words > $this->count ) {
				break;
			}

			$kept[] = $block;
		}

		return self::trim_tail( $kept );
	}

	private static function word_count( array $block ): int {
		// Script and style contents are not words a reader sees.
		$html  = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) ( $block['innerHTML'] ?? '' ) );
		$text  = trim( html_entity_decode( strip_tags( $html ), ENT_QUOTES ) ); // phpcs:ignore WordPressVIPMinimum.Functions.StripTags.StripTagsOneParameter, WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- script and style are removed above; unit tests run without WordPress.
		$count = '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) ?: [] );

		foreach ( (array) ( $block['innerBlocks'] ?? [] ) as $inner ) {
			$count += is_array( $inner ) ? self::word_count( $inner ) : 0;
		}

		return $count;
	}

	/**
	 * Drop trailing whitespace-only freeform chunks left between blocks.
	 */
	private static function trim_tail( array $blocks ): array {
		while ( $blocks && null === ( end( $blocks )['blockName'] ?? null ) && '' === trim( (string) ( end( $blocks )['innerHTML'] ?? '' ) ) ) {
			array_pop( $blocks );
		}

		return array_values( $blocks );
	}

	private static function named( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( null !== ( $block['blockName'] ?? null ) || '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	private static function paragraph( string $text ): array {
		$html = '<p>' . htmlspecialchars( trim( $text ), ENT_QUOTES, 'UTF-8' ) . '</p>';

		return [
			'blockName'    => 'core/paragraph',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
		];
	}
}
