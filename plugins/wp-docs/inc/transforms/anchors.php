<?php
/**
 * Heading anchors: every heading gets the `id` GitHub gives it, so `page.md#some-heading` links written
 * against GitHub work on the site, the theme's table of contents and the search index share one set of
 * anchors, and nobody has to compute them twice.
 *
 * The slug follows github-slugger: lowercase, drop everything that is not a letter, number, mark, space,
 * hyphen or underscore, spaces become hyphens, and repeats get `-1`, `-2`. A heading that already has an
 * id (`{#custom}` in Markdown Extra) keeps it.
 *
 * Pure PHP, no WordPress dependency.
 *
 * @package WP_Docs
 */

namespace WP_Docs\Transforms\Anchors;

/**
 * GitHub's anchor for a heading text.
 *
 * @param string $text Plain heading text.
 * @return string
 */
function slug( string $text ): string {
	$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	$text = preg_replace( '/[^\p{L}\p{M}\p{N}\p{Pc} \-]/u', '', $text );
	return str_replace( ' ', '-', (string) $text );
}

/**
 * Plain text of a heading's inner HTML.
 *
 * @param string $html Inner HTML.
 * @return string
 */
function heading_text( string $html ): string {
	$text = preg_replace( '#<[^>]*>#', '', $html );
	return trim( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/**
 * Add GitHub-style ids to headings that have none.
 *
 * @param string $html HTML.
 * @return string
 */
function add_ids( string $html ): string {
	$used = array();
	preg_match_all( '#<h[1-6]\b[^>]*\sid="([^"]*)"#i', $html, $existing );
	foreach ( $existing[1] as $id ) {
		$used[ $id ] = 0;
	}

	return preg_replace_callback(
		'#<h([1-6])(\s[^>]*)?>(.*?)</h\1>#is',
		static function ( $m ) use ( &$used ) {
			$attrs = $m[2] ?? '';
			if ( preg_match( '#\sid="#i', $attrs ) ) {
				return $m[0];
			}
			$base = slug( heading_text( $m[3] ) );
			if ( '' === $base ) {
				return $m[0];
			}
			$id = $base;
			if ( isset( $used[ $base ] ) ) {
				do {
					$id = $base . '-' . ( ++$used[ $base ] );
				} while ( isset( $used[ $id ] ) );
			}
			$used[ $id ] = $used[ $id ] ?? 0;
			return '<h' . $m[1] . ' id="' . htmlspecialchars( $id, ENT_QUOTES, 'UTF-8' ) . '"' . $attrs . '>' . $m[3] . '</h' . $m[1] . '>';
		},
		$html
	);
}

/**
 * Headings of block or HTML content with their ids.
 *
 * @param string $html   Content.
 * @param int[]  $levels Heading levels to include.
 * @return array<int,array{level: int, text: string, anchor: string}>
 */
function headings( string $html, array $levels = array( 2, 3 ) ): array {
	$out = array();
	if ( ! preg_match_all( '#<h([1-6])(\s[^>]*)?>(.*?)</h\1>#is', $html, $matches, PREG_SET_ORDER ) ) {
		return $out;
	}
	foreach ( $matches as $m ) {
		if ( ! in_array( (int) $m[1], $levels, true ) ) {
			continue;
		}
		$text = heading_text( $m[3] );
		if ( '' === $text ) {
			continue;
		}
		$anchor = preg_match( '#\sid="([^"]*)"#i', $m[2] ?? '', $id ) ? html_entity_decode( $id[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
		$out[]  = array(
			'level'  => (int) $m[1],
			'text'   => $text,
			'anchor' => $anchor,
		);
	}
	return $out;
}
