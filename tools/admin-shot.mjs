import { chromium } from 'playwright';
import { existsSync, readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
function shell() { const root = join( homedir(), 'Library/Caches/ms-playwright' ); for ( const d of readdirSync( root ).filter( ( d ) => d.startsWith( 'chromium_headless_shell' ) ).sort().reverse() ) { const p = join( root, d, 'chrome-headless-shell-mac-arm64/chrome-headless-shell' ); if ( existsSync( p ) ) return p; } }
const browser = await chromium.launch( { executablePath: shell() } );
const page = await ( await browser.newContext( { viewport: { width: 1440, height: 1000 } } ) ).newPage();
await page.goto( 'http://localhost:8882/wp-login.php' ); await page.fill( '#user_login', 'admin' ); await page.fill( '#user_pass', 'password' ); await page.click( '#wp-submit' ); await page.waitForURL( /wp-admin/ );
await page.goto( 'http://localhost:8882/wp-admin/edit.php?post_type=docs' );
await page.screenshot( { path: 'tools/shots/admin-docs.png', clip: { x: 0, y: 0, width: 700, height: 600 } } );
await page.goto( 'http://localhost:8882/wp-admin/post.php?post=19&action=edit' );
await page.waitForFunction( () => window.wp?.data?.select( 'core/block-editor' )?.getBlocks().length > 0, null, { timeout: 30000 } );
await page.waitForTimeout( 2000 );
try { await page.click( 'button[aria-label="Close"]', { timeout: 1500 } ); } catch ( e ) {}
await page.evaluate( () => wp.data.dispatch( 'core/edit-post' ).openGeneralSidebar( 'edit-post/document' ) );
await page.waitForTimeout( 800 );
console.log( 'notices:', await page.evaluate( () => wp.data.select( 'core/notices' ).getNotices().length ) );
if ( 'false' === await page.getAttribute( '.wp-docs-github-panel button', 'aria-expanded' ) ) { await page.click( '.wp-docs-github-panel button' ); await page.waitForTimeout( 500 ); }
console.log( 'panel:', await page.evaluate( () => document.querySelector( '.wp-docs-github-panel' )?.innerText ) );
await page.screenshot( { path: 'tools/shots/editor-panel.png', clip: { x: 1160, y: 90, width: 280, height: 820 } } );
await browser.close();
