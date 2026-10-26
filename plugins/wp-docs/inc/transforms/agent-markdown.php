<?php
/**
 * Markdown for agents: the page as served at `/docs/<path>.md` and stored in `_wp_docs_markdown`.
 *
 * A front matter block (`title`, `description`, `type`, `url`, `source`, and `status` when the page is a
 * placeholder or a draft), then the body as written, with its H1, where:
 * - relative links (`[x](other.md#a)`, reference definitions, `<a href>`) become absolute site URLs, or
 *   GitHub URLs for repository files that are not pages;
 * - relative images (`![x](images/a.webp)`, `<img src>`) become the URLs of their sideloaded attachments;
 * - absolute URLs, same-page `#anchors`, and anything inside code fences or inline code are left alone.
 *
 * The rewriting is delegated to two callbacks so this file stays pure and testable.
 *
 * Pure PHP, no WordPress dependency.
 *
 * @package WP_Docs
 */

namespace WP_Docs\Transforms\Agent_Markdown;

use WP_Docs\Sources;
use WP_Docs\Transforms\Links;

/**
 * Build the Markdown served to agents.
 *
 * @param string   $body  Markdown body without front matter (it should start with the `# Title` line).
 * @param array    $meta  title, description, type, url, source, status.
 * @param callable $link  fn( string $href ): ?string. Replacement for a link target, or null to keep it.
 * @param callable $image fn( string $src, string $alt ): ?string. Replacement for an image source, or null.
 * @return string
 */
function build( string $body, array $meta, callable $link, callable $image ): string {
	$body = preg_replace( "/^\xEF\xBB\xBF/", '', str_replace( "\r\n", "\n", $body ) );
	$body = rewrite( trim( (string) $body, "\n" ), $link, $image );

	$title = (string) ( $meta['title'] ?? '' );
	if ( '' !== $title && ! preg_match( '/^#\s/', ltrim( $body ) ) ) {
		$body = '# ' . $title . "\n\n" . $body;
	}

	return front_matter( $meta ) . "\n" . $body . "\n";
}

/**
 * The front matter block.
 *
 * @param array $meta Values.
 * @return string
 */
function front_matter( array $meta ): string {
	$lines = array( '---' );
	foreach ( array( 'title', 'description', 'type', 'url', 'source' ) as $key ) {
		$lines[] = $key . ': ' . yaml_scalar( (string) ( $meta[ $key ] ?? '' ) );
	}
	if ( ! empty( $meta['status'] ) ) {
		$lines[] = 'status: ' . yaml_scalar( (string) $meta['status'] );
	}
	$lines[] = '---';
	return implode( "\n", $lines ) . "\n";
}

/**
 * A YAML scalar: plain when safe, otherwise double quoted.
 *
 * @param string $value Value.
 * @return string
 */
function yaml_scalar( string $value ): string {
	if ( '' === $value ) {
		return '""';
	}
	if ( preg_match( '/^[A-Za-z0-9][A-Za-z0-9 .,\/()_\'?!+&-]*$/', $value ) && ! preg_match( '/^(true|false|yes|no|null|on|off|~)$/i', $value ) && ! preg_match( '/\s$/', $value ) ) {
		return $value;
	}
	return '"' . addcslashes( $value, "\"\\\n\r\t" ) . '"';
}

/**
 * Rewrite link and image targets outside code.
 *
 * @param string   $markdown Markdown.
 * @param callable $link     See build().
 * @param callable $image    See build().
 * @return string
 */
function rewrite( string $markdown, callable $link, callable $image ): string {
	$lines = explode( "\n", $markdown );
	$fence = null;
	foreach ( $lines as $i => $line ) {
		if ( null !== $fence ) {
			if ( preg_match( '/^\s{0,3}(`{3,}|~{3,})\s*$/', $line, $m ) && $m[1][0] === $fence[0] && strlen( $m[1] ) >= strlen( $fence ) ) {
				$fence = null;
			}
			continue;
		}
		if ( preg_match( '/^\s{0,3}(`{3,}|~{3,})/', $line, $m ) ) {
			$fence = $m[1];
			continue;
		}
		// Indented code (four spaces after a blank line) is rare in this content and not detected.

		// Reference definitions: `[label]: target "title"`.
		if ( preg_match( '/^(\s{0,3}\[[^\]]+\]:\s*)(<[^>]*>|\S+)(.*)$/', $line, $m ) ) {
			$target    = trim( $m[2], '<>' );
			$new       = $link( $target );
			$lines[ $i ] = null === $new ? $line : $m[1] . $new . $m[3];
			continue;
		}

		$lines[ $i ] = rewrite_line( $line, $link, $image );
	}
	return implode( "\n", $lines );
}

/**
 * Rewrite one line, leaving inline code spans alone.
 *
 * @param string   $line  Line.
 * @param callable $link  See build().
 * @param callable $image See build().
 * @return string
 */
function rewrite_line( string $line, callable $link, callable $image ): string {
	$parts = preg_split( '/(`+[^`]*`+)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE );
	foreach ( $parts as $n => $part ) {
		if ( 1 === $n % 2 ) {
			continue; // A code span.
		}

		// Inline images first (an image can sit inside a link), then inline links:
		// ![alt](src "title") and [text](target "title").
		$target_re = '\(\s*(<[^>]*>|[^\s()]*(?:\([^\s()]*\)[^\s()]*)*)(\s+(?:"[^"]*"|\'[^\']*\'))?\s*\)';
		$text_re   = '\[((?:[^\[\]\\\\]|\\\\.|\[[^\[\]]*\])*)\]';
		$part      = preg_replace_callback(
			'/!' . $text_re . $target_re . '/',
			static function ( $m ) use ( $image ) {
				$new = $image( trim( $m[2], '<>' ), $m[1] );
				return null === $new ? $m[0] : '![' . $m[1] . '](' . $new . ( $m[3] ?? '' ) . ')';
			},
			$part
		);
		$part      = preg_replace_callback(
			'/(?<!!)' . $text_re . $target_re . '/',
			static function ( $m ) use ( $link ) {
				$new = $link( trim( $m[2], '<>' ) );
				return null === $new ? $m[0] : '[' . $m[1] . '](' . $new . ( $m[3] ?? '' ) . ')';
			},
			$part
		);

		// Raw HTML: <a href="..."> and <img src="...">.
		$part = preg_replace_callback(
			'/(<a\b[^>]*?\shref=)(["\'])([^"\']*)\2/i',
			static function ( $m ) use ( $link ) {
				$new = $link( html_entity_decode( $m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				return null === $new ? $m[0] : $m[1] . $m[2] . htmlspecialchars( $new, ENT_QUOTES, 'UTF-8' ) . $m[2];
			},
			$part
		);
		$part = preg_replace_callback(
			'/(<img\b[^>]*?\ssrc=)(["\'])([^"\']*)\2/i',
			static function ( $m ) use ( $image ) {
				$alt = preg_match( '/\salt=(["\'])([^"\']*)\1/i', $m[0], $a ) ? $a[2] : '';
				$new = $image( html_entity_decode( $m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $alt );
				return null === $new ? $m[0] : $m[1] . $m[2] . htmlspecialchars( $new, ENT_QUOTES, 'UTF-8' ) . $m[2];
			},
			$part
		);

		$parts[ $n ] = $part;
	}
	return implode( '', $parts );
}

/**
 * Whether a link target is relative (a candidate for rewriting): not a URL with a scheme, not
 * protocol-relative, not a same-page anchor, not empty.
 *
 * @param string $href Target.
 * @return bool
 */
function is_relative( string $href ): bool {
	$href = trim( $href );
	return '' !== $href && '#' !== $href[0] && 0 !== strpos( $href, '//' ) && ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href );
}

/**
 * The link callback for build(), with the same rules as the HTML links transform (links.php): a target
 * that is a page in the manifest becomes that page's URL with its fragment; another repository file
 * becomes its GitHub URL; a Markdown file that matches no page, and every absolute URL that is not a
 * page, is kept.
 *
 * @param array         $base     Parsed source of the current file.
 * @param callable      $lookup   fn( array $target ): ?string, page URL for a parsed source.
 * @param callable|null $file_url fn( array $target ): ?string, URL of a non-page repository file.
 * @return callable
 */
function link_resolver( array $base, callable $lookup, ?callable $file_url = null ): callable {
	return static function ( string $href ) use ( $base, $lookup, $file_url ): ?string {
		$target = Sources\resolve( $base, $href );
		if ( ! $target ) {
			return null;
		}
		$fragment = '' !== $target['fragment'] ? '#' . $target['fragment'] : '';
		$url      = Links\resolve_target( $target, $lookup );
		if ( null !== $url ) {
			return $url . $fragment;
		}
		if ( ! is_relative( $href ) ) {
			return null;
		}
		if ( preg_match( '/\.(md|markdown)$/i', $target['path'] ) || '/' === substr( $target['path'], -1 ) || '' === $target['path'] ) {
			return null;
		}
		$file = $file_url ? $file_url( $target ) : ( 'github' === $target['kind'] ? Sources\blob_url( $target ) : null );
		return $file ? $file . $fragment : null;
	};
}

/**
 * The image callback for build(): a relative image becomes the URL of its attachment.
 *
 * @param array    $base   Parsed source of the current file.
 * @param callable $import fn( array $target, string $alt ): ?array{url: string, id: int}.
 * @return callable
 */
function image_resolver( array $base, callable $import ): callable {
	return static function ( string $src, string $alt ) use ( $base, $import ): ?string {
		if ( ! is_relative( $src ) ) {
			return null;
		}
		$target = Sources\resolve( $base, $src );
		if ( ! $target ) {
			return null;
		}
		$attachment = $import( $target, $alt );
		return ! empty( $attachment['url'] ) ? (string) $attachment['url'] : null;
	};
}
