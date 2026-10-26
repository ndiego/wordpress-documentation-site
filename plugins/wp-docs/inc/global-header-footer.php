<?php
/**
 * The WordPress.org global header and footer on a site outside the WordPress.org network.
 *
 * Loads the copies of `wporg-mu-plugins/mu-plugins/blocks/global-header-footer` and
 * `mu-plugins/global-fonts` in `vendor/` (unchanged, see vendor/PATCHES.md), so `wporg/global-header` and
 * `wporg/global-footer` are registered and the `wporg-global-fonts` stylesheet (Inter, IBM Plex Mono) is
 * available. Where the vendored code assumes the WordPress.org network, this file adjusts it with hooks:
 *
 * - Off the network (`wp_get_environment_type() === 'local'`, or no WPORGPATH) the header renders the
 *   global menu from its own code, the logo links to https://wordpress.org/ (its get_home_url()), the
 *   "Get WordPress" buttons to https://wordpress.org/download/ (get_download_url()), and the footer's links
 *   are absolute WordPress.org URLs. None of that needs a change.
 * - The header's search form posts to https://wordpress.org/search/do-search.php: the search block's
 *   `formAction` attribute is swapped in by the vendored `swap_header_search_action()`, which replaces
 *   `action="<home_url('/')>"`. That holds whatever this site's home URL is; `enforce_wporg_search()`
 *   below makes sure of it after every other filter.
 * - The header preconnects to Google Fonts (for the Open Sans the classic WordPress.org styles use). A
 *   block theme does not load Open Sans, so the preconnect is removed: no third-party connection.
 * - `set_current_item_class()` reads $_SERVER['HTTP_HOST'] and $_SERVER['REQUEST_URI'], which are missing
 *   when a page is rendered from WP-CLI; they are filled from the home URL first.
 * - The admin bar changes in `admin-bar.php` only run on the front end (`is_admin()` returns early), and
 *   only for logged-in users the bar is shown at all, so wp-admin is untouched.
 *
 * If the real `wporg-mu-plugins` are loaded (on the WordPress.org network), the vendored copies are not.
 *
 * @package WP_Docs
 */

namespace WP_Docs\Global_Header_Footer;

defined( 'WPINC' ) || die();

const HEADER_NS = 'WordPressdotorg\\MU_Plugins\\Global_Header_Footer';
const FONTS_NS  = 'WordPressdotorg\\MU_Plugins\\Global_Fonts';

/**
 * Load the vendored code unless the real mu-plugins already did.
 */
function load() {
	/**
	 * Filters whether the plugin loads its copy of the WordPress.org global header and footer.
	 *
	 * @param bool $load Default true.
	 */
	if ( ! apply_filters( 'wp_docs_load_global_header_footer', true ) ) {
		return;
	}
	if ( ! function_exists( FONTS_NS . '\\register_style' ) ) {
		require_once dirname( __DIR__ ) . '/vendor/global-fonts/index.php';
	}
	if ( ! function_exists( HEADER_NS . '\\register_block_types' ) ) {
		require_once dirname( __DIR__ ) . '/vendor/global-header-footer/blocks.php';
		remove_action( 'wp_head', HEADER_NS . '\\preload_google_fonts' );
	}

	add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_fonts' );
	add_filter( 'render_block_data', __NAMESPACE__ . '\\fill_server_vars' );
	add_filter( 'render_block_core/search', __NAMESPACE__ . '\\enforce_wporg_search', 100, 2 );
}

/**
 * Enqueue the WordPress.org fonts on the front end.
 */
function enqueue_fonts() {
	if ( wp_style_is( 'wporg-global-fonts', 'registered' ) ) {
		wp_enqueue_style( 'wporg-global-fonts' );
	}
}

/**
 * Fill $_SERVER['HTTP_HOST'] and $_SERVER['REQUEST_URI'] before the header renders outside a web request.
 *
 * @param array $block Parsed block.
 * @return array
 */
function fill_server_vars( $block ) {
	if ( 'wporg/global-header' === ( $block['blockName'] ?? '' ) ) {
		if ( empty( $_SERVER['HTTP_HOST'] ) ) {
			$_SERVER['HTTP_HOST'] = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			$_SERVER['REQUEST_URI'] = '/';
		}
	}
	return $block;
}

/**
 * The header's search form must search WordPress.org, never this site: when a search block carries a
 * `formAction` (only the global header's does), make sure the form posts there.
 *
 * @param string $content Block HTML.
 * @param array  $block   Block.
 * @return string
 */
function enforce_wporg_search( $content, $block ) {
	$action = $block['attrs']['formAction'] ?? '';
	if ( '' === $action || false !== strpos( $content, 'action="' . esc_url( $action ) . '"' ) ) {
		return $content;
	}
	$tags = new \WP_HTML_Tag_Processor( $content );
	if ( $tags->next_tag( 'form' ) ) {
		$tags->set_attribute( 'action', esc_url_raw( $action ) );
		return $tags->get_updated_html();
	}
	return $content;
}

load();
