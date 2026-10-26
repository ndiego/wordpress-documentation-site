<?php
/**
 * Fixture runner: tests/fixtures/<name>.md -> <name>.blocks.html (blocks mode) and <name>.classic.html
 * (classic mode), through the same pipeline the importer runs (Jetpack's parser, core's wpautop, the
 * plugin's transforms). No WordPress runtime, no database.
 *
 *     php tests/run-fixtures.php            compare against the expected files
 *     php tests/run-fixtures.php --update   rewrite the expected files (review the diff!)
 *
 * Besides the comparison, every blocks-mode output is checked for content outside blocks with both the
 * plugin's scanner and core's WP_Block_Parser.
 *
 * @package WP_Docs
 */

// phpcs:disable

require __DIR__ . '/bootstrap.php';

use WP_Docs\Manifest;
use WP_Docs\Pipeline;
use WP_Docs\Sources;
use WP_Docs\Transforms\Blocks;

$update = in_array( '--update', $argv, true );

// A small manifest the fixture links resolve against.
$manifests  = array(
	'get-started'  => array(
		'get-started'         => array( 'title' => 'Get started', 'slug' => 'get-started', 'parent' => null, 'order' => 10, 'markdown_source' => 'https://github.com/ndiego/wordpress-documentation/blob/trunk/content/get-started/README.md', 'id' => 'get-started', 'section' => 'get-started' ),
		'get-started/install' => array( 'title' => 'Install', 'slug' => 'install', 'parent' => 'get-started', 'order' => 20, 'markdown_source' => 'https://github.com/ndiego/wordpress-documentation/blob/trunk/content/get-started/install.md', 'id' => 'install', 'section' => 'get-started' ),
		'welcome'             => array( 'title' => 'Welcome', 'slug' => 'welcome', 'parent' => null, 'order' => 0, 'markdown_source' => 'https://github.com/ndiego/wordpress-documentation/blob/trunk/content/README.md', 'id' => 'welcome', 'section' => 'get-started' ),
	),
	'blocks'       => array(
		'blocks'       => array( 'title' => 'Blocks', 'slug' => 'blocks', 'parent' => null, 'order' => 10, 'markdown_source' => 'https://github.com/ndiego/wordpress-documentation/blob/trunk/content/blocks/README.md', 'id' => 'blocks', 'section' => 'blocks' ),
		'blocks/image' => array( 'title' => 'Image', 'slug' => 'image', 'parent' => 'blocks', 'order' => 10, 'markdown_source' => 'https://github.com/ndiego/wordpress-documentation/blob/trunk/content/blocks/image.md', 'id' => 'core-image', 'section' => 'blocks' ),
	),
	'block-editor' => array(
		'block-editor'                 => array( 'title' => 'Block editor', 'slug' => 'block-editor', 'parent' => null, 'order' => 10, 'markdown_source' => 'https://github.com/ndiego/wordpress-documentation/blob/trunk/content/block-editor/README.md', 'id' => 'block-editor', 'section' => 'block-editor' ),
		'block-editor/getting-started' => array( 'title' => 'Getting started', 'slug' => 'getting-started', 'parent' => 'block-editor', 'order' => 10, 'markdown_source' => 'https://github.com/WordPress/gutenberg/blob/trunk/docs/getting-started/README.md', 'id' => 'be-getting-started', 'section' => 'block-editor' ),
	),
);
$aliases    = array( 'ndiego/wordpress-documentation' => array( 'prefix' => '', 'ref' => 'trunk' ) );
$normalized = Manifest\normalize( $manifests );
$map        = Manifest\source_map( $normalized['entries'], $aliases );
$lookup     = Manifest\lookup_callback( $normalized['entries'], $map, $aliases, 'https://wordpress.org/docs/' );

$failures = 0;
$checked  = 0;
foreach ( glob( __DIR__ . '/fixtures/*.md' ) as $md ) {
	$name = basename( $md, '.md' );
	$base = Sources\parse( "https://github.com/ndiego/wordpress-documentation/blob/trunk/content/get-started/{$name}.md" );

	foreach ( array( 'blocks', 'classic' ) as $mode ) {
		$report  = array();
		$options = array(
			'mode'         => $mode,
			'base'         => $base,
			'lookup'       => $lookup,
			// Deterministic fake sideload: attachment id from the path.
			'import_image' => static fn( $target, $alt ) => array(
				'url' => 'https://wordpress.org/docs/files/' . basename( $target['path'] ),
				'id'  => 1000 + ( crc32( $target['path'] ) % 1000 ),
			),
		);
		$actual   = Pipeline\convert_file( file_get_contents( $md ), $options, $report ) . "\n";
		$expected = __DIR__ . "/fixtures/{$name}.{$mode}.html";

		++$checked;
		$problems = array();
		if ( 'blocks' === $mode ) {
			if ( $report['freeform'] ) {
				$problems[] = 'content outside blocks (scanner): ' . json_encode( $report['freeform'] );
			}
			$parser = new WP_Block_Parser();
			foreach ( $parser->parse( $actual ) as $block ) {
				if ( null === $block['blockName'] && '' !== trim( $block['innerHTML'] ) ) {
					$problems[] = 'content outside blocks (WP_Block_Parser): ' . trim( $block['innerHTML'] );
				}
			}
		}

		if ( $update ) {
			file_put_contents( $expected, $actual );
		} elseif ( ! file_exists( $expected ) ) {
			$problems[] = "missing {$name}.{$mode}.html (run with --update)";
		} elseif ( file_get_contents( $expected ) !== $actual ) {
			$tmp = sys_get_temp_dir() . "/wp-docs-{$name}.{$mode}.html";
			file_put_contents( $tmp, $actual );
			$problems[] = "differs from expected:\n" . shell_exec( 'diff -u ' . escapeshellarg( $expected ) . ' ' . escapeshellarg( $tmp ) );
		}

		$unresolved = $report['unresolved_links'] ? ' unresolved links: ' . implode( ', ', $report['unresolved_links'] ) : '';
		if ( $problems ) {
			++$failures;
			echo "FAIL {$name} [{$mode}]\n  " . implode( "\n  ", $problems ) . "\n";
		} else {
			echo "ok   {$name} [{$mode}]{$unresolved}\n";
		}
	}
}

echo "\n{$checked} fixture conversions, {$failures} failed" . ( $update ? ' (expected files updated)' : '' ) . "\n";
exit( $failures ? 1 : 0 );
