<?php
/**
 * The page footer: "Was this page helpful?", previous and next in sidebar order, then the provenance row.
 *
 * @package WP_Docs_Theme
 */

namespace WP_Docs_Theme;

defined( 'ABSPATH' ) || die();

$post = queried_doc();
if ( ! $post ) {
	return;
}
$meta     = page_meta( $post );
$adjacent = adjacent( $post );

$pager = '';
foreach ( array( 'prev', 'next' ) as $dir ) {
	$node = $adjacent[ $dir ] ?? null;
	if ( ! $node ) {
		$pager .= '<span class="wp-docs-pager__spacer" aria-hidden="true"></span>';
		continue;
	}
	$pager .= sprintf(
		'<a class="wp-docs-pager__link is-%1$s" href="%2$s" rel="%1$s"><span class="wp-docs-pager__label">%3$s%4$s</span><span class="wp-docs-pager__title">%5$s</span></a>',
		esc_attr( $dir ),
		esc_url( $node['url'] ),
		'prev' === $dir ? icon( 'arrow-left', 16 ) : '',
		'prev' === $dir ? esc_html__( 'Previous', 'wp-docs' ) : esc_html__( 'Next', 'wp-docs' ) . icon( 'arrow-right', 16 ),
		esc_html( html_entity_decode( $node['title'], ENT_QUOTES, 'UTF-8' ) )
	);
}

$updated = format_date( ! empty( $meta['updated'] ) ? $meta['updated'] : ( ! empty( $meta['imported'] ) ? $meta['imported'] : $post->post_modified_gmt ) );
$links   = array();
if ( ! empty( $meta['edit_url'] ) ) {
	$links[] = sprintf( '<a href="%1$s">%2$s%3$s</a>', esc_url( $meta['edit_url'] ), icon( 'edit', 16 ), esc_html__( 'Edit on GitHub', 'wp-docs' ) );
}
$links[] = sprintf( '<a href="%1$s">%2$s%3$s</a>', esc_url( ! empty( $meta['issue_url'] ) ? $meta['issue_url'] : issues_url() ), icon( 'flag', 16 ), esc_html__( 'Report a problem', 'wp-docs' ) );
if ( ! empty( $meta['source_url'] ) ) {
	$commit = $meta['commit'] ?? '';
	$source = $commit ? preg_replace( '#/blob/[^/]+/#', '/blob/' . rawurlencode( $commit ) . '/', $meta['source_url'], 1 ) : $meta['source_url'];
	$links[] = sprintf(
		'<a href="%1$s">%2$s%3$s%4$s</a>',
		esc_url( $source ),
		icon( 'commit', 16 ),
		esc_html__( 'View source', 'wp-docs' ),
		$commit ? ' <code>' . esc_html( substr( $commit, 0, 7 ) ) . '</code>' : ''
	);
}
?>
<div class="wp-docs-page-footer">
	<div class="wp-docs-feedback" data-wp-docs-feedback>
		<p class="wp-docs-feedback__question" id="wp-docs-feedback-q"><?php esc_html_e( 'Was this page helpful?', 'wp-docs' ); ?></p>
		<div class="wp-docs-feedback__buttons" role="group" aria-labelledby="wp-docs-feedback-q">
			<button type="button" class="wp-docs-button" data-feedback="yes"><?php esc_html_e( 'Yes', 'wp-docs' ); ?></button>
			<button type="button" class="wp-docs-button" data-feedback="no"><?php esc_html_e( 'No', 'wp-docs' ); ?></button>
		</div>
		<p class="wp-docs-feedback__thanks" role="status" data-thanks-yes="<?php esc_attr_e( 'Thanks for letting us know.', 'wp-docs' ); ?>" data-thanks-no="<?php esc_attr_e( 'Thanks. Tell us what was missing with Report a problem below.', 'wp-docs' ); ?>"></p>
	</div>
	<?php if ( $adjacent['prev'] || $adjacent['next'] ) : ?>
		<nav class="wp-docs-pager" aria-label="<?php esc_attr_e( 'Previous and next pages', 'wp-docs' ); ?>">
			<?php echo $pager; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</nav>
	<?php endif; ?>
	<div class="wp-docs-page-meta">
		<?php if ( $updated ) : ?>
			<span class="wp-docs-page-meta__updated">
				<?php
				/* translators: %s: date. */
				printf( esc_html__( 'Last updated %s', 'wp-docs' ), '<time>' . esc_html( $updated ) . '</time>' );
				?>
			</span>
		<?php endif; ?>
		<span class="wp-docs-page-meta__links"><?php echo implode( '', $links ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
	</div>
</div>
