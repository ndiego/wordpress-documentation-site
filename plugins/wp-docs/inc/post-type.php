<?php
/**
 * The `docs` post type and its meta (CONTRACT.md, "Post type" and "Post meta").
 *
 * @package WP_Docs
 */

namespace WP_Docs;

defined( 'WPINC' ) || die();

const POST_TYPE = 'docs';

/**
 * Bumped when stored posts need a one-time migration (see migrate()).
 */
const POST_TYPE_VERSION = '2';

add_action( 'init', __NAMESPACE__ . '\register_post_type' );
add_action( 'init', __NAMESPACE__ . '\register_meta' );
add_action( 'init', __NAMESPACE__ . '\migrate', 20 );

/**
 * Register the post type.
 */
function register_post_type() {
	\register_post_type(
		POST_TYPE,
		array(
			'labels'       => array(
				'name'          => 'Docs',
				'singular_name' => 'Doc page',
				'add_new_item'  => 'Add doc page',
				'edit_item'     => 'Edit doc page',
				'all_items'     => 'All doc pages',
				'search_items'  => 'Search docs',
			),
			'description'  => 'WordPress documentation, imported from Markdown on GitHub.',
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
 * One-time migrations of stored posts. Version 2: the post type slug changed from `wp-docs` to `docs`
 * (meta keys, options and the REST namespace kept their `wp_docs` / `wp-docs` names).
 */
function migrate() {
	if ( POST_TYPE_VERSION === get_option( 'wp_docs_post_type_version' ) ) {
		return;
	}
	global $wpdb;
	$moved = $wpdb->update( $wpdb->posts, array( 'post_type' => POST_TYPE ), array( 'post_type' => 'wp-docs' ) );
	if ( $moved ) {
		wp_cache_flush();
	}
	update_option( 'wp_docs_post_type_version', POST_TYPE_VERSION, false );
}

/**
 * Meta keys the importer writes. All are single strings; list values are JSON.
 *
 * @return array<string,string> Key => description.
 */
function meta_keys() {
	return array(
		// CONTRACT.md, "Post meta written by the importer".
		'_wp_docs_id'                => 'Stable page id (manifest `id`, default: the path without trailing slash).',
		'_wp_docs_path'              => 'URL path under /docs/ with a trailing slash; empty for the home.',
		'_wp_docs_section'           => 'First path segment; empty for the home.',
		'_wp_docs_type'              => 'tutorial, guide, concept or reference.',
		'_wp_docs_status'            => 'Empty, placeholder or draft.',
		'_wp_docs_description'       => 'The front matter description (also the excerpt).',
		'_wp_docs_updated'           => 'YYYY-MM-DD from front matter, or empty.',
		'_wp_docs_source_url'        => 'GitHub blob URL of the Markdown file.',
		'_wp_docs_edit_url'          => 'GitHub edit URL of the Markdown file.',
		'_wp_docs_issue_url'         => 'Prefilled new-issue URL in the content repository.',
		'_wp_docs_commit'            => 'Commit SHA of the imported revision; empty when unknown.',
		'_wp_docs_imported'          => 'ISO 8601 time of the last content import.',
		'_wp_docs_markdown'          => 'The page as Markdown for agents: front matter, then the body with absolute links.',
		'_wp_docs_redirect_from'     => 'JSON list of root-relative paths that 301 here.',
		// Bookkeeping.
		'_wp_docs_manifest'          => 'Manifest location the page was last imported from.',
		'_wp_docs_removed'           => 'ISO 8601 time the page left the manifest (the post is a draft).',
		'_wp_docs_output_mode'       => 'classic or blocks.',
		'_wp_docs_transform'         => 'Signature of the transforms the content was imported with.',
		'_wp_docs_blob_sha'          => 'Git blob SHA of the Markdown file as imported (the reverse sync checks it before writing).',
		'_wp_docs_pull_request'      => 'JSON of the open pull request proposed from this page: url, number, branch, user, time.',
		'_wp_docs_last_push'         => 'JSON of the last commit pushed from this page: commit, branch, user, time.',
		'_wp_docs_applies_to'        => 'JSON list of reference ids the page explains.',
		'_wp_docs_redirect_external' => 'JSON list of other-host URLs in redirect_from (not served by this site).',
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
