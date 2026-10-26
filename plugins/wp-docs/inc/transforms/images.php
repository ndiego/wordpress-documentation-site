<?php
/**
 * Transform (c): images in the repository are sideloaded into the media library.
 *
 * The stock importer stores `<img src="../images/x.webp">` as written, which is broken on the site
 * (spike 04: 2,300 relative images on 261 pages). Today authors upload images by hand to the
 * developer.wordpress.org media library and paste absolute URLs; only the Playground subclass
 * sideloads (import-playground.php `import_images()`, keyed per source URL).
 *
 * Here a relative image path is resolved against the Markdown file's location (GitHub semantics),
 * fetched raw, and imported once per content hash: the same bytes referenced from ten pages, or
 * re-imported after an unrelated edit, reuse one attachment. The `<img>` keeps its alt text and gets
 * the attachment URL and the `wp-image-<id>` class the image block expects. Absolute image URLs (other
 * hosts) are left alone.
 *
 * This file holds the pure rewrite; the fetch and sideload are in the importer
 * (Importer::import_image()), passed in as a callback.
 *
 * @package WP_Docs
 */

namespace WP_Docs\Transforms\Images;

use WP_Docs\Sources;

/**
 * Rewrite relative image sources.
 *
 * @param string   $html   HTML.
 * @param array    $base   Parsed source of the current file.
 * @param callable $import fn( array $target, string $alt ): ?array{url: string, id: int}. Returns the
 *                         local attachment for the image, or null to leave the tag unchanged.
 * @param array    $failed Receives the sources that could not be imported.
 * @return string
 */
function rewrite( string $html, array $base, callable $import, array &$failed = array() ): string {
	$tags = new \WP_HTML_Tag_Processor( $html );

	while ( $tags->next_tag( 'img' ) ) {
		$src = $tags->get_attribute( 'src' );
		if ( ! is_string( $src ) || '' === $src || preg_match( '#^([a-z][a-z0-9+.-]*:|//)#i', $src ) ) {
			// Absolute URLs (other hosts, data: URIs) are left alone.
			continue;
		}

		$target = Sources\resolve( $base, $src );
		if ( ! $target ) {
			$failed[] = $src;
			continue;
		}

		$alt        = $tags->get_attribute( 'alt' );
		$alt        = is_string( $alt ) ? $alt : '';
		$attachment = $import( $target, $alt );
		if ( ! $attachment || empty( $attachment['url'] ) ) {
			$failed[] = $src;
			continue;
		}

		$tags->set_attribute( 'src', $attachment['url'] );
		$tags->set_attribute( 'alt', $alt );
		if ( ! empty( $attachment['id'] ) ) {
			$tags->add_class( 'wp-image-' . (int) $attachment['id'] );
		}
	}

	return $tags->get_updated_html();
}
