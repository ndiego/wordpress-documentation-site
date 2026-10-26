<?php
/**
 * Reverse sync, step 2: block markup to Markdown.
 *
 * The inverse of blocks.php for the blocks the editor allows on a docs page (editor.php). Each block
 * is flattened to its saved HTML (inner blocks included) and that HTML is written as Markdown in the
 * dialect of the content repository (conventions/markdown.md): `**bold**`, `*italic*`, `-` bullets,
 * `1.` steps numbered in sequence, nested lists indented to the parent's text, three-backtick fences
 * with a language, GFM pipe tables, `> [!NOTE]` alerts, `<kbd>` and the other inline tags kept as HTML,
 * `<details>` kept as HTML with a blank line after `<summary>`.
 *
 * Links and image sources go through two callbacks so the caller can turn site URLs back into the
 * relative repository paths the Markdown uses.
 *
 * Nothing here is clever about formatting the file as a whole: reverse.php keeps every unchanged chunk
 * of the source byte for byte and only asks this module for the blocks that changed.
 *
 * Pure PHP: DOMDocument and core's block parser only.
 *
 * @package WP_Docs
 */

namespace WP_Docs\Transforms\Markdown_Out;

use DOMDocument;
use DOMElement;
use DOMNode;

const INLINE_TAGS = array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'i', 'img', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr' );

/** Inline tags written back as HTML (GitHub and the importer both render them). */
const HTML_INLINE = array( 'abbr', 'bdi', 'bdo', 'cite', 'data', 'del', 'dfn', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strike', 'sub', 'sup', 'time', 'u', 'var' );

/**
 * Default options.
 *
 * @return array
 */
function defaults(): array {
	return array(
		'link'       => static fn( string $href ): ?string => null, // Site URL -> Markdown target, or null to keep.
		'image'      => static fn( string $src ): ?string => null,  // Attachment URL -> Markdown path, or null to keep.
		'alert_map'  => array( // Notice type -> GitHub alert.
			'info'    => 'NOTE',
			'tip'     => 'TIP',
			'success' => 'TIP',
			'alert'   => 'WARNING',
			'warning' => 'CAUTION',
		),
		'hint'       => '', // Source Markdown of the chunk being replaced (keeps IMPORTANT vs WARNING).
	);
}

/*
 * ---------------------------------------------------------------------------------------------
 * Blocks.
 * ---------------------------------------------------------------------------------------------
 */

/**
 * Parse block markup with core's parser.
 *
 * @param string $markup Block markup.
 * @return array[] Top-level blocks that are real blocks (freeform whitespace dropped).
 */
function parse( string $markup ): array {
	$blocks = function_exists( 'parse_blocks' ) ? parse_blocks( $markup ) : ( new \WP_Block_Parser() )->parse( $markup );
	return array_values(
		array_filter(
			$blocks,
			static fn( $b ) => null !== $b['blockName'] || '' !== trim( (string) $b['innerHTML'] )
		)
	);
}

/**
 * Write a list of parsed blocks as Markdown.
 *
 * @param array[] $blocks   Parsed blocks.
 * @param array   $options  Options (defaults()).
 * @param array   $warnings Receives one line per block that was written as HTML because it has no Markdown form.
 * @return string Markdown, chunks separated by one blank line, no trailing newline.
 */
function blocks_to_markdown( array $blocks, array $options = array(), array &$warnings = array() ): string {
	$options = array_merge( defaults(), $options );
	$out     = array();
	foreach ( $blocks as $block ) {
		$md = block_to_markdown( $block, $options, $warnings );
		if ( '' !== $md ) {
			$out[] = $md;
		}
	}
	return implode( "\n\n", $out );
}

/**
 * One block as Markdown.
 *
 * @param array $block    Parsed block.
 * @param array $options  Options.
 * @param array $warnings Warnings.
 * @return string
 */
function block_to_markdown( array $block, array $options, array &$warnings = array() ): string {
	$options = array_merge( defaults(), $options );
	$name    = (string) $block['blockName'];
	$html    = flatten( $block );

	switch ( $name ) {
		case 'wporg/notice':
			return notice( $block, $html, $options, $warnings );

		case 'core/code':
		case 'core/preformatted':
		case 'core/paragraph':
		case 'core/heading':
		case 'core/list':
		case 'core/quote':
		case 'core/table':
		case 'core/image':
		case 'core/separator':
		case 'core/details':
		case '':
			return html_to_markdown( $html, $options, $warnings );

		case 'core/html':
			$md = html_to_markdown( $html, $options, $warnings );
			return '' !== $md ? $md : trim( $html );

		default:
			$warnings[] = "{$name}: no Markdown form; written as HTML.";
			return trim( $html );
	}
}

/**
 * The saved HTML of a block with its inner blocks spliced in (what the front end renders, minus
 * dynamic blocks, which the editor does not allow here).
 *
 * @param array $block Parsed block.
 * @return string
 */
function flatten( array $block ): string {
	$html  = '';
	$index = 0;
	foreach ( (array) $block['innerContent'] as $piece ) {
		if ( is_string( $piece ) ) {
			$html .= $piece;
		} elseif ( isset( $block['innerBlocks'][ $index ] ) ) {
			$html .= flatten( $block['innerBlocks'][ $index ] );
			++$index;
		}
	}
	return $html;
}

/**
 * A notice block as a GitHub alert.
 *
 * @param array  $block    Parsed block.
 * @param string $html     Flattened HTML.
 * @param array  $options  Options.
 * @param array  $warnings Warnings.
 * @return string
 */
function notice( array $block, string $html, array $options, array &$warnings ): string {
	$type = (string) ( $block['attrs']['type'] ?? '' );
	if ( '' === $type && preg_match( '/\bis-([a-z]+)-notice\b/', $html, $m ) ) {
		$type = $m[1];
	}
	$alert = $options['alert_map'][ $type ] ?? 'NOTE';

	// WARNING and IMPORTANT both import as `alert`; keep whichever the source had.
	if ( 'WARNING' === $alert && preg_match( '/^\s*>\s*\[!IMPORTANT\]/i', (string) $options['hint'] ) ) {
		$alert = 'IMPORTANT';
	}

	$root    = load( $html );
	$content = $root ? first_descendant_with_class( $root, 'wp-block-wporg-notice__content' ) : null;
	$inner   = $content ? inner_html( $content ) : $html;
	$body    = html_to_markdown( $inner, $options, $warnings );

	return quote_lines( "[!{$alert}]\n" . $body );
}

/**
 * Prefix every line with `> ` (`>` for blank lines).
 *
 * @param string $text Text.
 * @return string
 */
function quote_lines( string $text ): string {
	return implode(
		"\n",
		array_map(
			static fn( $line ) => '' === $line ? '>' : '> ' . $line,
			explode( "\n", $text )
		)
	);
}

/*
 * ---------------------------------------------------------------------------------------------
 * HTML to Markdown.
 * ---------------------------------------------------------------------------------------------
 */

/**
 * Convert an HTML fragment (block-level elements, or a run of inline content) to Markdown.
 *
 * @param string $html     HTML.
 * @param array  $options  Options.
 * @param array  $warnings Warnings.
 * @return string
 */
function html_to_markdown( string $html, array $options = array(), array &$warnings = array() ): string {
	$options = array_merge( defaults(), $options );
	$root    = load( $html );
	if ( ! $root ) {
		return trim( $html );
	}
	return children_to_markdown( $root, $options, $warnings );
}

/**
 * Convert the children of a node: block elements each become a chunk, runs of inline content a paragraph.
 *
 * @param DOMNode $parent   Parent.
 * @param array   $options  Options.
 * @param array   $warnings Warnings.
 * @return string Chunks separated by one blank line.
 */
function children_to_markdown( DOMNode $parent, array $options, array &$warnings ): string {
	$chunks = array();
	$inline = array();

	$flush = static function () use ( &$inline, &$chunks, $options, $warnings ) {
		if ( $inline ) {
			$text = trim( inline_nodes( $inline, $options ) );
			if ( '' !== $text ) {
				$chunks[] = escape_block_start( $text );
			}
			$inline = array();
		}
	};

	foreach ( iterator_to_array( $parent->childNodes ) as $node ) {
		if ( XML_COMMENT_NODE === $node->nodeType ) {
			$flush();
			$chunks[] = '<!--' . $node->nodeValue . '-->';
			continue;
		}
		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			if ( '' !== trim( $node->nodeValue ) || $inline ) {
				$inline[] = $node;
			}
			continue;
		}
		if ( ! $node instanceof DOMElement ) {
			continue;
		}
		if ( in_array( strtolower( $node->nodeName ), INLINE_TAGS, true ) ) {
			$inline[] = $node;
			continue;
		}
		$flush();
		$chunk = element_to_markdown( $node, $options, $warnings );
		if ( '' !== $chunk ) {
			$chunks[] = $chunk;
		}
	}
	$flush();

	return implode( "\n\n", $chunks );
}

/**
 * One block-level element as Markdown.
 *
 * @param DOMElement $el       Element.
 * @param array      $options  Options.
 * @param array      $warnings Warnings.
 * @return string
 */
function element_to_markdown( DOMElement $el, array $options, array &$warnings ): string {
	$tag = strtolower( $el->nodeName );

	switch ( $tag ) {
		case 'p':
			$children = meaningful_children( $el );
			if ( 1 === count( $children ) && $children[0] instanceof DOMElement && 'img' === strtolower( $children[0]->nodeName ) ) {
				return image( $children[0], $options );
			}
			return escape_block_start( trim( inline_nodes( iterator_to_array( $el->childNodes ), $options ) ) );

		case 'h1':
		case 'h2':
		case 'h3':
		case 'h4':
		case 'h5':
		case 'h6':
			return str_repeat( '#', (int) $tag[1] ) . ' ' . trim( inline_nodes( iterator_to_array( $el->childNodes ), $options ) );

		case 'pre':
			return code( $el );

		case 'ul':
		case 'ol':
			return lst( $el, $options, $warnings );

		case 'blockquote':
			return quote_lines( children_to_markdown( $el, $options, $warnings ) );

		case 'hr':
			return '---';

		case 'figure':
			return figure( $el, $options, $warnings );

		case 'img':
			return image( $el, $options );

		case 'table':
			return table( $el, $options );

		case 'details':
			return details( $el, $options, $warnings );

		case 'div':
			if ( preg_match( '/\bwp-block-wporg-notice\b/', $el->getAttribute( 'class' ) ) ) {
				return notice( array( 'attrs' => array(), 'innerContent' => array( outer_html( $el ) ), 'innerBlocks' => array() ), outer_html( $el ), $options, $warnings );
			}
			// A plain wrapper: write what is inside it.
			return children_to_markdown( $el, $options, $warnings );

		default:
			$warnings[] = "<{$tag}>: kept as HTML.";
			return outer_html( $el );
	}
}

/**
 * Fenced code from `<pre><code>` (or `<pre>` alone).
 *
 * @param DOMElement $pre Element.
 * @return string
 */
function code( DOMElement $pre ): string {
	$children = meaningful_children( $pre );
	$code     = ( 1 === count( $children ) && $children[0] instanceof DOMElement && 'code' === strtolower( $children[0]->nodeName ) ) ? $children[0] : null;
	$source   = $code ?: $pre;

	$language = '';
	foreach ( array( $code ? $code->getAttribute( 'class' ) : '', $pre->getAttribute( 'class' ) ) as $class ) {
		if ( preg_match( '/(?:^|\s)(?:language|lang)-([A-Za-z0-9_+#.-]+)/', $class, $m ) ) {
			$language = $m[1];
			break;
		}
	}
	if ( '' === $language && $code && $code->hasAttribute( 'lang' ) ) {
		$language = $code->getAttribute( 'lang' );
	}

	// The editor stores the text with entities; `<br>` only comes from pasted content.
	$text = inner_html( $source );
	$text = preg_replace( '#<br\s*/?>#i', "\n", $text );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = rtrim( $text, "\n" );

	$fence = '```';
	if ( preg_match_all( '/`{3,}/', $text, $m ) ) {
		$longest = max( array_map( 'strlen', $m[0] ) );
		$fence   = str_repeat( '`', $longest + 1 );
	}

	return $fence . $language . "\n" . $text . "\n" . $fence;
}

/**
 * A list, nested lists indented to the parent item's text.
 *
 * @param DOMElement $list     `<ul>` or `<ol>`.
 * @param array      $options  Options.
 * @param array      $warnings Warnings.
 * @return string
 */
function lst( DOMElement $list, array $options, array &$warnings ): string {
	$ordered = 'ol' === strtolower( $list->nodeName );
	$number  = $ordered && is_numeric( $list->getAttribute( 'start' ) ) ? (int) $list->getAttribute( 'start' ) : 1;
	$items   = array();
	$wrapped = array(); // The item's own text is a <p> (the parser saw a blank line next to the item).
	$ends    = array(); // The item ends with a block (continuation paragraph, nested list, image).

	foreach ( $list->childNodes as $li ) {
		if ( ! $li instanceof DOMElement || 'li' !== strtolower( $li->nodeName ) ) {
			continue;
		}
		$marker = $ordered ? $number . '. ' : '- ';
		$indent = '    ';
		++$number;
		$first_child = meaningful_children( $li )[0] ?? null;
		$wrapped[]   = $first_child instanceof DOMElement && 'p' === strtolower( $first_child->nodeName );

		// Leading inline run is the item's own line; block children follow, indented, each after a blank line.
		$parts  = array();
		$inline = array();
		$flush  = static function () use ( &$inline, &$parts, $options ) {
			if ( $inline ) {
				$text = trim( inline_nodes( $inline, $options ) );
				if ( '' !== $text ) {
					$parts[] = escape_block_start( $text );
				}
				$inline = array();
			}
		};
		foreach ( iterator_to_array( $li->childNodes ) as $child ) {
			if ( XML_COMMENT_NODE === $child->nodeType ) {
				continue;
			}
			if ( ! $child instanceof DOMElement || in_array( strtolower( $child->nodeName ), INLINE_TAGS, true ) ) {
				$inline[] = $child;
				continue;
			}
			$flush();
			$block = element_to_markdown( $child, $options, $warnings );
			if ( '' !== $block ) {
				$parts[] = $block;
			}
		}
		$flush();

		$first = array_shift( $parts );
		$first = null === $first ? '' : $first;
		$text  = $marker . indent_lines( $first, $indent, false );
		foreach ( $parts as $part ) {
			$is_list = (bool) preg_match( '/^(\d+\. |- )/', $part );
			$text   .= ( $is_list ? "\n" : "\n\n" ) . indent_lines( $part, $indent, true );
		}
		$items[] = $text;
		$ends[]  = (bool) $parts;
	}

	// A blank line after an item wraps the text of that item and the next one in <p>, except that an
	// item ending with a block keeps its text bare. Place blank lines so every wrapped item gets one.
	$count = count( $items );
	$blank = array_fill( 0, $count, false ); // After item i.
	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! $wrapped[ $i ] || ( $i > 0 && $blank[ $i - 1 ] ) ) {
			continue;
		}
		if ( $i > 0 && $ends[ $i - 1 ] ) {
			$blank[ $i - 1 ] = true;
		} elseif ( $i < $count - 1 ) {
			$blank[ $i ] = true;
		} elseif ( $i > 0 ) {
			$blank[ $i - 1 ] = true;
		}
	}
	$out = '';
	foreach ( $items as $i => $item ) {
		$out .= $item . ( $i < $count - 1 ? ( $blank[ $i ] ? "\n\n" : "\n" ) : '' );
	}
	return $out;
}

/**
 * Indent every line of a text (blank lines stay empty).
 *
 * @param string $text   Text.
 * @param string $indent Indent.
 * @param bool   $first  Indent the first line too.
 * @return string
 */
function indent_lines( string $text, string $indent, bool $first ): string {
	$lines = explode( "\n", $text );
	foreach ( $lines as $i => $line ) {
		if ( 0 === $i && ! $first ) {
			continue;
		}
		$lines[ $i ] = '' === $line ? '' : $indent . $line;
	}
	return implode( "\n", $lines );
}

/**
 * A figure: an image with an optional link and caption. A caption has no Markdown form, so the
 * figure is kept as HTML then; the importer turns it back into the same image block.
 *
 * @param DOMElement $figure   Element.
 * @param array      $options  Options.
 * @param array      $warnings Warnings.
 * @return string
 */
function figure( DOMElement $figure, array $options, array &$warnings ): string {
	$class = $figure->getAttribute( 'class' );
	if ( preg_match( '/\bwp-block-table\b/', $class ) ) {
		foreach ( $figure->childNodes as $child ) {
			if ( $child instanceof DOMElement && 'table' === strtolower( $child->nodeName ) ) {
				return table( $child, $options );
			}
		}
	}

	$img     = null;
	$link    = null;
	$caption = null;
	foreach ( meaningful_children( $figure ) as $child ) {
		if ( ! $child instanceof DOMElement ) {
			continue;
		}
		$name = strtolower( $child->nodeName );
		if ( 'img' === $name ) {
			$img = $child;
		} elseif ( 'a' === $name ) {
			foreach ( meaningful_children( $child ) as $grand ) {
				if ( $grand instanceof DOMElement && 'img' === strtolower( $grand->nodeName ) ) {
					$img  = $grand;
					$link = $child->getAttribute( 'href' );
				}
			}
		} elseif ( 'figcaption' === $name ) {
			$caption = $child;
		}
	}
	if ( ! $img ) {
		return children_to_markdown( $figure, $options, $warnings );
	}

	$image = image( $img, $options );
	if ( null !== $link && '' !== $link ) {
		$image = '[' . $image . '](' . link_target( $link, $options ) . ')';
	}
	if ( $caption ) {
		$src = image_target( $img->getAttribute( 'src' ), $options );
		return "<figure>\n<img src=\"" . attr( $src ) . '" alt="' . attr( $img->getAttribute( 'alt' ) ) . "\">\n<figcaption>" . trim( inner_html( $caption ) ) . "</figcaption>\n</figure>";
	}
	return $image;
}

/**
 * `![alt](src "title")`.
 *
 * @param DOMElement $img     Element.
 * @param array      $options Options.
 * @return string
 */
function image( DOMElement $img, array $options ): string {
	$src   = image_target( $img->getAttribute( 'src' ), $options );
	$alt   = str_replace( array( '[', ']' ), array( '\\[', '\\]' ), $img->getAttribute( 'alt' ) );
	$title = $img->getAttribute( 'title' );
	return '![' . $alt . '](' . link_url( $src ) . ( '' !== $title ? ' "' . str_replace( '"', '\\"', $title ) . '"' : '' ) . ')';
}

/**
 * A GFM pipe table.
 *
 * @param DOMElement $table   Element.
 * @param array      $options Options.
 * @return string
 */
function table( DOMElement $table, array $options ): string {
	$rows = array();
	foreach ( $table->getElementsByTagName( 'tr' ) as $tr ) {
		$cells = array();
		foreach ( $tr->childNodes as $cell ) {
			if ( ! $cell instanceof DOMElement || ! in_array( strtolower( $cell->nodeName ), array( 'td', 'th' ), true ) ) {
				continue;
			}
			$align = '';
			if ( preg_match( '/\bhas-text-align-(left|center|right)\b/', $cell->getAttribute( 'class' ), $m ) ) {
				$align = $m[1];
			} elseif ( in_array( strtolower( $cell->getAttribute( 'align' ) ), array( 'left', 'center', 'right' ), true ) ) {
				$align = strtolower( $cell->getAttribute( 'align' ) );
			}
			$text    = trim( inline_nodes( iterator_to_array( $cell->childNodes ), $options ) );
			$text    = str_replace( "\n", ' ', $text );
			$text    = preg_replace( '/(?<!\\\\)\|/', '\\|', $text );
			$cells[] = array(
				'text'  => $text,
				'align' => $align,
			);
		}
		if ( $cells ) {
			$rows[] = $cells;
		}
	}
	if ( ! $rows ) {
		return '';
	}

	$header  = array_shift( $rows );
	$columns = max( array_merge( array( count( $header ) ), array_map( 'count', $rows ) ) );
	$line    = static function ( array $cells ) use ( $columns ) {
		$texts = array_map( static fn( $c ) => $c['text'], $cells );
		$texts = array_pad( $texts, $columns, '' );
		return '| ' . implode( ' | ', $texts ) . ' |';
	};
	$delims  = array();
	for ( $i = 0; $i < $columns; $i++ ) {
		$align = $header[ $i ]['align'] ?? '';
		if ( '' === $align && isset( $rows[0][ $i ] ) ) {
			$align = $rows[0][ $i ]['align'];
		}
		$delims[] = 'center' === $align ? ':---:' : ( 'right' === $align ? '---:' : ( 'left' === $align ? ':---' : '---' ) );
	}

	$out = array( $line( $header ), '|' . implode( '|', $delims ) . '|' );
	foreach ( $rows as $row ) {
		$out[] = $line( $row );
	}
	return implode( "\n", $out );
}

/**
 * `<details>` kept as HTML, its body as Markdown after a blank line (the content convention).
 *
 * @param DOMElement $details  Element.
 * @param array      $options  Options.
 * @param array      $warnings Warnings.
 * @return string
 */
function details( DOMElement $details, array $options, array &$warnings ): string {
	$summary = '';
	foreach ( iterator_to_array( $details->childNodes ) as $child ) {
		if ( $child instanceof DOMElement && 'summary' === strtolower( $child->nodeName ) ) {
			$summary = trim( inner_html( $child ) );
			$details->removeChild( $child );
			break;
		}
	}
	$open = $details->hasAttribute( 'open' ) ? ' open' : '';
	$body = children_to_markdown( $details, $options, $warnings );

	return "<details{$open}>\n<summary>{$summary}</summary>\n\n{$body}\n\n</details>";
}

/*
 * ---------------------------------------------------------------------------------------------
 * Inline content.
 * ---------------------------------------------------------------------------------------------
 */

/**
 * A run of inline nodes as Markdown.
 *
 * @param DOMNode[] $nodes   Nodes.
 * @param array     $options Options.
 * @return string
 */
function inline_nodes( array $nodes, array $options ): string {
	$out = '';
	foreach ( $nodes as $node ) {
		$out .= inline_node( $node, $options );
	}
	// Whitespace inside a paragraph has no meaning beyond a single space; newlines come from hard breaks only.
	return preg_replace( '/[ \t]*\n[ \t]*/', "\n", $out );
}

/**
 * One inline node as Markdown.
 *
 * @param DOMNode $node    Node.
 * @param array   $options Options.
 * @return string
 */
function inline_node( DOMNode $node, array $options ): string {
	if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
		return escape_text( preg_replace( '/\s+/', ' ', $node->nodeValue ) );
	}
	if ( ! $node instanceof DOMElement ) {
		return '';
	}

	$tag   = strtolower( $node->nodeName );
	$inner = static fn() => inline_nodes( iterator_to_array( $node->childNodes ), $options );

	switch ( $tag ) {
		case 'strong':
		case 'b':
			return wrap( $inner(), '**' );

		case 'em':
		case 'i':
			// Nested emphasis alternates markers so `**` never appears by accident.
			$depth   = (int) ( $options['em_depth'] ?? 0 );
			$options = array_merge( $options, array( 'em_depth' => $depth + 1 ) );
			$text    = inline_nodes( iterator_to_array( $node->childNodes ), $options );
			return wrap( $text, $depth % 2 ? '_' : '*' );

		case 'code':
			return code_span( $node->textContent );

		case 'a':
			$href = $node->getAttribute( 'href' );
			$text = $inner();
			if ( '' === $href ) {
				return $text;
			}
			$target = link_target( $href, $options );
			$title  = $node->getAttribute( 'title' );
			if ( '' === trim( $text ) ) {
				$text = $target;
			}
			return '[' . $text . '](' . link_url( $target ) . ( '' !== $title ? ' "' . str_replace( '"', '\\"', $title ) . '"' : '' ) . ')';

		case 'img':
			return image( $node, $options );

		case 'br':
			return "  \n";

		default:
			if ( in_array( $tag, HTML_INLINE, true ) ) {
				$attrs = '';
				foreach ( iterator_to_array( $node->attributes ) as $attribute ) {
					$attrs .= ' ' . $attribute->name . '="' . attr( $attribute->value ) . '"';
				}
				return "<{$tag}{$attrs}>" . $inner() . "</{$tag}>";
			}
			// A block element inside inline content (the editor never produces one): keep its HTML.
			return outer_html( $node );
	}
}

/**
 * Wrap text in an emphasis marker, keeping surrounding spaces outside the marker.
 *
 * @param string $text   Text.
 * @param string $marker `**` or `*`.
 * @return string
 */
function wrap( string $text, string $marker ): string {
	if ( '' === trim( $text ) ) {
		return $text;
	}
	preg_match( '/^(\s*)(.*?)(\s*)$/s', $text, $m );
	return $m[1] . $marker . $m[2] . $marker . $m[3];
}

/**
 * A code span with a long enough backtick run.
 *
 * @param string $text Code text.
 * @return string
 */
function code_span( string $text ): string {
	$text  = str_replace( "\n", ' ', $text );
	$ticks = '`';
	if ( preg_match_all( '/`+/', $text, $m ) ) {
		$ticks = str_repeat( '`', max( array_map( 'strlen', $m[0] ) ) + 1 );
	}
	if ( '' !== $text && ( '`' === $text[0] || '`' === substr( $text, -1 ) ) ) {
		$text = ' ' . $text . ' ';
	}
	return $ticks . $text . $ticks;
}

/**
 * Escape the characters that would otherwise be read as Markdown inside text.
 *
 * `<` becomes `&lt;` before a letter, `/`, `!` or `?` (a possible tag), `&` becomes `&amp;` before
 * what looks like an entity; both are what the parser and GitHub need and what they print back.
 * Underscores are escaped at word boundaries only, so `wp_options` stays readable.
 *
 * @param string $text Text.
 * @return string
 */
function escape_text( string $text ): string {
	if ( false !== strpos( $text, '://' ) ) {
		// A bare URL is autolinked by the parser, which swallows everything up to the next space
		// (backslashes and entities included). Text that reached the page with a URL in it was written
		// raw in the source, so it is written raw again, and the parser does what it did before.
		$text = preg_replace( '/&(?=[A-Za-z#][A-Za-z0-9]*;)/', '&amp;', $text );
		$text = preg_replace( '/<(?=[A-Za-z\/!?])/', '&lt;', $text );
		return str_replace( "\u{00A0}", '&nbsp;', $text );
	}
	$text = str_replace( '\\', '\\\\', $text );
	$text = preg_replace( '/([*\[\]])/', '\\\\$1', $text );
	$text = preg_replace( '/(?<![A-Za-z0-9])_|_(?![A-Za-z0-9])/', '\\_', $text );
	$text = preg_replace( '/&(?=[A-Za-z#][A-Za-z0-9]*;)/', '&amp;', $text );
	$text = preg_replace( '/<(?=[A-Za-z\/!?])/', '&lt;', $text );
	// A backslash before a backtick is not reliable in the parser; the entity is, on GitHub too.
	$text = str_replace( '`', '&#96;', $text );
	$text = str_replace( "\u{00A0}", '&nbsp;', $text );
	return $text;
}

/**
 * Escape a paragraph whose first line would otherwise start a block (`# x`, `- x`, `1. x`, `> x`).
 *
 * @param string $text Text.
 * @return string
 */
function escape_block_start( string $text ): string {
	$lines = explode( "\n", $text );
	foreach ( $lines as $i => $line ) {
		if ( preg_match( '/^(\s{0,3})([#>+-])(\s|$)/', $line, $m ) ) {
			$lines[ $i ] = $m[1] . '\\' . substr( $line, strlen( $m[1] ) );
		} elseif ( preg_match( '/^(\s{0,3}\d{1,9})([.)])(\s|$)/', $line, $m ) ) {
			$lines[ $i ] = $m[1] . '\\' . substr( $line, strlen( $m[1] ) );
		}
	}
	return implode( "\n", $lines );
}

/**
 * A link target for the Markdown: the caller's mapping, else the URL as written.
 *
 * @param string $href    URL.
 * @param array  $options Options.
 * @return string
 */
function link_target( string $href, array $options ): string {
	$mapped = ( $options['link'] )( $href );
	return null !== $mapped ? $mapped : $href;
}

/**
 * An image source for the Markdown: the caller's mapping, else the URL as written.
 *
 * @param string $src     URL.
 * @param array  $options Options.
 * @return string
 */
function image_target( string $src, array $options ): string {
	$mapped = ( $options['image'] )( $src );
	return null !== $mapped ? $mapped : $src;
}

/**
 * A URL inside `(...)`: spaces and parentheses must not break the link.
 *
 * @param string $url URL.
 * @return string
 */
function link_url( string $url ): string {
	if ( preg_match( '/[\s()]/', $url ) ) {
		return '<' . $url . '>';
	}
	return $url;
}

/**
 * An attribute value for HTML output.
 *
 * @param string $value Value.
 * @return string
 */
function attr( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false );
}

/*
 * ---------------------------------------------------------------------------------------------
 * DOM helpers.
 * ---------------------------------------------------------------------------------------------
 */

/**
 * Parse an HTML fragment into a container element.
 *
 * @param string $html HTML fragment.
 * @return DOMElement|null
 */
function load( string $html ): ?DOMElement {
	$doc      = new DOMDocument( '1.0', 'UTF-8' );
	$previous = libxml_use_internal_errors( true );
	$doc->loadHTML(
		'<?xml encoding="UTF-8"?><!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="wp-docs-root">' . $html . '</div></body></html>',
		LIBXML_NONET | LIBXML_COMPACT
	);
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	foreach ( $doc->getElementsByTagName( 'div' ) as $div ) {
		if ( 'wp-docs-root' === $div->getAttribute( 'id' ) ) {
			return $div;
		}
	}
	return null;
}

/**
 * First descendant with a class.
 *
 * @param DOMElement $el    Root.
 * @param string     $class Class name.
 * @return DOMElement|null
 */
function first_descendant_with_class( DOMElement $el, string $class ): ?DOMElement {
	foreach ( $el->getElementsByTagName( '*' ) as $node ) {
		if ( $node instanceof DOMElement && preg_match( '/(?:^|\s)' . preg_quote( $class, '/' ) . '(?:\s|$)/', $node->getAttribute( 'class' ) ) ) {
			return $node;
		}
	}
	return null;
}

/**
 * Children that are not whitespace text or comments.
 *
 * @param DOMNode $el Element.
 * @return DOMNode[]
 */
function meaningful_children( DOMNode $el ): array {
	$out = array();
	foreach ( $el->childNodes as $child ) {
		if ( XML_COMMENT_NODE === $child->nodeType ) {
			continue;
		}
		if ( XML_TEXT_NODE === $child->nodeType && '' === trim( $child->nodeValue ) ) {
			continue;
		}
		$out[] = $child;
	}
	return $out;
}

/**
 * Inner HTML of a node.
 *
 * @param DOMNode $el Node.
 * @return string
 */
function inner_html( DOMNode $el ): string {
	$html = '';
	foreach ( $el->childNodes as $child ) {
		$html .= outer_html( $child );
	}
	return $html;
}

/**
 * Outer HTML of a node.
 *
 * @param DOMNode $node Node.
 * @return string
 */
function outer_html( DOMNode $node ): string {
	return (string) $node->ownerDocument->saveHTML( $node );
}
