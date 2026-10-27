import { chromium } from 'playwright';
import { existsSync, readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
function shell() { const root = join( homedir(), 'Library/Caches/ms-playwright' ); for ( const d of readdirSync( root ).filter( ( d ) => d.startsWith( 'chromium_headless_shell' ) ).sort().reverse() ) { const p = join( root, d, 'chrome-headless-shell-mac-arm64/chrome-headless-shell' ); if ( existsSync( p ) ) return p; } }
const browser = await chromium.launch( { executablePath: shell() } );
const page = await ( await browser.newContext( { viewport: { width: 1440, height: 1000 } } ) ).newPage();
await page.goto( 'http://localhost:8882/wp-login.php' ); await page.fill( '#user_login', 'admin' ); await page.fill( '#user_pass', 'password' ); await page.click( '#wp-submit' ); await page.waitForURL( /wp-admin/ );
await page.goto( 'http://localhost:8882/wp-admin/post.php?post=19&action=edit' );
await page.waitForFunction( () => window.wp?.data?.select( 'core/block-editor' )?.getBlocks().length > 0, null, { timeout: 30000 } );
await page.waitForTimeout( 2000 );
try { await page.click( 'button[aria-label="Close"]', { timeout: 1500 } ); } catch ( e ) {}
for ( const name of [ 'core/paragraph', 'core/heading', 'core/image', 'core/list', 'core/code' ] ) {
	const found = await page.evaluate( ( n ) => { const b = wp.data.select( 'core/block-editor' ).getBlocks().find( ( b ) => b.name === n ); if ( ! b ) return false; wp.data.dispatch( 'core/block-editor' ).selectBlock( b.clientId ); wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/block' ); return true; }, name );
	if ( ! found ) { console.log( name, '=> not on this page' ); continue; }
	await page.waitForTimeout( 800 );
	const panels = await page.evaluate( () => [ ...document.querySelectorAll( '.block-editor-block-inspector .components-panel__body-title, .block-editor-block-inspector .components-tools-panel-header h2, .block-editor-block-inspector .components-base-control__label, .block-editor-block-inspector .components-input-control__label' ) ].map( ( e ) => e.textContent.trim() ).filter( Boolean ) );
	console.log( name, '=>', panels.join( ' | ' ) );
	if ( 'core/paragraph' === name ) { try { await page.click( '.block-editor-block-inspector__advanced button, .block-editor-block-inspector .components-panel__body:last-child .components-panel__body-toggle', { timeout: 1500 } ); } catch ( e ) {} await page.waitForTimeout( 500 ); console.log( 'advanced:', await page.evaluate( () => document.querySelector( '.block-editor-block-inspector__advanced' )?.innerText.replace( /\n+/g, ' | ' ) ) ); }
	if ( 'core/paragraph' === name ) await page.screenshot( { path: 'tools/shots/inspector-paragraph.png', clip: { x: 1160, y: 90, width: 280, height: 700 } } );
}
await browser.close();
