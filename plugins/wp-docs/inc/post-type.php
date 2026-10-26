<?php
/**
 * The `wp-docs` post type and its meta (AGENTS.md "Post type" and "Post meta").
 *
 * @package WP_Docs
 */

namespace WP_Docs;

defined( 'WPINC' ) || die();

const POST_TYPE = 'wp-docs';

add_action( 'init', __NAMESPACE__ . '\register_post_type' );
add_action( 'init', __NAMESPACE__ . '\register_meta' );

/**
 * Register the post type.
 */
function register_post_type() {
	\register_post_type(
		POST_TYPE,
		array(
			'labels'       => array(
				'name'          => 'Docs',
				'singular_name' => 'Docs page',
				'add_new_item'  => 'Add docs page',
				'edit_item'     => 'Edit docs page',
				'all_items'     => 'All docs pages',
				'search_items'  => 'Search docs',
			),
			'description'  => 'WordPress.org documentation, imported from Markdown on GitHub.',
			'public'       => true,
			'hierarchical' => true,
			'has_archive'  => true,
			'show_in_rest' => true,
			'rest_base'    => 'docs',
			'menu_icon'    => 'dashicons-book-alt',
			'rewrite'      => array(
				'slug'       => 'docs',
				'with_front' => false,
			),
			// custom-fields is required for register_post_meta( show_in_rest ) to appear in REST.
			'supports'     => array( 'title', 'editor', 'excerpt', 'revisions', 'page-attributes', 'custom-fields' ),
		)
	);
}

/**
 * Meta keys the importer writes. All are single strings; list values are JSON.
 *
 * @return array<string,string> Key => description.
 */
function meta_keys() {
	return array(
		'_wp_docs_id'                => 'Stable page id from structure.yaml.',
		'_wp_docs_section'           => 'Section slug.',
		'_wp_docs_audience'          => 'JSON list, subset of ["user","developer","contributor"].',
		'_wp_docs_source_url'        => 'GitHub blob URL of the Markdown file.',
		'_wp_docs_edit_url'          => 'GitHub edit URL of the Markdown file.',
		'_wp_docs_issue_url'         => 'Prefilled new-issue URL in the owning repository.',
		'_wp_docs_commit'            => 'Commit SHA of the imported revision; empty when unknown.',
		'_wp_docs_imported'          => 'ISO 8601 time of the last content import.',
		'_wp_docs_generated'         => 'Empty, or JSON {by, from} for generated pages.',
		'_wp_docs_applies_to'        => 'JSON list of reference ids the page explains.',
		'_wp_docs_tab'               => 'Tab of the page\'s section: docs, build or reference (manifest `tab`).',
		// Plugin bookkeeping (not in the AGENTS.md contract; additive).
		'_wp_docs_path'              => 'Path under /docs/ (the manifest key).',
		'_wp_docs_manifest'          => 'Name of the manifest the page comes from.',
		'_wp_docs_redirect_from'     => 'JSON list of same-host paths that redirect here.',
		'_wp_docs_redirect_external' => 'JSON list of other-host URLs that should redirect here (not served by this site).',
		'_wp_docs_removed'           => 'ISO 8601 time the page left the manifest (the post is a draft).',
		'_wp_docs_output_mode'       => 'classic or blocks.',
	);
}

/**
 * Register the meta for REST and the theme.
 */
function register_meta() {
	foreach ( meta_keys() as $key => $description ) {
		register_post_meta(
			POST_TYPE,
			$key,
			array(
				'type'          => 'string',
				'description'   => $description,
				'single'        => true,
				'default'       => '',
				'show_in_rest'  => true,
				// Protected (underscore) keys: written by the importer only.
				'auth_callback' => static fn() => current_user_can( 'manage_options' ),
			)
		);
	}
}
