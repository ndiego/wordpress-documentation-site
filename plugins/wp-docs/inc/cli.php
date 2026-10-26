<?php
/**
 * WP-CLI: `wp wp-docs import|status|convert`.
 *
 * @package WP_Docs
 */

namespace WP_Docs;

use WP_CLI;
use WP_CLI\Utils;
use WP_Query;

defined( 'WPINC' ) || die();

/**
 * Import and inspect WordPress.org documentation.
 */
class CLI {

	/**
	 * Import the manifest: manifest stage, then Markdown stage, then removals and redirects.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Ignore stored ETags and re-import every page.
	 *
	 * [--manifest=<url-or-path>]
	 * : Import from this manifest instead of WP_DOCS_MANIFEST, for this run only. When it is a file, its
	 * directory is the content checkout local files are read from (unless WP_DOCS_CONTENT_PATH is set).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp-docs import
	 *     wp wp-docs import --force
	 *     wp wp-docs import --manifest=/var/www/html/wp-docs-tools/sample-content/manifest.json
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Named.
	 */
	public function import( $args, $assoc_args ) {
		$summary = Importer::instance()->run(
			array(
				'force'    => (bool) Utils\get_flag_value( $assoc_args, 'force', false ),
				'manifest' => (string) Utils\get_flag_value( $assoc_args, 'manifest', '' ),
			)
		);
		if ( is_wp_error( $summary ) ) {
			WP_CLI::error( $summary->get_error_message() );
		}

		WP_CLI::success(
			sprintf(
				'%d pages from %s: %d updated, %d unchanged, %d errors, %d restored, %d unpublished, %d warnings.',
				$summary['pages'],
				$summary['manifest'],
				$summary['updated'],
				$summary['unchanged'],
				$summary['errors'],
				$summary['restored'],
				count( $summary['removed'] ),
				$summary['warnings']
			)
		);
	}

	/**
	 * Show page counts per section, pages removed from the manifest, and the last import.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Named.
	 */
	public function status( $args, $assoc_args ) {
		$query = new WP_Query(
			array(
				'post_type'      => POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		$sections = array();
		$removed  = array();
		foreach ( $query->posts as $post ) {
			$section = (string) get_post_meta( $post->ID, '_wp_docs_section', true );
			$section = '' === $section ? '(home)' : $section;
			if ( ! isset( $sections[ $section ] ) ) {
				$sections[ $section ] = array(
					'section'   => $section,
					'published' => 0,
					'draft'     => 0,
					'removed'   => 0,
					'blocks'    => 0,
					'classic'   => 0,
				);
			}
			if ( 'publish' === $post->post_status ) {
				++$sections[ $section ]['published'];
			} else {
				++$sections[ $section ]['draft'];
			}
			$mode = (string) get_post_meta( $post->ID, '_wp_docs_output_mode', true );
			if ( isset( $sections[ $section ][ $mode ] ) ) {
				++$sections[ $section ][ $mode ];
			}
			$flag = (string) get_post_meta( $post->ID, '_wp_docs_removed', true );
			if ( '' !== $flag ) {
				++$sections[ $section ]['removed'];
				$removed[] = array(
					'post'    => $post->ID,
					'path'    => (string) get_post_meta( $post->ID, '_wp_docs_path', true ),
					'removed' => $flag,
				);
			}
		}
		ksort( $sections );

		$format = Utils\get_flag_value( $assoc_args, 'format', 'table' );
		Utils\format_items( $format, array_values( $sections ), array( 'section', 'published', 'draft', 'removed', 'blocks', 'classic' ) );

		if ( $removed ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Removed from the manifest (unpublished, kept as drafts):' );
			Utils\format_items( $format, $removed, array( 'post', 'path', 'removed' ) );
		}

		$last = get_option( Importer::LAST_IMPORT_OPTION );
		WP_CLI::log( '' );
		if ( is_array( $last ) ) {
			WP_CLI::log(
				sprintf(
					'Last import: %s from %s, %d pages, %d updated, %d errors, %d warnings.',
					$last['time'],
					(string) ( $last['manifest'] ?? '' ),
					$last['pages'],
					$last['updated'],
					$last['errors'],
					$last['warnings']
				)
			);
		} else {
			WP_CLI::log( 'No import has run yet.' );
		}
		$next = wp_next_scheduled( Importer::CRON_HOOK );
		WP_CLI::log( 'Next scheduled import: ' . ( $next ? gmdate( 'c', $next ) : 'none' ) );
		WP_CLI::log( 'Manifest: ' . manifest_location() );
		WP_CLI::log( 'Content checkout: ' . Local_Sources::content_path() . ( Local_Sources::prefer_local() ? ' (preferred over GitHub: WP_DOCS_PREFER_LOCAL)' : ' (used for repository-relative sources only)' ) );
		WP_CLI::log( 'Output mode: ' . output_mode() );
	}

	/**
	 * Convert one Markdown file with the import pipeline and print the result (no images, no links).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Markdown file on disk.
	 *
	 * [--mode=<mode>]
	 * : blocks or classic. Defaults to the configured output mode.
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Named.
	 */
	public function convert( $args, $assoc_args ) {
		if ( ! is_readable( $args[0] ) ) {
			WP_CLI::error( "Cannot read {$args[0]}." );
		}
		if ( ! class_exists( 'WPCom_GHF_Markdown_Parser' ) && defined( 'JETPACK__PLUGIN_DIR' ) ) {
			include JETPACK__PLUGIN_DIR . '/_inc/lib/markdown.php';
		}
		if ( ! class_exists( 'WPCom_GHF_Markdown_Parser' ) ) {
			WP_CLI::error( 'Jetpack Markdown is missing.' );
		}
		$report = array();
		$html   = Pipeline\convert_file(
			(string) file_get_contents( $args[0] ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			array( 'mode' => Utils\get_flag_value( $assoc_args, 'mode', output_mode() ) ),
			$report
		);
		WP_CLI::log( $html );
		if ( $report['freeform'] ) {
			WP_CLI::warning( 'Content outside blocks: ' . implode( ' | ', $report['freeform'] ) );
		}
	}
}
