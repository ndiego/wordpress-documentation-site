<?php
/**
 * The notice in the block editor that says where a save goes.
 *
 * The stock wporg-markdown Editor prints its warning on `edit_form_top`, which only the classic editor
 * screen fires; docs pages are edited in the block editor. The notice depends on the user's sync mode
 * (class-sync.php): a committer is told the save goes live, a proposer that it opens a pull request,
 * and everyone that structure changes happen on GitHub. An open pull request from this page is linked.
 *
 * @package WP_Docs
 */

namespace WP_Docs;

defined( 'WPINC' ) || die();

add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue_editor_notice' );

/**
 * Add the notice when a docs page is open in the block editor.
 */
function enqueue_editor_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || POST_TYPE !== $screen->post_type ) {
		return;
	}
	$post = get_post();
	$edit = $post ? (string) get_post_meta( $post->ID, '_wp_docs_edit_url', true ) : '';
	$mode = class_exists( __NAMESPACE__ . '\Sync' ) ? Sync::mode_for_user( get_current_user_id() ) : 'none';
	$repo = content_repo();
	$pull = $post ? json_decode( (string) get_post_meta( $post->ID, '_wp_docs_pull_request', true ), true ) : null;

	switch ( $mode ) {
		case 'commit':
			$text = sprintf(
				'This page is maintained on GitHub. Saving commits your change to %s and publishes it here right away. Rename, move, reorder or unpublish the page on GitHub.',
				$repo['repo'] . ' (' . Sync::branch() . ')'
			);
			break;
		case 'pull-request':
			$text = 'This page is maintained on GitHub. Saving opens a pull request with your change (or updates yours); the page changes when it is merged.';
			break;
		default:
			$text = 'This page is maintained on GitHub, and this site cannot write to it. Edit the page on GitHub; changes made here are discarded on the next import.';
	}

	$actions = array();
	if ( is_array( $pull ) && ! empty( $pull['url'] ) ) {
		$actions[] = array(
			'label' => sprintf( 'Pull request #%d', (int) $pull['number'] ),
			'url'   => esc_url_raw( (string) $pull['url'] ),
		);
	}
	if ( '' !== $edit ) {
		$actions[] = array(
			'label' => 'Edit on GitHub',
			'url'   => esc_url_raw( $edit ),
		);
	}
	$options = array(
		'id'            => 'wp-docs-maintained-on-github',
		'isDismissible' => false,
	);
	if ( $actions ) {
		$options['actions'] = $actions;
	}

	wp_add_inline_script(
		'wp-notices',
		sprintf(
			'window.addEventListener( "load", function () { wp.data.dispatch( "core/notices" ).%s( %s, %s ); } );',
			'commit' === $mode ? 'createInfoNotice' : 'createWarningNotice',
			wp_json_encode( $text ),
			wp_json_encode( $options )
		)
	);
}
