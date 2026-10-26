<?php
/**
 * The documentation sidebar. Server-rendered and complete without JavaScript: every section is open, the
 * current page and its ancestors are open, other pages with children are closed. sidebar.js adds the
 * toggles' memory (sessionStorage), the drawer and scroll-into-view.
 *
 * @package WP_Docs_Theme
 *
 * @var array $attributes Block attributes.
 */

namespace WP_Docs_Theme;

defined( 'ABSPATH' ) || die();

$tree = tree();
if ( empty( $tree['sections'] ) ) {
	return;
}

$current   = queried_doc();
$current   = $current ? $current->ID : 0;
$open_path = $current ? path_ids( $current ) : array();

if ( ! function_exists( __NAMESPACE__ . '\sidebar_items' ) ) {
	/**
	 * The list of pages under a parent.
	 *
	 * @param int   $parent    Parent post ID.
	 * @param int   $current   Current post ID.
	 * @param array $open_path Ids on the current path.
	 * @return string
	 */
	function sidebar_items( $parent, $current, $open_path ) {
		$html = '';
		foreach ( children( $parent ) as $node ) {
			$id      = (int) $node['id'];
			$kids    = children( $id );
			$is_cur  = $id === $current;
			$on_path = isset( $open_path[ $id ] );
			$classes = implode(
				' ',
				array_filter(
					array(
						'wp-docs-nav__item',
						$kids ? 'has-children' : '',
						$is_cur ? 'is-current' : '',
						$on_path && ! $is_cur ? 'is-ancestor' : '',
					)
				)
			);
			$link    = sprintf(
				'<a class="wp-docs-nav__link" href="%1$s"%2$s><span class="wp-docs-nav__title">%3$s</span>%4$s</a>',
				esc_url( $node['url'] ),
				$is_cur ? ' aria-current="page"' : '',
				esc_html( decode( $node['title'] ) ),
				'placeholder' === $node['status'] ? '<span class="wp-docs-nav__badge">' . esc_html__( 'Planned', 'wp-docs' ) . '</span>' : ''
			);
			if ( ! $kids ) {
				$html .= sprintf( '<li class="%1$s">%2$s</li>', esc_attr( $classes ), $link );
				continue;
			}
			$list_id = 'wp-docs-nav-' . $id;
			$html   .= sprintf(
				'<li class="%1$s%2$s" data-nav-id="%3$d"%4$s><div class="wp-docs-nav__row">%5$s<button type="button" class="wp-docs-nav__toggle" aria-expanded="%6$s" aria-controls="%7$s"><span class="screen-reader-text">%8$s</span>%9$s</button></div><ul class="wp-docs-nav__list" id="%7$s"%10$s>%11$s</ul></li>',
				esc_attr( $classes ),
				$on_path ? ' is-open' : '',
				$id,
				$on_path ? ' data-on-path' : '',
				$link,
				$on_path ? 'true' : 'false',
				esc_attr( $list_id ),
				/* translators: %s: page title. */
				esc_html( sprintf( __( '%s pages', 'wp-docs' ), decode( $node['title'] ) ) ),
				icon( 'chevron-right', 16 ),
				$on_path ? '' : ' hidden',
				sidebar_items( $id, $current, $open_path )
			);
		}
		return $html;
	}
}

$groups = '';
foreach ( children( 0 ) as $section ) {
	$id      = (int) $section['id'];
	$is_cur  = $id === $current;
	$list_id = 'wp-docs-nav-' . $id;
	$title   = decode( $section['title'] );
	$items   = sidebar_items( $id, $current, $open_path );
	$groups .= sprintf(
		'<li class="wp-docs-nav__group is-open%1$s" data-nav-id="%2$d"%3$s><div class="wp-docs-nav__group-row"><a class="wp-docs-nav__group-label" href="%4$s"%5$s>%6$s</a>%7$s</div>%8$s</li>',
		isset( $open_path[ $id ] ) ? ' is-on-path' : '',
		$id,
		isset( $open_path[ $id ] ) ? ' data-on-path' : '',
		esc_url( $section['url'] ),
		$is_cur ? ' aria-current="page"' : '',
		esc_html( $title ),
		$items ? sprintf(
			'<button type="button" class="wp-docs-nav__toggle" aria-expanded="true" aria-controls="%1$s"><span class="screen-reader-text">%2$s</span>%3$s</button>',
			esc_attr( $list_id ),
			/* translators: %s: section title. */
			esc_html( sprintf( __( '%s pages', 'wp-docs' ), $title ) ),
			icon( 'chevron-right', 16 )
		) : '',
		$items ? sprintf( '<ul class="wp-docs-nav__list" id="%1$s">%2$s</ul>', esc_attr( $list_id ), $items ) : ''
	);
}

$classes = 'wp-docs-sidebar' . ( ! empty( $attributes['drawerOnly'] ) ? ' is-drawer-only' : '' );
?>
<nav id="wp-docs-sidebar" class="<?php echo esc_attr( $classes ); ?>" aria-label="<?php esc_attr_e( 'Documentation', 'wp-docs' ); ?>" data-wp-docs-sidebar>
	<div class="wp-docs-sidebar__head">
		<span class="wp-docs-sidebar__head-title"><?php esc_html_e( 'Documentation', 'wp-docs' ); ?></span>
		<button type="button" class="wp-docs-sidebar__close" data-wp-docs-menu-close>
			<?php echo icon( 'close', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<span class="screen-reader-text"><?php esc_html_e( 'Close menu', 'wp-docs' ); ?></span>
		</button>
	</div>
	<div class="wp-docs-sidebar__scroll" data-wp-docs-sidebar-scroll>
		<ul class="wp-docs-nav">
			<?php echo $groups; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</ul>
	</div>
</nav>
<div class="wp-docs-sidebar-backdrop" data-wp-docs-menu-close hidden></div>
