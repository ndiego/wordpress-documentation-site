<?php
/**
 * The notice in the block editor that says where a save goes.
 *
 * The stock wporg-markdown Editor prints its warning on `edit_form_top`, which only the classic editor
 * screen fires; docs pages are edited in the block editor. The notice depends on the user's sync mode
 * (class-sync.php): a committer is told the save goes live, a proposer that it opens a pull request,
 * and everyone that structure changes happen on GitHub. An open pull request from this page is linked.
 * The script (assets/editor-notice.js) also turns the REST error that reports a proposal into a notice
 * that reads as one.
 *
 * @package WP_Docs
 */

namespace WP_Docs;

defined( 'WPINC' ) || die();

add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue_editor_notice' );

/**
 * Enqueue the notice script with its data when a docs page is open in the block editor.
 */
function enqueue_editor_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || POST_TYPE !== $screen->post_type ) {
		return;
	}
	$post = get_post();
	$edit = $post ? (string) get_post_meta( $post->ID, '_wp_docs_edit_url', true ) : '';
	$mode = Sync::mode_for_user( get_current_user_id() );
	$repo = content_repo();
	$pull = $post ? json_decode( (string) get_post_meta( $post->ID, '_wp_docs_pull_request', true ), true ) : null;

	switch ( $mode ) {
		case 'commit':
			$text = sprintf(
				/* translators: 1: repository, 2: branch */
				__( 'This page is maintained on GitHub. Saving commits your change to %1$s (%2$s) and publishes it here right away. Rename, move, reorder or unpublish the page on GitHub.', 'wp-docs' ),
				$repo['repo'],
				Sync::branch()
			);
			break;
		case 'pull-request':
			$text = __( 'This page is maintained on GitHub. Saving opens a pull request with your change (or updates yours); the page changes when it is merged.', 'wp-docs' );
			break;
		default:
			$text = __( 'This page is maintained on GitHub, and this site cannot write to it. Edit the page on GitHub; changes made here are discarded on the next import.', 'wp-docs' );
	}

	$actions = array();
	if ( is_array( $pull ) && ! empty( $pull['url'] ) ) {
		$actions[] = array(
			/* translators: %d: pull request number */
			'label' => sprintf( __( 'Pull request #%d', 'wp-docs' ), (int) $pull['number'] ),
			'url'   => esc_url_raw( (string) $pull['url'] ),
		);
	}
	if ( '' !== $edit ) {
		$actions[] = array(
			'label' => __( 'Edit on GitHub', 'wp-docs' ),
			'url'   => esc_url_raw( $edit ),
		);
	}

	$managed = $post && '' !== (string) get_post_meta( $post->ID, 'wporg_markdown_source', true );

	$file = dirname( __DIR__ ) . '/assets/editor-notice.js';
	wp_enqueue_script(
		'wp-docs-editor-notice',
		plugins_url( 'assets/editor-notice.js', PLUGIN_FILE ),
		array( 'wp-data', 'wp-notices', 'wp-dom-ready', 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components' ),
		file_exists( $file ) ? (string) filemtime( $file ) : '0',
		true
	);
	wp_add_inline_script(
		'wp-docs-editor-notice',
		'window.wpDocsEditor = ' . wp_json_encode(
			array(
				'mode'     => $mode,
				'text'     => $text,
				'actions'  => $actions,
				'managed'  => $managed,
				'sections' => $managed ? array() : sections_for_editor(),
				'home'     => $managed ? 0 : (int) ( wp_docs_get_tree()['home'] ?? 0 ),
			)
		) . ';',
		'before'
	);
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
