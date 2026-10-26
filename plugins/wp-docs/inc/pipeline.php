<?php
/**
 * The content pipeline the importer subclass runs inside the stock importer's transform hooks.
 *
 * Order, for one page:
 *
 * 1. Raw file (the importer's HTTP response): front matter split robustly (front-matter.php), BOM
 *    dropped. The stock importer's own strip regex then finds nothing to strip.
 * 2. `wporg_markdown_before_transform`: `before_parse()`, GitHub alerts to placeholders (alerts.php).
 * 3. The stock importer takes the title from the first `# ` line and runs Jetpack's parser.
 * 4. `wporg_markdown_after_transform`: `after_parse()`, links (links.php), images (images.php), then
 *    the output mode: `classic` renders alerts as notice HTML and leaves paragraphs to `wpautop` at
 *    render time; `blocks` runs `wpautop( $html, false )` once and converts to blocks (blocks.php).
 * 5. The stock importer runs kses and saves.
 *
 * Pure PHP apart from `wpautop()` (core formatting.php, loadable without WordPress).
 *
 * @package WordPressdotorg\Docs
 */

namespace WordPressdotorg\Docs\Pipeline;

use WordPressdotorg\Docs\Transforms\Alerts;
use WordPressdotorg\Docs\Transforms\Blocks;
use WordPressdotorg\Docs\Transforms\Images;
use WordPressdotorg\Docs\Transforms\Links;

/**
 * Default options. Each transform can be switched off.
 *
 * @return array
 */
function defaults(): array {
	return array(
		'alerts'         => true,
		'links'          => true,
		'images'         => true,
		'mode'           => 'blocks',
		'classic_format' => 'github',
		'callout_block'  => 'wporg/notice',
		'type_map'       => Alerts\default_type_map(),
		'base'           => null,    // Parsed source of the current file.
		'lookup'         => null,    // fn( array $target ): ?string, page URL for a source.
		'import_image'   => null,    // fn( array $target, string $alt ): ?array{url, id}.
		'file_url'       => null,    // fn( array $target ): ?string, URL for other repository files.
	);
}

/**
 * Markdown stage.
 *
 * @param string $markdown Markdown (front matter already removed).
 * @param array  $options  Options.
 * @return string
 */
function before_parse( string $markdown, array $options ): string {
	$options = array_merge( defaults(), $options );
	if ( $options['alerts'] ) {
		$markdown = Alerts\markdown_to_placeholders( $markdown );
	}
	return $markdown;
}

/**
 * HTML stage.
 *
 * @param string $html    HTML from the Markdown parser.
 * @param array  $options Options.
 * @param array  $report  Receives `unresolved_links`, `failed_images`, `freeform`.
 * @return string
 */
function after_parse( string $html, array $options, array &$report = array() ): string {
	$options = array_merge( defaults(), $options );
	$report  = array_merge(
		array(
			'unresolved_links' => array(),
			'failed_images'    => array(),
			'freeform'         => array(),
		),
		$report
	);

	if ( $options['base'] ) {
		if ( $options['links'] && $options['lookup'] ) {
			$html = Links\rewrite( $html, $options['base'], $options['lookup'], $report['unresolved_links'], $options['file_url'] );
		}
		if ( $options['images'] && $options['import_image'] ) {
			$html = Images\rewrite( $html, $options['base'], $options['import_image'], $report['failed_images'] );
		}
	}

	if ( 'blocks' !== $options['mode'] ) {
		return Alerts\render_classic( $html, $options['classic_format'], $options['type_map'] );
	}

	$html   = wpautop( $html, false );
	$blocks = Blocks\convert(
		$html,
		array(
			'callout_block' => $options['callout_block'],
			'type_map'      => $options['type_map'],
		)
	);

	$report['freeform'] = Blocks\find_freeform( $blocks );
	return $blocks;
}

/**
 * The whole conversion for one file, mirroring the stock importer's steps (class-importer.php lines
 * 481-501) with this plugin's hooks in place. Used by the tests and `wp wporg-docs convert`; the
 * importer itself runs the real stock code with the hooks.
 *
 * @param string $raw     Raw file contents.
 * @param array  $options Options.
 * @param array  $report  Receives the report from after_parse() plus `front_matter` and `title`.
 * @return string Post content.
 */
function convert_file( string $raw, array $options = array(), array &$report = array() ): string {
	list( $front_matter, $markdown ) = \WordPressdotorg\Docs\Transforms\Front_Matter\split( $raw );

	// The stock importer's own strip (line 483) runs on the already-stripped body.
	$markdown = preg_replace( '#^---(.+)---#Us', '', $markdown );
	$markdown = trim( $markdown );
	$markdown = before_parse( $markdown, $options );

	$title = null;
	if ( preg_match( '/^#\s(.+)/', $markdown, $matches ) ) {
		$title    = $matches[1];
		$markdown = preg_replace( '/^#\s(.+)/', '', $markdown );
	}
	$markdown = trim( $markdown );

	$parser                      = new \WPCom_GHF_Markdown_Parser();
	$parser->preserve_shortcodes = false;
	$html                        = $parser->transform( $markdown );

	$html = after_parse( $html, $options, $report );

	$report['front_matter'] = $front_matter;
	$report['title']        = $title;

	return $html;
}
