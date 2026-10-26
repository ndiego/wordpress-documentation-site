<?php
/**
 * Source URLs: parsing, resolving and deriving GitHub links for a page's Markdown source.
 *
 * A manifest entry's `markdown_source` is one of:
 * - a GitHub blob URL, `https://github.com/<owner>/<repo>/blob/<ref>/<path>` (the contract form);
 * - a raw URL, `https://raw.githubusercontent.com/<owner>/<repo>/<ref>/<path>`;
 * - a repository-relative path, `content/...` or `.spokes/<spoke>/...` (the offline manifests under
 *   `manifest/local/`), which the plugin turns into a local URL `https://wp-docs.local/repo/<path>`
 *   and serves from disk (see local-sources.php).
 *
 * Refs containing a slash are not supported (the ref is the segment after `blob/`).
 *
 * Pure PHP, no WordPress dependency.
 *
 * @package WP_Docs
 */

namespace WP_Docs\Sources;

const LOCAL_BASE = 'https://wp-docs.local/repo/';

/**
 * Parse a source URL.
 *
 * @param string $url URL or repository-relative path.
 * @return array|null { kind: github|local, owner, repo, ref, path, fragment, query } or null.
 */
function parse( string $url ): ?array {
	$fragment = '';
	$query    = '';
	if ( false !== ( $pos = strpos( $url, '#' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
		$fragment = substr( $url, $pos + 1 );
		$url      = substr( $url, 0, $pos );
	}
	if ( false !== ( $pos = strpos( $url, '?' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
		$query = substr( $url, $pos + 1 );
		$url   = substr( $url, 0, $pos );
	}

	$base = array(
		'kind'     => '',
		'owner'    => '',
		'repo'     => '',
		'ref'      => '',
		'path'     => '',
		'fragment' => $fragment,
		'query'    => $query,
	);

	if ( preg_match( '#^https?://github\.com/([^/]+)/([^/]+)/(?:blob|edit|tree|raw)/([^/]+)/(.+)$#i', $url, $m ) ) {
		return array_merge( $base, array( 'kind' => 'github', 'owner' => $m[1], 'repo' => $m[2], 'ref' => $m[3], 'path' => rawurldecode( $m[4] ) ) );
	}
	if ( preg_match( '#^https?://raw\.githubusercontent\.com/([^/]+)/([^/]+)/([^/]+)/(.+)$#i', $url, $m ) ) {
		return array_merge( $base, array( 'kind' => 'github', 'owner' => $m[1], 'repo' => $m[2], 'ref' => $m[3], 'path' => rawurldecode( $m[4] ) ) );
	}
	if ( 0 === strpos( $url, LOCAL_BASE ) ) {
		return array_merge( $base, array( 'kind' => 'local', 'path' => rawurldecode( substr( $url, strlen( LOCAL_BASE ) ) ) ) );
	}
	if ( '' !== $url && ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) && '/' !== $url[0] ) {
		// A repository-relative path from an offline manifest.
		$path = normalize_path( $url );
		return null === $path ? null : array_merge( $base, array( 'kind' => 'local', 'path' => $path ) );
	}

	return null;
}

/**
 * The URL the importer should fetch for a source.
 *
 * @param array $s Parsed source.
 * @return string
 */
function fetch_url( array $s ): string {
	if ( 'local' === $s['kind'] ) {
		return LOCAL_BASE . encode_path( $s['path'] );
	}
	return "https://raw.githubusercontent.com/{$s['owner']}/{$s['repo']}/{$s['ref']}/" . encode_path( $s['path'] );
}

/**
 * The canonical form stored as the importer's markdown source: the blob URL for GitHub sources (so
 * the vendored importer converts it to raw for fetching and the edit link works), the local URL
 * otherwise.
 *
 * @param array $s Parsed source.
 * @return string
 */
function canonical_url( array $s ): string {
	return 'github' === $s['kind'] ? blob_url( $s ) : fetch_url( $s );
}

/**
 * GitHub blob URL.
 *
 * @param array $s Parsed GitHub source.
 * @return string
 */
function blob_url( array $s ): string {
	return "https://github.com/{$s['owner']}/{$s['repo']}/blob/{$s['ref']}/" . encode_path( $s['path'] );
}

/**
 * GitHub edit URL.
 *
 * @param array $s Parsed GitHub source.
 * @return string
 */
function edit_url( array $s ): string {
	return "https://github.com/{$s['owner']}/{$s['repo']}/edit/{$s['ref']}/" . encode_path( $s['path'] );
}

/**
 * Prefilled "new issue" URL in the owning repository.
 *
 * @param array       $s        Parsed GitHub source.
 * @param string      $page_url Site URL of the page.
 * @param string      $title    Page title.
 * @param string|null $commit   Imported commit SHA, if known.
 * @return string
 */
function issue_url( array $s, string $page_url, string $title, ?string $commit ): string {
	$body = "Page: {$page_url}\nSource: {$s['path']}\nCommit: " . ( $commit ? $commit : 'unknown' ) . "\n\nWhat is wrong or missing?\n";

	return "https://github.com/{$s['owner']}/{$s['repo']}/issues/new?" . http_build_query(
		array(
			'title' => "Docs: {$title}",
			'body'  => $body,
		),
		'',
		'&',
		PHP_QUERY_RFC3986
	);
}

/**
 * Translate a local source into its GitHub equivalent using the repository aliases.
 *
 * @param array $s       Parsed source.
 * @param array $aliases 'owner/repo' => [ 'prefix' => 'local/path/prefix/', 'ref' => 'trunk' ].
 * @return array|null Parsed GitHub source, or null when no alias covers the path.
 */
function to_github( array $s, array $aliases ): ?array {
	if ( 'github' === $s['kind'] ) {
		return $s;
	}

	$best = null;
	foreach ( $aliases as $repo => $alias ) {
		$prefix = (string) ( $alias['prefix'] ?? '' );
		if ( 0 === strpos( $s['path'], $prefix ) && ( null === $best || strlen( $prefix ) > strlen( $best[1] ) ) ) {
			$best = array( $repo, $prefix, (string) ( $alias['ref'] ?? 'trunk' ) );
		}
	}
	if ( null === $best || false === strpos( $best[0], '/' ) ) {
		return null;
	}
	list( $owner, $repo ) = explode( '/', $best[0], 2 );

	return array_merge(
		$s,
		array(
			'kind'  => 'github',
			'owner' => $owner,
			'repo'  => $repo,
			'ref'   => $best[2],
			'path'  => substr( $s['path'], strlen( $best[1] ) ),
		)
	);
}

/**
 * Key used to match a link target to a manifest entry. The ref is ignored (a link to `blob/trunk/x.md`
 * matches an entry imported from `blob/wp/6.9/x.md`), owner and repository are case-insensitive, and
 * GitHub sources covered by an alias match their local path.
 *
 * @param array $s       Parsed source.
 * @param array $aliases Repository aliases, see to_github().
 * @return string
 */
function match_key( array $s, array $aliases = array() ): string {
	if ( 'github' === $s['kind'] ) {
		$id = strtolower( "{$s['owner']}/{$s['repo']}" );
		foreach ( $aliases as $repo => $alias ) {
			if ( strtolower( $repo ) === $id ) {
				return 'local:' . ( $alias['prefix'] ?? '' ) . $s['path'];
			}
		}
		return "github:{$id}:{$s['path']}";
	}
	return 'local:' . $s['path'];
}

/**
 * Resolve a link or image reference relative to the Markdown file it appears in, the way GitHub does.
 *
 * Handles `x.md`, `./x.md`, `../x.md` and repository-root paths `/x.md`, with optional `?query` and
 * `#fragment`. Absolute GitHub blob and raw URLs are parsed as is. Anything else (other hosts,
 * `mailto:`, same-page `#anchors`, protocol-relative URLs) returns null.
 *
 * @param array  $base Parsed source of the current file.
 * @param string $href Reference as written.
 * @return array|null Parsed target source.
 */
function resolve( array $base, string $href ): ?array {
	$href = trim( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	if ( '' === $href || '#' === $href[0] || 0 === strpos( $href, '//' ) ) {
		return null;
	}
	if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) ) {
		$parsed = parse( $href );
		return ( $parsed && 'github' === $parsed['kind'] ) ? $parsed : null;
	}

	$fragment = '';
	$query    = '';
	if ( false !== ( $pos = strpos( $href, '#' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
		$fragment = substr( $href, $pos + 1 );
		$href     = substr( $href, 0, $pos );
	}
	if ( false !== ( $pos = strpos( $href, '?' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
		$query = substr( $href, $pos + 1 );
		$href  = substr( $href, 0, $pos );
	}
	$href = rawurldecode( $href );
	if ( '' === $href ) {
		return null;
	}

	if ( '/' === $href[0] ) {
		// Repository root. For a spoke checked out under `.spokes/<name>/`, that directory is its root.
		$root = ( 'local' === $base['kind'] && preg_match( '#^\.spokes/[^/]+/#', $base['path'], $m ) ) ? $m[0] : '';
		$path = normalize_path( $root . ltrim( $href, '/' ) );
	} else {
		$dir  = dirname( $base['path'] );
		$path = normalize_path( ( '.' === $dir ? '' : $dir . '/' ) . $href );
	}
	if ( null === $path ) {
		return null;
	}

	return array_merge(
		$base,
		array(
			'path'     => $path,
			'fragment' => $fragment,
			'query'    => $query,
		)
	);
}

/**
 * Collapse `.` and `..` segments. Returns null when the path climbs above the root.
 * A trailing slash is kept (it marks a directory).
 *
 * @param string $path Path.
 * @return string|null
 */
function normalize_path( string $path ): ?string {
	$trailing = '/' === substr( $path, -1 );
	$out      = array();
	foreach ( explode( '/', $path ) as $segment ) {
		if ( '' === $segment || '.' === $segment ) {
			continue;
		}
		if ( '..' === $segment ) {
			if ( ! $out ) {
				return null;
			}
			array_pop( $out );
			continue;
		}
		$out[] = $segment;
	}
	$joined = implode( '/', $out );

	return $trailing && '' !== $joined ? $joined . '/' : $joined;
}

/**
 * Percent-encode each path segment.
 *
 * @param string $path Path.
 * @return string
 */
function encode_path( string $path ): string {
	return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
}
