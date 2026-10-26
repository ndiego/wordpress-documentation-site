#!/usr/bin/env node
/**
 * Screenshots of the local site for design review, into tools/shots/ (gitignored).
 *
 * Scenes: the home, a section landing, a tutorial page with the right rail (top of the page and the full
 * page), the search palette open with a query and the mobile drawer open, at 1440 and 390 pixels wide.
 *
 *   npm run shots                              # every scene
 *   ONLY=home,palette npm run shots            # some scenes (names below)
 *   BASE_URL=http://localhost:8889 SECTION=/docs/write/ LEAF=/docs/write/create-a-post/ npm run shots
 *   CHROMIUM_PATH=/path/to/chrome-headless-shell npm run shots
 *
 * Browser: Playwright's own when installed (`npx playwright install chromium-headless-shell`),
 * otherwise the newest chromium_headless_shell found under ~/Library/Caches/ms-playwright.
 */

import { chromium } from 'playwright';
import { existsSync, mkdirSync, readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const outDir = join( here, 'shots' );
const base = ( process.env.BASE_URL || 'http://localhost:8889' ).replace( /\/$/, '' );

const paths = {
	home: process.env.HOME_PATH || '/docs/',
	section: process.env.SECTION || '/docs/get-started/',
	leaf: process.env.LEAF || '/docs/write/create-a-post/',
	query: process.env.QUERY || 'publish',
};
const only = ( process.env.ONLY || '' ).split( ',' ).map( ( s ) => s.trim() ).filter( Boolean );
const widths = [ 1440, 390 ];

function findCachedHeadlessShell() {
	const cache = join( homedir(), 'Library/Caches/ms-playwright' );
	if ( ! existsSync( cache ) ) {
		return undefined;
	}
	const dirs = readdirSync( cache )
		.filter( ( d ) => d.startsWith( 'chromium_headless_shell-' ) )
		.sort( ( a, b ) => Number( b.split( '-' )[ 1 ] ) - Number( a.split( '-' )[ 1 ] ) );
	for ( const d of dirs ) {
		for ( const sub of [ 'chrome-headless-shell-mac-arm64', 'chrome-headless-shell-mac-x64', 'chrome-headless-shell-linux64' ] ) {
			const bin = join( cache, d, sub, 'chrome-headless-shell' );
			if ( existsSync( bin ) ) {
				return bin;
			}
		}
	}
	return undefined;
}

async function launch() {
	if ( process.env.CHROMIUM_PATH ) {
		return chromium.launch( { executablePath: process.env.CHROMIUM_PATH } );
	}
	try {
		return await chromium.launch();
	} catch ( error ) {
		const executablePath = findCachedHeadlessShell();
		if ( ! executablePath ) {
			throw error;
		}
		console.log( `Using cached browser ${ executablePath }` );
		return chromium.launch( { executablePath } );
	}
}

/**
 * Each scene: a name, the widths it runs at, and what to do before the screenshot.
 * `full` takes the whole page.
 */
const scenes = [
	{ name: 'home', path: paths.home, full: true },
	{ name: 'section', path: paths.section, full: true },
	{ name: 'leaf', path: paths.leaf },
	{ name: 'leaf-full', path: paths.leaf, full: true },
	{
		name: 'palette',
		path: paths.leaf,
		async act( page ) {
			await page.keyboard.press( 'Control+k' );
			await page.waitForSelector( 'dialog.wp-docs-search[open]' );
			await page.keyboard.type( paths.query, { delay: 20 } );
			await page.waitForSelector( '.wp-docs-search__item' );
		},
	},
	{
		name: 'drawer',
		path: paths.leaf,
		widths: [ 390 ],
		async act( page ) {
			await page.click( '[data-wp-docs-menu]' );
			await page.waitForTimeout( 300 );
		},
	},
	{
		name: 'menu',
		path: paths.leaf,
		widths: [ 1440 ],
		async act( page ) {
			await page.click( '.wp-docs-rail .wp-docs-actions__more' );
			await page.waitForTimeout( 100 );
		},
	},
];

mkdirSync( outDir, { recursive: true } );
const browser = await launch();

for ( const width of widths ) {
	for ( const scene of scenes ) {
		if ( only.length && ! only.includes( scene.name ) ) {
			continue;
		}
		if ( scene.widths && ! scene.widths.includes( width ) ) {
			continue;
		}
		const context = await browser.newContext( {
			viewport: { width, height: width < 600 ? 844 : 900 },
			deviceScaleFactor: 1,
			colorScheme: 'light',
			reducedMotion: 'reduce',
		} );
		const page = await context.newPage();
		const errors = [];
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );
		page.on( 'response', ( r ) => r.status() >= 400 && errors.push( `${ r.status() } ${ r.url() }` ) );
		const response = await page.goto( base + scene.path, { waitUntil: 'networkidle' } );
		if ( scene.act ) {
			await scene.act( page );
		}
		const file = join( outDir, `${ scene.name }-${ width }.png` );
		await page.screenshot( { path: file, fullPage: Boolean( scene.full ) } );
		console.log( `${ response?.status() } ${ scene.path } -> ${ file }${ errors.length ? `\n  errors: ${ errors.join( ' | ' ) }` : '' }` );
		await context.close();
	}
}

await browser.close();
