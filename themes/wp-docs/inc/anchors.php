<?php
/**
 * Heading anchors and the table of contents.
 *
 * The plugin writes GitHub's heading ids into the content at import (plugins/wp-docs/inc/transforms/anchors.php).
 * The theme keeps any id it finds and computes the same id for a heading that has none (content imported
 * before that transform, or edited by hand), with the plugin's slug function when it is loaded and an
 * identical copy otherwise. On H2 to H4 it appends a link to the heading that shows on hover.
 *
 * The table of contents reads the same ids from the raw post content, so it never disagrees with the page.
 *
 * @package WP_Docs_Theme
 */

namespace WP_Docs_Theme;

defined( 'ABSPATH' ) || die();

/**
 * GitHub's anchor for a heading text (github-slugger): lowercase, keep letters, marks, numbers, connector
 * punctuation, spaces and hyphens, spaces become hyphens. Repeats are handled by add_heading_ids().
 *
 * @param string $text Plain heading text.
 * @return string
 */
function heading_slug( $text ) {
	if ( function_exists( '\WP_Docs\Transforms\Anchors\slug' ) ) {
		return \WP_Docs\Transforms\Anchors\slug( (string) $text );
	}
	if ( function_exists( 'wp_docs_heading_anchor' ) ) {
		return (string) wp_docs_heading_anchor( (string) $text );
	}
	$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	$text = preg_replace( '/[^\p{L}\p{M}\p{N}\p{Pc} \-]/u', '', $text );
	return str_replace( ' ', '-', (string) $text );
}

/**
 * Plain text of a heading's inner HTML.
 *
 * @param string $html Inner HTML.
 * @return string
 */
function heading_text( $html ) {
	$text = preg_replace( '#<[^>]*>#', '', (string) $html );
	return trim( html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/**
 * Every heading of a post, in order, with the id it has or gets: [ [ level, text, id ] ].
 * Ids already in the content are reserved first, then headings without one are numbered like GitHub.
 *
 * @param \WP_Post $post Post.
 * @return array[]
 */
function post_headings( $post ) {
	static $cache = array();
	if ( ! $post ) {
		return array();
	}
	if ( isset( $cache[ $post->ID ] ) ) {
		return $cache[ $post->ID ];
	}
	$html = (string) $post->post_content;
	$out  = array();
	if ( ! preg_match_all( '#<h([1-6])(\s[^>]*)?>(.*?)</h\1>#is', $html, $matches, PREG_SET_ORDER ) ) {
		$cache[ $post->ID ] = $out;
		return $out;
	}
	$used = array();
	foreach ( $matches as $m ) {
		if ( preg_match( '#\sid="([^"]*)"#i', $m[2] ?? '', $id ) ) {
			$used[ html_entity_decode( $id[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ] = 0;
		}
	}
	foreach ( $matches as $m ) {
		$text = heading_text( $m[3] );
		if ( preg_match( '#\sid="([^"]*)"#i', $m[2] ?? '', $id ) ) {
			$anchor = html_entity_decode( $id[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		} else {
			$base   = heading_slug( $text );
			$anchor = $base;
			if ( '' !== $base && isset( $used[ $base ] ) ) {
				do {
					$anchor = $base . '-' . ( ++$used[ $base ] );
				} while ( isset( $used[ $anchor ] ) );
			}
			if ( '' !== $anchor ) {
				$used[ $anchor ] = $used[ $anchor ] ?? 0;
			}
		}
		$out[] = array(
			'level' => (int) $m[1],
			'text'  => $text,
			'id'    => $anchor,
		);
	}
	$cache[ $post->ID ] = $out;
	return $out;
}

/**
 * Headings for the table of contents (H2 and H3 with an id).
 *
 * @param \WP_Post|null $post Post.
 * @return array[]
 */
function toc_items( $post = null ) {
	$post = current_post( $post );
	if ( ! $post ) {
		return array();
	}
	return array_values(
		array_filter(
			post_headings( $post ),
			static function ( $h ) {
				return in_array( $h['level'], array( 2, 3 ), true ) && '' !== $h['id'] && '' !== $h['text'];
			}
		)
	);
}

/**
 * Track when the main post content is rendering, so only its headings are changed.
 */
final class Heading_Anchors {
	/**
	 * Post whose content is rendering, or null.
	 *
	 * @var \WP_Post|null
	 */
	private static $post = null;

	/**
	 * Index of the next heading in post_headings().
	 *
	 * @var int
	 */
	private static $cursor = 0;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'render_block_data', array( __CLASS__, 'start' ), 10, 1 );
		add_filter( 'render_block_core/post-content', array( __CLASS__, 'stop' ), 1, 1 );
		add_filter( 'render_block_core/heading', array( __CLASS__, 'heading' ), 10, 2 );
	}

	/**
	 * Before core/post-content renders on a docs page, remember the post.
	 *
	 * @param array $block Parsed block.
	 * @return array
	 */
	public static function start( $block ) {
		if ( 'core/post-content' === ( $block['blockName'] ?? '' ) && ! is_admin() ) {
			$post = current_post( get_the_ID() );
			if ( $post ) {
				self::$post   = $post;
				self::$cursor = 0;
			}
		}
		return $block;
	}

	/**
	 * After core/post-content renders.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function stop( $content ) {
		self::$post = null;
		return $content;
	}

	/**
	 * Give a heading its id and, on H2 to H4, the anchor link.
	 *
	 * @param string $content Rendered heading.
	 * @param array  $block   Block.
	 * @return string
	 */
	public static function heading( $content, $block ) {
		if ( ! self::$post || '' === trim( $content ) ) {
			return $content;
		}
		if ( ! preg_match( '#<h([1-6])\b[^>]*>(.*)</h\1>#is', $content, $m ) ) {
			return $content;
		}
		$level = (int) $m[1];
		$text  = heading_text( $m[2] );

		$processor = new \WP_HTML_Tag_Processor( $content );
		if ( ! $processor->next_tag( 'h' . $level ) ) {
			return $content;
		}
		$id = (string) $processor->get_attribute( 'id' );
		if ( '' === $id ) {
			$id = self::lookup( $text );
			if ( '' === $id ) {
				return $content;
			}
			$processor->set_attribute( 'id', $id );
		} else {
			self::advance( $text );
		}
		$processor->add_class( 'wp-docs-heading' );
		$content = $processor->get_updated_html();

		if ( $level >= 2 && $level <= 4 ) {
			$link    = sprintf(
				'<a class="wp-docs-heading__anchor" href="#%1$s" aria-label="%2$s">%3$s</a>',
				esc_attr( $id ),
				/* translators: %s: heading text. */
				esc_attr( sprintf( __( 'Link to section: %s', 'wp-docs' ), $text ) ),
				icon( 'link', 18 )
			);
			$close   = '</h' . $level . '>';
			$pos     = strripos( $content, $close );
			$content = false === $pos ? $content : substr_replace( $content, $link . $close, $pos, strlen( $close ) );
		}
		return $content;
	}

	/**
	 * The id of the next heading with this text, from post_headings().
	 *
	 * @param string $text Heading text.
	 * @return string
	 */
	private static function lookup( $text ) {
		$headings = post_headings( self::$post );
		for ( $i = self::$cursor, $n = count( $headings ); $i < $n; $i++ ) {
			if ( $headings[ $i ]['text'] === $text ) {
				self::$cursor = $i + 1;
				return $headings[ $i ]['id'];
			}
		}
		return heading_slug( $text );
	}

	/**
	 * Move the cursor past a heading that already had an id.
	 *
	 * @param string $text Heading text.
	 */
	private static function advance( $text ) {
		self::lookup( $text );
	}
}
