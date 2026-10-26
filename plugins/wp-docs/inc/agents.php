<?php
/**
 * Endpoints for agents and for the theme's search palette (CONTRACT.md, "Endpoints the plugin serves").
 *
 * - `/docs/<path>.md` (and `/docs/index.md` for the home), `?output_format=md` on a page URL, and
 *   `Accept: text/markdown` on a page URL: the page's `_wp_docs_markdown` as `text/markdown`.
 * - `<link rel="alternate" type="text/markdown">` in the head of every docs page.
 * - `/llms.txt`, `/llms-full.txt` and `/docs/index.json`.
 * - `GET /wp-json/wp-docs/v1/search-index`.
 *
 * Every generated document is cached until the next import (see cached()).
 *
 * @package WP_Docs
 */

namespace WP_Docs;

use WP;
use WP_REST_Response;

defined( 'WPINC' ) || die();

/**
 * Agent endpoints.
 */
class Agents {

	const QUERY_VAR_MD       = 'wp_docs_md';
	const QUERY_VAR_ENDPOINT = 'wp_docs_endpoint';

	/**
	 * Bump when a rule changes, so the rules are flushed once.
	 */
	const RULES_VERSION = '1';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'parse_request', array( __CLASS__, 'parse_request' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'template_redirect' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'head_link' ), 5 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'no_canonical_redirect' ), 10, 2 );
	}

	/**
	 * Rewrite rules, ahead of the post type's own.
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?' . self::QUERY_VAR_ENDPOINT . '=llms', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?' . self::QUERY_VAR_ENDPOINT . '=llms-full', 'top' );
		add_rewrite_rule( '^docs/index\.json$', 'index.php?' . self::QUERY_VAR_ENDPOINT . '=index-json', 'top' );
		add_rewrite_rule( '^docs/(.+?)\.md$', 'index.php?' . self::QUERY_VAR_MD . '=$matches[1]', 'top' );

		// Flush once when the rules are new (activation also flushes).
		if ( get_option( 'wp_docs_rules_version' ) !== self::RULES_VERSION && get_option( 'permalink_structure' ) ) {
			update_option( 'wp_docs_rules_version', self::RULES_VERSION );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Register the query vars.
	 *
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR_MD;
		$vars[] = self::QUERY_VAR_ENDPOINT;
		return $vars;
	}

	/**
	 * Serve the endpoints as soon as the request is parsed (no main query needed).
	 *
	 * @param WP $wp Request.
	 */
	public static function parse_request( $wp ) {
		if ( ! empty( $wp->query_vars[ self::QUERY_VAR_ENDPOINT ] ) ) {
			switch ( $wp->query_vars[ self::QUERY_VAR_ENDPOINT ] ) {
				case 'llms':
					self::send( 'text/plain; charset=utf-8', self::llms_txt() );
					break;
				case 'llms-full':
					self::send( 'text/plain; charset=utf-8', self::llms_full_txt() );
					break;
				case 'index-json':
					self::send( 'application/json; charset=utf-8', (string) wp_json_encode( self::index_json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
					break;
			}
			return;
		}

		if ( isset( $wp->query_vars[ self::QUERY_VAR_MD ] ) ) {
			$path = trim( (string) $wp->query_vars[ self::QUERY_VAR_MD ], '/' );
			$id   = Permalinks::post_for_path( 'index' === $path ? '' : $path );
			if ( ! $id && 'index' === $path ) {
				$id = Permalinks::post_for_path( 'index' );
			}
			if ( $id ) {
				self::send_markdown( $id );
			}
			// Unknown page: let WordPress answer with its 404 (and the redirect lookup).
			unset( $wp->query_vars[ self::QUERY_VAR_MD ] );
			$wp->query_vars['error'] = '404';
		}
	}

	/**
	 * `?output_format=md` and `Accept: text/markdown` on a page URL; `Vary: Accept` on every docs page.
	 */
	public static function template_redirect() {
		$id = self::current_page_id();
		if ( ! $id ) {
			return;
		}
		$format = isset( $_GET['output_format'] ) ? sanitize_key( wp_unslash( $_GET['output_format'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $format, array( 'md', 'markdown' ), true ) || self::accepts_markdown() ) {
			self::send_markdown( $id );
		}
		header( 'Vary: Accept', false );
	}

	/**
	 * The docs page the current request shows: the singular page, or the home on /docs/ and the front page.
	 *
	 * @return int
	 */
	public static function current_page_id() {
		if ( is_singular( POST_TYPE ) ) {
			$id = (int) get_queried_object_id();
			return 'publish' === get_post_status( $id ) ? $id : 0;
		}
		if ( is_post_type_archive( POST_TYPE ) || ( is_front_page() && ! is_singular() ) ) {
			return Permalinks::post_for_path( '' );
		}
		return 0;
	}

	/**
	 * Whether the request asks for Markdown in its Accept header (and prefers it to HTML).
	 *
	 * @return bool
	 */
	public static function accepts_markdown() {
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( false === strpos( $accept, 'text/markdown' ) ) {
			return false;
		}
		$q = static function ( $type ) use ( $accept ) {
			foreach ( explode( ',', $accept ) as $part ) {
				$bits = array_map( 'trim', explode( ';', $part ) );
				if ( $bits[0] === $type ) {
					foreach ( array_slice( $bits, 1 ) as $param ) {
						if ( 0 === strpos( $param, 'q=' ) ) {
							return (float) substr( $param, 2 );
						}
					}
					return 1.0;
				}
			}
			return -1.0;
		};
		return $q( 'text/markdown' ) >= $q( 'text/html' ) && $q( 'text/markdown' ) > 0;
	}

	/**
	 * `<link rel="alternate" type="text/markdown">` on docs pages.
	 */
	public static function head_link() {
		$id = self::current_page_id();
		if ( $id ) {
			printf( '<link rel="alternate" type="text/markdown" title="Markdown" href="%s" />' . "\n", esc_url( wp_docs_markdown_url( $id ) ) );
		}
	}

	/**
	 * Keep WordPress from "correcting" the endpoint URLs.
	 *
	 * @param string|false $redirect  Redirect URL.
	 * @param string       $requested Requested URL.
	 * @return string|false
	 */
	public static function no_canonical_redirect( $redirect, $requested ) {
		$path = (string) wp_parse_url( $requested, PHP_URL_PATH );
		if ( preg_match( '#(/llms(-full)?\.txt|/docs/index\.json|/docs/.+\.md)$#', $path ) ) {
			return false;
		}
		return $redirect;
	}

	/**
	 * Send the Markdown of a page and stop.
	 *
	 * @param int $id Post ID.
	 */
	public static function send_markdown( $id ) {
		$markdown = (string) get_post_meta( $id, '_wp_docs_markdown', true );
		if ( '' === $markdown ) {
			// Imported before the Markdown was stored: a minimal document from the post.
			$markdown = "# " . get_post_field( 'post_title', $id, 'raw' ) . "\n\n" . wp_strip_all_tags( (string) get_post_field( 'post_content', $id, 'raw' ) ) . "\n";
		}
		header( 'Link: <' . esc_url_raw( (string) get_permalink( $id ) ) . '>; rel="canonical"' );
		self::send( 'text/markdown; charset=utf-8', $markdown );
	}

	/**
	 * Send a response body and stop.
	 *
	 * @param string $type Content type.
	 * @param string $body Body.
	 */
	protected static function send( $type, $body ) {
		status_header( 200 );
		header( 'Content-Type: ' . $type );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: public, max-age=300' );
		header( 'Vary: Accept', false );
		header( 'Access-Control-Allow-Origin: *' );
		if ( 'HEAD' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text, Markdown or JSON.
		}
		exit;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Documents.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Pages in sidebar order with the home first.
	 *
	 * @return array[] Tree nodes.
	 */
	protected static function pages_in_order() {
		$tree  = wp_docs_get_tree();
		$pages = array();
		if ( $tree['home'] && isset( $tree['nodes'][ $tree['home'] ] ) ) {
			$pages[] = $tree['nodes'][ $tree['home'] ];
		}
		foreach ( $tree['order'] as $id ) {
			$pages[] = $tree['nodes'][ $id ];
		}
		return $pages;
	}

	/**
	 * Plain text for a Markdown link label or description.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	protected static function plain( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * `/llms.txt`.
	 *
	 * @return string
	 */
	public static function llms_txt() {
		return cached(
			'llms',
			static function () {
				$tree  = wp_docs_get_tree();
				$title = 'WordPress Documentation';
				$lines = array(
					'# ' . $title,
					'',
					'> ' . self::summary(),
					'',
				);

				$line = static function ( array $node ) {
					$label       = str_replace( array( '[', ']' ), array( '\[', '\]' ), self::plain( $node['title'] ) );
					$description = self::plain( $node['description'] );
					return '- [' . $label . '](' . $node['md_url'] . ')' . ( '' !== $description ? ': ' . $description : '' );
				};

				if ( $tree['home'] && isset( $tree['nodes'][ $tree['home'] ] ) ) {
					$lines[] = $line( $tree['nodes'][ $tree['home'] ] );
					$lines[] = '';
				}

				// One group per section: the section landing, then every page under it in sidebar order.
				$section = 0;
				foreach ( $tree['order'] as $id ) {
					$node = $tree['nodes'][ $id ];
					if ( 0 === $node['depth'] ) {
						if ( $section ) {
							$lines[] = '';
						}
						$section = $id;
						$lines[] = '## ' . self::plain( $node['title'] );
						$lines[] = '';
					}
					$lines[] = $line( $node );
				}

				return implode( "\n", $lines ) . "\n";
			}
		);
	}

	/**
	 * The summary paragraph of llms.txt.
	 *
	 * @return string
	 */
	public static function summary() {
		/**
		 * Filters the summary paragraph of llms.txt.
		 *
		 * @param string $summary Summary.
		 */
		return (string) apply_filters(
			'wp_docs_llms_summary',
			'The WordPress user documentation explains how to install WordPress, write and publish content, design a site with blocks and themes, and manage it; it is written in Markdown and maintained on GitHub. Every page is available as Markdown at its URL plus `.md` (for example /docs/write/create-a-post.md for /docs/write/create-a-post/), and each one is listed below.'
		);
	}

	/**
	 * `/llms-full.txt`: every page's Markdown in sidebar order, separated by a line of `---`.
	 *
	 * @return string
	 */
	public static function llms_full_txt() {
		return cached(
			'llms_full',
			static function () {
				$parts = array();
				foreach ( self::pages_in_order() as $node ) {
					$markdown = trim( (string) get_post_meta( $node['id'], '_wp_docs_markdown', true ) );
					if ( '' !== $markdown ) {
						$parts[] = $markdown;
					}
				}
				return implode( "\n\n---\n\n", $parts ) . "\n";
			}
		);
	}

	/**
	 * `/docs/index.json`.
	 *
	 * @return array
	 */
	public static function index_json() {
		return cached(
			'index_json',
			static function () {
				$tree  = wp_docs_get_tree();
				$pages = array();
				foreach ( self::pages_in_order() as $node ) {
					$meta    = wp_docs_get_page_meta( $node['id'] );
					$parent  = $node['parent'] && isset( $tree['nodes'][ $node['parent'] ] ) ? $tree['nodes'][ $node['parent'] ]['doc_id'] : null;
					$pages[] = array(
						'id'          => $node['doc_id'],
						'title'       => self::plain( $node['title'] ),
						'path'        => $node['path'],
						'url'         => $node['url'],
						'md_url'      => $node['md_url'],
						'section'     => $node['section'],
						'type'        => $node['type'],
						'status'      => $node['status'],
						'description' => $node['description'],
						'parent'      => $parent,
						'order'       => $node['order'],
						'source_url'  => $meta['source_url'] ?? '',
						'edit_url'    => $meta['edit_url'] ?? '',
						'updated'     => $meta['updated'] ?? '',
						'imported'    => $meta['imported'] ?? '',
					);
				}
				$stamp = import_stamp();
				return array(
					'generated' => '' !== $stamp ? $stamp : gmdate( 'c' ),
					'pages'     => $pages,
				);
			}
		);
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Search index (REST).
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Register `GET /wp-json/wp-docs/v1/search-index`.
	 */
	public static function register_routes() {
		register_rest_route(
			'wp-docs/v1',
			'/search-index',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'serve_search_index' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Serve the search index, cacheable for five minutes.
	 *
	 * @return WP_REST_Response
	 */
	public static function serve_search_index() {
		$response = new WP_REST_Response( self::search_index() );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/**
	 * The search index: one record per page except the home, in sidebar order.
	 *
	 * Record: title, path, url, section (slug), section_title, type, status, breadcrumb (titles of the
	 * ancestors from the section landing down to the parent; empty for a section landing), description,
	 * headings (H2 and H3: text and anchor).
	 *
	 * @return array[]
	 */
	public static function search_index() {
		return cached(
			'search_index',
			static function () {
				global $wpdb;
				$tree = wp_docs_get_tree();
				$ids  = $tree['order'];

				// Content of every page in one query, for the headings.
				$content = array();
				foreach ( array_chunk( $ids, 200 ) as $chunk ) {
					$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
					$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID IN ($placeholders)", $chunk ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					foreach ( $rows as $row ) {
						$content[ (int) $row->ID ] = (string) $row->post_content;
					}
				}

				$index = array();
				foreach ( $ids as $id ) {
					$node   = $tree['nodes'][ $id ];
					$crumbs = array();
					$parent = $node['parent'];
					while ( $parent && isset( $tree['nodes'][ $parent ] ) ) {
						array_unshift( $crumbs, self::plain( $tree['nodes'][ $parent ]['title'] ) );
						$parent = $tree['nodes'][ $parent ]['parent'];
					}
					$section_id = $id;
					while ( $tree['nodes'][ $section_id ]['parent'] && isset( $tree['nodes'][ $tree['nodes'][ $section_id ]['parent'] ] ) ) {
						$section_id = $tree['nodes'][ $section_id ]['parent'];
					}
					$headings = array();
					foreach ( Transforms\Anchors\headings( $content[ $id ] ?? '' ) as $heading ) {
						$headings[] = array(
							'text'   => $heading['text'],
							'anchor' => $heading['anchor'],
						);
					}
					$index[] = array(
						'title'         => self::plain( $node['title'] ),
						'path'          => $node['path'],
						'url'           => $node['url'],
						'section'       => $node['section'],
						'section_title' => self::plain( $tree['nodes'][ $section_id ]['title'] ),
						'type'          => $node['type'],
						'status'        => $node['status'],
						'breadcrumb'    => $crumbs,
						'description'   => $node['description'],
						'headings'      => $headings,
					);
				}
				return $index;
			}
		);
	}
}
