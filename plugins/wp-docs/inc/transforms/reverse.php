<?php
/**
 * Reverse sync, step 3: merge the blocks saved in the editor into the Markdown source.
 *
 * Three inputs: the Markdown body the page was imported from (the baseline), the blocks the editor
 * saved (live), and a converter that turns a piece of Markdown into blocks the same way the import
 * does. The merge:
 *
 * 1. splits the baseline into chunks (chunks.php) and converts each chunk on its own, so every
 *    baseline block knows which chunk of source text produced it;
 * 2. fingerprints baseline and live blocks by meaning (name, attributes that matter, the saved HTML
 *    with whitespace and entities normalised) and takes the longest common subsequence;
 * 3. walks the baseline chunks in order: a chunk whose blocks all survived, in order and together,
 *    is copied byte for byte; blocks the editor added, changed or reordered are written with
 *    markdown-out.php; a chunk whose blocks are all gone is dropped.
 *
 * The result is a Markdown body whose diff against the source is the editor's change and nothing
 * else. The caller verifies it by converting the result and comparing fingerprints with the live
 * blocks (verify()), and refuses the save when they differ.
 *
 * Pure PHP.
 *
 * @package WP_Docs
 */

namespace WP_Docs\Transforms\Reverse;

use WP_Docs\Transforms\Chunks;
use WP_Docs\Transforms\Markdown_Out;

/**
 * Merge the live blocks into the baseline Markdown body.
 *
 * @param string   $baseline Baseline Markdown body (no front matter, no title H1).
 * @param array[]  $live     Live blocks (parsed; Markdown_Out\parse()).
 * @param callable $convert  fn( string $markdown ): string. Block markup for a piece of Markdown.
 * @param array    $options  Markdown_Out options (link, image, alert_map).
 * @return array {
 *     @type string $markdown   The merged body, no trailing newline.
 *     @type int    $verbatim   Chunks copied unchanged.
 *     @type int    $rewritten  Chunks rewritten from blocks.
 *     @type int    $added      Blocks added outside any chunk.
 *     @type int    $removed    Chunks dropped.
 *     @type array  $warnings   From Markdown_Out.
 * }
 */
function merge( string $baseline, array $live, callable $convert, array $options = array() ): array {
	$chunks = Chunks\chunk( $baseline );
	$base   = array(); // Flat list of baseline blocks, each with its chunk index.
	foreach ( $chunks as $ci => $chunk ) {
		$blocks = 'comment' === $chunk['kind'] ? array() : Markdown_Out\parse( $convert( $chunk['text'] ) );
		$chunks[ $ci ]['start'] = count( $base );
		foreach ( $blocks as $block ) {
			$base[] = array(
				'chunk' => $ci,
				'fp'    => fingerprint( $block ),
			);
		}
		$chunks[ $ci ]['end'] = count( $base );
	}

	$live_fp = array_map( __NAMESPACE__ . '\fingerprint', $live );
	$pairs   = lcs( array_column( $base, 'fp' ), $live_fp ); // base index => live index.
	$matched = array_flip( $pairs );                          // live index => base index.

	$out      = array();     // Pieces of output: [ text, verbatim?, gap ].
	$warnings = array();
	$stats    = array(
		'verbatim'  => 0,
		'rewritten' => 0,
		'added'     => 0,
		'removed'   => 0,
	);
	$l        = 0;
	$total    = count( $live );

	$emit_live = static function ( int $from, int $to, string $hint = '' ) use ( &$out, &$warnings, &$stats, $live, $options ) {
		for ( $k = $from; $k <= $to; $k++ ) {
			$md = Markdown_Out\block_to_markdown( $live[ $k ], array_merge( $options, array( 'hint' => $hint ) ), $warnings );
			if ( '' !== $md ) {
				$out[] = array( $md, false, '' );
			}
		}
	};

	$chunk_count = count( $chunks );
	for ( $ci = 0; $ci < $chunk_count; $ci++ ) {
		$chunk = $chunks[ $ci ];
		$s     = $chunk['start'];
		$e     = $chunk['end'];

		if ( $e === $s ) {
			// A comment, or Markdown that produces no block: keep it where it was.
			$out[] = array( $chunk['text'], true, $chunk['gap'] );
			continue;
		}

		$first_live = null;
		$last_live  = null;
		for ( $b = $s; $b < $e; $b++ ) {
			if ( isset( $pairs[ $b ] ) ) {
				$first_live = null === $first_live ? $pairs[ $b ] : min( $first_live, $pairs[ $b ] );
				$last_live  = null === $last_live ? $pairs[ $b ] : max( $last_live, $pairs[ $b ] );
			}
		}

		if ( null === $first_live ) {
			// None of the chunk's blocks survived. The run of such chunks from here, together with the
			// live blocks up to the next surviving block, is one replacement: a removal when there are
			// no new live blocks, a rewrite otherwise (with the source as a hint when it is one for one).
			$run_end = $ci;
			while ( $run_end + 1 < $chunk_count && $chunks[ $run_end + 1 ]['end'] > $chunks[ $run_end + 1 ]['start'] && ! chunk_has_match( $chunks[ $run_end + 1 ], $pairs ) ) {
				++$run_end;
			}
			$anchor = $total;
			for ( $b = $chunks[ $run_end ]['end']; $b < count( $base ); $b++ ) {
				if ( isset( $pairs[ $b ] ) ) {
					$anchor = $pairs[ $b ];
					break;
				}
			}
			$removed_chunks = $run_end - $ci + 1;
			if ( $anchor > $l ) {
				$stats['rewritten'] += $removed_chunks;
				$hint = ( 1 === $removed_chunks && 1 === $e - $s && 1 === $anchor - $l ) ? $chunk['text'] : '';
				$emit_live( $l, $anchor - 1, $hint );
				$l = $anchor;
			} else {
				$stats['removed'] += $removed_chunks;
			}
			$ci = $run_end;
			continue;
		}

		// Live blocks before this chunk's first surviving block are new.
		if ( $first_live > $l ) {
			$stats['added'] += $first_live - $l;
			$emit_live( $l, $first_live - 1 );
			$l = $first_live;
		}

		$intact = true;
		for ( $b = $s; $intact && $b < $e; $b++ ) {
			if ( ! isset( $pairs[ $b ] ) || $pairs[ $b ] !== $l + ( $b - $s ) ) {
				$intact = false;
			}
		}

		if ( $intact ) {
			$out[] = array( $chunk['text'], true, $chunk['gap'] );
			$l    += $e - $s;
			++$stats['verbatim'];
			continue;
		}

		// Part of the chunk changed: rewrite the live span it covers.
		++$stats['rewritten'];
		$emit_live( $l, $last_live );
		$l = $last_live + 1;
	}

	if ( $l < $total ) {
		$stats['added'] += $total - $l;
		$emit_live( $l, $total - 1 );
	}

	// Join: the file's own blank lines between two verbatim chunks, one blank line everywhere else.
	$markdown = '';
	$previous = null;
	foreach ( $out as $i => $piece ) {
		list( $text, $verbatim, $gap ) = $piece;
		if ( 0 === $i ) {
			$markdown = $text;
		} elseif ( $verbatim && $previous && '' !== $gap ) {
			$markdown .= "\n" . $gap . $text;
		} else {
			$markdown .= "\n\n" . $text;
		}
		$previous = $verbatim;
	}

	return array_merge(
		$stats,
		array(
			'markdown' => $markdown,
			'warnings' => $warnings,
		)
	);
}

/**
 * Whether any block of a chunk survived in the live blocks.
 *
 * @param array $chunk Chunk with start and end.
 * @param array $pairs LCS pairs.
 * @return bool
 */
function chunk_has_match( array $chunk, array $pairs ): bool {
	for ( $b = $chunk['start']; $b < $chunk['end']; $b++ ) {
		if ( isset( $pairs[ $b ] ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Compare the blocks a Markdown body converts to with the live blocks.
 *
 * @param string   $markdown Merged Markdown body.
 * @param array[]  $live     Live blocks.
 * @param callable $convert  The converter used by merge().
 * @return array|null Null when they match; else { index, expected, actual } for the first difference
 *                    (expected and actual are block names, or "(none)").
 */
function verify( string $markdown, array $live, callable $convert ): ?array {
	$result  = Markdown_Out\parse( $convert( $markdown ) );
	$live_fp = array_map( __NAMESPACE__ . '\fingerprint', $live );
	$res_fp  = array_map( __NAMESPACE__ . '\fingerprint', $result );
	$count   = max( count( $live_fp ), count( $res_fp ) );

	for ( $i = 0; $i < $count; $i++ ) {
		if ( ( $live_fp[ $i ] ?? null ) !== ( $res_fp[ $i ] ?? null ) ) {
			return array(
				'index'    => $i,
				'expected' => isset( $live[ $i ] ) ? (string) $live[ $i ]['blockName'] : '(none)',
				'actual'   => isset( $result[ $i ] ) ? (string) $result[ $i ]['blockName'] : '(none)',
				'live'     => $live_fp[ $i ] ?? '',
				'result'   => $res_fp[ $i ] ?? '',
			);
		}
	}
	return null;
}

/**
 * A block's fingerprint: what has to be equal for two blocks to mean the same thing.
 *
 * Attributes the editor may add or drop without a visible change are ignored; the saved HTML is
 * compared with entities decoded, whitespace collapsed and self-closing slashes dropped.
 *
 * @param array $block Parsed block.
 * @return string
 */
function fingerprint( array $block ): string {
	$name  = (string) $block['blockName'];
	$attrs = (array) $block['attrs'];
	foreach ( array( 'className', 'lock', 'metadata', 'sizeSlug', 'width', 'height', 'aspectRatio', 'scale', 'linkDestination', 'anchor', 'align', 'style', 'fontSize', 'backgroundColor', 'textColor' ) as $drop ) {
		unset( $attrs[ $drop ] );
	}
	if ( isset( $attrs['ordered'] ) && ! $attrs['ordered'] ) {
		unset( $attrs['ordered'] );
	}
	ksort( $attrs );

	return $name . '|' . json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '|' . normalize_html( Markdown_Out\flatten( $block ) );
}

/**
 * HTML normalised for comparison.
 *
 * @param string $html HTML.
 * @return string
 */
function normalize_html( string $html ): string {
	$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$html = preg_replace( '/\s*\/>/', '>', $html );
	$html = preg_replace( '/<(h[1-6])\b([^>]*)\sid="[^"]*"/', '<$1$2', $html );
	$html = preg_replace( '/>\s+</', '><', $html );
	$html = preg_replace( '/\s+/', ' ', $html );
	$html = preg_replace( '/<(\w+)[^>]*\bclass=""[^>]*>/', '<$1>', $html );
	return trim( $html );
}

/**
 * Longest common subsequence of two fingerprint lists.
 *
 * @param string[] $a Baseline fingerprints.
 * @param string[] $b Live fingerprints.
 * @return array<int,int> a index => b index, in increasing order on both sides.
 */
function lcs( array $a, array $b ): array {
	$n = count( $a );
	$m = count( $b );
	if ( ! $n || ! $m ) {
		return array();
	}

	$table = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
	for ( $i = $n - 1; $i >= 0; $i-- ) {
		for ( $j = $m - 1; $j >= 0; $j-- ) {
			$table[ $i ][ $j ] = $a[ $i ] === $b[ $j ]
				? $table[ $i + 1 ][ $j + 1 ] + 1
				: max( $table[ $i + 1 ][ $j ], $table[ $i ][ $j + 1 ] );
		}
	}

	$pairs = array();
	$i     = 0;
	$j     = 0;
	while ( $i < $n && $j < $m ) {
		if ( $a[ $i ] === $b[ $j ] ) {
			$pairs[ $i ] = $j;
			++$i;
			++$j;
		} elseif ( $table[ $i + 1 ][ $j ] >= $table[ $i ][ $j + 1 ] ) {
			++$i;
		} else {
			++$j;
		}
	}
	return $pairs;
}

/*
 * ---------------------------------------------------------------------------------------------
 * The whole file: front matter, title, body.
 * ---------------------------------------------------------------------------------------------
 */

/**
 * Split a raw Markdown file into its three parts without reformatting any of them.
 *
 * @param string $raw Raw file.
 * @return array { front_matter: string (the `---` block with its trailing newline, or ""), title: string,
 *                 title_line: string (the `# ` line as written, or ""), body: string, eol: string }
 */
function split_file( string $raw ): array {
	$eol = false !== strpos( $raw, "\r\n" ) ? "\r\n" : "\n";
	$raw = str_replace( "\r\n", "\n", ltrim( $raw, "\xEF\xBB\xBF" ) );

	$front = '';
	if ( preg_match( '/\A---[ \t]*\n(.*?\n)?---[ \t]*\n/s', $raw, $m ) ) {
		$front = $m[0];
		$raw   = substr( $raw, strlen( $front ) );
	}

	$title      = '';
	$title_line = '';
	$body       = $raw;
	if ( preg_match( '/\A\s*(# (.+))\n?/', $raw, $m ) ) {
		$title_line = $m[1];
		$title      = trim( $m[2] );
		$body       = substr( $raw, strlen( $m[0] ) );
	}

	return array(
		'front_matter' => $front,
		'title'        => $title,
		'title_line'   => $title_line,
		'body'         => ltrim( $body, "\n" ),
		'eol'          => $eol,
	);
}

/**
 * Put a file back together.
 *
 * @param array  $parts From split_file(), possibly with `front_matter` and `title` changed.
 * @param string $body  The merged body.
 * @return string Raw file with a trailing newline.
 */
function join_file( array $parts, string $body ): string {
	$raw = $parts['front_matter'];
	if ( '' !== $parts['title'] ) {
		$raw .= ( '' !== $raw ? "\n" : '' ) . '# ' . $parts['title'] . "\n\n";
	}
	$raw .= rtrim( $body, "\n" ) . "\n";
	return "\n" === $parts['eol'] ? $raw : str_replace( "\n", $parts['eol'], $raw );
}

/**
 * Replace a scalar field in a front matter block, keeping every other line as written. Adds the
 * field when it is missing.
 *
 * @param string $front_matter The `---` block.
 * @param string $key          Field.
 * @param string $value        New value (plain text).
 * @return string
 */
function set_front_matter_field( string $front_matter, string $key, string $value ): string {
	$scalar = yaml_scalar( $value );
	$line   = $key . ': ' . $scalar;
	$count  = 0;
	$out    = preg_replace( '/^' . preg_quote( $key, '/' ) . ':[^\n]*(\n(?:[ \t]+[^\n]*)?)*$/m', $line, $front_matter, 1, $count );
	if ( $count ) {
		return $out;
	}
	if ( '' === $front_matter ) {
		return "---\n{$line}\n---\n";
	}
	return preg_replace( '/\n---[ \t]*\n\z/', "\n{$line}\n---\n", $front_matter, 1 );
}

/**
 * A YAML scalar: plain when it is safe, double-quoted otherwise.
 *
 * @param string $value Value.
 * @return string
 */
function yaml_scalar( string $value ): string {
	if ( '' === $value || preg_match( '/^[\s\-?:,\[\]{}#&*!|>\'"%@`]|[:#]\s|\s$|^(true|false|null|yes|no|on|off|~)$|^[\d.+-]+$/i', $value ) ) {
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
	}
	return $value;
}
