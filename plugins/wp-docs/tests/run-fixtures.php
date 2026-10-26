<?php
/**
 * Fixture runner: tests/fixtures/<name>.md -> <name>.blocks.html (blocks mode), <name>.classic.html
 * (classic mode) and <name>.agent.md (the Markdown served to agents), through the same pipeline the importer runs (Jetpack's parser, core's wpautop, the
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
use WP_Docs\Transforms\Agent_Markdown;
use WP_Docs\Transforms\Front_Matter;
use WP_Docs\Transforms\Blocks;

$update = in_array( '--update', $argv, true );

// A small manifest the fixture links resolve against: one manifest, with the home as `index` (path "").
$manifests  = array(
	'manifest' => array(
		'index'                        => array( 'title' => 'Welcome', 'slug' => 'index', 'parent' => null, 'order' => 0, 'path' => '', 'markdown_source' => 'https://github.com/ndiego/wordpress-documentation/blob/trunk/docs/README.md', 'id' => 'index', 'section' => '' ),
		'get-started'                  => array( 'title' => 'Get started', 'slug' => 'get-started', 'parent' => null, 'order' => 10, 'path' => 'get-started/', 'markdown_source' => 'https://github.com/ndiego/wordpress-documentation/blob/trunk/docs/get-started/README.md', 'section' => 'get-started' ),
		'get-started/install'          => array( 'title' => 'Install', 'slug' => 'install', 'parent' => 'get-started', 'order' => 20, 'path' => 'get-started/install/', 'markdown_source' => 'https://github.com/ndiego/wordpress-documentation/blob/trunk/docs/get-started/install.md', 'section' => 'get-started' ),
		'blocks'                       => array( 'title' => 'Blocks', 'slug' => 'blocks', 'parent' => null, 'order' => 20, 'path' => 'blocks/', 'markdown_source' => 'https://github.com/ndiego/wordpress-documentation/blob/trunk/docs/blocks/README.md', 'section' => 'blocks' ),
		'blocks/image'                 => array( 'title' => 'Image', 'slug' => 'image-block', 'parent' => 'blocks', 'order' => 10, 'path' => 'blocks/image/', 'markdown_source' => 'https://github.com/ndiego/wordpress-documentation/blob/trunk/docs/blocks/image.md', 'id' => 'core-image', 'section' => 'blocks' ),
		'block-editor'                 => array( 'title' => 'Block editor', 'slug' => 'block-editor', 'parent' => null, 'order' => 30, 'path' => 'block-editor/', 'markdown_source' => 'https://github.com/ndiego/wordpress-documentation/blob/trunk/docs/block-editor/README.md', 'section' => 'block-editor' ),
		'block-editor/getting-started' => array( 'title' => 'Getting started', 'slug' => 'getting-started', 'parent' => 'block-editor', 'order' => 10, 'path' => 'block-editor/getting-started/', 'markdown_source' => 'https://github.com/WordPress/gutenberg/blob/trunk/docs/getting-started/README.md', 'section' => 'block-editor' ),
	),
);
$aliases    = array( 'ndiego/wordpress-documentation' => array( 'prefix' => '', 'ref' => 'trunk' ) );
$normalized = Manifest\normalize( $manifests );
if ( $normalized['warnings'] ) {
	echo "Fixture manifest warnings:\n  " . implode( "\n  ", $normalized['warnings'] ) . "\n";
	exit( 1 );
}
$map        = Manifest\source_map( $normalized['entries'], $aliases );
$lookup     = Manifest\lookup_callback( $normalized['entries'], $map, $aliases, 'https://wordpress.org/docs/' );

$failures = 0;
$checked  = 0;
foreach ( preg_grep( '/\.agent\.md$/', glob( __DIR__ . '/fixtures/*.md' ), PREG_GREP_INVERT ) as $md ) {
	$name = basename( $md, '.md' );
	$base = Sources\parse( "https://github.com/ndiego/wordpress-documentation/blob/trunk/docs/get-started/{$name}.md" );

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

	// Markdown for agents: <name>.agent.md.
	list( , $body ) = Front_Matter\split( file_get_contents( $md ) );
	$fake_import    = static fn( $target, $alt ) => array( 'url' => 'https://wordpress.org/docs/files/' . basename( $target['path'] ), 'id' => 1 );
	$actual         = Agent_Markdown\build(
		$body,
		array(
			'title'       => 'Fixture ' . $name,
			'description' => 'A test fixture.',
			'type'        => 'reference',
			'url'         => "https://wordpress.org/docs/get-started/{$name}/",
			'source'      => Sources\blob_url( $base ),
		),
		Agent_Markdown\link_resolver( $base, $lookup ),
		Agent_Markdown\image_resolver( $base, $fake_import )
	);
	$expected = __DIR__ . "/fixtures/{$name}.agent.md";
	++$checked;
	if ( $update ) {
		file_put_contents( $expected, $actual );
		echo "ok   {$name} [agent]\n";
	} elseif ( ! file_exists( $expected ) ) {
		++$failures;
		echo "FAIL {$name} [agent]\n  missing {$name}.agent.md (run with --update)\n";
	} elseif ( file_get_contents( $expected ) !== $actual ) {
		++$failures;
		$tmp = sys_get_temp_dir() . "/wp-docs-{$name}.agent.md";
		file_put_contents( $tmp, $actual );
		echo "FAIL {$name} [agent]\n  differs from expected:\n" . shell_exec( 'diff -u ' . escapeshellarg( $expected ) . ' ' . escapeshellarg( $tmp ) );
	} else {
		echo "ok   {$name} [agent]\n";
	}
}

echo "\n{$checked} fixture conversions, {$failures} failed" . ( $update ? ' (expected files updated)' : '' ) . "\n";
exit( $failures ? 1 : 0 );
