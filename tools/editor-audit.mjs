#!/usr/bin/env node
/**
 * Opens every docs page in the block editor and records which blocks the editor marks invalid or
 * missing. Writes tools/editor-audit.json and prints a summary.
 *
 *   BASE_URL=http://localhost:8882 WP_USER=admin WP_PASS=password POSTS=tools/posts.json node tools/editor-audit.mjs
 *
 * POSTS is a JSON list of { ID, post_title } (from `wp post list --post_type=docs --fields=ID,post_title
 * --format=json`). ONLY=19,166 limits the run to some ids.
 */

import { chromium } from 'playwright';
import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const base = ( process.env.BASE_URL || 'http://localhost:8882' ).replace( /\/$/, '' );
const user = process.env.WP_USER || 'admin';
const pass = process.env.WP_PASS || 'password';
const postsFile = process.env.POSTS || join( here, 'posts.json' );
const only = ( process.env.ONLY || '' ).split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ).map( Number );

let posts = JSON.parse( readFileSync( postsFile, 'utf8' ) );
if ( only.length ) {
	posts = posts.filter( ( p ) => only.includes( Number( p.ID ) ) );
}

async function launch() {
	if ( process.env.CHROMIUM_PATH ) {
		return chromium.launch( { executablePath: process.env.CHROMIUM_PATH } );
	}
	try {
		return await chromium.launch();
	} catch ( e ) {
		const cache = join( homedir(), 'Library/Caches/ms-playwright' );
		const dirs = existsSync( cache ) ? readdirSync( cache ).filter( ( d ) => d.startsWith( 'chromium_headless_shell' ) ).sort() : [];
		if ( ! dirs.length ) {
			throw e;
		}
		return chromium.launch( { executablePath: join( cache, dirs.at( -1 ), 'chrome-headless-shell-mac-arm64/chrome-headless-shell' ) } );
	}
}

const browser = await launch();
const context = await browser.newContext( { viewport: { width: 1440, height: 900 } } );
const page = await context.newPage();

await page.goto( `${ base }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
await page.fill( '#user_login', user );
await page.fill( '#user_pass', pass );
await Promise.all( [ page.waitForNavigation( { waitUntil: 'domcontentloaded' } ), page.click( '#wp-submit' ) ] );
if ( ! ( await context.cookies() ).some( ( c ) => c.name.startsWith( 'wordpress_logged_in' ) ) ) {
	console.error( 'Login failed.' );
	process.exit( 1 );
}

const results = [];
const started = Date.now();
for ( const [ i, post ] of posts.entries() ) {
	const id = Number( post.ID );
	const url = `${ base }/wp-admin/post.php?post=${ id }&action=edit`;
	const record = { id, title: post.post_title, blocks: 0, invalid: [], missing: [], error: '' };
	try {
		await page.goto( url, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		await page.waitForFunction(
			() => window.wp && wp.data && wp.data.select( 'core/block-editor' ) && wp.data.select( 'core/block-editor' ).getBlocks().length > 0,
			null,
			{ timeout: 20000 }
		);
		// Give validation a moment to settle after the first render.
		await page.waitForTimeout( 500 );
		const data = await page.evaluate( () => {
			const flatten = ( blocks, out = [] ) => {
				for ( const b of blocks ) {
					out.push( b );
					if ( b.innerBlocks && b.innerBlocks.length ) {
						flatten( b.innerBlocks, out );
					}
				}
				return out;
			};
			const all = flatten( wp.data.select( 'core/block-editor' ).getBlocks() );
			const top = wp.data.select( 'core/block-editor' ).getBlocks().length;
			const invalid = all.filter( ( b ) => b.isValid === false ).map( ( b ) => ( {
				name: b.name,
				originalContent: ( b.originalContent || '' ).slice( 0, 120 ),
			} ) );
			const missing = all.filter( ( b ) => b.name === 'core/missing' ).map( ( b ) => ( {
				originalName: b.attributes && b.attributes.originalName,
				originalContent: ( ( b.attributes && b.attributes.originalContent ) || '' ).slice( 0, 120 ),
			} ) );
			const settings = wp.data.select( 'core/block-editor' ).getSettings();
			return {
				blocks: top,
				total: all.length,
				invalid,
				missing,
				noticeRegistered: Boolean( wp.blocks.getBlockType( 'wporg/notice' ) ),
				customColors: settings.__experimentalFeatures?.color?.custom,
				allowedBlockTypes: Array.isArray( settings.allowedBlockTypes ) ? settings.allowedBlockTypes.length : settings.allowedBlockTypes,
			};
		} );
		Object.assign( record, data );
	} catch ( e ) {
		record.error = String( e.message || e ).split( '\n' )[ 0 ];
	}
	results.push( record );
	const flag = record.error ? 'ERR' : ( record.invalid.length || record.missing.length ? 'BAD' : 'ok ' );
	console.log( `${ flag } ${ String( i + 1 ).padStart( 3 ) }/${ posts.length } ${ id } ${ post.post_title } (${ record.total ?? 0 } blocks, ${ record.invalid.length } invalid, ${ record.missing.length } missing)${ record.error ? ': ' + record.error : '' }` );
}

await browser.close();

const invalidBy = {};
const missingBy = {};
let pagesInvalid = 0;
for ( const r of results ) {
	if ( r.invalid.length || r.missing.length ) {
		pagesInvalid++;
	}
	for ( const b of r.invalid ) {
		invalidBy[ b.name ] = ( invalidBy[ b.name ] || 0 ) + 1;
	}
	for ( const b of r.missing ) {
		missingBy[ b.originalName ] = ( missingBy[ b.originalName ] || 0 ) + 1;
	}
}
const summary = {
	audited: results.length,
	errors: results.filter( ( r ) => r.error ).length,
	pagesWithInvalidOrMissing: pagesInvalid,
	invalidByBlock: invalidBy,
	missingByOriginalName: missingBy,
	noticeRegistered: results.find( ( r ) => ! r.error )?.noticeRegistered,
	customColors: results.find( ( r ) => ! r.error )?.customColors,
	allowedBlockTypes: results.find( ( r ) => ! r.error )?.allowedBlockTypes,
	seconds: Math.round( ( Date.now() - started ) / 1000 ),
};
writeFileSync( join( here, 'editor-audit.json' ), JSON.stringify( { summary, results }, null, '\t' ) + '\n' );
console.log( JSON.stringify( summary, null, 2 ) );
