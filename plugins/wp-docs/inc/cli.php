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

	/**
	 * Write the current content of a page back to its Markdown file: show the diff, or commit it.
	 *
	 * Runs the same merge the editor's save runs (fetch the file from the branch, merge the stored
	 * blocks into it, verify the round trip). Without --commit nothing is written.
	 *
	 * ## OPTIONS
	 *
	 * <post>
	 * : Post ID or page path (write/create-a-post).
	 *
	 * [--commit]
	 * : Commit to the content branch as the user given by --user (default: the first administrator).
	 *
	 * [--user=<login>]
	 * : The WordPress user the commit is attributed to.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp-docs push write/create-a-post
	 *     wp wp-docs push 19 --commit --user=nick
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Named.
	 */
	public function push( $args, $assoc_args ) {
		$post = is_numeric( $args[0] ) ? get_post( (int) $args[0] ) : get_post( (int) Permalinks::post_for_path( trim( $args[0], '/' ) ) );
		if ( ! $post || POST_TYPE !== $post->post_type ) {
			WP_CLI::error( "No docs page: {$args[0]}" );
		}
		$login = (string) Utils\get_flag_value( $assoc_args, 'user', '' );
		$user  = '' !== $login ? get_user_by( 'login', $login ) : ( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0] ?? null );
		if ( ! $user ) {
			WP_CLI::error( 'No such user.' );
		}
		wp_set_current_user( $user->ID );

		$prepared               = new \stdClass();
		$prepared->ID           = $post->ID;
		$prepared->post_title   = $post->post_title;
		$prepared->post_excerpt = $post->post_excerpt;
		$prepared->post_content = $post->post_content;

		$built = Sync::build( $post, $prepared, array( 'title' => false, 'description' => false, 'content' => true, 'structure' => array() ) );
		if ( is_wp_error( $built ) ) {
			WP_CLI::error( $built->get_error_message() );
		}
		$stats = $built['stats'];
		WP_CLI::log( sprintf( '%s: %d chunks unchanged, %d rewritten, %d blocks added, %d chunks removed, %d images to add.', $built['path'], $stats['verbatim'], $stats['rewritten'], $stats['added'], $stats['removed'], count( $built['images'] ) ) );
		foreach ( $stats['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}
		if ( $built['before'] === $built['raw'] ) {
			WP_CLI::success( 'The file would not change.' );
			return;
		}
		$this->print_diff( $built['before'], $built['raw'], $built['path'] );

		if ( ! Utils\get_flag_value( $assoc_args, 'commit', false ) ) {
			WP_CLI::log( 'Dry run; add --commit to push.' );
			return;
		}
		$message = sprintf( "docs(%s): %s\n\nPushed with WP-CLI by %s.", $built['key'], $built['title'], $user->user_login );
		$author  = array(
			'name'  => $user->display_name,
			'email' => $user->user_email,
		);
		$files   = array_merge( array( $built['path'] => $built['raw'] ), $built['images'] );
		$result  = $built['github']->push( Sync::branch(), $files, array( $built['path'] => $built['blob_sha'] ), $message, $author );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		Importer::instance()->refresh_agent_markdown( $post->ID, $built['raw'] );
		delete_post_meta( $post->ID, 'wporg_markdown_etag' );
		WP_CLI::success( "Committed {$result['commit']} on " . Sync::branch() . '.' );
	}

	/**
	 * A unified diff of two texts (with the system diff when it is there).
	 *
	 * @param string $before Before.
	 * @param string $after  After.
	 * @param string $path   Label.
	 */
	protected function print_diff( $before, $after, $path ) {
		$dir = get_temp_dir();
		$a   = tempnam( $dir, 'wpd' );
		$b   = tempnam( $dir, 'wpd' );
		file_put_contents( $a, $before ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $b, $after ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$diff = shell_exec( 'diff -u --label a/' . escapeshellarg( $path ) . ' --label b/' . escapeshellarg( $path ) . ' ' . escapeshellarg( $a ) . ' ' . escapeshellarg( $b ) . ' 2>/dev/null' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		wp_delete_file( $a );
		wp_delete_file( $b );
		WP_CLI::log( is_string( $diff ) && '' !== $diff ? $diff : $after );
	}
}
