<?php
/**
 * "On this page": H2 and H3 of the content, linked by their GitHub ids. toc.js adds scroll-spy.
 *
 * @package WP_Docs_Theme
 *
 * @var array $attributes Block attributes.
 */

namespace WP_Docs_Theme;

defined( 'ABSPATH' ) || die();

$post  = queried_doc();
$items = $post ? toc_items( $post ) : array();
if ( count( $items ) < 2 ) {
	return;
}
$title = '' !== ( $attributes['title'] ?? '' ) ? $attributes['title'] : __( 'On this page', 'wp-docs' );
?>
<div class="wp-docs-toc" data-wp-docs-toc>
	<p class="wp-docs-toc__title"><?php echo esc_html( $title ); ?></p>
	<?php echo toc_list_markup( $items ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</div>
