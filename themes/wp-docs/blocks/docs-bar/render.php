<?php
/**
 * The docs bar: wordmark, documentation sets, search trigger, color scheme, GitHub.
 *
 * @package WP_Docs_Theme
 */

namespace WP_Docs_Theme;

defined( 'ABSPATH' ) || die();

$sections = array();
foreach ( children( 0 ) as $node ) {
	$sections[ $node['section'] ? $node['section'] : trim( $node['path'], '/' ) ] = html_entity_decode( $node['title'], ENT_QUOTES, 'UTF-8' );
}
$search_url = add_query_arg(
	array(
		'post_type' => POST_TYPE,
		's'         => '',
	),
	home_url( '/' )
);

?>
<div class="wp-docs-bar">
	<div class="wp-docs-bar__inner">
		<button type="button" class="wp-docs-bar__menu" aria-controls="wp-docs-sidebar" aria-expanded="false" data-wp-docs-menu>
			<?php echo icon( 'menu', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<span><?php esc_html_e( 'Menu', 'wp-docs' ); ?></span>
		</button>
		<a class="wp-docs-bar__brand" href="<?php echo esc_url( docs_home_url() ); ?>">
			<?php echo w_mark( 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<span><?php esc_html_e( 'Documentation', 'wp-docs' ); ?></span>
		</a>
		<nav class="wp-docs-bar__sets" aria-label="<?php esc_attr_e( 'Documentation sets', 'wp-docs' ); ?>">
			<a href="<?php echo esc_url( docs_home_url() ); ?>" aria-current="page"><?php esc_html_e( 'User docs', 'wp-docs' ); ?></a>
			<a href="https://developer.wordpress.org/"><?php esc_html_e( 'Developer docs', 'wp-docs' ); ?><?php echo icon( 'external', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
			<a href="https://developer.wordpress.org/reference/"><?php esc_html_e( 'Code reference', 'wp-docs' ); ?><?php echo icon( 'external', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
		</nav>
		<div class="wp-docs-bar__tools">
			<button type="button" class="wp-docs-search-trigger" data-wp-docs-search
				data-index-url="<?php echo esc_url( Search::index_url() ); ?>"
				data-fallback-url="<?php echo esc_url( Search::fallback_url() ); ?>"
				data-search-url="<?php echo esc_url( $search_url ); ?>"
				data-sections="<?php echo esc_attr( wp_json_encode( $sections ) ); ?>"
				aria-label="<?php esc_attr_e( 'Search documentation', 'wp-docs' ); ?>">
				<?php echo icon( 'search', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span class="wp-docs-search-trigger__label"><?php esc_html_e( 'Search documentation', 'wp-docs' ); ?></span>
				<kbd aria-hidden="true">⌘K</kbd>
			</button>
			<?php echo scheme_control_markup(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<a class="wp-docs-bar__github" href="<?php echo esc_url( repo_url() ); ?>">
				<?php echo icon( 'github', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span class="screen-reader-text"><?php esc_html_e( 'The documentation on GitHub', 'wp-docs' ); ?></span>
			</a>
		</div>
	</div>
</div>
