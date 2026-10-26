#!/usr/bin/env node
/**
 * Screenshots of the local site: the /docs/ home, a section page and a leaf page, at 1400 and 390
 * pixels wide, full page, into site/tools/shots/ (gitignored).
 *
 *   npm run shots                       # default pages
 *   BASE_URL=http://localhost:8888 SECTION=/docs/themes/ LEAF=/docs/themes/x/ npm run shots
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
const base = ( process.env.BASE_URL || 'http://localhost:8888' ).replace( /\/$/, '' );

const pages = {
	home: process.env.HOME_PATH || '/docs/',
	section: process.env.SECTION || '/docs/get-started/sample-install/',
	leaf: process.env.LEAF || '/docs/get-started/sample-install/sample-install-locally/',
};
const widths = [ 1400, 390 ];

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

mkdirSync( outDir, { recursive: true } );
const browser = await launch();

for ( const width of widths ) {
	const context = await browser.newContext( { viewport: { width, height: 900 }, deviceScaleFactor: 1 } );
	const page = await context.newPage();
	for ( const [ name, path ] of Object.entries( pages ) ) {
		const response = await page.goto( base + path, { waitUntil: 'networkidle' } );
		const file = join( outDir, `${ name }-${ width }.png` );
		await page.screenshot( { path: file, fullPage: true } );
		console.log( `${ response?.status() } ${ path } -> ${ file }` );
	}
	await context.close();
}

await browser.close();
