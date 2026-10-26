<?php
/**
 * Markup shared by more than one block (the page actions and the table of contents appear in the right
 * rail and, below 1100px, in the page header).
 *
 * @package WP_Docs_Theme
 */

namespace WP_Docs_Theme;

defined( 'ABSPATH' ) || die();

/**
 * The docs post the request is for (singular `wp-docs`), or null.
 *
 * @return \WP_Post|null
 */
function queried_doc() {
	if ( ! is_singular( POST_TYPE ) ) {
		return null;
	}
	return current_post( get_queried_object() );
}

/**
 * A title or other stored text with its HTML entities decoded (escape it on output).
 *
 * @param string $text Text.
 * @return string
 */
function decode( $text ) {
	return html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * The "Copy page" split button and its menu.
 *
 * @param \WP_Post $post    Post.
 * @param string   $variant `rail` or `inline`.
 * @return string
 */
function page_actions_markup( $post, $variant = 'rail' ) {
	$meta = page_meta( $post );
	if ( ! $meta ) {
		return '';
	}
	static $n = 0;
	++$n;
	$md_url  = $meta['markdown_url'] ?? '';
	$menu_id = 'wp-docs-actions-menu-' . $n;
	/* translators: %s: URL of the page's Markdown. */
	$prompt = sprintf( __( 'Read %s and help me with it.', 'wp-docs' ), $md_url ? $md_url : ( $meta['url'] ?? get_permalink( $post ) ) );

	$items = array();
	if ( $md_url ) {
		$items[] = array(
			'href'  => $md_url,
			'icon'  => 'markdown',
			'label' => __( 'View as Markdown', 'wp-docs' ),
			'desc'  => __( 'This page as plain text', 'wp-docs' ),
		);
	}
	$items[] = array(
		'href'  => 'https://chatgpt.com/?hints=search&q=' . rawurlencode( $prompt ),
		'icon'  => 'external',
		'label' => __( 'Open in ChatGPT', 'wp-docs' ),
		'desc'  => __( 'Ask questions about this page', 'wp-docs' ),
	);
	$items[] = array(
		'href'  => 'https://claude.ai/new?q=' . rawurlencode( $prompt ),
		'icon'  => 'external',
		'label' => __( 'Open in Claude', 'wp-docs' ),
		'desc'  => __( 'Ask questions about this page', 'wp-docs' ),
	);
	$secondary = array();
	if ( ! empty( $meta['edit_url'] ) ) {
		$secondary[] = array(
			'href'  => $meta['edit_url'],
			'icon'  => 'edit',
			'label' => __( 'Edit on GitHub', 'wp-docs' ),
		);
	}
	$secondary[] = array(
		'href'  => report_url( $meta ),
		'icon'  => 'flag',
		'label' => __( 'Report a problem', 'wp-docs' ),
	);

	$item_html = static function ( $item ) {
		return sprintf(
			'<a class="wp-docs-menu__item" role="menuitem" href="%1$s" target="_blank" rel="noopener">%2$s<span class="wp-docs-menu__text"><span class="wp-docs-menu__label">%3$s</span>%4$s</span></a>',
			esc_url( $item['href'] ),
			icon( $item['icon'], 20 ),
			esc_html( $item['label'] ),
			isset( $item['desc'] ) ? '<span class="wp-docs-menu__desc">' . esc_html( $item['desc'] ) . '</span>' : ''
		);
	};

	return sprintf(
		'<div class="wp-docs-actions wp-docs-actions--%1$s" data-wp-docs-actions data-md-url="%2$s">' .
			'<button type="button" class="wp-docs-actions__copy" data-copy-page>%3$s<span class="wp-docs-actions__label" data-label-default="%4$s" data-label-done="%5$s" data-label-error="%6$s">%4$s</span></button>' .
			'<button type="button" class="wp-docs-actions__more" aria-haspopup="menu" aria-expanded="false" aria-controls="%7$s" aria-label="%8$s">%9$s</button>' .
			'<div class="wp-docs-menu" id="%7$s" role="menu" aria-label="%10$s" hidden>%11$s<div class="wp-docs-menu__sep" role="separator"></div>%12$s</div>' .
		'</div>',
		esc_attr( $variant ),
		esc_url( $md_url ),
		icon( 'copy', 18 ),
		esc_html__( 'Copy page', 'wp-docs' ),
		esc_attr__( 'Copied', 'wp-docs' ),
		esc_attr__( 'Copy failed', 'wp-docs' ),
		esc_attr( $menu_id ),
		esc_attr__( 'More page actions', 'wp-docs' ),
		icon( 'chevron-down', 18 ),
		esc_attr__( 'Page actions', 'wp-docs' ),
		implode( '', array_map( $item_html, $items ) ),
		implode( '', array_map( $item_html, $secondary ) )
	);
}

/**
 * The list of headings for the table of contents.
 *
 * @param array[] $items toc_items().
 * @return string
 */
function toc_list_markup( $items ) {
	$html = '<ul class="wp-docs-toc__list">';
	foreach ( $items as $item ) {
		$html .= sprintf(
			'<li class="wp-docs-toc__item is-level-%1$d"><a href="#%2$s" data-toc-link>%3$s</a></li>',
			(int) $item['level'],
			esc_attr( $item['id'] ),
			esc_html( $item['text'] )
		);
	}
	return $html . '</ul>';
}

/**
 * A type chip ("Guide").
 *
 * @param string $type Page type.
 * @return string
 */
function type_chip( $type ) {
	$label = type_label( $type );
	if ( '' === $label ) {
		return '';
	}
	return sprintf( '<span class="wp-docs-chip is-%1$s">%2$s</span>', esc_attr( $type ), esc_html( $label ) );
}

/**
 * A "Planned" chip for placeholder pages, "Draft" for drafts.
 *
 * @param string $status Page status.
 * @return string
 */
function status_chip( $status ) {
	if ( 'placeholder' === $status ) {
		return '<span class="wp-docs-chip is-planned">' . esc_html__( 'Planned', 'wp-docs' ) . '</span>';
	}
	if ( 'draft' === $status ) {
		return '<span class="wp-docs-chip is-planned">' . esc_html__( 'Draft', 'wp-docs' ) . '</span>';
	}
	return '';
}

/**
 * A date as "October 20, 2026" in the site's format.
 *
 * @param string $value YYYY-MM-DD or ISO 8601.
 * @return string
 */
function format_date( $value ) {
	$time = $value ? strtotime( $value ) : false;
	return $time ? wp_date( get_option( 'date_format', 'F j, Y' ), $time ) : '';
}

