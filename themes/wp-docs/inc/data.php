<?php
/**
 * Data access for the blocks. Every function prefers the plugin's helper (CONTRACT.md, "What the theme
 * expects") and falls back to reading the post and its meta directly, so the theme renders without the
 * plugin and never fatals.
 *
 * @package WP_Docs_Theme
 */

namespace WP_Docs_Theme;

defined( 'ABSPATH' ) || die();

const POST_TYPE = 'docs';

/**
 * The documentation tree, in the shape of `wp_docs_get_tree()`:
 * nodes (by post ID), children (by parent post ID, 0 lists the sections), sections, order, home.
 *
 * @return array
 */
function tree() {
	static $fallback = null;
	if ( function_exists( 'wp_docs_get_tree' ) ) {
		$tree = wp_docs_get_tree();
		if ( is_array( $tree ) && isset( $tree['nodes'] ) ) {
			return $tree + array(
				'children' => array(),
				'sections' => array(),
				'order'    => array(),
				'home'     => 0,
			);
		}
	}
	if ( null !== $fallback ) {
		return $fallback;
	}

	$posts = get_posts(
		array(
			'post_type'        => POST_TYPE,
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'orderby'          => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'no_found_rows'    => true,
			'suppress_filters' => true,
		)
	);

	$nodes = array();
	$kids  = array();
	$home  = 0;
	foreach ( $posts as $post ) {
		$path = trim( (string) get_post_meta( $post->ID, '_wp_docs_path', true ), '/' );
		$node = array(
			'id'          => $post->ID,
			'doc_id'      => (string) get_post_meta( $post->ID, '_wp_docs_id', true ),
			'title'       => get_the_title( $post ),
			'parent'      => (int) $post->post_parent,
			'path'        => '' === $path ? '' : $path . '/',
			'url'         => (string) get_permalink( $post ),
			'md_url'      => '',
			'section'     => (string) get_post_meta( $post->ID, '_wp_docs_section', true ),
			'type'        => (string) get_post_meta( $post->ID, '_wp_docs_type', true ),
			'status'      => (string) get_post_meta( $post->ID, '_wp_docs_status', true ),
			'description' => (string) get_post_meta( $post->ID, '_wp_docs_description', true ),
			'order'       => (int) $post->menu_order,
			'depth'       => 0,
		);
		$nodes[ $post->ID ] = $node;
		if ( 0 === $node['parent'] && '' === $path && in_array( $node['doc_id'], array( '', 'index' ), true ) && metadata_exists( 'post', $post->ID, '_wp_docs_path' ) ) {
			$home = $post->ID;
			continue;
		}
		$kids[ $node['parent'] ][] = $post->ID;
	}

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

	$fallback = array(
		'nodes'    => $nodes,
		'children' => $children,
		'sections' => $children[0] ?? array(),
		'order'    => $order,
		'home'     => $home,
	);
	return $fallback;
}

/**
 * Child nodes of a post, in order.
 *
 * @param int $id Post ID (0 for the sections).
 * @return array[]
 */
function children( $id ) {
	$tree = tree();
	$out  = array();
	foreach ( $tree['children'][ (int) $id ] ?? array() as $child ) {
		if ( isset( $tree['nodes'][ $child ] ) ) {
			$out[] = $tree['nodes'][ $child ];
		}
	}
	return $out;
}

/**
 * A section landing by its path segment ("write"), or null.
 *
 * @param string $slug First path segment.
 * @return array|null
 */
function section( $slug ) {
	foreach ( children( 0 ) as $node ) {
		if ( trim( $node['path'], '/' ) === $slug || $node['section'] === $slug ) {
			return $node;
		}
	}
	return null;
}

/**
 * The current docs post, or null when the request is not for one.
 *
 * @param int|\WP_Post|null $post Post.
 * @return \WP_Post|null
 */
function current_post( $post = null ) {
	$post = get_post( $post );
	return ( $post instanceof \WP_Post && POST_TYPE === $post->post_type ) ? $post : null;
}

/**
 * Page meta in the shape of `wp_docs_get_page_meta()`.
 *
 * @param int|\WP_Post|null $post Post.
 * @return array
 */
function page_meta( $post = null ) {
	$post = current_post( $post );
	if ( ! $post ) {
		return array();
	}
	$meta = function_exists( 'wp_docs_get_page_meta' ) ? (array) wp_docs_get_page_meta( $post ) : array();
	if ( ! $meta ) {
		foreach ( array( 'id', 'path', 'section', 'type', 'status', 'description', 'updated', 'source_url', 'edit_url', 'issue_url', 'commit', 'imported' ) as $key ) {
			$value        = get_post_meta( $post->ID, '_wp_docs_' . $key, true );
			$meta[ $key ] = is_scalar( $value ) && 'null' !== $value ? (string) $value : '';
		}
		$meta['url']          = (string) get_permalink( $post );
		$meta['markdown_url'] = '';
		$meta['is_home']      = false;
	}
	if ( '' === ( $meta['description'] ?? '' ) && has_excerpt( $post ) ) {
		$meta['description'] = get_the_excerpt( $post );
	}
	if ( empty( $meta['markdown_url'] ) ) {
		$meta['markdown_url'] = markdown_url( $post );
	}
	return $meta;
}

/**
 * The Markdown URL of a page ("" when unknown).
 *
 * @param int|\WP_Post|null $post Post.
 * @return string
 */
function markdown_url( $post = null ) {
	$post = current_post( $post );
	if ( ! $post ) {
		return '';
	}
	// Without the plugin there is no Markdown endpoint: "" hides the Markdown actions.
	return function_exists( 'wp_docs_markdown_url' ) ? (string) wp_docs_markdown_url( $post ) : '';
}

/**
 * Breadcrumbs: [ [ id, title, url, current ] ], starting with the docs home.
 *
 * @param int|\WP_Post|null $post Post.
 * @return array[]
 */
function breadcrumbs( $post = null ) {
	$post = current_post( $post );
	if ( function_exists( 'wp_docs_get_breadcrumbs' ) ) {
		return (array) wp_docs_get_breadcrumbs( $post );
	}
	$crumbs = array(
		array(
			'id'      => 0,
			'title'   => __( 'Documentation', 'wp-docs' ),
			'url'     => docs_home_url(),
			'current' => false,
		),
	);
	if ( ! $post ) {
		return $crumbs;
	}
	$chain = array();
	foreach ( array_merge( array( $post->ID ), get_post_ancestors( $post ) ) as $id ) {
		$chain[] = array(
			'id'      => $id,
			'title'   => get_the_title( $id ),
			'url'     => (string) get_permalink( $id ),
			'current' => $id === $post->ID,
		);
	}
	return array_merge( $crumbs, array_reverse( $chain ) );
}

/**
 * Previous and next pages in sidebar order.
 *
 * @param int|\WP_Post|null $post Post.
 * @return array{prev: array|null, next: array|null}
 */
function adjacent( $post = null ) {
	$post = current_post( $post );
	if ( ! $post ) {
		return array(
			'prev' => null,
			'next' => null,
		);
	}
	if ( function_exists( 'wp_docs_get_adjacent' ) ) {
		return (array) wp_docs_get_adjacent( $post ) + array(
			'prev' => null,
			'next' => null,
		);
	}
	$tree  = tree();
	$index = array_search( $post->ID, $tree['order'], true );
	return array(
		'prev' => false !== $index && $index > 0 ? $tree['nodes'][ $tree['order'][ $index - 1 ] ] : null,
		'next' => false !== $index && isset( $tree['order'][ $index + 1 ] ) ? $tree['nodes'][ $tree['order'][ $index + 1 ] ] : null,
	);
}

/**
 * The /docs/ URL.
 *
 * @return string
 */
function docs_home_url() {
	if ( function_exists( 'wp_docs_path_url' ) ) {
		return (string) wp_docs_path_url( '' );
	}
	$archive = get_post_type_archive_link( POST_TYPE );
	return $archive ? $archive : home_url( '/docs/' );
}

/**
 * Ids of the current page and its ancestors, for the sidebar's open state.
 *
 * @param int $id Post ID.
 * @return int[] id => true
 */
function path_ids( $id ) {
	$out  = array();
	$tree = tree();
	while ( $id && ! isset( $out[ $id ] ) ) {
		$out[ $id ] = true;
		$id         = isset( $tree['nodes'][ $id ] ) ? (int) $tree['nodes'][ $id ]['parent'] : (int) wp_get_post_parent_id( $id );
	}
	return $out;
}

/**
 * Display label of a page type.
 *
 * @param string $type tutorial, guide, concept, reference.
 * @return string
 */
function type_label( $type ) {
	$labels = array(
		'tutorial'  => __( 'Tutorial', 'wp-docs' ),
		'guide'     => __( 'Guide', 'wp-docs' ),
		'concept'   => __( 'Concept', 'wp-docs' ),
		'reference' => __( 'Reference', 'wp-docs' ),
	);
	return $labels[ $type ] ?? '';
}

/**
 * The content repository on GitHub (for the docs bar link).
 *
 * @return string
 */
function repo_url() {
	/**
	 * Filters the content repository URL shown in the docs bar.
	 *
	 * @param string $url Default the ndiego/wordpress-documentation repository.
	 */
	return (string) apply_filters( 'wp_docs_theme_repo_url', 'https://github.com/ndiego/wordpress-documentation' );
}

/**
 * Where to report a problem with a page: its prefilled issue URL, or a new issue in the content repository.
 *
 * @param array $meta page_meta().
 * @return string
 */
function report_url( $meta ) {
	return ! empty( $meta['issue_url'] ) ? $meta['issue_url'] : trailingslashit( repo_url() ) . 'issues/new';
}
