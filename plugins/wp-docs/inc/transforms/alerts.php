<?php
/**
 * Transform (a): GitHub alerts to WordPress.org notices.
 *
 * GitHub renders `> [!NOTE]`, `[!TIP]`, `[!IMPORTANT]`, `[!WARNING]` and `[!CAUTION]` blockquotes as
 * alerts. The stock importer's parser (Jetpack's WPCom_GHF_Markdown_Parser) knows nothing about them:
 * the marker is stored as literal text, and two alerts separated by a blank line merge into one
 * blockquote (spike 04, section 3: 865 callouts on 174 pages).
 *
 * The conversion runs in two steps so that consecutive alerts can never merge:
 *
 * 1. Before the Markdown is parsed (`wporg_markdown_before_transform`), every top-level alert outside a
 *    code fence becomes a Markdown Extra block `<div data-wp-docs-alert="note" markdown="1">`. The
 *    parser converts the alert body as ordinary Markdown inside it.
 * 2. After parsing, `render_classic()` turns those divs into the notice markup the site uses, or the
 *    blocks converter (blocks.php) turns them into notice blocks.
 *
 * Pure PHP, no WordPress dependency.
 *
 * @package WP_Docs
 */

namespace WP_Docs\Transforms\Alerts;

const ATTRIBUTE = 'data-wp-docs-alert';

/**
 * Default GitHub alert -> WordPress.org notice type map.
 *
 * Matches GitHub's colors to the notice colors: NOTE (blue) is info, TIP (green) is tip, IMPORTANT
 * (purple, no equivalent) and WARNING (yellow) are alert (yellow), CAUTION (red) is warning (red).
 * Notice types are the `wporg/notice` block's enum: alert, info, warning, tip, success.
 *
 * @return array<string,string>
 */
function default_type_map(): array {
	return array(
		'note'      => 'info',
		'tip'       => 'tip',
		'important' => 'alert',
		'warning'   => 'alert',
		'caution'   => 'warning',
	);
}

/**
 * Replace top-level GitHub alerts in Markdown with Markdown Extra placeholder divs.
 *
 * Fence-aware: nothing inside ``` or ~~~ fences is touched. An alert starts at a line that is exactly
 * `> [!TYPE]` (case-insensitive, optional trailing spaces) and runs over the following `>` lines and
 * lazy continuation lines until a blank line or a line that is neither, as in CommonMark.
 *
 * @param string $markdown Markdown.
 * @return string
 */
function markdown_to_placeholders( string $markdown ): string {
	$lines = preg_split( '/(?<=\n)/', $markdown );
	$out   = '';
	$fence = '';
	$count = count( $lines );

	for ( $i = 0; $i < $count; $i++ ) {
		$line = $lines[ $i ];

		if ( '' !== $fence ) {
			if ( preg_match( '/^\s{0,3}' . preg_quote( $fence[0], '/' ) . '{' . strlen( $fence ) . ',}\s*$/', rtrim( $line, "\r\n" ) ) ) {
				$fence = '';
			}
			$out .= $line;
			continue;
		}

		if ( preg_match( '/^\s{0,3}(`{3,}|~{3,})/', $line, $m ) ) {
			$fence = $m[1];
			$out  .= $line;
			continue;
		}

		if ( ! preg_match( '/^>[ \t]?\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\][ \t]*\r?\n?$/i', $line, $m ) ) {
			$out .= $line;
			continue;
		}

		$type      = strtolower( $m[1] );
		$body      = array();
		$prev_text = false;
		while ( $i + 1 < $count ) {
			$next    = $lines[ $i + 1 ];
			$trimmed = rtrim( $next, "\r\n" );
			if ( preg_match( '/^>/', $trimmed ) ) {
				$content   = preg_replace( '/^>[ ]?/', '', $trimmed );
				$body[]    = $content;
				$prev_text = '' !== trim( $content ) && ! preg_match( '/^\s{0,3}(`{3,}|~{3,})/', $content );
				++$i;
				continue;
			}
			if ( $prev_text && '' !== trim( $trimmed ) && ! preg_match( '/^\s{0,3}(#|`{3,}|~{3,}|[-*_]{3,}\s*$|<|[-*+]\s|\d+[.)]\s)/', $trimmed ) ) {
				// Lazy continuation of a paragraph inside the alert.
				$body[] = $trimmed;
				++$i;
				continue;
			}
			break;
		}

		$out .= "\n<div " . ATTRIBUTE . '="' . $type . '" markdown="1">' . "\n\n" . trim( implode( "\n", $body ), "\n" ) . "\n\n</div>\n\n";
	}

	return $out;
}

/**
 * Find placeholder divs in HTML and replace each with the result of a callback.
 *
 * Matches the placeholder's own closing tag by counting nested divs.
 *
 * @param string   $html     HTML.
 * @param callable $callback fn( string $type, string $inner_html ): string.
 * @return string
 */
function replace_placeholders( string $html, callable $callback ): string {
	$pattern = '/<div\s+' . preg_quote( ATTRIBUTE, '/' ) . '="([a-z]+)"[^>]*>/i';
	$offset  = 0;

	while ( preg_match( $pattern, $html, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
		$start = $m[0][1];
		$inner = $start + strlen( $m[0][0] );
		$depth = 1;
		$pos   = $inner;

		while ( $depth > 0 && preg_match( '#<(/?)div\b[^>]*>#i', $html, $t, PREG_OFFSET_CAPTURE, $pos ) ) {
			$depth += '/' === $t[1][0] ? -1 : 1;
			$pos    = $t[0][1] + strlen( $t[0][0] );
			if ( 0 === $depth ) {
				$end         = $t[0][1];
				$replacement = $callback( strtolower( $m[1][0] ), substr( $html, $inner, $end - $inner ) );
				$html        = substr( $html, 0, $start ) . $replacement . substr( $html, $pos );
				$offset      = $start + strlen( $replacement );
				continue 2;
			}
		}

		// Unbalanced: leave it and move on.
		$offset = $inner;
	}

	return $html;
}

/**
 * Render alerts for the classic output mode.
 *
 * Formats:
 * - `github` (default): GitHub's own alert markup, `<div class="markdown-alert markdown-alert-note">`
 *   with a `<p class="markdown-alert-title">`, so the page carries the same classes on GitHub and on
 *   WordPress.org (the theme styles it).
 * - `notice`: the static HTML the `wporg/notice` block saves (wporg-mu-plugins
 *   mu-plugins/blocks/notice/src/save.js), styled by the notice stylesheet the theme enqueues.
 * - `shortcode`: `[info]...[/info]` and friends, which wporg-mu-plugins renders through the same notice
 *   markup (the path the Theme Handbook spike verified, 148 of 148 identical).
 * - `callout`: the `<div class="callout callout-info">` markup the Block Editor Handbook uses today.
 *
 * Inner content is left as the parser produced it (no `<p>`), because classic content is run through
 * `wpautop` at render time.
 *
 * @param string $html     HTML from the Markdown parser.
 * @param string $format   github|notice|shortcode|callout.
 * @param array  $type_map GitHub type -> notice type.
 * @return string
 */
function render_classic( string $html, string $format = 'github', array $type_map = array() ): string {
	$type_map = $type_map ? $type_map : default_type_map();

	return replace_placeholders(
		$html,
		static function ( $type, $inner ) use ( $format, $type_map ) {
			$notice = $type_map[ $type ] ?? 'info';
			$inner  = trim( $inner );

			switch ( $format ) {
				case 'shortcode':
					return "\n[{$notice}]\n\n{$inner}\n\n[/{$notice}]\n";
				case 'github':
					return "\n<div class=\"markdown-alert markdown-alert-{$type}\">\n<p class=\"markdown-alert-title\">" . ucfirst( $type ) . "</p>\n\n{$inner}\n\n</div>\n";
				case 'callout':
					return "\n<div class=\"callout callout-{$notice}\">\n\n{$inner}\n\n</div>\n";
				default:
					return "\n<div class=\"wp-block-wporg-notice is-{$notice}-notice\">\n<div class=\"wp-block-wporg-notice__icon\"></div>\n<div class=\"wp-block-wporg-notice__content\">\n\n{$inner}\n\n</div></div>\n";
			}
		}
	);
}
