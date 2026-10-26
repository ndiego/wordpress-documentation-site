<?php
/**
 * WP-CLI: `wp wporg-docs import|status|convert`.
 *
 * @package WordPressdotorg\Docs
 */

namespace WordPressdotorg\Docs;

use WP_CLI;
use WP_CLI\Utils;
use WP_Query;

defined( 'WPINC' ) || die();

/**
 * Import and inspect WordPress.org documentation.
 */
class CLI {

	/**
	 * Import manifests: manifest stage, then Markdown stage, then removals and redirects.
	 *
	 * ## OPTIONS
	 *
	 * [--manifest=<name>]
	 * : Import one manifest (file name with or without .json).
	 *
	 * [--all]
	 * : Import every manifest (the default when --manifest is not given).
	 *
	 * [--force]
	 * : Ignore stored ETags and re-import every page.
	 *
	 * [--local]
	 * : Use the offline manifests written by scripts/assemble.py (.spokes/manifest/ in the repository
	 * checkout at WPORG_DOCS_REPO_PATH) and read files from disk.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wporg-docs import --all
	 *     wp wporg-docs import --manifest=themes --force
	 *     wp wporg-docs import --all --local
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Named.
	 */
	public function import( $args, $assoc_args ) {
		$manifest = Utils\get_flag_value( $assoc_args, 'manifest', null );
		if ( $manifest && Utils\get_flag_value( $assoc_args, 'all', false ) ) {
			WP_CLI::error( 'Use either --manifest or --all.' );
		}
		$manifest = $manifest ? preg_replace( '/\.json$/', '', basename( (string) $manifest ) ) : null;

		$summary = Importer::instance()->run(
			array(
				'manifest' => $manifest,
				'force'    => (bool) Utils\get_flag_value( $assoc_args, 'force', false ),
				'local'    => Utils\get_flag_value( $assoc_args, 'local', false ) ? true : null,
			)
		);
		if ( is_wp_error( $summary ) ) {
			WP_CLI::error( $summary->get_error_message() );
		}

		WP_CLI::success(
			sprintf(
				'%d pages from %s: %d updated, %d unchanged, %d errors, %d restored, %d unpublished, %d warnings.',
				$summary['pages'],
				implode( ', ', $summary['manifests'] ),
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
			$section = (string) get_post_meta( $post->ID, '_wporg_docs_section', true );
			$section = '' === $section ? '(none)' : $section;
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
			$mode = (string) get_post_meta( $post->ID, '_wporg_docs_output_mode', true );
			if ( isset( $sections[ $section ][ $mode ] ) ) {
				++$sections[ $section ][ $mode ];
			}
			$flag = (string) get_post_meta( $post->ID, '_wporg_docs_removed', true );
			if ( '' !== $flag ) {
				++$sections[ $section ]['removed'];
				$removed[] = array(
					'post'    => $post->ID,
					'path'    => (string) get_post_meta( $post->ID, '_wporg_docs_path', true ),
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
					'Last import: %s (%s), %d pages, %d updated, %d errors, %d warnings.',
					$last['time'],
					implode( ', ', (array) $last['manifests'] ),
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
		WP_CLI::log( 'Manifests: ' . Manifests::location() );
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
