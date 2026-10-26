<?php
/**
 * Search: the search template is limited to documentation pages, and a fallback search index for the
 * palette when the plugin's `wp-docs/v1/search-index` route is not registered.
 *
 * @package WP_Docs_Theme
 */

namespace WP_Docs_Theme;

defined( 'ABSPATH' ) || die();

/**
 * Search hooks.
 */
final class Search {

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'scope' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'fallback_route' ), 100 );
	}

	/**
	 * Limit the front-end search to documentation pages.
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function scope( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}
		if ( post_type_exists( POST_TYPE ) ) {
			$query->set( 'post_type', POST_TYPE );
			$query->set( 'posts_per_page', 20 );
		}
	}

	/**
	 * URL of the plugin's index.
	 *
	 * @return string
	 */
	public static function index_url() {
		return rest_url( 'wp-docs/v1/search-index' );
	}

	/**
	 * URL of the theme's fallback index.
	 *
	 * @return string
	 */
	public static function fallback_url() {
		return rest_url( 'wp-docs-theme/v1/search-index' );
	}

	/**
	 * Register the fallback route only when the plugin has not registered its own.
	 */
	public static function fallback_route() {
		$routes = rest_get_server()->get_routes( 'wp-docs' );
		if ( isset( $routes['/wp-docs/v1/search-index'] ) ) {
			return;
		}
		register_rest_route(
			'wp-docs-theme/v1',
			'/search-index',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'fallback_index' ),
			)
		);
	}

	/**
	 * The index in the plugin's shape: [ { title, path, url, section, type, breadcrumb, description, headings } ].
	 *
	 * @return \WP_REST_Response
	 */
	public static function fallback_index() {
		$tree  = tree();
		$stamp = md5( wp_json_encode( array( $tree['stamp'] ?? '', wp_cache_get_last_changed( 'posts' ), count( $tree['order'] ) ) ) );
		$data  = get_transient( 'wp_docs_theme_search_index' );
		if ( ! is_array( $data ) || ( $data['stamp'] ?? '' ) !== $stamp ) {
			$records = array();
			foreach ( $tree['order'] as $id ) {
				$node  = $tree['nodes'][ $id ];
				$crumb = array();
				$p     = (int) $node['parent'];
				while ( $p && isset( $tree['nodes'][ $p ] ) ) {
					array_unshift( $crumb, $tree['nodes'][ $p ]['title'] );
					$p = (int) $tree['nodes'][ $p ]['parent'];
				}
				$headings = array();
				$post     = get_post( $id );
				foreach ( $post ? post_headings( $post ) : array() as $h ) {
					if ( in_array( $h['level'], array( 2, 3 ), true ) && '' !== $h['id'] ) {
						$headings[] = array(
							'text'   => $h['text'],
							'anchor' => $h['id'],
						);
					}
				}
				$records[] = array(
					'title'       => decode( $node['title'] ),
					'path'        => $node['path'],
					'url'         => $node['url'],
					'section'     => $node['section'],
					'type'        => $node['type'],
					'breadcrumb'  => $crumb,
					'description' => $node['description'],
					'headings'    => $headings,
				);
			}
			$data = array(
				'stamp'   => $stamp,
				'records' => $records,
			);
			set_transient( 'wp_docs_theme_search_index', $data, DAY_IN_SECONDS );
		}
		$response = new \WP_REST_Response( $data['records'] );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}
}
