<?php
/**
 * The manifest: load, normalise and look up `manifest.json` from the content repository.
 *
 * The format is the stock importer's (wporg-markdown README.md): an object keyed by the page key (the path
 * without trailing slash), each entry with `title`, `slug`, `parent` (the parent's key), `order` and
 * `markdown_source`, parents before children. CONTRACT.md adds `id`, `path`, `section`, `type`, `status`,
 * `description`, `updated`, `redirect_from` and `applies_to`, which the stock importer ignores. The home is
 * the key `index` with `path: ""`.
 *
 * Normalisation makes the manifest safe for the stock importer:
 * - Keys are rewritten to the parent/slug chain (the hierarchy the stock importer builds) and `parent`
 *   values are remapped the same way. The page's URL is the manifest's `path` field when present (kept as
 *   `_path`), so the URL does not have to follow the slugs. An entry with `path: ""` is the home (`_path`
 *   is empty).
 * - Entries whose parent is unknown are dropped with a warning instead of reaching the stock importer's
 *   recursive `process_manifest_doc( $manifest[ $parent ] )` with a missing key (line 213).
 * - Repository-relative `markdown_source` paths become local URLs served from the content checkout.
 *
 * `normalize()` accepts several manifests (name => decoded JSON) for testing and for merging; the plugin
 * passes one, named `manifest`.
 *
 * Pure PHP, no WordPress dependency.
 *
 * @package WP_Docs
 */

namespace WP_Docs\Manifest;

use WP_Docs\Sources;

/**
 * Normalise and merge one or more decoded manifests.
 *
 * @param array<string,array> $manifests Manifest name => decoded JSON. Order matters: earlier manifests
 *                                       win on duplicate keys.
 * @param array               $known_paths Paths of posts that already exist (parents defined elsewhere).
 * @return array{entries: array<string,array>, warnings: string[]} Entries keyed by path, parents first.
 */
function normalize( array $manifests, array $known_paths = array() ): array {
	$warnings = array();
	$raw      = array();

	foreach ( $manifests as $name => $manifest ) {
		if ( ! is_array( $manifest ) ) {
			$warnings[] = "Manifest {$name} is not a JSON object; skipped.";
			continue;
		}

		// Same convention as the stock importer for list-style manifests (line 135).
		$keys = array_keys( $manifest );
		if ( $keys && is_int( $keys[0] ) ) {
			$keyed = array();
			foreach ( $manifest as $index => $item ) {
				if ( is_array( $item ) && isset( $item['slug'] ) ) {
					$item['order']          = $item['order'] ?? $index;
					$keyed[ $item['slug'] ] = $item;
				}
			}
			$manifest = $keyed;
		}

		foreach ( $manifest as $key => $doc ) {
			$key = (string) $key;
			if ( ! is_array( $doc ) || empty( $doc['slug'] ) || empty( $doc['markdown_source'] ) ) {
				$warnings[] = "{$name}: entry {$key} has no slug or markdown_source; skipped.";
				continue;
			}
			if ( isset( $raw[ $key ] ) ) {
				$warnings[] = "{$name}: duplicate key {$key} (already defined in {$raw[ $key ]['_manifest']}); skipped.";
				continue;
			}
			if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $doc['slug'] ) ) {
				// The stock importer silently skips slugs that sanitize differently (line 241).
				$warnings[] = "{$name}: slug \"{$doc['slug']}\" of {$key} is not a sanitized slug; skipped.";
				continue;
			}
			$doc['_manifest'] = (string) $name;
			$doc['_key']      = $key;
			$raw[ $key ]      = $doc;
		}
	}

	// Section mapping: a top-level entry whose `section` names another entry hangs under that entry.
	foreach ( $raw as $key => &$doc ) {
		$section = isset( $doc['section'] ) ? (string) $doc['section'] : '';
		if ( empty( $doc['parent'] ) && '' !== $section && $section !== $key && $section !== $doc['slug'] && ( $doc['id'] ?? '' ) !== $section ) {
			$doc['parent'] = $section;
		}
	}
	unset( $doc );

	// Index by original key, by id and by slug, to resolve `parent` references.
	$by_slug = array();
	$by_id   = array();
	foreach ( $raw as $key => $doc ) {
		$by_slug[ $doc['slug'] ] = $by_slug[ $doc['slug'] ] ?? $key;
		if ( ! empty( $doc['id'] ) ) {
			$by_id[ (string) $doc['id'] ] = $key;
		}
	}
	$seen_slugs = array();
	foreach ( $raw as $key => $doc ) {
		if ( isset( $seen_slugs[ $doc['slug'] ] ) ) {
			$warnings[] = "Slug \"{$doc['slug']}\" is used by {$seen_slugs[ $doc['slug'] ]} and {$key}. The stock importer matches posts by slug as a fallback (line 171) and can move one onto the other.";
		}
		$seen_slugs[ $doc['slug'] ] = $key;
	}

	$paths   = array();
	$resolve = static function ( $key, $stack = array() ) use ( &$resolve, &$paths, $raw, $by_id, $by_slug, $known_paths ) {
		if ( isset( $paths[ $key ] ) ) {
			return $paths[ $key ];
		}
		$doc    = $raw[ $key ];
		$parent = isset( $doc['parent'] ) && '' !== (string) $doc['parent'] ? (string) $doc['parent'] : null;
		if ( null === $parent ) {
			return $paths[ $key ] = $doc['slug']; // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
		}
		if ( in_array( $key, $stack, true ) ) {
			return $paths[ $key ] = false; // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
		}
		$parent_key = isset( $raw[ $parent ] ) ? $parent : ( $by_id[ $parent ] ?? ( $by_slug[ $parent ] ?? null ) );
		if ( null === $parent_key ) {
			if ( in_array( trim( $parent, '/' ), $known_paths, true ) ) {
				return $paths[ $key ] = trim( $parent, '/' ) . '/' . $doc['slug']; // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
			}
			return $paths[ $key ] = false; // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
		}
		$parent_path = $resolve( $parent_key, array_merge( $stack, array( $key ) ) );
		return $paths[ $key ] = false === $parent_path ? false : $parent_path . '/' . $doc['slug']; // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
	};

	$parent_path_of = static fn( $path ) => false === strpos( $path, '/' ) ? null : substr( $path, 0, strrpos( $path, '/' ) );

	$entries = array();
	$homes   = 0;
	foreach ( $raw as $key => $doc ) {
		$path = $resolve( $key );
		if ( false === $path ) {
			$warnings[] = "{$doc['_manifest']}: parent \"{$doc['parent']}\" of {$key} is not in the manifests or the site; skipped.";
			continue;
		}
		if ( isset( $entries[ $path ] ) ) {
			$warnings[] = "{$doc['_manifest']}: {$key} resolves to the same parent and slug as {$entries[ $path ]['_manifest']}:{$path}; skipped.";
			continue;
		}
		if ( $path !== $key ) {
			$doc['_original_key'] = $key;
		}
		// The designed URL path under /docs/ (manifest `path`), else the parent/slug chain. `path: ""` is the home.
		if ( array_key_exists( 'path', $doc ) && null !== $doc['path'] ) {
			$doc['_path'] = trim( (string) $doc['path'], '/' );
		} else {
			$doc['_path'] = $path;
		}
		if ( '' === $doc['_path'] && null !== $parent_path_of( $path ) ) {
			$warnings[] = "{$doc['_manifest']}: {$key} has an empty path but a parent; only a top-level entry can be the home. Using {$path}.";
			$doc['_path'] = $path;
		}
		if ( '' === $doc['_path'] && ++$homes > 1 ) {
			$warnings[] = "{$doc['_manifest']}: {$key} is a second entry with an empty path (the home); skipped.";
			continue;
		}
		$doc['_key']    = $path;
		$doc['parent']  = $parent_path_of( $path );

		$source = Sources\parse( (string) $doc['markdown_source'] );
		if ( ! $source ) {
			$warnings[] = "{$doc['_manifest']}: markdown_source of {$key} is not a GitHub or repository path ({$doc['markdown_source']}); skipped.";
			continue;
		}
		$doc['_source']          = $source;
		// Offline manifests carry the GitHub blob URL in `source_url` for provenance.
		$github         = ! empty( $doc['source_url'] ) ? Sources\parse( (string) $doc['source_url'] ) : null;
		$doc['_github'] = ( $github && 'github' === $github['kind'] ) ? $github : ( 'github' === $source['kind'] ? $source : null );
		$doc['markdown_source']  = Sources\canonical_url( $source );
		$doc['id']               = isset( $doc['id'] ) && '' !== (string) $doc['id'] ? (string) $doc['id'] : $path;
		$entries[ $path ]        = $doc;
	}

	// Parents before children (the stock importer briefly creates orphans otherwise, spike 04 case K).
	uksort(
		$entries,
		static function ( $a, $b ) use ( $entries ) {
			$da = substr_count( $a, '/' );
			$db = substr_count( $b, '/' );
			if ( $da !== $db ) {
				return $da <=> $db;
			}
			return ( (int) ( $entries[ $a ]['order'] ?? 0 ) <=> (int) ( $entries[ $b ]['order'] ?? 0 ) ) ?: strcmp( $a, $b );
		}
	);

	return array(
		'entries'  => $entries,
		'warnings' => $warnings,
	);
}

/**
 * Strip the plugin's bookkeeping keys before handing entries to the stock importer.
 *
 * @param array<string,array> $entries Normalised entries.
 * @return array<string,array>
 */
function for_importer( array $entries ): array {
	$out = array();
	foreach ( $entries as $path => $doc ) {
		unset( $doc['_source'], $doc['_github'] );
		$out[ $path ] = $doc;
	}
	return $out;
}

/**
 * Build the lookup used by the link transform: match key => page path.
 *
 * @param array<string,array> $entries Normalised entries.
 * @param array               $aliases Repository aliases (Sources\to_github()).
 * @return array<string,string>
 */
function source_map( array $entries, array $aliases = array() ): array {
	$map = array();
	foreach ( $entries as $path => $doc ) {
		if ( empty( $doc['_source'] ) ) {
			continue;
		}
		$map[ Sources\match_key( $doc['_source'], $aliases ) ] = $path;
		// A local source also answers for its GitHub equivalent, and the reverse.
		$github = ! empty( $doc['_github'] ) ? $doc['_github'] : Sources\to_github( $doc['_source'], $aliases );
		if ( $github ) {
			$map[ Sources\match_key( $github, array() ) ] = $path;
		}
	}
	return $map;
}

/**
 * Link lookup callback for the links transform: parsed target source => page URL.
 *
 * @param array<string,array>  $entries  Normalised entries.
 * @param array<string,string> $map      source_map().
 * @param array                $aliases  Repository aliases.
 * @param string               $base_url URL of /docs/ with a trailing slash.
 * @return callable
 */
function lookup_callback( array $entries, array $map, array $aliases, string $base_url ): callable {
	return static function ( array $target ) use ( $entries, $map, $aliases, $base_url ) {
		$key = Sources\match_key( $target, $aliases );
		if ( ! isset( $map[ $key ] ) ) {
			$github = Sources\to_github( $target, $aliases );
			$key    = $github ? Sources\match_key( $github, array() ) : $key;
		}
		if ( ! isset( $map[ $key ], $entries[ $map[ $key ] ] ) ) {
			return null;
		}
		$path = (string) ( $entries[ $map[ $key ] ]['_path'] ?? $map[ $key ] );
		return rtrim( $base_url, '/' ) . '/' . ( '' === $path ? '' : $path . '/' );
	};
}

/**
 * Normalise `redirect_from` into same-host paths and other-host URLs.
 *
 * Root-relative paths are served by this site's redirect handler. Full URLs on another host (old
 * developer.wordpress.org handbook pages, for example) cannot be served from this site; they are kept
 * for a later cross-host rule.
 *
 * @param array  $values    redirect_from values.
 * @param string $site_host Host of this site (lowercase), to treat same-host URLs as paths.
 * @return array{paths: string[], external: string[]}
 */
function split_redirects( array $values, string $site_host = '' ): array {
	$paths    = array();
	$external = array();
	foreach ( $values as $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			continue;
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			$host = strtolower( (string) parse_url( $value, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			if ( '' !== $site_host && $host === $site_host ) {
				$paths[] = normalize_redirect_path( (string) parse_url( $value, PHP_URL_PATH ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			} else {
				$external[] = $value;
			}
			continue;
		}
		$paths[] = normalize_redirect_path( $value );
	}
	return array(
		'paths'    => array_values( array_unique( array_filter( $paths ) ) ),
		'external' => array_values( array_unique( $external ) ),
	);
}

/**
 * Normalise a path for redirect matching: leading slash, no trailing slash, lowercase, no query.
 *
 * @param string $path Path.
 * @return string
 */
function normalize_redirect_path( string $path ): string {
	$path = preg_replace( '/[?#].*$/', '', $path );
	$path = '/' . trim( rawurldecode( $path ), '/' );
	return '/' === $path ? '' : strtolower( $path );
}
