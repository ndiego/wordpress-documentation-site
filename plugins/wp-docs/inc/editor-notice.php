<?php
/**
 * The "maintained on GitHub" notice in the block editor.
 *
 * The stock wporg-markdown Editor prints its warning on `edit_form_top`, which only the classic editor
 * screen fires; the `wp-docs` post type is edited in the block editor. This adds the same warning there,
 * with a link to the page's GitHub edit URL.
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

	$options = array(
		'id'            => 'wp-docs-maintained-on-github',
		'isDismissible' => false,
	);
	if ( '' !== $edit ) {
		$options['actions'] = array(
			array(
				'label' => 'Edit on GitHub',
				'url'   => esc_url_raw( $edit ),
			),
		);
	}

	wp_add_inline_script(
		'wp-notices',
		sprintf(
			'window.addEventListener( "load", function () { wp.data.dispatch( "core/notices" ).createWarningNotice( %s, %s ); } );',
			wp_json_encode( 'This page is maintained on GitHub. Content, title, and slug edits here will be discarded on the next import.' ),
			wp_json_encode( $options )
		)
	);
}
