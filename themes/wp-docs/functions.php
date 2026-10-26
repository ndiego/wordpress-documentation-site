<?php
/**
 * The wp-docs block theme: setup, blocks, assets and the small filters the layout needs.
 *
 * @package WP_Docs_Theme
 */

namespace WP_Docs_Theme;

defined( 'ABSPATH' ) || die();

require_once __DIR__ . '/inc/data.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/anchors.php';
require_once __DIR__ . '/inc/render.php';
require_once __DIR__ . '/inc/search.php';

const BLOCKS = array( 'docs-bar', 'sidebar', 'breadcrumbs', 'page-header', 'page-actions', 'toc', 'page-footer', 'section-cards', 'home' );

Heading_Anchors::init();
Search::init();

add_action( 'after_setup_theme', __NAMESPACE__ . '\setup' );
add_action( 'init', __NAMESPACE__ . '\register_blocks' );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_assets' );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\dequeue_assets', 100 );
add_action( 'wp_head', __NAMESPACE__ . '\js_script', 0 );
add_action( 'wp_head', __NAMESPACE__ . '\meta_description', 1 );
add_filter( 'pre_render_block', __NAMESPACE__ . '\global_header_footer_fallback', 10, 2 );
add_filter( 'render_block_core/group', __NAMESPACE__ . '\label_rail', 10, 2 );
add_filter( 'render_block_core/code', __NAMESPACE__ . '\code_block', 10, 2 );
add_filter( 'render_block', __NAMESPACE__ . '\inline_code_shortcodes', 10, 2 );
add_filter( 'single_template_hierarchy', __NAMESPACE__ . '\home_post_template' );
add_filter( 'body_class', __NAMESPACE__ . '\body_class' );

/**
 * Theme supports.
 */
function setup() {
	add_theme_support( 'responsive-embeds' );
	remove_theme_support( 'core-block-patterns' );
}

/**
 * Register the theme's server-rendered blocks (template only, not in the inserter).
 */
function register_blocks() {
	foreach ( BLOCKS as $name ) {
		register_block_type( __DIR__ . '/blocks/' . $name );
	}
}

/**
 * The front-end stylesheet and scripts. Nothing loads in the editor.
 */
function enqueue_assets() {
	$version = static function ( $file ) {
		$path = __DIR__ . '/assets/' . $file;
		return file_exists( $path ) ? (string) filemtime( $path ) : '0';
	};
	$uri = get_theme_file_uri( 'assets/' );

	$deps = array();
	if ( wp_style_is( 'wporg-global-fonts', 'registered' ) ) {
		$deps[] = 'wporg-global-fonts';
	}
	wp_enqueue_style( 'wp-docs', $uri . 'style.css', $deps, $version( 'style.css' ) );

	$args = array(
		'in_footer' => true,
		'strategy'  => 'defer',
	);
	wp_enqueue_script( 'wp-docs-sidebar', $uri . 'sidebar.js', array(), $version( 'sidebar.js' ), $args );
	wp_enqueue_script( 'wp-docs-page-actions', $uri . 'page-actions.js', array(), $version( 'page-actions.js' ), $args );
	wp_enqueue_script( 'wp-docs-search-palette', $uri . 'search-palette.js', array(), $version( 'search-palette.js' ), $args );
	// The palette loads its own stylesheet when it is first needed (see search-palette.js).
	wp_add_inline_script( 'wp-docs-search-palette', 'window.wpDocsPaletteCss=' . wp_json_encode( $uri . 'search-palette.css?ver=' . $version( 'search-palette.css' ) ) . ';', 'before' );
	if ( is_singular( POST_TYPE ) ) {
		wp_enqueue_script( 'wp-docs-toc', $uri . 'toc.js', array(), $version( 'toc.js' ), $args );
	}
}

/**
 * Leave out what the theme replaces: code-syntax-block's Prism colors (the theme draws tokens from its
 * own custom properties) and the classic theme styles.
 */
function dequeue_assets() {
	wp_dequeue_style( 'mkaz-code-syntax-prism-css' );
	wp_dequeue_style( 'classic-theme-styles' );
}

/**
 * Mark that scripts run (`html.js`) before the first paint, so script-only controls can be hidden without them.
 */
function js_script() {
	wp_print_inline_script_tag( "document.documentElement.classList.add('js');" );
}

/**
 * Plain fallbacks for the WordPress.org global header and footer when the vendored blocks are not registered.
 *
 * @param string|null $content Short-circuit content.
 * @param array       $block   Parsed block.
 * @return string|null
 */
function global_header_footer_fallback( $content, $block ) {
	$name = $block['blockName'] ?? '';
	if ( ! in_array( $name, array( 'wporg/global-header', 'wporg/global-footer' ), true ) ) {
		return $content;
	}
	if ( \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
		return $content;
	}
	if ( 'wporg/global-header' === $name ) {
		return sprintf(
			'<header class="wp-docs-global-fallback wp-docs-global-fallback--header"><a class="wp-docs-global-fallback__brand" href="https://wordpress.org/">%1$s<span>WordPress.org</span></a></header>',
			w_mark( 28 )
		);
	}
	$links = array(
		'https://wordpress.org/about/'      => __( 'About', 'wp-docs' ),
		'https://wordpress.org/news/'       => __( 'News', 'wp-docs' ),
		'https://wordpress.org/support/'    => __( 'Support', 'wp-docs' ),
		'https://make.wordpress.org/'       => __( 'Get involved', 'wp-docs' ),
		'https://wordpress.org/download/'   => __( 'Get WordPress', 'wp-docs' ),
		'https://wordpress.org/about/privacy/' => __( 'Privacy', 'wp-docs' ),
	);
	$items = '';
	foreach ( $links as $url => $label ) {
		$items .= sprintf( '<li><a href="%1$s">%2$s</a></li>', esc_url( $url ), esc_html( $label ) );
	}
	return sprintf(
		'<footer class="wp-docs-global-fallback wp-docs-global-fallback--footer"><div class="wp-docs-global-fallback__inner"><a class="wp-docs-global-fallback__brand" href="https://wordpress.org/">%1$s<span>WordPress.org</span></a><ul>%2$s</ul><p>Code is Poetry</p></div></footer>',
		w_mark( 28 ),
		$items
	);
}

/**
 * Name the right rail landmark ("On this page").
 *
 * @param string $content Rendered group.
 * @param array  $block   Block.
 * @return string
 */
function label_rail( $content, $block ) {
	$class = $block['attrs']['className'] ?? '';
	if ( false === strpos( $class, 'wp-docs-rail' ) ) {
		return $content;
	}
	$processor = new \WP_HTML_Tag_Processor( $content );
	if ( $processor->next_tag() ) {
		$processor->set_attribute( 'aria-label', __( 'On this page', 'wp-docs' ) );
		$content = $processor->get_updated_html();
	}
	return $content;
}

/**
 * Wrap code blocks in a frame with the language label; page-actions.js adds the copy button to the bar.
 *
 * @param string $content Rendered code block.
 * @param array  $block   Block.
 * @return string
 */
function code_block( $content, $block ) {
	if ( '' === trim( $content ) || false !== strpos( $content, 'wp-docs-code' ) ) {
		return $content;
	}
	$lang = '';
	if ( preg_match( '#<code[^>]*\blang="([^"]+)"#i', $content, $m ) || preg_match( '#<code[^>]*\bclass="[^"]*\blanguage-([a-z0-9_+-]+)#i', $content, $m ) ) {
		$lang = strtolower( $m[1] );
	}
	$labels = array(
		'php'        => 'PHP',
		'js'         => 'JavaScript',
		'javascript' => 'JavaScript',
		'jsx'        => 'JSX',
		'ts'         => 'TypeScript',
		'typescript' => 'TypeScript',
		'css'        => 'CSS',
		'scss'       => 'SCSS',
		'html'       => 'HTML',
		'markup'     => 'HTML',
		'xml'        => 'XML',
		'json'       => 'JSON',
		'yaml'       => 'YAML',
		'yml'        => 'YAML',
		'sql'        => 'SQL',
		'bash'       => 'Terminal',
		'sh'         => 'Terminal',
		'shell'      => 'Terminal',
		'console'    => 'Terminal',
		'apacheconf' => 'Apache',
		'nginx'      => 'Nginx',
		'ini'        => 'INI',
		'md'         => 'Markdown',
		'markdown'   => 'Markdown',
		'diff'       => 'Diff',
	);
	$content = protect_code_shortcodes( $content );
	$label = in_array( $lang, array( '', 'text', 'plain', 'plaintext', 'txt', 'none' ), true ) ? '' : ( $labels[ $lang ] ?? strtoupper( $lang ) );
	return sprintf(
		'<div class="wp-docs-code%1$s"%2$s><div class="wp-docs-code__bar"><span class="wp-docs-code__lang">%3$s</span></div>%4$s</div>',
		'' === $label ? ' has-no-label' : '',
		'' === $lang ? '' : ' data-lang="' . esc_attr( $lang ) . '"',
		esc_html( $label ),
		$content
	);
}

/**
 * The docs home post (path "") renders with the home template wherever it is reached.
 *
 * @param string[] $templates Template hierarchy.
 * @return string[]
 */
function home_post_template( $templates ) {
	$post = queried_doc();
	if ( $post ) {
		$tree = tree();
		if ( (int) $tree['home'] === $post->ID ) {
			array_unshift( $templates, 'front-page.php' );
		}
	}
	return $templates;
}

/**
 * Body classes for the layout.
 *
 * @param string[] $classes Classes.
 * @return string[]
 */
function body_class( $classes ) {
	$post = queried_doc();
	if ( $post ) {
		$meta = page_meta( $post );
		if ( ! empty( $meta['type'] ) ) {
			$classes[] = 'wp-docs-type-' . sanitize_html_class( $meta['type'] );
		}
		if ( children( $post->ID ) ) {
			$classes[] = 'wp-docs-has-children';
		}
	}
	return $classes;
}

/**
 * Encode brackets inside <code>, so a shortcode written as an example (`[gallery ids="1,2"]`) is shown
 * rather than run: core runs do_shortcode() on the content after the blocks render.
 *
 * @param string $content HTML.
 * @return string
 */
function protect_code_shortcodes( $content ) {
	if ( false === strpos( $content, '[' ) || false === stripos( $content, '<code' ) ) {
		return $content;
	}
	return preg_replace_callback(
		'#(<code\b[^>]*>)(.*?)(</code>)#is',
		static function ( $m ) {
			return $m[1] . str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $m[2] ) . $m[3];
		},
		$content
	);
}

/**
 * Inline code in paragraphs, lists, tables and headings.
 *
 * @param string $content Rendered block.
 * @param array  $block   Block.
 * @return string
 */
function inline_code_shortcodes( $content, $block ) {
	$names = array( 'core/paragraph', 'core/list-item', 'core/list', 'core/table', 'core/heading', 'core/html', 'core/details' );
	return in_array( $block['blockName'] ?? '', $names, true ) ? protect_code_shortcodes( $content ) : $content;
}

/**
 * A meta description from the page's front matter description (the home's on / and /docs/).
 */
function meta_description() {
	$text = '';
	$post = queried_doc();
	if ( $post ) {
		$meta = page_meta( $post );
		$text = $meta['description'] ?? '';
	} elseif ( is_front_page() || is_post_type_archive( POST_TYPE ) ) {
		$tree = tree();
		$text = $tree['home'] ? (string) get_post_meta( $tree['home'], '_wp_docs_description', true ) : '';
	}
	if ( '' !== $text ) {
		printf( "<meta name=\"description\" content=\"%s\">\n", esc_attr( wp_strip_all_tags( $text ) ) );
	}
}
