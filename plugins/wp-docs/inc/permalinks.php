<?php
/**
 * Designed URLs: a page's URL is `/docs/<path>/` from the manifest `path` field (stored in
 * `_wp_docs_path`), independent of where the page sits in the tree. `post_parent` still comes from
 * `parent` and drives the sidebar.
 *
 * - `post_type_link` returns `home_url( '/docs/<path>/' )` for published pages that have a path, and
 *   `/docs/` for the home (the manifest `index`, stored with an empty path).
 * - `parse_request` resolves `/docs/<path>/` to the page with that path; when no path matches, the
 *   normal hierarchical lookup runs unchanged.
 *
 * @package WP_Docs
 */

namespace WP_Docs;

use WP;
use WP_Post;

defined( 'WPINC' ) || die();

/**
 * Path permalinks.
 */
class Permalinks {

	const OPTION = 'wp_docs_paths';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'post_type_link', array( __CLASS__, 'post_type_link' ), 10, 2 );
		add_action( 'parse_request', array( __CLASS__, 'parse_request' ) );
	}

	/**
	 * Rebuild the path => post ID lookup from the meta of published pages. Called after every import.
	 *
	 * @return array<string,int>
	 */
	public static function rebuild() {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_wp_docs_path' AND p.post_type = %s AND p.post_status = 'publish' ORDER BY p.ID",
				POST_TYPE
			)
		);
		$map = array();
		foreach ( $rows as $row ) {
			$path = trim( (string) $row->meta_value, '/' );
			if ( ! isset( $map[ $path ] ) ) {
				$map[ $path ] = (int) $row->post_id;
			}
		}
		update_option( self::OPTION, $map, true );
		return $map;
	}

	/**
	 * Permalink from the stored path.
	 *
	 * @param string  $link Link.
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function post_type_link( $link, $post ) {
		if ( POST_TYPE !== $post->post_type || 'publish' !== $post->post_status || ! get_option( 'permalink_structure' ) ) {
			return $link;
		}
		if ( ! metadata_exists( 'post', $post->ID, '_wp_docs_path' ) ) {
			return $link;
		}
		return wp_docs_path_url( (string) get_post_meta( $post->ID, '_wp_docs_path', true ) );
	}

	/**
	 * Post ID of the published page with a path ("" is the home), from the lookup.
	 *
	 * @param string $path Path under /docs/, with or without slashes.
	 * @return int 0 when there is none.
	 */
	public static function post_for_path( $path ) {
		$path = trim( (string) $path, '/' );
		$map  = (array) get_option( self::OPTION, array() );
		$id   = isset( $map[ $path ] ) ? (int) $map[ $path ] : 0;
		return $id && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	/**
	 * Resolve `/docs/<path>/` by stored path.
	 *
	 * @param WP $wp Request.
	 */
	public static function parse_request( $wp ) {
		$request = trim( (string) $wp->request, '/' );
		if ( 0 !== strpos( $request, 'docs/' ) ) {
			return;
		}
		$id = self::post_for_path( substr( $request, 5 ) );
		if ( ! $id ) {
			return;
		}
		$wp->query_vars = array(
			'post_type' => POST_TYPE,
			'p'         => $id,
		);
	}
}
