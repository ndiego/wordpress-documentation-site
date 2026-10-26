<?php
/**
 * Round-trip tests for the reverse sync, over every page of the content checkout.
 *
 *     php tests/run-roundtrip.php [--content=/path/to/wordpress-documentation] [--only=write/create-a-post] [-v]
 *
 * For each page, three checks, with the real manifest for link resolution:
 *
 * 1. Regenerate: import the page, write every block back as Markdown, import that, and compare block
 *    fingerprints. This is what the editor's save goes through for a changed chunk, so every block the
 *    importer produces must survive it.
 * 2. No-op merge: merge the imported blocks (unchanged) into the source body; the result must be the
 *    source body, byte for byte.
 * 3. One edit: change the text of the first paragraph; the merged body must differ from the source only
 *    in that chunk, and verify() must pass.
 *
 * No WordPress runtime: images resolve to a fake uploads URL and back.
 *
 * @package WP_Docs
 */

// phpcs:disable

require __DIR__ . '/bootstrap.php';

use WP_Docs\Manifest;
use WP_Docs\Pipeline;
use WP_Docs\Sources;
use WP_Docs\Transforms\Front_Matter;
use WP_Docs\Transforms\Markdown_Out;
use WP_Docs\Transforms\Reverse;

$content = dirname( __DIR__, 4 ) . '/wordpress-documentation';
$only    = '';
$verbose = false;
foreach ( array_slice( $argv, 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--content=' ) ) {
		$content = substr( $arg, 10 );
	} elseif ( 0 === strpos( $arg, '--only=' ) ) {
		$only = substr( $arg, 7 );
	} elseif ( '-v' === $arg ) {
		$verbose = true;
	}
}
if ( ! is_file( "$content/manifest.json" ) ) {
	fwrite( STDERR, "No manifest at $content/manifest.json\n" );
	exit( 2 );
}

$repo     = 'ndiego/wordpress-documentation';
$ref      = 'trunk';
$aliases  = array( $repo => array( 'prefix' => '', 'ref' => $ref ) );
$manifest = json_decode( file_get_contents( "$content/manifest.json" ), true );
$norm     = Manifest\normalize( array( 'manifest' => $manifest ) );
$entries  = $norm['entries'];
$map      = Manifest\source_map( $entries, $aliases );
$site     = 'https://wordpress.org/docs/';
$lookup   = Manifest\lookup_callback( $entries, $map, $aliases, $site );

// Page URL => source path, for the reverse link map.
$url_to_source = array();
foreach ( $entries as $key => $doc ) {
	$path = (string) ( $doc['_path'] ?? $key );
	$url  = rtrim( $site, '/' ) . '/' . ( '' === $path ? '' : $path . '/' );
	$src  = Sources\parse( (string) $doc['markdown_source'] );
	if ( $src ) {
		$url_to_source[ $url ] = $src['path'];
	}
}

// Fake media library: repository image path => uploads URL, and back.
$uploads = array();
$import_image = static function ( array $target, string $alt ) use ( &$uploads ) {
	$url             = 'https://wordpress.org/docs/uploads/' . md5( $target['path'] ) . '/' . basename( $target['path'] );
	$uploads[ $url ] = $target['path'];
	return array( 'url' => $url, 'id' => crc32( $target['path'] ) % 100000 + 1 );
};

$relative = static function ( string $from_file, string $to_path ): string {
	$from = explode( '/', dirname( $from_file ) );
	$to   = explode( '/', $to_path );
	while ( $from && $to && $from[0] === $to[0] ) {
		array_shift( $from );
		array_shift( $to );
	}
	return str_repeat( '../', count( array_filter( $from, static fn( $s ) => '.' !== $s ) ) ) . implode( '/', $to );
};

$pages = array();
foreach ( $entries as $key => $doc ) {
	$src = Sources\parse( (string) $doc['markdown_source'] );
	if ( ! $src || ! is_file( "$content/{$src['path']}" ) ) {
		continue;
	}
	if ( '' !== $only && $key !== $only ) {
		continue;
	}
	$pages[ $key ] = $src;
}

$failed  = array();
$checked = 0;
$stats   = array( 'warnings' => array() );

foreach ( $pages as $key => $base ) {
	++$checked;
	$raw     = file_get_contents( "$content/{$base['path']}" );
	$options = array(
		'mode'         => 'blocks',
		'base'         => $base,
		'lookup'       => $lookup,
		'import_image' => $import_image,
		'file_url'     => static fn( array $t ) => Sources\blob_url( $t ),
	);
	$convert = static fn( string $md ) => Pipeline\convert_body( $md, $options );

	$out_options = array(
		'link'  => static function ( string $href ) use ( $url_to_source, $base, $relative ): ?string {
			$fragment = '';
			if ( false !== ( $pos = strpos( $href, '#' ) ) ) {
				$fragment = substr( $href, $pos );
				$href     = substr( $href, 0, $pos );
			}
			if ( isset( $url_to_source[ $href ] ) ) {
				return $relative( $base['path'], $url_to_source[ $href ] ) . $fragment;
			}
			return null;
		},
		'image' => static function ( string $src ) use ( &$uploads, $base, $relative ): ?string {
			return isset( $uploads[ $src ] ) ? $relative( $base['path'], $uploads[ $src ] ) : null;
		},
	);

	$report = array();
	$blocks = Markdown_Out\parse( Pipeline\convert_file( $raw, $options, $report ) );
	if ( $report['freeform'] ) {
		$failed[ $key ][] = 'freeform content after conversion (classic page); skipped';
		continue;
	}
	$parts = Reverse\split_file( $raw );

	// 1. Regenerate everything.
	$warnings = array();
	$regen    = Markdown_Out\blocks_to_markdown( $blocks, $out_options, $warnings );
	foreach ( $warnings as $w ) {
		$stats['warnings'][ $w ] = ( $stats['warnings'][ $w ] ?? 0 ) + 1;
	}
	$diff = Reverse\verify( $regen, $blocks, $convert );
	if ( $diff ) {
		$at = 0;
		while ( isset( $diff['live'][ $at ], $diff['result'][ $at ] ) && $diff['live'][ $at ] === $diff['result'][ $at ] ) {
			++$at;
		}
		$from = max( 0, $at - 80 );
		$failed[ $key ][] = "regenerate: block {$diff['index']} expected {$diff['expected']} got {$diff['actual']}" . ( $verbose ? "\n    live:   ..." . substr( $diff['live'], $from, 200 ) . "\n    result: ..." . substr( $diff['result'], $from, 200 ) : '' );
	}

	// 2. No-op merge.
	$merged = Reverse\merge( $parts['body'], $blocks, $convert, $out_options );
	if ( rtrim( $merged['markdown'], "\n" ) !== rtrim( $parts['body'], "\n" ) ) {
		$a = explode( "\n", rtrim( $parts['body'], "\n" ) );
		$b = explode( "\n", rtrim( $merged['markdown'], "\n" ) );
		$line = 0;
		while ( isset( $a[ $line ], $b[ $line ] ) && $a[ $line ] === $b[ $line ] ) {
			++$line;
		}
		$failed[ $key ][] = sprintf(
			"no-op merge differs at line %d (verbatim %d, rewritten %d, added %d, removed %d)\n    source: %s\n    merged: %s",
			$line + 1,
			$merged['verbatim'],
			$merged['rewritten'],
			$merged['added'],
			$merged['removed'],
			var_export( $a[ $line ] ?? null, true ),
			var_export( $b[ $line ] ?? null, true )
		);
	}

	// 3. One edit: the first paragraph.
	$edited = $blocks;
	foreach ( $edited as $i => $block ) {
		if ( 'core/paragraph' === $block['blockName'] ) {
			$edited[ $i ]['innerHTML']       = preg_replace( '#</p>\s*$#', ' Edited in WordPress.</p>', $block['innerHTML'] );
			$edited[ $i ]['innerContent'][0] = $edited[ $i ]['innerHTML'];
			break;
		}
	}
	$merged = Reverse\merge( $parts['body'], $edited, $convert, $out_options );
	$diff   = Reverse\verify( $merged['markdown'], $edited, $convert );
	if ( $diff ) {
		$failed[ $key ][] = "edit: verify failed at block {$diff['index']} ({$diff['expected']} vs {$diff['actual']})";
	} elseif ( $edited !== $blocks && 1 !== $merged['rewritten'] ) {
		$failed[ $key ][] = "edit: expected 1 rewritten chunk, got {$merged['rewritten']} (added {$merged['added']}, removed {$merged['removed']})";
	}
}

echo "Checked {$checked} pages, " . count( $failed ) . " with failures.\n";
foreach ( $failed as $key => $messages ) {
	echo "FAIL {$key}\n  " . implode( "\n  ", $messages ) . "\n";
}
if ( $stats['warnings'] ) {
	arsort( $stats['warnings'] );
	echo "Warnings:\n";
	foreach ( $stats['warnings'] as $w => $n ) {
		echo "  {$n}  {$w}\n";
	}
}
exit( $failed ? 1 : 0 );
