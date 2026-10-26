<?php
/**
 * Breadcrumbs: the docs home and the ancestors of the current page (the page itself is the H1 below).
 *
 * @package WP_Docs_Theme
 */

namespace WP_Docs_Theme;

defined( 'ABSPATH' ) || die();

$post = queried_doc();
if ( ! $post ) {
	return;
}
$crumbs = array_values(
	array_filter(
		breadcrumbs( $post ),
		static function ( $crumb ) {
			return empty( $crumb['current'] );
		}
	)
);
if ( ! $crumbs ) {
	return;
}
$items = '';
foreach ( $crumbs as $crumb ) {
	$items .= sprintf(
		'<li><a href="%1$s">%2$s</a></li>',
		esc_url( $crumb['url'] ),
		esc_html( html_entity_decode( $crumb['title'], ENT_QUOTES, 'UTF-8' ) )
	);
}
printf(
	'<nav class="wp-docs-breadcrumbs" aria-label="%1$s"><ol>%2$s</ol></nav>',
	esc_attr__( 'Breadcrumbs', 'wp-docs' ),
	$items // phpcs:ignore WordPress.Security.EscapeOutput
);
