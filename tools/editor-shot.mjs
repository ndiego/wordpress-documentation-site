// Screenshot a docs page in the block editor and print the canvas root's classes.
//   POST_ID=514 SHOT=tools/shots/editor-514.png node tools/editor-shot.mjs
import { chromium } from 'playwright';
import { existsSync, readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
const base = ( process.env.BASE_URL || 'http://localhost:8882' ).replace( /\/$/, '' );
function shell() {
	const root = join( homedir(), 'Library/Caches/ms-playwright' );
	for ( const d of readdirSync( root ).filter( ( d ) => d.startsWith( 'chromium_headless_shell' ) ).sort().reverse() ) {
		const p = join( root, d, 'chrome-headless-shell-mac-arm64/chrome-headless-shell' );
		if ( existsSync( p ) ) return p;
	}
}
const browser = await chromium.launch( { executablePath: shell() } );
const page = await ( await browser.newContext( { viewport: { width: 1440, height: 1100 } } ) ).newPage();
await page.goto( base + '/wp-login.php' );
await page.fill( '#user_login', process.env.WP_USER || 'admin' );
await page.fill( '#user_pass', process.env.WP_PASS || 'password' );
await page.click( '#wp-submit' );
await page.waitForURL( /wp-admin/ );
await page.goto( base + '/wp-admin/post.php?post=' + process.env.POST_ID + '&action=edit' );
await page.waitForFunction( () => window.wp?.data?.select( 'core/block-editor' )?.getBlocks().length > 0, null, { timeout: 30000 } );
await page.waitForTimeout( 2500 );
try { await page.click( 'button[aria-label="Close"]', { timeout: 1500 } ); } catch ( e ) {}
const frame = page.frames().find( ( f ) => f.name() === 'editor-canvas' );
if ( frame ) {
	console.log( 'root classes:', await frame.evaluate( () => document.querySelector( '.is-root-container' )?.className ) );
	console.log( 'body classes:', await frame.evaluate( () => document.body.className ) );
	console.log( 'styles loaded:', await frame.evaluate( () => [ ...document.querySelectorAll( 'style, link[rel=stylesheet]' ) ].map( ( s ) => s.id || s.href || 'inline' ).filter( ( s ) => /wp-docs|editor-style|global/.test( s ) ) ) );
	console.log( 'styles with content rules:', await frame.evaluate( () => [ ...document.querySelectorAll( 'style' ) ].filter( ( s ) => s.textContent.includes( 'is-root-container' ) ).length ) );
	console.log( 'root children:', await frame.evaluate( () => [ ...document.querySelectorAll( '.is-root-container > *' ) ].slice( 0, 6 ).map( ( e ) => e.tagName + '.' + e.className.split( ' ' ).slice( 0, 2 ).join( '.' ) ) ) );
	console.log( 'rule sample:', await frame.evaluate( () => { const s = [ ...document.querySelectorAll( 'style' ) ].find( ( s ) => s.textContent.includes( 'is-root-container) > * + *' ) ); const i = s ? s.textContent.indexOf( 'is-root-container) > * + *' ) : -1; return s ? s.textContent.slice( Math.max( 0, i - 80 ), i + 120 ) : 'none'; } ) );
	console.log( 'second block margin-top / h2 size:', await frame.evaluate( () => { const b = document.querySelectorAll( '.is-root-container > *' )[ 1 ]; const h = document.querySelector( '.is-root-container h2' ); return ( b && getComputedStyle( b ).marginTop ) + ' / ' + ( h && getComputedStyle( h ).fontSize ); } ) );
}
await page.screenshot( { path: process.env.SHOT || 'tools/shots/editor-shot.png' } );
await browser.close();
