<?php
/**
 * Transform (b): robust YAML front matter.
 *
 * The stock importer strips front matter with `preg_replace( '#^---(.+)---#Us', '', $markdown )`
 * (wporg-markdown inc/class-importer.php line 483). That regex ends the block at the first `---`
 * anywhere (so a value containing `---` corrupts the page) and fails on a UTF-8 BOM (so the front
 * matter is rendered as a horizontal rule and a heading). See notes/wporg-handbook-pipeline/research/
 * 04-importer-spike.md section 2.
 *
 * This file is pure PHP with no WordPress dependency so it can be tested with the plain `php` CLI.
 *
 * @package WP_Docs
 */

namespace WP_Docs\Transforms\Front_Matter;

/**
 * Split a Markdown document into front matter and body.
 *
 * Front matter is recognised only when the document starts (after an optional UTF-8 BOM) with a line
 * that is exactly `---`, and it ends at the next line that is exactly `---` (or `...`). A `---` inside
 * a value is therefore safe. Line endings may be LF or CRLF; the body keeps its own line endings, minus
 * the BOM.
 *
 * @param string $markdown Raw file contents.
 * @return array{0: array, 1: string, 2: bool} Parsed data, body, and whether front matter was present.
 */
function split( string $markdown ): array {
	if ( 0 === strncmp( $markdown, "\xEF\xBB\xBF", 3 ) ) {
		$markdown = substr( $markdown, 3 );
	}

	if ( ! preg_match( '/\A---[ \t]*\r?\n/', $markdown, $open ) ) {
		return array( array(), $markdown, false );
	}

	$offset = strlen( $open[0] );
	if ( ! preg_match( '/^(?:---|\.\.\.)[ \t]*(?:\r?\n|\z)/m', $markdown, $close, PREG_OFFSET_CAPTURE, $offset ) ) {
		// An opening fence with no closing fence is not front matter (it is a thematic break).
		return array( array(), $markdown, false );
	}

	$yaml = substr( $markdown, $offset, $close[0][1] - $offset );
	$body = substr( $markdown, $close[0][1] + strlen( $close[0][0] ) );

	return array( parse_yaml( $yaml ), $body, true );
}

/**
 * Parse the YAML subset the hub's front matter uses.
 *
 * Uses the `yaml` PECL extension when it is loaded. Otherwise a small parser handles: `key: value`
 * scalars (plain, single- or double-quoted, with `#` comments), flow lists `[a, b]`, flow maps
 * `{by: x, from: y}`, block lists (`- item`), one level of nested block maps, block scalars (`|` and
 * `>`), and `null`/`~`/booleans/integers. Anything it does not understand is kept as a string, never
 * dropped silently.
 *
 * @param string $yaml YAML text without the `---` fences.
 * @return array
 */
function parse_yaml( string $yaml ): array {
	if ( function_exists( 'yaml_parse' ) ) {
		$parsed = @yaml_parse( $yaml ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid YAML falls back to the subset parser.
		if ( is_array( $parsed ) ) {
			return $parsed;
		}
	}

	$lines  = preg_split( '/\r?\n/', $yaml );
	$result = array();
	$count  = count( $lines );

	for ( $i = 0; $i < $count; $i++ ) {
		$line = $lines[ $i ];
		if ( '' === trim( $line ) || preg_match( '/^\s*#/', $line ) ) {
			continue;
		}
		if ( ! preg_match( '/^([A-Za-z0-9_][A-Za-z0-9_\-]*)\s*:(?:\s+(.*)|\s*)$/', $line, $m ) ) {
			continue;
		}
		$key   = $m[1];
		$value = isset( $m[2] ) ? strip_comment( $m[2] ) : '';

		if ( '' === $value ) {
			// A block list or a nested block map follows (more indented lines).
			$children = array();
			while ( $i + 1 < $count && ( '' === trim( $lines[ $i + 1 ] ) || preg_match( '/^(\s+|-\s)/', $lines[ $i + 1 ] ) ) ) {
				$children[] = $lines[ ++$i ];
			}
			$result[ $key ] = parse_block_children( $children );
			continue;
		}

		if ( '|' === $value[0] || '>' === $value[0] ) {
			$block = array();
			while ( $i + 1 < $count && ( '' === trim( $lines[ $i + 1 ] ) || preg_match( '/^\s+/', $lines[ $i + 1 ] ) ) ) {
				$block[] = $lines[ ++$i ];
			}
			$result[ $key ] = block_scalar( $block, '>' === $value[0] );
			continue;
		}

		$result[ $key ] = parse_inline( $value );
	}

	return $result;
}

/**
 * Parse the indented lines under a key with an empty value.
 *
 * @param string[] $lines Lines.
 * @return array|null
 */
function parse_block_children( array $lines ) {
	$lines = array_values( array_filter( $lines, static fn( $l ) => '' !== trim( $l ) ) );
	if ( ! $lines ) {
		return null;
	}

	if ( preg_match( '/^\s*-(\s|$)/', $lines[0] ) ) {
		$list = array();
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*-\s*(.*)$/', $line, $m ) ) {
				$list[] = parse_inline( strip_comment( $m[1] ) );
			}
		}
		return $list;
	}

	$map = array();
	foreach ( $lines as $line ) {
		if ( preg_match( '/^\s+([A-Za-z0-9_][A-Za-z0-9_\-]*)\s*:\s*(.*)$/', $line, $m ) ) {
			$map[ $m[1] ] = parse_inline( strip_comment( $m[2] ) );
		}
	}
	return $map;
}

/**
 * Join a block scalar.
 *
 * @param string[] $lines  Indented lines.
 * @param bool     $folded `>` (folded) rather than `|` (literal).
 * @return string
 */
function block_scalar( array $lines, bool $folded ): string {
	$indent = null;
	foreach ( $lines as $line ) {
		if ( '' !== trim( $line ) ) {
			$len    = strlen( $line ) - strlen( ltrim( $line ) );
			$indent = null === $indent ? $len : min( $indent, $len );
		}
	}
	$lines = array_map( static fn( $l ) => (string) substr( $l, (int) $indent ), $lines );
	$text  = rtrim( implode( "\n", $lines ) );

	return $folded ? preg_replace( '/(?<!\n)\n(?!\n)/', ' ', $text ) : $text;
}

/**
 * Remove a trailing ` # comment` that is not inside quotes.
 *
 * @param string $value Raw value.
 * @return string
 */
function strip_comment( string $value ): string {
	$value = trim( $value );
	if ( '' === $value ) {
		return $value;
	}
	if ( '"' === $value[0] && preg_match( '/^"(?:[^"\\\\]|\\\\.)*"/', $value, $m ) ) {
		return $m[0];
	}
	if ( "'" === $value[0] && preg_match( "/^'(?:[^']|'')*'/", $value, $m ) ) {
		return $m[0];
	}
	$pos = strpos( $value, ' #' );
	return false === $pos ? $value : rtrim( substr( $value, 0, $pos ) );
}

/**
 * Parse an inline YAML value.
 *
 * @param string $value Value text.
 * @return mixed
 */
function parse_inline( string $value ) {
	$value = trim( $value );
	if ( '' === $value ) {
		return '';
	}

	$first = $value[0];
	$last  = substr( $value, -1 );

	if ( '[' === $first && ']' === $last ) {
		$inner = trim( substr( $value, 1, -1 ) );
		return '' === $inner ? array() : array_map( __NAMESPACE__ . '\parse_inline', split_flow( $inner ) );
	}

	if ( '{' === $first && '}' === $last ) {
		$map   = array();
		$inner = trim( substr( $value, 1, -1 ) );
		if ( '' === $inner ) {
			return $map;
		}
		foreach ( split_flow( $inner ) as $pair ) {
			if ( preg_match( '/^\s*("[^"]*"|\'[^\']*\'|[^:]+?)\s*:\s*(.*)$/s', $pair, $m ) ) {
				$map[ (string) parse_inline( $m[1] ) ] = parse_inline( $m[2] );
			}
		}
		return $map;
	}

	if ( '"' === $first && '"' === $last && strlen( $value ) >= 2 ) {
		$decoded = json_decode( $value );
		return is_string( $decoded ) ? $decoded : stripcslashes( substr( $value, 1, -1 ) );
	}

	if ( "'" === $first && "'" === $last && strlen( $value ) >= 2 ) {
		return str_replace( "''", "'", substr( $value, 1, -1 ) );
	}

	$lower = strtolower( $value );
	if ( in_array( $lower, array( 'null', '~' ), true ) ) {
		return null;
	}
	if ( in_array( $lower, array( 'true', 'yes' ), true ) ) {
		return true;
	}
	if ( in_array( $lower, array( 'false', 'no' ), true ) ) {
		return false;
	}
	if ( preg_match( '/^-?\d+$/', $value ) ) {
		return (int) $value;
	}

	return $value;
}

/**
 * Split a flow collection's inner text on top-level commas, respecting quotes and nesting.
 *
 * @param string $inner Text between the brackets.
 * @return string[]
 */
function split_flow( string $inner ): array {
	$parts = array();
	$depth = 0;
	$quote = '';
	$buf   = '';
	$len   = strlen( $inner );

	for ( $i = 0; $i < $len; $i++ ) {
		$c = $inner[ $i ];
		if ( '' !== $quote ) {
			if ( $c === $quote ) {
				$quote = '';
			}
		} elseif ( '"' === $c || "'" === $c ) {
			$quote = $c;
		} elseif ( '[' === $c || '{' === $c ) {
			++$depth;
		} elseif ( ']' === $c || '}' === $c ) {
			--$depth;
		} elseif ( ',' === $c && 0 === $depth ) {
			$parts[] = trim( $buf );
			$buf     = '';
			continue;
		}
		$buf .= $c;
	}
	if ( '' !== trim( $buf ) ) {
		$parts[] = trim( $buf );
	}

	return $parts;
}

/**
 * Normalise the front matter fields of the content repository (its conventions/front-matter.md).
 *
 * @param array $data Parsed front matter.
 * @return array{id: string, title: string, description: string, type: string, status: string, updated: string, redirect_from: string[], applies_to: string[]}
 */
function normalize( array $data ): array {
	$list = static function ( $value ): array {
		if ( null === $value || '' === $value ) {
			return array();
		}
		$value = is_array( $value ) ? $value : array( $value );
		return array_values( array_filter( array_map( 'strval', $value ), 'strlen' ) );
	};
	$text = static fn( $key ) => isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? trim( (string) $data[ $key ] ) : '';

	return array(
		'id'            => $text( 'id' ),
		'title'         => $text( 'title' ),
		'description'   => $text( 'description' ),
		'type'          => $text( 'type' ),
		'status'        => $text( 'status' ),
		'updated'       => $text( 'updated' ),
		'redirect_from' => $list( $data['redirect_from'] ?? null ),
		'applies_to'    => $list( $data['applies_to'] ?? null ),
	);
}
