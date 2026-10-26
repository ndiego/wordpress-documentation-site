<?php
/**
 * Manifests and local sources.
 *
 * - `Manifests::load()` reads the manifests from a directory (default: the repository's `manifest/`
 *   mounted at `wp-content/wp-docs-manifest`), a single file, or one or more URLs.
 * - `Local_Sources` answers three kinds of request inside `wp_remote_get()` through `pre_http_request`,
 *   so the stock importer's own fetch code runs unchanged:
 *   1. the normalised manifest of the current run (`https://wp-docs.local/manifest/<name>.json`);
 *   2. repository files of offline manifests (`https://wp-docs.local/repo/<path>`), read from the
 *      repository checkout (WP_DOCS_REPO_PATH), with no ETag;
 *   3. optionally (WP_DOCS_PREFER_LOCAL), raw.githubusercontent.com files of the hub repository,
 *      read from the checkout instead, for working on content before it is pushed.
 *
 * @package WP_Docs
 */

namespace WP_Docs;

use WP_Error;

defined( 'WPINC' ) || die();

/**
 * Manifest loading.
 */
class Manifests {

	/**
	 * Where manifests are read from.
	 *
	 * @param bool|null $local Use the offline manifests written by scripts/assemble.py.
	 * @return string Directory, file or URL(s) separated by whitespace or commas.
	 */
	public static function location( $local = null ) {
		if ( null === $local ) {
			$local = defined( 'WP_DOCS_USE_LOCAL_MANIFESTS' ) && WP_DOCS_USE_LOCAL_MANIFESTS;
		}
		if ( $local ) {
			$location = Local_Sources::repo_path() . '/.spokes/manifest';
		} else {
			$location = defined( 'WP_DOCS_MANIFEST' ) ? WP_DOCS_MANIFEST : WP_CONTENT_DIR . '/wp-docs-manifest';
		}

		/**
		 * Filters where manifests are read from: a directory of `*.json`, one file, or URLs.
		 *
		 * @param string $location Location.
		 * @param bool   $local    Whether the offline manifests were requested.
		 */
		return (string) apply_filters( 'wp_docs_manifest_location', $location, (bool) $local );
	}

	/**
	 * Load and decode the manifests.
	 *
	 * In a directory, every `*.json` is a manifest named after its file, in name order. `all.json` (the
	 * combined manifest scripts/assemble.py also writes) is skipped when per-section files exist.
	 *
	 * @param bool|null $local See location().
	 * @return array|WP_Error { manifests: name => array, warnings: string[] }
	 */
	public static function load( $local = null ) {
		$location  = self::location( $local );
		$manifests = array();
		$warnings  = array();

		if ( preg_match( '#^https?://#i', trim( $location ) ) ) {
			foreach ( preg_split( '/[\s,]+/', trim( $location ) ) as $url ) {
				$response = wp_remote_get( $url, array( 'timeout' => 30 ) );
				if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
					return new WP_Error( 'wp-docs-manifest', "Could not fetch manifest {$url}." );
				}
				$manifests[ basename( wp_parse_url( $url, PHP_URL_PATH ), '.json' ) ] = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			}
		} elseif ( is_dir( $location ) ) {
			$files = glob( trailingslashit( $location ) . '*.json' );
			sort( $files );
			if ( count( $files ) > 1 ) {
				$files = array_values( array_filter( $files, static fn( $f ) => 'all.json' !== basename( $f ) ) );
			}
			foreach ( $files as $file ) {
				$manifests[ basename( $file, '.json' ) ] = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
		} elseif ( is_file( $location ) ) {
			$manifests[ basename( $location, '.json' ) ] = json_decode( (string) file_get_contents( $location ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} else {
			return new WP_Error( 'wp-docs-manifest', "No manifests at {$location}." );
		}

		foreach ( $manifests as $name => $data ) {
			if ( ! is_array( $data ) ) {
				$warnings[] = "Manifest {$name} is not valid JSON; skipped.";
				unset( $manifests[ $name ] );
			}
		}
		if ( ! $manifests ) {
			return new WP_Error( 'wp-docs-manifest', "No valid manifests at {$location}." );
		}

		return array(
			'manifests' => $manifests,
			'warnings'  => $warnings,
		);
	}

	/**
	 * Names of the available manifests (for validation and the webhook).
	 *
	 * @return string[]
	 */
	public static function names() {
		$loaded = self::load();
		return is_wp_error( $loaded ) ? array() : array_keys( $loaded['manifests'] );
	}
}

/**
 * Local sources served through `pre_http_request`.
 */
class Local_Sources {

	const MANIFEST_BASE = 'https://wp-docs.local/manifest/';

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
	 * Repository checkout on disk.
	 *
	 * @return string Path without trailing slash.
	 */
	public static function repo_path() {
		$path = defined( 'WP_DOCS_REPO_PATH' ) ? WP_DOCS_REPO_PATH : ABSPATH . 'wp-docs-repo';

		/**
		 * Filters the base path that repository-relative `markdown_source` paths resolve against.
		 *
		 * @param string $path Default WP_DOCS_REPO_PATH, or `wp-docs-repo` next to WordPress.
		 */
		return untrailingslashit( (string) apply_filters( 'wp_docs_repo_path', $path ) );
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
	 * Absolute path of a repository file, or null when it is missing or outside the repository.
	 *
	 * @param string $path Repository-relative path.
	 * @return string|null
	 */
	public static function file_for( $path ) {
		$path = Sources\normalize_path( (string) $path );
		if ( null === $path || '' === $path ) {
			return null;
		}

		$roots = array( self::repo_path() => '' );
		// The site also mounts the repository's content/ on its own.
		if ( 0 === strpos( $path, 'content/' ) ) {
			$roots[ WP_CONTENT_DIR . '/wp-docs-content' ] = 'content/';
		}

		foreach ( $roots as $root => $strip ) {
			$candidate = $root . '/' . substr( $path, strlen( $strip ) );
			$real      = realpath( $candidate );
			$real_root = realpath( $root );
			if ( $real && $real_root && 0 === strpos( $real, $real_root . DIRECTORY_SEPARATOR ) && is_file( $real ) ) {
				return $real;
			}
		}
		return null;
	}

	/**
	 * Short-circuit requests for local manifests and files.
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
		} elseif ( defined( 'WP_DOCS_PREFER_LOCAL' ) && WP_DOCS_PREFER_LOCAL && 0 === strpos( $bare, 'https://raw.githubusercontent.com/' ) ) {
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

		$file     = self::file_for( $path );
		$response = $file
			? self::response( 200, (string) file_get_contents( $file ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			: self::response( 404, '' );

		// A preempted request skips the `http_response` filter; apply it so the front matter step runs.
		return apply_filters( 'http_response', $response, $args, $url );
	}

	/**
	 * Build an HTTP API response array (no ETag: local files are always re-read).
	 *
	 * @param int    $code Status.
	 * @param string $body Body.
	 * @return array
	 */
	protected static function response( $code, $body ) {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Not Found',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
