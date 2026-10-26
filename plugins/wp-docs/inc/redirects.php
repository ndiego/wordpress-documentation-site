<?php
/**
 * Change (e): redirects as data.
 *
 * Today a renamed handbook page needs a second pull request to wporg-developer adding a line to a
 * hard-coded PHP array (import-block-editor.php `redirects()`, about 70 entries). Here `redirect_from`
 * travels with the page in the manifest (and front matter); the importer stores it in
 * `_wp_docs_redirect_from` and this handler serves a 301 for any old path that would otherwise 404.
 *
 * Only same-host paths can be served here. Full URLs on other hosts (old developer.wordpress.org
 * handbook pages, for example) are stored in `_wp_docs_redirect_external` for a rule on that host;
 * this site never sees those requests.
 *
 * @package WP_Docs
 */

namespace WP_Docs;

defined( 'WPINC' ) || die();

/**
 * Redirect lookup.
 */
class Redirects {

	const OPTION = 'wp_docs_redirects';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
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
				"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_wp_docs_redirect_from' AND p.post_type = %s AND p.post_status = 'publish' ORDER BY p.ID",
				POST_TYPE
			)
		);

		$map = array();
		foreach ( $rows as $row ) {
			foreach ( (array) json_decode( (string) $row->meta_value, true ) as $path ) {
				$path = Manifest\normalize_redirect_path( (string) $path );
				if ( '' !== $path && ! isset( $map[ $path ] ) ) {
					$map[ $path ] = (int) $row->post_id;
				}
			}
		}
		update_option( self::OPTION, $map, false );
		return $map;
	}

	/**
	 * 301 an old path to its page, only when the request would 404.
	 */
	public static function maybe_redirect() {
		/**
		 * Filters whether the redirect handler runs.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! is_404() || ! apply_filters( 'wp_docs_redirects_enabled', true ) ) {
			return;
		}
		$target = self::lookup( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( $target ) {
			wp_safe_redirect( $target, 301, 'wp-docs' );
			exit;
		}
	}

	/**
	 * The URL an old path redirects to.
	 *
	 * @param string $request_path Request path.
	 * @return string|null
	 */
	public static function lookup( $request_path ) {
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '/' !== $home && 0 === strpos( $request_path, untrailingslashit( $home ) ) ) {
			$request_path = substr( $request_path, strlen( untrailingslashit( $home ) ) );
		}
		$path = Manifest\normalize_redirect_path( $request_path );
		$map  = (array) get_option( self::OPTION, array() );

		/**
		 * Filters the redirect lookup (normalised path => post ID).
		 *
		 * @param array<string,int> $map Lookup.
		 */
		$map = (array) apply_filters( 'wp_docs_redirects', $map );
		if ( '' === $path || empty( $map[ $path ] ) || 'publish' !== get_post_status( (int) $map[ $path ] ) ) {
			return null;
		}
		return get_permalink( (int) $map[ $path ] );
	}
}
