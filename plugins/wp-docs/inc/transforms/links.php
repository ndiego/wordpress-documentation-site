<?php
/**
 * Transform (d): links to other Markdown files become site URLs.
 *
 * The stock importer rewrites no links (spike 04: 2,254 relative `.md` links on 273 pages left broken).
 * Each handbook subclass carries its own regexes today (import-block-editor.php lines 295-335). This
 * transform resolves every `<a href>` the way GitHub does (relative to the current file, or to the
 * repository root for `/path`), then looks the target up in the manifest by its source, so the same
 * Markdown link works on GitHub and on WordPress.org.
 *
 * - A target that matches a manifest entry's `markdown_source` (blob URL, raw URL or resolved relative
 *   path, any ref) becomes that page's URL, keeping `#fragment`.
 * - A directory link (`./blocks/`) matches the directory's `README.md` (or `index.md`).
 * - A relative link to a Markdown file that matches no entry is left as written and reported.
 * - A relative link to any other repository file (a `.php` example, a `.json` schema) becomes its GitHub
 *   blob URL, because the relative path can never work on the site.
 *
 * Uses WP_HTML_Tag_Processor (WordPress 6.2+), so `href="..."` text inside escaped code samples is never
 * touched. No other WordPress dependency.
 *
 * @package WordPressdotorg\Docs
 */

namespace WordPressdotorg\Docs\Transforms\Links;

use WordPressdotorg\Docs\Sources;

/**
 * Rewrite links.
 *
 * @param string   $html       HTML.
 * @param array    $base       Parsed source of the current file (Sources\parse()).
 * @param callable $lookup     fn( array $target ): ?string. Returns the page URL (without fragment)
 *                             for a parsed target source, or null.
 * @param array    $unresolved Receives the hrefs that look like Markdown links and resolved to nothing.
 * @param callable|null $file_url fn( array $target ): ?string. URL for a non-Markdown repository file
 *                             (defaults to its GitHub blob URL for GitHub sources, null otherwise).
 * @return string
 */
function rewrite( string $html, array $base, callable $lookup, array &$unresolved = array(), ?callable $file_url = null ): string {
	$tags = new \WP_HTML_Tag_Processor( $html );

	while ( $tags->next_tag( 'a' ) ) {
		$href = $tags->get_attribute( 'href' );
		if ( ! is_string( $href ) || '' === $href ) {
			continue;
		}

		$target = Sources\resolve( $base, $href );
		if ( ! $target ) {
			continue;
		}
		$is_absolute = (bool) preg_match( '#^https?://#i', $href );

		$url = resolve_target( $target, $lookup );
		if ( null !== $url ) {
			$tags->set_attribute( 'href', $url . ( '' !== $target['fragment'] ? '#' . $target['fragment'] : '' ) );
			continue;
		}

		if ( $is_absolute ) {
			// An absolute GitHub URL that is not a page in the manifest stays a GitHub link.
			continue;
		}

		if ( preg_match( '/\.(md|markdown)$/i', $target['path'] ) || '/' === substr( $target['path'], -1 ) || '' === $target['path'] ) {
			$unresolved[] = $href;
			continue;
		}

		$file = $file_url ? $file_url( $target ) : ( 'github' === $target['kind'] ? Sources\blob_url( $target ) : null );
		if ( $file ) {
			$tags->set_attribute( 'href', $file . ( '' !== $target['fragment'] ? '#' . $target['fragment'] : '' ) );
		}
	}

	return $tags->get_updated_html();
}

/**
 * Look up a target, trying the README of a directory and an extensionless path.
 *
 * @param array    $target Parsed target.
 * @param callable $lookup Lookup callback.
 * @return string|null
 */
function resolve_target( array $target, callable $lookup ): ?string {
	$candidates = array( $target['path'] );
	$path       = rtrim( $target['path'], '/' );
	if ( '/' === substr( $target['path'], -1 ) || '' === $target['path'] ) {
		$dir        = '' === $path ? '' : $path . '/';
		$candidates = array( $dir . 'README.md', $dir . 'readme.md', $dir . 'index.md' );
	} elseif ( ! preg_match( '/\.[a-z0-9]+$/i', $path ) ) {
		$candidates[] = $path . '.md';
		$candidates[] = $path . '/README.md';
		$candidates[] = $path . '/index.md';
	}

	foreach ( $candidates as $candidate ) {
		$url = $lookup( array_merge( $target, array( 'path' => $candidate ) ) );
		if ( null !== $url ) {
			return $url;
		}
	}

	return null;
}
