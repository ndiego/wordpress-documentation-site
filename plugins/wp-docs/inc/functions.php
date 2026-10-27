<?php
/**
 * Helpers shared by the importer, the endpoints, the CLI and local sources.
 *
 * @package WP_Docs
 */

namespace WP_Docs;

defined( 'WPINC' ) || die();

/**
 * Default manifest: `manifest.json` on the content repository's default branch.
 */
const DEFAULT_MANIFEST = 'https://raw.githubusercontent.com/ndiego/wordpress-documentation/trunk/manifest.json';

/**
 * Option holding the last import summary ({time, pages, warnings, ...}). Every cache is keyed by it.
 */
const LAST_IMPORT_OPTION = 'wp_docs_last_import';

/**
 * Text of the first paragraph block, for the excerpt.
 *
 * @param string $blocks Block markup.
 * @return string
 */
function first_paragraph_text( $blocks ) {
	if ( preg_match( '#<!-- wp:paragraph[^>]*-->\s*<p[^>]*>(.*?)</p>#s', $blocks, $m ) ) {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $m[1] ) ) );
	}
	return '';
}

/**
 * The output mode for a page.
 *
 * @param int $post_id Post ID (0 outside an import).
 * @return string classic|blocks
 */
function output_mode( $post_id = 0 ) {
	/**
	 * Filters how imported content is stored.
	 *
	 * - `blocks` (default): block markup; every piece of content is inside a block.
	 * - `classic`: the stock importer's classic HTML, paragraphs added by `wpautop` at render time.
	 *
	 * @param string $mode    Default 'blocks'.
	 * @param int    $post_id Post being imported.
	 */
	$mode = apply_filters( 'wp_docs_output_mode', 'blocks', $post_id );
	return 'classic' === $mode ? 'classic' : 'blocks';
}

/**
 * Where the manifest is read from: WP_DOCS_MANIFEST (a URL or a file path), else the raw GitHub URL of
 * `manifest.json` on `trunk`. A run can override it (`wp wp-docs import --manifest=<url|path>`).
 *
 * @return string
 */
function manifest_location() {
	$location = Local_Sources::$manifest_override;
	if ( '' === $location ) {
		$location = defined( 'WP_DOCS_MANIFEST' ) && WP_DOCS_MANIFEST ? (string) WP_DOCS_MANIFEST : DEFAULT_MANIFEST;
	}

	/**
	 * Filters where the manifest is read from: a URL or a file path.
	 *
	 * @param string $location Location.
	 */
	return (string) apply_filters( 'wp_docs_manifest_location', $location );
}

/**
 * The content repository as `owner/repo`, and the branch the manifest's sources point at.
 *
 * @return array{repo: string, ref: string}
 */
function content_repo() {
	return array(
		'repo' => defined( 'WP_DOCS_REPO' ) ? (string) WP_DOCS_REPO : 'ndiego/wordpress-documentation',
		'ref'  => defined( 'WP_DOCS_REF' ) ? (string) WP_DOCS_REF : 'trunk',
	);
}

/**
 * Repository aliases: which GitHub repository corresponds to the local content checkout.
 *
 * @return array 'owner/repo' => [ 'prefix' => string, 'ref' => string ].
 */
function repo_aliases() {
	$repo    = content_repo();
	$aliases = array(
		$repo['repo'] => array(
			'prefix' => '',
			'ref'    => $repo['ref'],
		),
	);

	/**
	 * Filters the repository aliases. The content repository maps to the root of the local checkout
	 * (WP_DOCS_PREFER_LOCAL reads its files from there).
	 *
	 * @param array $aliases 'owner/repo' => [ 'prefix' => '', 'ref' => 'trunk' ].
	 */
	return (array) apply_filters( 'wp_docs_repo_aliases', $aliases );
}

/**
 * Commit SHA of the latest commit touching a file, from the GitHub API. Needs a token.
 *
 * @param array $source Parsed source.
 * @return string|null
 */
function fetch_commit_sha( array $source ) {
	if ( 'github' !== $source['kind'] ) {
		return null;
	}
	/**
	 * Filters the GitHub token used to look up commit SHAs. Without one, `_wp_docs_commit` stays empty.
	 *
	 * @param string $token Default: the WP_DOCS_GITHUB_TOKEN constant.
	 */
	$token = (string) apply_filters( 'wp_docs_github_token', defined( 'WP_DOCS_GITHUB_TOKEN' ) ? WP_DOCS_GITHUB_TOKEN : '' );
	if ( '' === $token ) {
		return null;
	}

	$url      = add_query_arg(
		array(
			'path'     => rawurlencode( $source['path'] ),
			'sha'      => rawurlencode( $source['ref'] ),
			'per_page' => 1,
		),
		"https://api.github.com/repos/{$source['owner']}/{$source['repo']}/commits"
	);
	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 10,
			'headers' => array(
				'Accept'        => 'application/vnd.github+json',
				'Authorization' => 'Bearer ' . $token,
				'User-Agent'    => 'wp-docs',
			),
		)
	);
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	$sha  = $data[0]['sha'] ?? null;
	return is_string( $sha ) && preg_match( '/^[0-9a-f]{40}$/', $sha ) ? $sha : null;
}

/**
 * The stamp every cache is keyed by: the time of the last import.
 *
 * @return string Empty before the first import.
 */
function import_stamp() {
	$last = get_option( LAST_IMPORT_OPTION );
	return is_array( $last ) && ! empty( $last['time'] ) ? (string) $last['time'] : '';
}

/**
 * A value cached until the next import. Without an import the value is built on every call.
 *
 * @param string   $name  Cache name.
 * @param callable $build Builder.
 * @return mixed
 */
function cached( $name, callable $build ) {
	static $memo = array();
	$stamp = import_stamp() . '|' . get_option( 'wp_docs_cache_version', '' ) . '|' . home_url();
	if ( isset( $memo[ $name ] ) && $memo[ $name ][0] === $stamp ) {
		return $memo[ $name ][1];
	}
	$key = 'wp_docs_cache_' . $name;
	if ( '' !== import_stamp() ) {
		$cached = get_transient( $key );
		if ( is_array( $cached ) && ( $cached['stamp'] ?? null ) === $stamp && array_key_exists( 'value', $cached ) ) {
			$memo[ $name ] = array( $stamp, $cached['value'] );
			return $cached['value'];
		}
	}
	$value = $build();
	if ( '' !== import_stamp() ) {
		set_transient(
			$key,
			array(
				'stamp' => $stamp,
				'value' => $value,
			),
			WEEK_IN_SECONDS
		);
	}
	$memo[ $name ] = array( $stamp, $value );
	return $value;
}

/**
 * Invalidate every value cached until the next import (after a page was created from the editor): the
 * caches are keyed by a version that this bumps.
 */
function flush_caches() {
	update_option( 'wp_docs_cache_version', (string) microtime( true ), false );
}
