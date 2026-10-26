<?php
/**
 * The page header: type eyebrow, H1, description lede, status banner; below 1100px also the page actions
 * and the "On this page" disclosure (the right rail is hidden there).
 *
 * @package WP_Docs_Theme
 */

namespace WP_Docs_Theme;

defined( 'ABSPATH' ) || die();

$post = queried_doc();
if ( ! $post ) {
	return;
}
$meta   = page_meta( $post );
$type   = $meta['type'] ?? '';
$label  = type_label( $type );
$status = $meta['status'] ?? '';
$desc   = $meta['description'] ?? '';
$toc    = toc_items( $post );

$banner = '';
if ( in_array( $status, array( 'placeholder', 'draft' ), true ) ) {
	$text   = 'placeholder' === $status
		? __( 'This page is a placeholder. It describes something planned; nothing on it is shipped yet.', 'wp-docs' )
		: __( 'This page is a draft. It may be incomplete or change before it is final.', 'wp-docs' );
	$banner = sprintf(
		'<div class="wp-docs-banner is-%1$s" role="note">%2$s<p>%3$s <a href="%4$s">%5$s</a></p></div>',
		esc_attr( $status ),
		icon( 'warning', 20 ),
		esc_html( $text ),
		esc_url( report_url( $meta ) ),
		esc_html__( 'Follow or discuss it on the issue tracker.', 'wp-docs' )
	);
}
?>
<header class="wp-docs-page-header">
	<?php if ( $label ) : ?>
		<p class="wp-docs-eyebrow is-<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></p>
	<?php endif; ?>
	<h1 class="wp-docs-title"><?php echo esc_html( decode( get_the_title( $post ) ) ); ?></h1>
	<?php if ( $desc ) : ?>
		<p class="wp-docs-lede"><?php echo esc_html( $desc ); ?></p>
	<?php endif; ?>
	<?php echo $banner; // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<div class="wp-docs-page-header__tools">
		<?php echo page_actions_markup( $post, 'inline' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php if ( count( $toc ) > 1 ) : ?>
			<details class="wp-docs-toc-disclosure">
				<summary><?php esc_html_e( 'On this page', 'wp-docs' ); ?><?php echo icon( 'chevron-down', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></summary>
				<?php echo toc_list_markup( $toc ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</details>
		<?php endif; ?>
	</div>
</header>
