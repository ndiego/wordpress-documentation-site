<?php
/**
 * The manifest and local sources.
 *
 * - `Local_Sources::load_manifest()` reads `manifest.json` from a URL or a file path
 *   (WP_DOCS_MANIFEST, see manifest_location()).
 * - `Local_Sources` answers three kinds of request inside `wp_remote_get()` through `pre_http_request`, so
 *   the stock importer's own fetch code runs unchanged:
 *   1. the normalised manifest of the current run (`https://wp-docs.local/manifest/manifest.json`);
 *   2. repository-relative sources (`https://wp-docs.local/repo/<path>`), read from the content checkout;
 *   3. with WP_DOCS_PREFER_LOCAL, raw.githubusercontent.com files of the content repository, read from the
 *      content checkout instead, so unpushed content can be previewed. A file missing from the checkout
 *      is fetched from GitHub as usual.
 *   Local files get an ETag (MD5 of the bytes) and answer `If-None-Match` with 304, as GitHub does, so an
 *   unchanged file is not re-imported.
 *
 * The content checkout is WP_DOCS_CONTENT_PATH; else the directory of the manifest when the manifest is
 * a local file; else `wp-content/wp-docs-content` (where `.wp-env.json` mounts it).
 *
 * @package WP_Docs
 */

namespace WP_Docs;

use WP_Error;

defined( 'WPINC' ) || die();

/**
 * Local sources served through `pre_http_request`.
 */
class Local_Sources {

	const MANIFEST_BASE = 'https://wp-docs.local/manifest/';

	/**
	 * Manifest location for the current run (CLI `--manifest`), empty for the configured one.
	 *
	 * @var string
	 */
	public static $manifest_override = '';

	/**
	 * Manifest payloads of the current request, by URL.
	 *
	 * @var array<string,array>
	 */
	protected static $manifests = array();

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'pre_http_request', array( __CLASS__, 'pre_http_request' ), 10, 3 );
	}

	/**
	 * Read and decode the manifest.
	 *
	 * @return array|WP_Error Decoded manifest.
	 */
	public static function load_manifest() {
		$location = manifest_location();
		if ( preg_match( '#^https?://#i', $location ) ) {
			$response = wp_remote_get( $location, array( 'timeout' => 30 ) );
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'wp-docs-manifest', "Could not fetch the manifest {$location}: " . $response->get_error_message() );
			}
			if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return new WP_Error( 'wp-docs-manifest', "Could not fetch the manifest {$location}: HTTP " . wp_remote_retrieve_response_code( $response ) . '.' );
			}
			$json = (string) wp_remote_retrieve_body( $response );
		} elseif ( is_file( $location ) && is_readable( $location ) ) {
			$json = (string) file_get_contents( $location ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} else {
			return new WP_Error( 'wp-docs-manifest', "No manifest at {$location}." );
		}

		$manifest = json_decode( $json, true );
		if ( ! is_array( $manifest ) || ! $manifest ) {
			return new WP_Error( 'wp-docs-manifest', "The manifest at {$location} is not a JSON object." );
		}
		return $manifest;
	}

	/**
	 * The content checkout on disk.
	 *
	 * @return string Path without trailing slash.
	 */
	public static function content_path() {
		if ( defined( 'WP_DOCS_CONTENT_PATH' ) && WP_DOCS_CONTENT_PATH ) {
			$path = (string) WP_DOCS_CONTENT_PATH;
		} else {
			$manifest = manifest_location();
			$path     = ( ! preg_match( '#^https?://#i', $manifest ) && is_file( $manifest ) ) ? dirname( $manifest ) : WP_CONTENT_DIR . '/wp-docs-content';
		}

		/**
		 * Filters the content checkout that local sources are read from.
		 *
		 * @param string $path Path.
		 */
		return untrailingslashit( (string) apply_filters( 'wp_docs_content_path', $path ) );
	}

	/**
	 * Whether files of the content repository are read from the checkout first.
	 *
	 * @return bool
	 */
	public static function prefer_local() {
		return defined( 'WP_DOCS_PREFER_LOCAL' ) && WP_DOCS_PREFER_LOCAL;
	}

	/**
	 * Register the manifest payload for a run and return the URL the stock importer should fetch.
	 *
	 * @param string $name    Name.
	 * @param array  $entries Entries.
	 * @return string
	 */
	public static function register_manifest( $name, array $entries ) {
		$url                     = self::MANIFEST_BASE . rawurlencode( $name ) . '.json';
		self::$manifests[ $url ] = $entries;
		return $url;
	}

	/**
	 * The payload registered for a manifest URL.
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	public static function get_manifest_payload( $url ) {
		return self::$manifests[ $url ] ?? null;
	}

	/**
	 * Absolute path of a repository file, or null when it is missing or outside the checkout.
	 *
	 * @param string $path Repository-relative path.
	 * @return string|null
	 */
	public static function file_for( $path ) {
		$path = Sources\normalize_path( (string) $path );
		if ( null === $path || '' === $path ) {
			return null;
		}
		$root      = self::content_path();
		$real      = realpath( $root . '/' . $path );
		$real_root = realpath( $root );
		if ( $real && $real_root && 0 === strpos( $real, $real_root . DIRECTORY_SEPARATOR ) && is_file( $real ) ) {
			return $real;
		}
		return null;
	}

	/**
	 * Short-circuit requests for the run's manifest and for local files.
	 *
	 * @param false|array|WP_Error $pre  Preempted response.
	 * @param array                $args Request args.
	 * @param string               $url  URL.
	 * @return false|array|WP_Error
	 */
	public static function pre_http_request( $pre, $args, $url ) {
		if ( false !== $pre ) {
			return $pre;
		}
		$bare = preg_replace( '/\?.*$/', '', (string) $url );

		if ( 0 === strpos( $bare, self::MANIFEST_BASE ) ) {
			if ( ! isset( self::$manifests[ $bare ] ) ) {
				return self::response( 404, '' );
			}
			return self::response( 200, (string) wp_json_encode( self::$manifests[ $bare ] ) );
		}

		$path = null;
		if ( 0 === strpos( $bare, Sources\LOCAL_BASE ) ) {
			$path = rawurldecode( substr( $bare, strlen( Sources\LOCAL_BASE ) ) );
		} elseif ( self::prefer_local() && 0 === strpos( $bare, 'https://raw.githubusercontent.com/' ) ) {
			$source = Sources\parse( $bare );
			if ( $source ) {
				$key = Sources\match_key( $source, repo_aliases() );
				if ( 0 === strpos( $key, 'local:' ) && self::file_for( substr( $key, 6 ) ) ) {
					$path = substr( $key, 6 );
				}
			}
		}
		if ( null === $path ) {
			return false;
		}

		$file = self::file_for( $path );
		if ( ! $file ) {
			$response = self::response( 404, '' );
		} else {
			$body = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$etag = '"' . md5( $body ) . '"';
			$sent = '';
			foreach ( (array) ( $args['headers'] ?? array() ) as $name => $value ) {
				if ( 'if-none-match' === strtolower( (string) $name ) ) {
					$sent = (string) $value;
				}
			}
			$response = $sent === $etag ? self::response( 304, '', $etag ) : self::response( 200, $body, $etag );
		}

		// A preempted request skips the `http_response` filter; apply it so the front matter step runs.
		return apply_filters( 'http_response', $response, $args, $url );
	}

	/**
	 * Build an HTTP API response array.
	 *
	 * @param int    $code Status.
	 * @param string $body Body.
	 * @param string $etag ETag header, if any.
	 * @return array
	 */
	protected static function response( $code, $body, $etag = '' ) {
		$messages = array(
			200 => 'OK',
			304 => 'Not Modified',
			404 => 'Not Found',
		);
		return array(
			'headers'  => '' !== $etag ? array( 'etag' => $etag ) : array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => $messages[ $code ] ?? '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
