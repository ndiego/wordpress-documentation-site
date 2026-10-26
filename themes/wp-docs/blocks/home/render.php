<?php
/**
 * The documentation home (`/` and `/docs/`): hero with search, the four doors, "New to WordPress?",
 * "WordPress and AI", the block reference and the Classic WordPress footnote. Built from the tree: a new
 * page or section shows up without touching the theme (a section that is none of the known ones is listed
 * under "More documentation").
 *
 * @package WP_Docs_Theme
 */

namespace WP_Docs_Theme;

defined( 'ABSPATH' ) || die();

$tree = tree();
$home = $tree['home'] ? get_post( $tree['home'] ) : null;
$used = array();

$promise = $home ? (string) get_post_meta( $home->ID, '_wp_docs_description', true ) : '';
if ( '' === $promise ) {
	$promise = __( 'Learn to build, write and run a WordPress site, one task at a time.', 'wp-docs' );
}
$search_action = home_url( '/' );

// The four doors.
$doors = array(
	'get-started' => __( 'New here? Start with what WordPress is, install it and take a guided first tour.', 'wp-docs' ),
	'write'       => __( 'Write posts and pages with blocks, add media, publish and organize your content.', 'wp-docs' ),
	'design'      => __( 'Change how your site looks with themes, the Site Editor, styles and patterns.', 'wp-docs' ),
	'manage'      => __( 'Settings, users, plugins, updates, security and what to do when something breaks.', 'wp-docs' ),
);
$door_html = '';
foreach ( $doors as $slug => $fallback ) {
	$section = section( $slug );
	if ( ! $section ) {
		continue;
	}
	$used[ $section['id'] ] = true;
	$links                  = '';
	foreach ( array_slice( children( $section['id'] ), 0, 3 ) as $node ) {
		$links .= sprintf( '<li><a href="%1$s">%2$s</a></li>', esc_url( $node['url'] ), esc_html( decode( $node['title'] ) ) );
	}
	$door_html .= sprintf(
		'<li class="wp-docs-door"><a class="wp-docs-door__main" href="%1$s"><span class="wp-docs-door__icon">%2$s</span><span class="wp-docs-door__title">%3$s</span><span class="wp-docs-door__desc">%4$s</span></a>%5$s</li>',
		esc_url( $section['url'] ),
		icon( section_icon( $slug ), 24 ),
		esc_html( decode( $section['title'] ) ),
		esc_html( $section['description'] ? $section['description'] : $fallback ),
		$links ? '<ul class="wp-docs-door__links">' . $links . '</ul>' : ''
	);
}

// New to WordPress? The Get started path in order.
$path_html = '';
$start     = section( 'get-started' );
if ( $start ) {
	$i = 0;
	foreach ( children( $start['id'] ) as $node ) {
		++$i;
		$path_html .= sprintf(
			'<li><a href="%1$s"><span class="wp-docs-step__n" aria-hidden="true">%2$d</span><span class="wp-docs-step__title">%3$s</span></a></li>',
			esc_url( $node['url'] ),
			$i,
			esc_html( decode( $node['title'] ) )
		);
	}
}

// WordPress and AI.
$ai_html = '';
$ai      = section( 'ai' );
if ( $ai ) {
	$used[ $ai['id'] ] = true;
	foreach ( children( $ai['id'] ) as $node ) {
		$ai_html .= sprintf(
			'<li class="wp-docs-card"><a class="wp-docs-card__link" href="%1$s"><span class="wp-docs-card__title">%2$s</span>%3$s<span class="wp-docs-card__chips">%4$s</span></a></li>',
			esc_url( $node['url'] ),
			esc_html( decode( $node['title'] ) ),
			$node['description'] ? '<span class="wp-docs-card__desc">' . esc_html( $node['description'] ) . '</span>' : '',
			type_chip( $node['type'] ) . status_chip( $node['status'] )
		);
	}
}

// Block reference.
$blocks_html = '';
$blocks      = section( 'blocks' );
if ( $blocks ) {
	$used[ $blocks['id'] ] = true;
	$kids                  = children( $blocks['id'] );
	foreach ( array_slice( $kids, 0, 14 ) as $node ) {
		$count        = count( children( $node['id'] ) );
		$blocks_html .= sprintf(
			'<li><a class="wp-docs-pill" href="%1$s">%2$s%3$s</a></li>',
			esc_url( $node['url'] ),
			esc_html( decode( $node['title'] ) ),
			$count ? '<span class="wp-docs-pill__count">' . (int) $count . '</span>' : ''
		);
	}
	if ( count( $kids ) > 14 ) {
		$blocks_html .= sprintf( '<li><a class="wp-docs-pill is-more" href="%1$s">%2$s</a></li>', esc_url( $blocks['url'] ), esc_html__( 'All blocks', 'wp-docs' ) );
	}
}

// Classic WordPress, and anything else.
$classic = section( 'classic' );
if ( $classic ) {
	$used[ $classic['id'] ] = true;
}
if ( $start ) {
	$used[ $start['id'] ] = true;
}
$more_html = '';
foreach ( children( 0 ) as $node ) {
	if ( isset( $used[ $node['id'] ] ) ) {
		continue;
	}
	$more_html .= sprintf(
		'<li class="wp-docs-card"><a class="wp-docs-card__link" href="%1$s"><span class="wp-docs-card__title">%2$s</span>%3$s</a></li>',
		esc_url( $node['url'] ),
		esc_html( decode( $node['title'] ) ),
		$node['description'] ? '<span class="wp-docs-card__desc">' . esc_html( $node['description'] ) . '</span>' : ''
	);
}
?>
<div class="wp-docs-home">
	<section class="wp-docs-hero">
		<h1 class="wp-docs-hero__title"><?php esc_html_e( 'WordPress documentation', 'wp-docs' ); ?></h1>
		<p class="wp-docs-hero__promise"><?php echo esc_html( $promise ); ?></p>
		<form class="wp-docs-hero__search" role="search" action="<?php echo esc_url( $search_action ); ?>" method="get" data-wp-docs-search-form>
			<label class="screen-reader-text" for="wp-docs-hero-search"><?php esc_html_e( 'Search documentation', 'wp-docs' ); ?></label>
			<?php echo icon( 'search', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<input id="wp-docs-hero-search" type="search" name="s" placeholder="<?php esc_attr_e( 'Search documentation', 'wp-docs' ); ?>" autocomplete="off">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( POST_TYPE ); ?>">
			<kbd aria-hidden="true">⌘K</kbd>
		</form>
	</section>

	<?php if ( $door_html ) : ?>
		<section class="wp-docs-home__section" aria-label="<?php esc_attr_e( 'Where to start', 'wp-docs' ); ?>">
			<ul class="wp-docs-doors"><?php echo $door_html; // phpcs:ignore WordPress.Security.EscapeOutput ?></ul>
		</section>
	<?php endif; ?>

	<?php if ( $path_html ) : ?>
		<section class="wp-docs-home__section wp-docs-strip" aria-labelledby="wp-docs-home-new">
			<div class="wp-docs-strip__head">
				<h2 id="wp-docs-home-new"><?php esc_html_e( 'New to WordPress?', 'wp-docs' ); ?></h2>
				<p><?php esc_html_e( 'Read these in order. Each one takes a few minutes.', 'wp-docs' ); ?></p>
			</div>
			<ol class="wp-docs-steps"><?php echo $path_html; // phpcs:ignore WordPress.Security.EscapeOutput ?></ol>
		</section>
	<?php endif; ?>

	<?php if ( $ai_html ) : ?>
		<section class="wp-docs-home__section wp-docs-strip" aria-labelledby="wp-docs-home-ai">
			<div class="wp-docs-strip__head">
				<h2 id="wp-docs-home-ai"><?php echo esc_html( decode( $ai['title'] ) ); ?></h2>
				<p><?php echo esc_html( $ai['description'] ? $ai['description'] : __( 'What AI can and cannot do with WordPress today, and what is planned.', 'wp-docs' ) ); ?> <a href="<?php echo esc_url( $ai['url'] ); ?>"><?php esc_html_e( 'Overview', 'wp-docs' ); ?></a></p>
			</div>
			<ul class="wp-docs-cards is-compact"><?php echo $ai_html; // phpcs:ignore WordPress.Security.EscapeOutput ?></ul>
		</section>
	<?php endif; ?>

	<?php if ( $blocks_html ) : ?>
		<section class="wp-docs-home__section wp-docs-strip" aria-labelledby="wp-docs-home-blocks">
			<div class="wp-docs-strip__head">
				<h2 id="wp-docs-home-blocks"><?php echo esc_html( decode( $blocks['title'] ) ); ?></h2>
				<p><?php echo esc_html( $blocks['description'] ? $blocks['description'] : __( 'Every core block: what it does and its settings.', 'wp-docs' ) ); ?></p>
			</div>
			<ul class="wp-docs-pills"><?php echo $blocks_html; // phpcs:ignore WordPress.Security.EscapeOutput ?></ul>
		</section>
	<?php endif; ?>

	<?php if ( $more_html ) : ?>
		<section class="wp-docs-home__section wp-docs-strip" aria-labelledby="wp-docs-home-more">
			<div class="wp-docs-strip__head"><h2 id="wp-docs-home-more"><?php esc_html_e( 'More documentation', 'wp-docs' ); ?></h2></div>
			<ul class="wp-docs-cards is-compact"><?php echo $more_html; // phpcs:ignore WordPress.Security.EscapeOutput ?></ul>
		</section>
	<?php endif; ?>

	<?php if ( $classic ) : ?>
		<p class="wp-docs-home__footnote">
			<?php echo icon( 'archive', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<span>
				<?php esc_html_e( 'Using the Classic Editor, widgets, menus or the Customizer?', 'wp-docs' ); ?>
				<a href="<?php echo esc_url( $classic['url'] ); ?>"><?php echo esc_html( decode( $classic['title'] ) ); ?></a>
			</span>
		</p>
	<?php endif; ?>
</div>
