<?php
/**
 * The documentation tree: every published `wp-docs` page from one query, cached in a transient that is
 * valid for one import (keyed by the time in the `wp_docs_last_import` option). The theme's sidebar, home,
 * section cards, breadcrumbs and prev/next all read it; so do llms.txt, llms-full.txt and docs/index.json.
 *
 * Ported from the hub theme's `blocks/sidebar/tree.php` (ndiego/wordpress-documentation), without tabs.
 *
 * @package WP_Docs
 */

defined( 'WPINC' ) || die();

/**
 * The whole documentation tree.
 *
 * Shape (stable; the theme codes against it):
 *
 *     array(
 *         'nodes'    => array(
 *             <post ID> => array(
 *                 'id'          => int     Post ID.
 *                 'doc_id'      => string  Stable page id (`_wp_docs_id`, e.g. "write/create-a-post"; "index" for the home).
 *                 'title'       => string  Title, texturized.
 *                 'parent'      => int     Parent post ID, 0 for the home and the section landings.
 *                 'path'        => string  Path under /docs/ with a trailing slash ("write/create-a-post/"); "" for the home.
 *                 'url'         => string  Absolute URL of the page.
 *                 'md_url'      => string  Absolute URL of the page's Markdown.
 *                 'section'     => string  First path segment ("write"); "" for the home.
 *                 'type'        => string  tutorial, guide, concept, reference, or "".
 *                 'status'      => string  "", "placeholder" or "draft".
 *                 'description' => string  The front matter description.
 *                 'order'       => int     menu_order (the manifest `order`).
 *                 'depth'       => int     0 for the home and the section landings, 1 for their children, ...
 *             ),
 *         ),
 *         'children' => array( <parent post ID> => array( <child post IDs in order> ) ),
 *                       Key 0 lists the section landings in order. The home is not in it.
 *         'sections' => array( <post IDs of the section landings in order> ) (same as children[0]),
 *         'order'    => array( <post IDs in sidebar order: depth first, sections then their pages> ),
 *                       The home is not in it.
 *         'home'     => int    Post ID of the home (the manifest key `index`, path ""), 0 when there is none.
 *         'stamp'    => string Cache key the tree was built for.
 *     )
 *
 * Password-protected pages, and pages whose parent is not published, are left out. Without an import (`wp_docs_last_import` empty) the
 * tree is built on every request.
 *
 * @return array
 */
function wp_docs_get_tree() {
	static $tree = null;
	static $tree_stamp = null;

	$last  = get_option( 'wp_docs_last_import' );
	$time  = is_array( $last ) && ! empty( $last['time'] ) ? (string) $last['time'] : '';
	$stamp = '2|' . $time . '|' . home_url();

	if ( null !== $tree && $tree_stamp === $stamp ) {
		return $tree;
	}

	if ( '' !== $time ) {
		$cached = get_transient( 'wp_docs_tree' );
		if ( is_array( $cached ) && isset( $cached['stamp'] ) && $cached['stamp'] === $stamp ) {
			$tree       = $cached;
			$tree_stamp = $stamp;
			return $tree;
		}
	}

	$tree          = wp_docs_build_tree();
	$tree['stamp'] = $stamp;
	$tree_stamp    = $stamp;
	if ( '' !== $time ) {
		// An expiration keeps the transient out of the autoloaded options.
		set_transient( 'wp_docs_tree', $tree, WEEK_IN_SECONDS );
	}
	return $tree;
}

/**
 * Build the tree from the published pages with one query. Use wp_docs_get_tree() instead.
 *
 * @return array See wp_docs_get_tree().
 */
function wp_docs_build_tree() {
	global $wpdb;

	$meta = array(
		'doc_id'      => '_wp_docs_id',
		'path'        => '_wp_docs_path',
		'section'     => '_wp_docs_section',
		'type'        => '_wp_docs_type',
		'status'      => '_wp_docs_status',
		'description' => '_wp_docs_description',
	);
	$select = '';
	$joins  = '';
	foreach ( $meta as $alias => $key ) {
		$select .= ", m_{$alias}.meta_value AS {$alias}";
		$joins  .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} m_{$alias} ON ( m_{$alias}.post_id = p.ID AND m_{$alias}.meta_key = %s )", $key ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_parent, p.post_name, p.menu_order{$select}
			FROM {$wpdb->posts} p{$joins}
			WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_password = ''
			ORDER BY p.menu_order ASC, p.post_title ASC, p.ID ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			\WP_Docs\POST_TYPE
		)
	);

	$nodes = array();
	$kids  = array();
	$home  = 0;
	foreach ( (array) $rows as $row ) {
		$id = (int) $row->ID;
		if ( isset( $nodes[ $id ] ) ) {
			continue;
		}
		$path = trim( (string) $row->path, '/' );
		$path = '' === $path ? '' : $path . '/';
		$node = array(
			'id'          => $id,
			'doc_id'      => (string) $row->doc_id,
			'title'       => wptexturize( (string) $row->post_title ),
			'parent'      => (int) $row->post_parent,
			'path'        => $path,
			'url'         => wp_docs_path_url( $path ),
			'md_url'      => wp_docs_path_markdown_url( $path ),
			'section'     => (string) $row->section,
			'type'        => (string) $row->type,
			'status'      => (string) $row->status,
			'description' => (string) $row->description,
			'order'       => (int) $row->menu_order,
			'depth'       => 0,
		);
		$nodes[ $id ] = $node;
		if ( '' === $path && 0 === $node['parent'] && ( 'index' === $node['doc_id'] || '' === $node['doc_id'] ) && null !== $row->path ) {
			$home = $id;
			continue;
		}
		$kids[ $node['parent'] ][] = $id;
	}

	// Keep what is reachable from the sections, in order, and record depth and sidebar order.
	$children = array();
	$order    = array();
	$walk     = static function ( $parent, $depth ) use ( &$walk, &$children, &$order, &$nodes, $kids ) {
		if ( empty( $kids[ $parent ] ) ) {
			return;
		}
		$children[ $parent ] = $kids[ $parent ];
		foreach ( $kids[ $parent ] as $id ) {
			$nodes[ $id ]['depth'] = $depth;
			$order[]               = $id;
			$walk( $id, $depth + 1 );
		}
	};
	$walk( 0, 0 );

	$keep = array_flip( $order );
	if ( $home ) {
		$keep[ $home ] = true;
	}
	$nodes = array_intersect_key( $nodes, $keep );

	return array(
		'nodes'    => $nodes,
		'children' => $children,
		'sections' => $children[0] ?? array(),
		'order'    => $order,
		'home'     => $home,
	);
}

/**
 * Absolute URL of a docs path ("write/create-a-post/" or "" for the home).
 *
 * @param string $path Path under /docs/.
 * @return string
 */
function wp_docs_path_url( $path ) {
	$path = trim( (string) $path, '/' );
	return home_url( '' === $path ? '/docs/' : user_trailingslashit( '/docs/' . $path ) );
}

/**
 * Absolute URL of the Markdown of a docs path: /docs/<path>.md, and /docs/index.md for the home.
 *
 * @param string $path Path under /docs/.
 * @return string
 */
function wp_docs_path_markdown_url( $path ) {
	$path = trim( (string) $path, '/' );
	return home_url( '/docs/' . ( '' === $path ? 'index' : $path ) . '.md' );
}
