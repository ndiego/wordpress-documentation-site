// Create a doc from the editor: title, a paragraph, Publish, the pre-publish panel, choose a section, Publish.
//   TITLE="Pre-publish test" SECTION_LABEL="Get started" node tools/editor-create-test.mjs
import { chromium } from 'playwright';
import { existsSync, readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
const base = ( process.env.BASE_URL || 'http://localhost:8882' ).replace( /\/$/, '' );
function shell() { const root = join( homedir(), 'Library/Caches/ms-playwright' ); for ( const d of readdirSync( root ).filter( ( d ) => d.startsWith( 'chromium_headless_shell' ) ).sort().reverse() ) { const p = join( root, d, 'chrome-headless-shell-mac-arm64/chrome-headless-shell' ); if ( existsSync( p ) ) return p; } }
const browser = await chromium.launch( { executablePath: shell() } );
const page = await ( await browser.newContext( { viewport: { width: 1440, height: 1100 } } ) ).newPage();
page.on( 'pageerror', ( e ) => console.log( 'pageerror:', e.message ) );
await page.goto( base + '/wp-login.php' ); await page.fill( '#user_login', process.env.WP_USER || 'admin' ); await page.fill( '#user_pass', process.env.WP_PASS || 'password' ); await page.click( '#wp-submit' ); await page.waitForURL( /wp-admin/ );
await page.goto( base + '/wp-admin/post-new.php?post_type=docs' );
await page.waitForFunction( () => window.wp?.data?.select( 'core/editor' )?.getCurrentPostId() > 0, null, { timeout: 30000 } );
await page.waitForTimeout( 1500 );
try { await page.click( 'button[aria-label="Close"]', { timeout: 1500 } ); } catch ( e ) {}
console.log( 'admin menu label:', await page.evaluate( () => document.querySelector( '#menu-posts-docs .wp-menu-name' )?.textContent ) );
console.log( 'document title:', await page.title() );
await page.evaluate( ( t ) => wp.data.dispatch( 'core/editor' ).editPost( { title: t } ), process.env.TITLE || 'Pre-publish test' );
await page.evaluate( () => {
	const block = wp.blocks.createBlock( 'core/paragraph', { content: 'Written in the editor to test the pre-publish check.' } );
	wp.data.dispatch( 'core/block-editor' ).insertBlocks( block );
} );
await page.waitForTimeout( 500 );
await page.click( 'button.editor-post-publish-panel__toggle, button.editor-post-publish-button__button' );
await page.waitForSelector( '.editor-post-publish-panel', { timeout: 15000 } );
await page.waitForTimeout( 1000 );
const panel = await page.evaluate( () => ( {
	panelText: document.querySelector( '.editor-post-publish-panel' )?.innerText.slice( 0, 400 ),
	publishDisabled: document.querySelector( '.editor-post-publish-panel .editor-post-publish-button__button' )?.disabled,
	locked: wp.data.select( 'core/editor' ).isPostSavingLocked(),
} ) );
console.log( 'before choosing:', JSON.stringify( panel, null, 2 ) );
await page.screenshot( { path: 'tools/shots/prepublish-1.png' } );
const label = process.env.SECTION_LABEL || 'Get started';
await page.selectOption( '.editor-post-publish-panel select', { label } );
await page.waitForTimeout( 800 );
console.log( 'after choosing:', JSON.stringify( await page.evaluate( () => ( {
	parent: wp.data.select( 'core/editor' ).getEditedPostAttribute( 'parent' ),
	publishDisabled: document.querySelector( '.editor-post-publish-panel .editor-post-publish-button__button' )?.disabled,
	locked: wp.data.select( 'core/editor' ).isPostSavingLocked(),
} ) ) ) );
await page.screenshot( { path: 'tools/shots/prepublish-2.png' } );
await page.click( '.editor-post-publish-panel .editor-post-publish-button__button' );
await page.waitForFunction( () => ! wp.data.select( 'core/editor' ).isSavingPost() && ! wp.data.select( 'core/editor' ).isPublishingPost(), null, { timeout: 60000 } );
await page.waitForTimeout( 2000 );
console.log( 'after publish:', JSON.stringify( await page.evaluate( () => ( {
	status: wp.data.select( 'core/editor' ).getEditedPostAttribute( 'status' ),
	id: wp.data.select( 'core/editor' ).getCurrentPostId(),
	link: wp.data.select( 'core/editor' ).getCurrentPost().link,
	notices: wp.data.select( 'core/notices' ).getNotices().map( ( n ) => n.status + ': ' + String( n.content ).slice( 0, 120 ) ),
} ) ) ) );
await page.screenshot( { path: 'tools/shots/prepublish-3.png' } );
await browser.close();
