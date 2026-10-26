<?php
/**
 * The "Copy page" split button (right rail).
 *
 * @package WP_Docs_Theme
 */

namespace WP_Docs_Theme;

defined( 'ABSPATH' ) || die();

$post = queried_doc();
if ( $post ) {
	echo page_actions_markup( $post, 'rail' ); // phpcs:ignore WordPress.Security.EscapeOutput
}
