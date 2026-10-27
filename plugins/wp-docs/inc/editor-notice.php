<?php
/**
 * The block editor's GitHub panel (assets/editor-notice.js): where the doc lives, what a save does for
 * this user (class-sync.php), and links to the file, its history, the open pull request and the last
 * commit pushed from here. The script also holds the pre-publish check for new docs and turns the REST
 * error that reports a proposal into a notice that reads as one.
 *
 * @package WP_Docs
 */

namespace WP_Docs;

defined( 'WPINC' ) || die();

add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue_editor_notice' );

/**
 * Enqueue the editor script with its data when a doc is open in the block editor.
 */
function enqueue_editor_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || POST_TYPE !== $screen->post_type ) {
		return;
	}
	$post    = get_post();
	$repo    = content_repo();
	$managed = $post && '' !== (string) get_post_meta( $post->ID, 'wporg_markdown_source', true );
	$source  = $managed ? Sources\parse( (string) get_post_meta( $post->ID, 'wporg_markdown_source', true ) ) : null;
	$github  = $source ? Sources\to_github( $source, repo_aliases() ) : null;
	$pull    = $post ? json_decode( (string) get_post_meta( $post->ID, '_wp_docs_pull_request', true ), true ) : null;
	$push    = $post ? json_decode( (string) get_post_meta( $post->ID, '_wp_docs_last_push', true ), true ) : null;
	$base    = 'https://github.com/' . $repo['repo'];
	$branch  = Sync::branch();

	$data = array(
		'mode'        => Sync::mode_for_user( get_current_user_id() ),
		'managed'     => $managed,
		'repo'        => $repo['repo'],
		'branch'      => $branch,
		'source_path' => $github ? $github['path'] : '',
		'source_url'  => $github ? $base . '/blob/' . rawurlencode( $branch ) . '/' . Sources\encode_path( $github['path'] ) : '',
		'edit_url'    => $github ? $base . '/edit/' . rawurlencode( $branch ) . '/' . Sources\encode_path( $github['path'] ) : '',
		'history_url' => $github ? $base . '/commits/' . rawurlencode( $branch ) . '/' . Sources\encode_path( $github['path'] ) : '',
		'pull'        => is_array( $pull ) && ! empty( $pull['url'] ) ? array(
			'number' => (int) $pull['number'],
			'url'    => (string) $pull['url'],
		) : null,
		'last_push'   => is_array( $push ) && ! empty( $push['commit'] ) ? array(
			'commit' => (string) $push['commit'],
			'url'    => $base . '/commit/' . (string) $push['commit'],
		) : null,
		'sections'    => $managed ? array() : sections_for_editor(),
		'home'        => $managed ? 0 : (int) ( wp_docs_get_tree()['home'] ?? 0 ),
	);

	$file = dirname( __DIR__ ) . '/assets/editor-notice.js';
	wp_enqueue_script(
		'wp-docs-editor-notice',
		plugins_url( 'assets/editor-notice.js', PLUGIN_FILE ),
		array( 'wp-data', 'wp-notices', 'wp-dom-ready', 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components' ),
		file_exists( $file ) ? (string) filemtime( $file ) : '0',
		true
	);
	wp_add_inline_script( 'wp-docs-editor-notice', 'window.wpDocsEditor = ' . wp_json_encode( $data ) . ';', 'before' );
}

/**
 * The pages a new doc can live under: every page with a folder of its own (a README.md source), in
 * sidebar order, with its depth for indentation.
 *
 * @return array[] { id, title, depth }
 */
function sections_for_editor() {
	$tree     = wp_docs_get_tree();
	$sections = array();
	foreach ( $tree['order'] as $id ) {
		$source = (string) get_post_meta( $id, 'wporg_markdown_source', true );
		if ( 'readme.md' !== strtolower( basename( (string) wp_parse_url( $source, PHP_URL_PATH ) ) ) ) {
			continue;
		}
		$sections[] = array(
			'id'    => (int) $id,
			'title' => html_entity_decode( (string) $tree['nodes'][ $id ]['title'], ENT_QUOTES, 'UTF-8' ),
			'depth' => (int) $tree['nodes'][ $id ]['depth'],
		);
	}
	return $sections;
}
