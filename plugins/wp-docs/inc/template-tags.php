<?php
/**
 * Helpers for the theme (CONTRACT.md, "What the theme expects"). Global functions so the theme can check
 * `function_exists()` and degrade without the plugin. Signatures are stable.
 *
 * @package WP_Docs
 */

defined( 'WPINC' ) || die();

require_once __DIR__ . '/tree.php';

/**
 * The post, when it is a docs page.
 *
 * @param int|WP_Post|null $post Post, default the current one.
 * @return WP_Post|null
 */
function wp_docs_get_post( $post = null ) {
	$post = get_post( $post );
	return ( $post instanceof WP_Post && \WP_Docs\POST_TYPE === $post->post_type ) ? $post : null;
}

/**
 * The importer's meta of a page as an array, without the `_wp_docs_` prefix.
 *
 * Keys (all strings unless noted; "" when unknown):
 * id, path, section, type, status ("", "placeholder", "draft"), description, updated (YYYY-MM-DD),
 * source_url, edit_url, issue_url, commit, imported (ISO 8601), url, markdown_url,
 * redirect_from (array of paths), applies_to (array), is_home (bool).
 * The page's Markdown itself is not included (read `_wp_docs_markdown` when needed).
 *
 * @param int|WP_Post|null $post Post, default the current one.
 * @return array Empty array when the post is not a docs page.
 */
function wp_docs_get_page_meta( $post = null ) {
	$post = wp_docs_get_post( $post );
	if ( ! $post ) {
		return array();
	}

	$meta = array();
	foreach ( array( 'id', 'path', 'section', 'type', 'status', 'description', 'updated', 'source_url', 'edit_url', 'issue_url', 'commit', 'imported' ) as $key ) {
		$value        = get_post_meta( $post->ID, '_wp_docs_' . $key, true );
		$meta[ $key ] = is_scalar( $value ) && 'null' !== $value ? (string) $value : '';
	}
	foreach ( array( 'redirect_from', 'applies_to' ) as $key ) {
		$value        = json_decode( (string) get_post_meta( $post->ID, '_wp_docs_' . $key, true ), true );
		$meta[ $key ] = is_array( $value ) ? array_values( $value ) : array();
	}
	if ( '' !== $meta['path'] ) {
		$meta['path'] = trim( $meta['path'], '/' ) . '/';
	}
	$meta['is_home']      = '' === $meta['path'] && 0 === (int) $post->post_parent && in_array( $meta['id'], array( 'index', '' ), true );
	$meta['url']          = wp_docs_path_url( $meta['path'] );
	$meta['markdown_url'] = wp_docs_path_markdown_url( $meta['path'] );

	/**
	 * Filters the page meta returned to the theme.
	 *
	 * @param array   $meta Meta.
	 * @param WP_Post $post Post.
	 */
	return apply_filters( 'wp_docs_page_meta', $meta, $post );
}

/**
 * Breadcrumbs of a page: the home ("Documentation", /docs/), then each ancestor, then the page itself.
 *
 * Each item: array( 'id' => post ID (0 for the home when it has no post), 'title' => string,
 * 'url' => string, 'current' => bool ). The home page itself returns only the home item.
 *
 * @param int|WP_Post|null $post Post, default the current one.
 * @return array[]
 */
function wp_docs_get_breadcrumbs( $post = null ) {
	$post = wp_docs_get_post( $post );
	$tree = wp_docs_get_tree();

	$crumbs = array(
		array(
			'id'      => (int) $tree['home'],
			'title'   => 'Documentation',
			'url'     => wp_docs_path_url( '' ),
			'current' => false,
		),
	);
	if ( ! $post ) {
		return $crumbs;
	}
	if ( (int) $tree['home'] === $post->ID ) {
		$crumbs[0]['current'] = true;
		return $crumbs;
	}

	$chain = array();
	$id    = $post->ID;
	$seen  = array();
	while ( $id && ! isset( $seen[ $id ] ) ) {
		$seen[ $id ] = true;
		if ( isset( $tree['nodes'][ $id ] ) ) {
			$node   = $tree['nodes'][ $id ];
			$title  = $node['title'];
			$url    = $node['url'];
			$parent = (int) $node['parent'];
		} else {
			// Not in the published tree (a draft preview): fall back to the post itself.
			$p = get_post( $id );
			if ( ! $p ) {
				break;
			}
			$title  = get_the_title( $p );
			$url    = (string) get_permalink( $p );
			$parent = (int) $p->post_parent;
		}
		$chain[] = array(
			'id'      => $id,
			'title'   => $title,
			'url'     => $url,
			'current' => $id === $post->ID,
		);
		$id      = $parent;
	}

	return array_merge( $crumbs, array_reverse( $chain ) );
}

/**
 * The previous and next pages in sidebar order (depth first through the sections; the home is not part
 * of the sequence). Each is a tree node (see wp_docs_get_tree()) or null.
 *
 * @param int|WP_Post|null $post Post, default the current one.
 * @return array{prev: array|null, next: array|null}
 */
function wp_docs_get_adjacent( $post = null ) {
	$post   = wp_docs_get_post( $post );
	$result = array(
		'prev' => null,
		'next' => null,
	);
	if ( ! $post ) {
		return $result;
	}
	$tree  = wp_docs_get_tree();
	$index = array_search( $post->ID, $tree['order'], true );
	if ( false === $index ) {
		return $result;
	}
	if ( $index > 0 ) {
		$result['prev'] = $tree['nodes'][ $tree['order'][ $index - 1 ] ];
	}
	if ( isset( $tree['order'][ $index + 1 ] ) ) {
		$result['next'] = $tree['nodes'][ $tree['order'][ $index + 1 ] ];
	}
	return $result;
}

/**
 * Absolute URL of a page's Markdown: /docs/<path>.md (/docs/index.md for the home).
 *
 * @param int|WP_Post|null $post Post, default the current one.
 * @return string "" when the post is not a docs page.
 */
function wp_docs_markdown_url( $post = null ) {
	$post = wp_docs_get_post( $post );
	if ( ! $post ) {
		return '';
	}
	return wp_docs_path_markdown_url( (string) get_post_meta( $post->ID, '_wp_docs_path', true ) );
}

/**
 * Edit, source and issue links of a page with the imported commit and time (kept from the hub plugin).
 *
 * @param int|WP_Post|null $post Post.
 * @return array{edit_url: string, issue_url: string, source_url: string, commit: string, imported: string}
 */
function wp_docs_page_links( $post = null ) {
	$meta  = wp_docs_get_page_meta( $post );
	$links = array();
	foreach ( array( 'edit_url', 'issue_url', 'source_url', 'commit', 'imported' ) as $key ) {
		$links[ $key ] = $meta[ $key ] ?? '';
	}
	return apply_filters( 'wp_docs_page_links', $links, wp_docs_get_post( $post ) );
}
