<?php
/**
 * The children of the current page as cards (title, description, type chip). A child with children of
 * its own is a group card that lists them.
 *
 * @package WP_Docs_Theme
 *
 * @var array $attributes Block attributes.
 */

namespace WP_Docs_Theme;

defined( 'ABSPATH' ) || die();

$post = queried_doc();
$kids = $post ? children( $post->ID ) : array();
if ( ! $kids ) {
	return;
}

$cards = '';
foreach ( $kids as $node ) {
	$grand = children( $node['id'] );
	$title = esc_html( decode( $node['title'] ) );
	$chips = type_chip( $node['type'] ) . status_chip( $node['status'] );
	$desc  = $node['description'] ? '<p class="wp-docs-card__desc">' . esc_html( $node['description'] ) . '</p>' : '';
	if ( ! $grand ) {
		$cards .= sprintf(
			'<li class="wp-docs-card"><a class="wp-docs-card__link" href="%1$s"><span class="wp-docs-card__title">%2$s</span>%3$s<span class="wp-docs-card__chips">%4$s</span></a></li>',
			esc_url( $node['url'] ),
			$title,
			$desc,
			$chips
		);
		continue;
	}
	$list = '';
	foreach ( $grand as $g ) {
		$list .= sprintf(
			'<li><a href="%1$s">%2$s</a>%3$s</li>',
			esc_url( $g['url'] ),
			esc_html( decode( $g['title'] ) ),
			status_chip( $g['status'] )
		);
	}
	$cards .= sprintf(
		'<li class="wp-docs-card is-group"><div class="wp-docs-card__head"><a class="wp-docs-card__title" href="%1$s">%2$s</a><span class="wp-docs-card__count">%3$s</span></div>%4$s<ul class="wp-docs-card__list">%5$s</ul></li>',
		esc_url( $node['url'] ),
		$title,
		/* translators: %d: number of pages. */
		esc_html( sprintf( _n( '%d page', '%d pages', count( $grand ), 'wp-docs' ), count( $grand ) ) ),
		$desc,
		$list
	);
}
$heading = '' !== ( $attributes['heading'] ?? '' ) ? $attributes['heading'] : __( 'In this section', 'wp-docs' );
?>
<section class="wp-docs-section-cards" aria-labelledby="wp-docs-section-cards-title">
	<h2 class="wp-docs-section-cards__title" id="wp-docs-section-cards-title"><?php echo esc_html( $heading ); ?></h2>
	<ul class="wp-docs-cards">
		<?php echo $cards; // phpcs:ignore WordPress.Security.EscapeOutput ?>
	</ul>
</section>
