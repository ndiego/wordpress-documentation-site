<?php
/**
 * Helpers shared by the importer, the CLI and local sources.
 *
 * @package WordPressdotorg\Docs
 */

namespace WordPressdotorg\Docs;

defined( 'WPINC' ) || die();

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
	$mode = apply_filters( 'wporg_docs_output_mode', 'blocks', $post_id );
	return 'classic' === $mode ? 'classic' : 'blocks';
}

/**
 * Repository aliases: which GitHub repositories correspond to which local paths.
 *
 * @return array 'owner/repo' => [ 'prefix' => string, 'ref' => string ].
 */
function repo_aliases() {
	$aliases = array(
		( defined( 'WPORG_DOCS_HUB_REPO' ) ? WPORG_DOCS_HUB_REPO : 'ndiego/wporg-docs' ) => array(
			'prefix' => '',
			'ref'    => defined( 'WPORG_DOCS_HUB_REF' ) ? WPORG_DOCS_HUB_REF : 'trunk',
		),
	);

	/**
	 * Filters the repository aliases. The hub repository maps to the repository root; add spokes with
	 * their `.spokes/<name>/` prefix so offline imports get GitHub edit links and cross-links resolve.
	 *
	 * @param array $aliases 'owner/repo' => [ 'prefix' => '', 'ref' => 'trunk' ].
	 */
	return (array) apply_filters( 'wporg_docs_repo_aliases', $aliases );
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
	 * Filters the GitHub token used to look up commit SHAs. Without one, `_wporg_docs_commit` stays empty.
	 *
	 * @param string $token Default: the WPORG_DOCS_GITHUB_TOKEN constant.
	 */
	$token = (string) apply_filters( 'wporg_docs_github_token', defined( 'WPORG_DOCS_GITHUB_TOKEN' ) ? WPORG_DOCS_GITHUB_TOKEN : '' );
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
				'User-Agent'    => 'wporg-docs',
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
