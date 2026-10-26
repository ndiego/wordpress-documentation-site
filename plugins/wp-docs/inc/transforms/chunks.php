<?php
/**
 * Reverse sync, step 1: split a Markdown body into top-level chunks.
 *
 * A chunk is the smallest run of lines the forward pipeline turns into whole blocks: one paragraph,
 * one list (with its nested lists and continuation lines), one fenced code block, one table, one
 * blockquote or alert, one heading, one `<details>` element, one HTML comment. The merge (reverse.php)
 * converts each chunk on its own to learn which blocks it produces, then keeps the chunk's text
 * byte for byte when none of those blocks changed in the editor.
 *
 * The rules follow CommonMark's block starts closely enough for the content conventions
 * (conventions/markdown.md in the content repository): ATX headings, `-` bullets, `1.` steps, backtick
 * fences, pipe tables with a delimiter row, `>` quotes. Setext headings and indented code are not
 * recognised (the conventions forbid them); they end up inside paragraph chunks, which still round-trip
 * because the merge only regenerates a chunk whose blocks changed.
 *
 * Pure PHP, no WordPress dependency.
 *
 * @package WP_Docs
 */

namespace WP_Docs\Transforms\Chunks;

const INLINE_TAGS = array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'i', 'img', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr' );

/**
 * Split a Markdown body into chunks.
 *
 * @param string $markdown Markdown body (no front matter).
 * @return array[] Each `{ kind: string, text: string, gap: string }`. `gap` is the exact text of the
 *                 blank lines before the chunk (the file's own spacing), `text` has no trailing newline.
 */
function chunk( string $markdown ): array {
	$lines  = preg_split( '/\r\n|\r|\n/', $markdown );
	$count  = count( $lines );
	$chunks = array();
	$i      = 0;
	$gap    = array();

	while ( $i < $count ) {
		$line = $lines[ $i ];
		if ( '' === trim( $line ) ) {
			$gap[] = $line;
			++$i;
			continue;
		}

		$kind  = kind_of( $line, $lines[ $i + 1 ] ?? null );
		$start = $i;
		$i     = end_of( $kind, $lines, $i );

		$chunks[] = array(
			'kind' => $kind,
			'text' => implode( "\n", array_slice( $lines, $start, $i - $start ) ),
			'gap'  => $gap ? implode( "\n", $gap ) . "\n" : '',
		);
		$gap      = array();
	}

	return $chunks;
}

/**
 * Which kind of chunk a line starts.
 *
 * @param string      $line Line.
 * @param string|null $next Following line (for tables).
 * @return string fence|comment|details|html|heading|hr|quote|list|table|paragraph
 */
function kind_of( string $line, ?string $next ): string {
	if ( is_fence_start( $line ) ) {
		return 'fence';
	}
	if ( preg_match( '/^ {0,3}<!--/', $line ) ) {
		return 'comment';
	}
	if ( preg_match( '/^ {0,3}<details\b/i', $line ) ) {
		return 'details';
	}
	if ( preg_match( '/^ {0,3}<\/?([A-Za-z][A-Za-z0-9-]*)/', $line, $m ) && ! in_array( strtolower( $m[1] ), INLINE_TAGS, true ) ) {
		return 'html';
	}
	if ( preg_match( '/^ {0,3}#{1,6}(\s|$)/', $line ) ) {
		return 'heading';
	}
	if ( is_hr( $line ) ) {
		return 'hr';
	}
	if ( is_alert_start( $line ) ) {
		return 'alert';
	}
	if ( preg_match( '/^ {0,3}>/', $line ) ) {
		return 'quote';
	}
	if ( null !== list_marker( $line ) ) {
		return 'list';
	}
	if ( null !== $next && false !== strpos( $line, '|' ) && is_table_delimiter( $next ) ) {
		return 'table';
	}
	return 'paragraph';
}

/**
 * Index of the line after the chunk that starts at $i.
 *
 * @param string   $kind  Chunk kind.
 * @param string[] $lines Lines.
 * @param int      $i     Start index.
 * @return int
 */
function end_of( string $kind, array $lines, int $i ): int {
	$count = count( $lines );

	switch ( $kind ) {
		case 'fence':
			preg_match( '/^ {0,3}(`{3,}|~{3,})/', $lines[ $i ], $m );
			$fence = $m[1];
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( preg_match( '/^ {0,3}' . preg_quote( $fence[0], '/' ) . '{' . strlen( $fence ) . ',}\s*$/', $lines[ $j ] ) ) {
					return $j + 1;
				}
			}
			return $count;

		case 'comment':
			for ( $j = $i; $j < $count; $j++ ) {
				if ( false !== strpos( $lines[ $j ], '-->' ) ) {
					return $j + 1;
				}
			}
			return $count;

		case 'details':
			$depth = 0;
			for ( $j = $i; $j < $count; $j++ ) {
				$depth += preg_match_all( '/<details\b/i', $lines[ $j ] );
				$depth -= preg_match_all( '/<\/details>/i', $lines[ $j ] );
				if ( $depth <= 0 ) {
					return $j + 1;
				}
			}
			return $count;

		case 'heading':
		case 'hr':
			return $i + 1;

		case 'list':
			return end_of_list( $lines, $i );

		case 'alert':
			// `>` lines and lazy continuation lines, up to a blank line (alerts.php stops there too).
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( '' === trim( $lines[ $j ] ) ) {
					return $j;
				}
				if ( ! preg_match( '/^ {0,3}>/', $lines[ $j ] ) && 'paragraph' !== kind_of( $lines[ $j ], $lines[ $j + 1 ] ?? null ) ) {
					return $j;
				}
			}
			return $count;

		case 'quote':
			// Like the alert, but the parser joins `>` paragraphs separated by blank lines into one
			// blockquote, so the chunk continues while the next non-blank line is a plain `>` line.
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( '' === trim( $lines[ $j ] ) ) {
					$k = $j;
					while ( $k < $count && '' === trim( $lines[ $k ] ) ) {
						++$k;
					}
					if ( $k < $count && preg_match( '/^ {0,3}>/', $lines[ $k ] ) && ! is_alert_start( $lines[ $k ] ) ) {
						$j = $k;
						continue;
					}
					return $j;
				}
				if ( ! preg_match( '/^ {0,3}>/', $lines[ $j ] ) && 'paragraph' !== kind_of( $lines[ $j ], $lines[ $j + 1 ] ?? null ) ) {
					return $j;
				}
			}
			return $count;

		case 'html':
		case 'table':
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( '' === trim( $lines[ $j ] ) ) {
					return $j;
				}
			}
			return $count;

		default:
			// Paragraph: up to a blank line or a line that starts another block.
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$l = $lines[ $j ];
				if ( '' === trim( $l ) ) {
					return $j;
				}
				if ( is_fence_start( $l ) || preg_match( '/^ {0,3}#{1,6}(\s|$)/', $l ) || is_hr( $l ) || preg_match( '/^ {0,3}>/', $l ) ) {
					return $j;
				}
				if ( preg_match( '/^ {0,3}<(!--|details\b|\/?([A-Za-z][A-Za-z0-9-]*))/i', $l, $m ) && ( ! isset( $m[2] ) || '' === $m[2] || ! in_array( strtolower( $m[2] ), INLINE_TAGS, true ) ) ) {
					return $j;
				}
				$marker = list_marker( $l );
				if ( null !== $marker && ( 'bullet' === $marker['type'] || '1' === $marker['number'] ) ) {
					return $j;
				}
				if ( false !== strpos( $l, '|' ) && isset( $lines[ $j + 1 ] ) && is_table_delimiter( $lines[ $j + 1 ] ) ) {
					return $j;
				}
			}
			return $count;
	}
}

/**
 * End of a list: items, indented continuation, and blank lines that are followed by more of the list.
 *
 * @param string[] $lines Lines.
 * @param int      $i     Start index (a marker line).
 * @return int
 */
function end_of_list( array $lines, int $i ): int {
	$count = count( $lines );
	$first = list_marker( $lines[ $i ] );
	$j     = $i + 1;
	$fence = '';

	while ( $j < $count ) {
		$line = $lines[ $j ];

		if ( '' !== $fence ) {
			if ( preg_match( '/^\s*' . preg_quote( $fence[0], '/' ) . '{' . strlen( $fence ) . ',}\s*$/', $line ) ) {
				$fence = '';
			}
			++$j;
			continue;
		}
		if ( preg_match( '/^\s+(`{3,}|~{3,})/', $line, $m ) ) {
			$fence = $m[1];
			++$j;
			continue;
		}

		if ( '' === trim( $line ) ) {
			// Look past the blank lines: the list goes on when the next line is indented or a marker.
			$k = $j;
			while ( $k < $count && '' === trim( $lines[ $k ] ) ) {
				++$k;
			}
			if ( $k >= $count ) {
				return $j;
			}
			$next   = $lines[ $k ];
			$marker = list_marker( $next );
			if ( preg_match( '/^\s+\S/', $next ) || ( null !== $marker && $marker['type'] === $first['type'] && $marker['indent'] <= 3 ) ) {
				$j = $k;
				continue;
			}
			return $j;
		}

		$marker = list_marker( $line );
		if ( null !== $marker && 0 === $marker['indent'] && $marker['type'] !== $first['type'] ) {
			return $j; // A bullet list after a numbered one (or the reverse) is a new list.
		}
		if ( null === $marker && ! preg_match( '/^\s/', $line ) && 'paragraph' !== kind_of( $line, $lines[ $j + 1 ] ?? null ) ) {
			return $j; // A heading, fence, quote or table at column 0 ends the list.
		}
		++$j;
	}

	return $count;
}

/**
 * Parse a list marker at the start of a line.
 *
 * @param string $line Line.
 * @return array|null { type: bullet|ordered, indent: int, number: string, width: int } or null.
 */
function list_marker( string $line ): ?array {
	if ( preg_match( '/^( {0,3})([-*+])( +|$)/', $line, $m ) ) {
		if ( is_hr( $line ) ) {
			return null;
		}
		return array(
			'type'   => 'bullet',
			'indent' => strlen( $m[1] ),
			'number' => '',
			'width'  => strlen( $m[2] ) + max( 1, strlen( $m[3] ) ),
		);
	}
	if ( preg_match( '/^( {0,3})(\d{1,9})([.)])( +|$)/', $line, $m ) ) {
		return array(
			'type'   => 'ordered',
			'indent' => strlen( $m[1] ),
			'number' => $m[2],
			'width'  => strlen( $m[2] ) + 1 + max( 1, strlen( $m[4] ) ),
		);
	}
	return null;
}

/**
 * Whether a line starts a GitHub alert (`> [!NOTE]`).
 *
 * @param string $line Line.
 * @return bool
 */
function is_alert_start( string $line ): bool {
	return (bool) preg_match( '/^ {0,3}>[ \t]?\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\][ \t]*$/i', $line );
}

/**
 * Whether a line opens a fence.
 *
 * @param string $line Line.
 * @return bool
 */
function is_fence_start( string $line ): bool {
	return (bool) preg_match( '/^ {0,3}(`{3,}[^`]*|~{3,}.*)$/', $line );
}

/**
 * Whether a line is a thematic break.
 *
 * @param string $line Line.
 * @return bool
 */
function is_hr( string $line ): bool {
	return (bool) preg_match( '/^ {0,3}([-*_])( *\1){2,} *$/', $line );
}

/**
 * Whether a line is a GFM table delimiter row (`|---|:---:|`).
 *
 * @param string $line Line.
 * @return bool
 */
function is_table_delimiter( string $line ): bool {
	return (bool) preg_match( '/^ {0,3}\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/', $line ) && false !== strpos( $line, '-' );
}
