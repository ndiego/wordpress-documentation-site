<?php
// Debug: php tests/debug-reverse.php <page-key> [--content=...]  Shows chunk -> blocks alignment against the full conversion.
require __DIR__ . '/bootstrap.php';
use WP_Docs\Manifest; use WP_Docs\Pipeline; use WP_Docs\Sources; use WP_Docs\Transforms\Chunks; use WP_Docs\Transforms\Markdown_Out; use WP_Docs\Transforms\Reverse;
$content = dirname( __DIR__, 4 ) . '/wordpress-documentation';
$key = $argv[1];
$manifest = json_decode( file_get_contents( "$content/manifest.json" ), true );
$aliases  = array( 'ndiego/wordpress-documentation' => array( 'prefix' => '', 'ref' => 'trunk' ) );
$norm = Manifest\normalize( array( 'manifest' => $manifest ) ); $entries = $norm['entries'];
$map = Manifest\source_map( $entries, $aliases ); $lookup = Manifest\lookup_callback( $entries, $map, $aliases, 'https://wordpress.org/docs/' );
$base = Sources\parse( $entries[ $key ]['markdown_source'] );
$raw = file_get_contents( "$content/{$base['path']}" );
$options = array( 'mode' => 'blocks', 'base' => $base, 'lookup' => $lookup, 'import_image' => static fn( $t, $a ) => array( 'url' => 'https://x/' . basename( $t['path'] ), 'id' => 1 ), 'file_url' => static fn( $t ) => Sources\blob_url( $t ) );
$convert = static fn( $md ) => Pipeline\convert_body( $md, $options );
$full = Markdown_Out\parse( Pipeline\convert_file( $raw, $options ) );
$parts = Reverse\split_file( $raw );
$chunks = Chunks\chunk( $parts['body'] );
$short = static fn( $s ) => substr( preg_replace( '/\s+/', ' ', $s ), 0, 70 );
$fi = 0;
foreach ( $chunks as $ci => $c ) {
	$blocks = 'comment' === $c['kind'] ? array() : Markdown_Out\parse( $convert( $c['text'] ) );
	$names = implode( ',', array_map( static fn( $b ) => $b['blockName'] ?: 'null', $blocks ) );
	printf( "%3d %-9s %-40s -> %s\n", $ci, $c['kind'], $short( $c['text'] ), $names );
	foreach ( $blocks as $b ) {
		$f = $full[ $fi ] ?? null;
		$same = $f && Reverse\fingerprint( $f ) === Reverse\fingerprint( $b );
		if ( ! $same ) {
			printf( "      !! full[%d]=%s %s\n         chunk: %s\n", $fi, $f ? $f['blockName'] : 'none', $f ? $short( Reverse\normalize_html( Markdown_Out\flatten( $f ) ) ) : '', $short( Reverse\normalize_html( Markdown_Out\flatten( $b ) ) ) );
		}
		++$fi;
	}
}
printf( "chunk blocks: %d, full blocks: %d\n", $fi, count( $full ) );
