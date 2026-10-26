<?php
/**
 * Transform (g): classic HTML to block markup.
 *
 * The stock importer stores classic HTML without `<p>` tags (Jetpack's parser relies on `wpautop` at
 * render time), so any block comment in a page disables `wpautop` and the page loses its paragraphs
 * (spike 04, section 3). In the `blocks` output mode the importer instead stores real block markup:
 * the parser's HTML is run through `wpautop( $html, false )` once at import time and then converted
 * here, so every piece of content sits inside a block and nothing depends on render-time filters.
 *
 * Mapping:
 *
 * | HTML                                 | Block                                                  |
 * |--------------------------------------|--------------------------------------------------------|
 * | `<p>`, stray inline content          | core/paragraph                                         |
 * | `<h1>`..`<h6>`                       | core/heading (level, id kept as the anchor)            |
 * | `<pre><code class="language-x">`     | core/code, `lang="x" class="language-x"` (the markup Code Syntax Block saves and DevHub highlights) |
 * | `<pre>` without `<code>`             | core/preformatted                                      |
 * | `<ul>`, `<ol>`                       | core/list + core/list-item (nested lists as inner blocks) |
 * | `<table>`                            | core/table (`align` becomes `has-text-align-*`)        |
 * | `<blockquote>`                       | core/quote with inner blocks                           |
 * | `<img>` alone, `<figure><img>`       | core/image (attachment id from `wp-image-<id>`)        |
 * | `<hr>`                               | core/separator                                         |
 * | alert placeholder (alerts.php)       | wporg/notice (default), wporg/callout or core/group    |
 * | `<details>`                          | core/details with inner blocks                         |
 * | anything else                        | core/html                                              |
 *
 * A list whose items hold block content (a code sample inside a step, two paragraphs in one item)
 * cannot be a core/list (list items only accept inline content and nested lists) and becomes one
 * core/html block. HTML comments outside blocks are dropped.
 *
 * Deterministic and pure: DOMDocument only, no WordPress dependency.
 *
 * @package WP_Docs
 */

namespace WP_Docs\Transforms\Blocks;

use DOMDocument;
use DOMElement;
use DOMNode;
use WP_Docs\Transforms\Alerts;

const INLINE_TAGS = array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'i', 'img', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strike', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr' );

/**
 * Convert HTML (paragraphs already wrapped) to serialized block markup.
 *
 * @param string $html    HTML.
 * @param array  $options {
 *     @type string $callout_block wporg/notice (default), wporg/callout or core/group.
 *     @type array  $type_map      GitHub alert type -> notice type (alerts.php default_type_map()).
 * }
 * @return string Block markup.
 */
function convert( string $html, array $options = array() ): string {
	$options = array_merge(
		array(
			'callout_block' => 'wporg/notice',
			'type_map'      => Alerts\default_type_map(),
		),
		$options
	);

	$root = load( $html );
	if ( ! $root ) {
		return serialize_block( 'core/html', array(), trim( $html ) );
	}

	return implode( "\n\n", convert_children( $root, $options ) );
}

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

	$root = $doc->getElementById( 'wp-docs-root' );
	if ( ! $root ) {
		foreach ( $doc->getElementsByTagName( 'div' ) as $div ) {
			if ( 'wp-docs-root' === $div->getAttribute( 'id' ) ) {
				return $div;
			}
		}
	}
	return $root;
}

/**
 * Convert the children of a node into a list of serialized blocks.
 *
 * Consecutive text and inline elements are collected into one paragraph.
 *
 * @param DOMNode $parent  Parent node.
 * @param array   $options Options.
 * @return string[]
 */
function convert_children( DOMNode $parent, array $options ): array {
	$blocks = array();
	$inline = array();

	$flush = static function () use ( &$inline, &$blocks ) {
		if ( $inline ) {
			$block = inline_run_to_block( $inline );
			if ( null !== $block ) {
				$blocks[] = $block;
			}
			$inline = array();
		}
	};

	foreach ( iterator_to_array( $parent->childNodes ) as $node ) {
		if ( XML_COMMENT_NODE === $node->nodeType ) {
			continue;
		}
		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			$inline[] = $node;
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
		$block = convert_element( $node, $options );
		if ( null !== $block && '' !== $block ) {
			$blocks[] = $block;
		}
	}
	$flush();

	return $blocks;
}

/**
 * Convert one block-level element.
 *
 * @param DOMElement $el      Element.
 * @param array      $options Options.
 * @return string|null Serialized block, or null to drop (empty paragraphs).
 */
function convert_element( DOMElement $el, array $options ): ?string {
	$tag = strtolower( $el->nodeName );

	switch ( $tag ) {
		case 'p':
			return paragraph( $el );

		case 'h1':
		case 'h2':
		case 'h3':
		case 'h4':
		case 'h5':
		case 'h6':
			return heading( $el, (int) $tag[1] );

		case 'pre':
			return code( $el );

		case 'ul':
		case 'ol':
			$list = lst( $el );
			return null !== $list ? $list : serialize_block( 'core/html', array(), outer_html( $el ) );

		case 'table':
			return table( $el );

		case 'blockquote':
			$inner = convert_children( $el, $options );
			return serialize_block( 'core/quote', array(), '<blockquote class="wp-block-quote">' . join_inner( $inner ) . '</blockquote>' );

		case 'hr':
			return serialize_block( 'core/separator', array(), '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );

		case 'figure':
			$image = image_from( $el );
			return null !== $image ? $image : serialize_block( 'core/html', array(), outer_html( $el ) );

		case 'details':
			return details( $el, $options );

		case 'div':
			if ( $el->hasAttribute( Alerts\ATTRIBUTE ) ) {
				return callout( $el, $options );
			}
			return serialize_block( 'core/html', array(), outer_html( $el ) );

		default:
			return serialize_block( 'core/html', array(), outer_html( $el ) );
	}
}

/**
 * A run of text and inline elements outside any paragraph.
 *
 * @param DOMNode[] $nodes Nodes.
 * @return string|null
 */
function inline_run_to_block( array $nodes ): ?string {
	$meaningful = array_values(
		array_filter(
			$nodes,
			static fn( $n ) => ! ( XML_TEXT_NODE === $n->nodeType && '' === trim( $n->nodeValue ) )
		)
	);
	if ( ! $meaningful ) {
		return null;
	}
	if ( 1 === count( $meaningful ) && $meaningful[0] instanceof DOMElement ) {
		$image = image_from( $meaningful[0] );
		if ( null !== $image ) {
			return $image;
		}
	}

	$html = trim( implode( '', array_map( 'WP_Docs\Transforms\Blocks\outer_html', $nodes ) ) );
	$html = preg_replace( '#^(<br\s*/?>\s*)+|(\s*<br\s*/?>)+$#i', '', $html );
	if ( '' === trim( $html ) ) {
		return null;
	}

	return serialize_block( 'core/paragraph', array(), '<p>' . $html . '</p>' );
}

/**
 * Paragraph, or an image block when the paragraph only holds an image.
 *
 * @param DOMElement $el Paragraph.
 * @return string|null
 */
function paragraph( DOMElement $el ): ?string {
	$children = meaningful_children( $el );
	if ( ! $children ) {
		return null; // Empty, or only an HTML comment (wpautop wraps comments in a paragraph).
	}
	if ( 1 === count( $children ) && $children[0] instanceof DOMElement ) {
		$image = image_from( $children[0] );
		if ( null !== $image ) {
			return $image;
		}
	}

	$inner = trim( inner_html( $el ) );
	$check = preg_replace( '#<br\s*/?>|&nbsp;|\s#i', '', $inner );
	if ( '' === $check ) {
		return null;
	}

	$attrs = array();
	$class = trim( $el->getAttribute( 'class' ) );
	if ( '' !== $class ) {
		$attrs['className'] = $class;
	}

	return serialize_block( 'core/paragraph', $attrs, '<p' . attrs_html( array( 'id' => $el->getAttribute( 'id' ), 'class' => $class ) ) . '>' . $inner . '</p>' );
}

/**
 * Heading.
 *
 * @param DOMElement $el    Heading element.
 * @param int        $level Level 1-6.
 * @return string
 */
function heading( DOMElement $el, int $level ): string {
	$attrs = array();
	if ( 2 !== $level ) {
		$attrs['level'] = $level;
	}
	$class = trim( $el->getAttribute( 'class' ) );
	if ( '' !== $class ) {
		$attrs['className'] = $class;
	}

	$html = sprintf(
		'<h%1$d%2$s>%3$s</h%1$d>',
		$level,
		attrs_html(
			array(
				'class' => trim( 'wp-block-heading ' . $class ),
				'id'    => $el->getAttribute( 'id' ),
			)
		),
		trim( inner_html( $el ) )
	);

	return serialize_block( 'core/heading', $attrs, $html );
}

/**
 * Code block (or preformatted when there is no `<code>`).
 *
 * @param DOMElement $pre `<pre>` element.
 * @return string
 */
function code( DOMElement $pre ): string {
	$children = meaningful_children( $pre );
	$code     = ( 1 === count( $children ) && $children[0] instanceof DOMElement && 'code' === strtolower( $children[0]->nodeName ) ) ? $children[0] : null;

	if ( ! $code ) {
		return serialize_block( 'core/preformatted', array(), '<pre class="wp-block-preformatted">' . inner_html( $pre ) . '</pre>' );
	}

	$language = '';
	foreach ( array( $code->getAttribute( 'class' ), $pre->getAttribute( 'class' ) ) as $class ) {
		if ( preg_match( '/(?:^|\s)(?:language|lang)-([A-Za-z0-9_+#.-]+)/', $class, $m ) ) {
			$language = strtolower( $m[1] );
			break;
		}
	}
	if ( '' === $language && $code->hasAttribute( 'lang' ) ) {
		$language = strtolower( $code->getAttribute( 'lang' ) );
	}

	$code_attrs = '' === $language ? '' : attrs_html(
		array(
			'lang'  => $language,
			'class' => 'language-' . $language,
		)
	);

	// The parser ends every code block with a newline; the code block does not need it.
	$inner = preg_replace( '/\n$/', '', inner_html( $code ) );

	return serialize_block( 'core/code', array(), '<pre class="wp-block-code"><code' . $code_attrs . '>' . $inner . '</code></pre>' );
}

/**
 * List, or null when an item holds content a list item block cannot.
 *
 * @param DOMElement $list `<ul>` or `<ol>`.
 * @return string|null
 */
function lst( DOMElement $list ): ?string {
	$ordered = 'ol' === strtolower( $list->nodeName );
	$attrs   = array();
	$tag_att = array( 'class' => 'wp-block-list' );
	if ( $ordered ) {
		$attrs['ordered'] = true;
		$start            = $list->getAttribute( 'start' );
		if ( '' !== $start && '1' !== $start && is_numeric( $start ) ) {
			$attrs['start']   = (int) $start;
			$tag_att['start'] = (string) (int) $start;
		}
	}

	$items = array();
	foreach ( $list->childNodes as $child ) {
		if ( XML_TEXT_NODE === $child->nodeType && '' === trim( $child->nodeValue ) ) {
			continue;
		}
		if ( XML_COMMENT_NODE === $child->nodeType ) {
			continue;
		}
		if ( ! $child instanceof DOMElement || 'li' !== strtolower( $child->nodeName ) ) {
			return null;
		}
		$item = list_item( $child );
		if ( null === $item ) {
			return null;
		}
		$items[] = $item;
	}

	$tag = $ordered ? 'ol' : 'ul';
	return serialize_block( 'core/list', $attrs, '<' . $tag . attrs_html( $tag_att ) . '>' . join_inner( $items ) . '</' . $tag . '>' );
}

/**
 * List item, or null when the item holds block content.
 *
 * @param DOMElement $li `<li>`.
 * @return string|null
 */
function list_item( DOMElement $li ): ?string {
	$inline     = '';
	$nested     = array();
	$paragraphs = 0;
	$has_text   = false;

	foreach ( $li->childNodes as $child ) {
		if ( XML_COMMENT_NODE === $child->nodeType ) {
			continue;
		}
		if ( XML_TEXT_NODE === $child->nodeType ) {
			if ( '' !== trim( $child->nodeValue ) ) {
				if ( $nested ) {
					return null; // Text after a nested list.
				}
				$has_text = true;
			}
			$inline .= outer_html( $child );
			continue;
		}
		if ( ! $child instanceof DOMElement ) {
			continue;
		}
		$tag = strtolower( $child->nodeName );
		if ( 'ul' === $tag || 'ol' === $tag ) {
			$sub = lst( $child );
			if ( null === $sub ) {
				return null;
			}
			$nested[] = $sub;
			continue;
		}
		if ( $nested ) {
			return null; // Content after a nested list cannot be represented.
		}
		if ( 'p' === $tag ) {
			if ( '' === trim( preg_replace( '#<br\s*/?>|&nbsp;#i', '', inner_html( $child ) ) ) ) {
				continue; // Empty paragraph (the parser's stray `</p>`).
			}
			++$paragraphs;
			$inline .= inner_html( $child );
			continue;
		}
		if ( in_array( $tag, INLINE_TAGS, true ) ) {
			$has_text = true;
			$inline  .= outer_html( $child );
			continue;
		}
		return null; // Block content (pre, blockquote, table, div...).
	}

	if ( $paragraphs > 1 || ( 1 === $paragraphs && $has_text ) ) {
		return null;
	}

	$inline = trim( $inline );
	return serialize_block( 'core/list-item', array(), '<li>' . $inline . join_inner( $nested ) . '</li>' );
}

/**
 * Table, rebuilt without formatting whitespace and with core's alignment classes.
 *
 * @param DOMElement $table `<table>`.
 * @return string
 */
function table( DOMElement $table ): string {
	$html = '<figure class="wp-block-table"><table>';
	foreach ( $table->childNodes as $section ) {
		if ( ! $section instanceof DOMElement ) {
			continue;
		}
		$name = strtolower( $section->nodeName );
		if ( 'tr' === $name ) {
			$html .= row( $section );
			continue;
		}
		if ( ! in_array( $name, array( 'thead', 'tbody', 'tfoot' ), true ) ) {
			return serialize_block( 'core/html', array(), outer_html( $table ) );
		}
		$html .= '<' . $name . '>';
		foreach ( $section->childNodes as $tr ) {
			if ( $tr instanceof DOMElement && 'tr' === strtolower( $tr->nodeName ) ) {
				$html .= row( $tr );
			}
		}
		$html .= '</' . $name . '>';
	}
	$html .= '</table></figure>';

	return serialize_block( 'core/table', array(), $html );
}

/**
 * Table row.
 *
 * @param DOMElement $tr Row.
 * @return string
 */
function row( DOMElement $tr ): string {
	$html = '<tr>';
	foreach ( $tr->childNodes as $cell ) {
		if ( ! $cell instanceof DOMElement ) {
			continue;
		}
		$tag   = strtolower( $cell->nodeName );
		$attrs = array();
		$align = strtolower( $cell->getAttribute( 'align' ) );
		if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			$attrs['class']      = 'has-text-align-' . $align;
			$attrs['data-align'] = $align;
		}
		foreach ( array( 'colspan', 'rowspan', 'scope' ) as $name ) {
			if ( $cell->hasAttribute( $name ) ) {
				$attrs[ $name ] = $cell->getAttribute( $name );
			}
		}
		$html .= '<' . $tag . attrs_html( $attrs ) . '>' . trim( inner_html( $cell ) ) . '</' . $tag . '>';
	}
	return $html . '</tr>';
}

/**
 * Image block from `<img>`, `<a><img></a>` or `<figure>` holding one of those plus a caption.
 *
 * @param DOMElement $el Element.
 * @return string|null Null when the element is not a lone image.
 */
function image_from( DOMElement $el ): ?string {
	$tag     = strtolower( $el->nodeName );
	$caption = '';
	$link    = null;
	$img     = null;

	if ( 'figure' === $tag ) {
		$children = meaningful_children( $el );
		foreach ( $children as $child ) {
			if ( ! $child instanceof DOMElement ) {
				return null;
			}
			$name = strtolower( $child->nodeName );
			if ( 'figcaption' === $name ) {
				$caption = trim( inner_html( $child ) );
			} elseif ( null === $img && in_array( $name, array( 'img', 'a' ), true ) ) {
				$img = $child;
			} else {
				return null;
			}
		}
		if ( ! $img ) {
			return null;
		}
		$el  = $img;
		$tag = strtolower( $el->nodeName );
	}

	if ( 'a' === $tag ) {
		$children = meaningful_children( $el );
		if ( 1 !== count( $children ) || ! $children[0] instanceof DOMElement || 'img' !== strtolower( $children[0]->nodeName ) ) {
			return null;
		}
		$link = $el->getAttribute( 'href' );
		$el   = $children[0];
		$tag  = 'img';
	}

	if ( 'img' !== $tag ) {
		return null;
	}

	$id = 0;
	if ( preg_match( '/(?:^|\s)wp-image-(\d+)(?:\s|$)/', $el->getAttribute( 'class' ), $m ) ) {
		$id = (int) $m[1];
	}

	$attrs  = array();
	$figure = 'wp-block-image';
	if ( $id ) {
		$attrs['id']       = $id;
		$attrs['sizeSlug'] = 'full';
		$figure           .= ' size-full';
	}
	$attrs['linkDestination'] = null === $link ? 'none' : 'custom';
	if ( ! $id && null === $link ) {
		$attrs = array();
	}

	$img_html = '<img' . attrs_html(
		array(
			'src'   => $el->getAttribute( 'src' ),
			'alt'   => $el->getAttribute( 'alt' ),
			'class' => $id ? 'wp-image-' . $id : '',
			'title' => $el->getAttribute( 'title' ),
		),
		array( 'alt' )
	) . '/>';
	if ( null !== $link ) {
		$img_html = '<a' . attrs_html( array( 'href' => $link ) ) . '>' . $img_html . '</a>';
	}
	if ( '' !== $caption ) {
		$img_html .= '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
	}

	return serialize_block( 'core/image', $attrs, '<figure class="' . $figure . '">' . $img_html . '</figure>' );
}

/**
 * Callout from an alert placeholder.
 *
 * @param DOMElement $el      Placeholder div.
 * @param array      $options Options.
 * @return string
 */
function callout( DOMElement $el, array $options ): string {
	$type   = strtolower( $el->getAttribute( Alerts\ATTRIBUTE ) );
	$notice = $options['type_map'][ $type ] ?? 'info';

	switch ( $options['callout_block'] ) {
		case 'wporg/callout':
			return serialize_block(
				'wporg/callout',
				array( 'type' => $notice ),
				'<div class="callout callout-' . $notice . '">' . join_inner( convert_children( $el, $options ) ) . '</div>'
			);

		case 'core/group':
			return serialize_block(
				'core/group',
				array(
					'className' => 'callout callout-' . $notice,
					'layout'    => array( 'type' => 'constrained' ),
				),
				'<div class="wp-block-group callout callout-' . $notice . '">' . join_inner( convert_children( $el, $options ) ) . '</div>'
			);

		default:
			// wporg/notice saves its content as static HTML (RichText multiline "p"), not inner blocks.
			return serialize_block(
				'wporg/notice',
				array( 'type' => $notice ),
				'<div class="wp-block-wporg-notice is-' . $notice . '-notice"><div class="wp-block-wporg-notice__icon"></div><div class="wp-block-wporg-notice__content">' . trim( inner_html( $el ) ) . '</div></div>'
			);
	}
}

/**
 * Details block.
 *
 * @param DOMElement $el      `<details>`.
 * @param array      $options Options.
 * @return string
 */
function details( DOMElement $el, array $options ): string {
	$summary = '';
	foreach ( $el->childNodes as $child ) {
		if ( $child instanceof DOMElement && 'summary' === strtolower( $child->nodeName ) ) {
			$summary = trim( inner_html( $child ) );
			$el->removeChild( $child );
			break;
		}
	}
	$attrs = array();
	if ( $el->hasAttribute( 'open' ) ) {
		$attrs['showContent'] = true;
	}

	return serialize_block(
		'core/details',
		$attrs,
		'<details class="wp-block-details"' . ( $el->hasAttribute( 'open' ) ? ' open' : '' ) . '><summary>' . $summary . '</summary>' . join_inner( convert_children( $el, $options ) ) . '</details>'
	);
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

/**
 * Serialize attributes, skipping empty values unless listed in $keep_empty.
 *
 * @param array    $attrs      Name => value.
 * @param string[] $keep_empty Names to output even when empty.
 * @return string
 */
function attrs_html( array $attrs, array $keep_empty = array() ): string {
	$html = '';
	foreach ( $attrs as $name => $value ) {
		$value = (string) $value;
		if ( '' === $value && ! in_array( $name, $keep_empty, true ) ) {
			continue;
		}
		$html .= ' ' . $name . '="' . htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', false ) . '"';
	}
	return $html;
}

/**
 * Join inner blocks the way the block serializer does.
 *
 * @param string[] $blocks Serialized blocks.
 * @return string
 */
function join_inner( array $blocks ): string {
	return implode( "\n\n", $blocks );
}

/**
 * Serialize one block (same comment format and attribute encoding as core's serialize_block()).
 *
 * @param string $name  Block name.
 * @param array  $attrs Comment attributes.
 * @param string $html  Saved HTML.
 * @return string
 */
function serialize_block( string $name, array $attrs, string $html ): string {
	$name = 0 === strpos( $name, 'core/' ) ? substr( $name, 5 ) : $name;
	$json = '';
	if ( $attrs ) {
		$json = json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$json = preg_replace( '/--/', '\\u002d\\u002d', $json );
		$json = preg_replace( '/</', '\\u003c', $json );
		$json = preg_replace( '/>/', '\\u003e', $json );
		$json = preg_replace( '/&/', '\\u0026', $json );
		$json = preg_replace( '/\\\\"/', '\\u0022', $json );
		$json = ' ' . $json;
	}

	return "<!-- wp:{$name}{$json} -->\n{$html}\n<!-- /wp:{$name} -->";
}

/**
 * Return any non-whitespace content that sits outside a top-level block.
 *
 * Block markup disables `wpautop`, so text outside a block would render without paragraphs. The
 * importer calls this after converting and refuses to save classic leftovers.
 *
 * @param string $markup Block markup.
 * @return string[] Freeform fragments (empty when everything is inside a block).
 */
function find_freeform( string $markup ): array {
	$pattern = '#<!--\s+(?P<closer>/)?wp:(?P<name>[a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)\s+(?:\{(?:(?!-->).)*?\}\s+)?(?P<void>/)?-->#s';
	$depth   = 0;
	$offset  = 0;
	$found   = array();

	while ( preg_match( $pattern, $markup, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
		$start = $m[0][1];
		if ( 0 === $depth ) {
			$between = substr( $markup, $offset, $start - $offset );
			if ( '' !== trim( $between ) ) {
				$found[] = trim( $between );
			}
		}
		if ( ! empty( $m['void'][0] ) ) {
			// Self-closing block: depth unchanged.
		} elseif ( ! empty( $m['closer'][0] ) ) {
			--$depth;
		} else {
			++$depth;
		}
		$offset = $start + strlen( $m[0][0] );
	}
	$rest = substr( $markup, $offset );
	if ( 0 === $depth && '' !== trim( $rest ) ) {
		$found[] = trim( $rest );
	}
	if ( 0 !== $depth ) {
		$found[] = '(unbalanced block delimiters)';
	}

	return $found;
}
