// Save a docs page from the real block editor and report what the editor showed.
//   BASE_URL=http://localhost:8882 PAGE=write/create-a-page node tools/editor-save-test.mjs
import { chromium } from 'playwright';
import { existsSync, readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';

const base = ( process.env.BASE_URL || 'http://localhost:8882' ).replace( /\/$/, '' );
const page_path = process.env.PAGE || 'write/create-a-page';
const text = process.env.TEXT || ' Saved from the block editor.';

function shell() {
	const root = join( homedir(), 'Library/Caches/ms-playwright' );
	const dirs = readdirSync( root ).filter( ( d ) => d.startsWith( 'chromium_headless_shell' ) ).sort().reverse();
	for ( const d of dirs ) {
		const p = join( root, d, 'chrome-headless-shell-mac-arm64/chrome-headless-shell' );
		if ( existsSync( p ) ) return p;
	}
	return undefined;
}

const browser = await chromium.launch( { executablePath: shell() } );
const context = await browser.newContext( { viewport: { width: 1440, height: 1000 } } );
const page = await context.newPage();
await page.goto( base + '/wp-login.php' );
await page.fill( '#user_login', process.env.WP_USER || 'admin' );
await page.fill( '#user_pass', process.env.WP_PASS || 'password' );
await page.click( '#wp-submit' );
await page.waitForURL( /wp-admin/ );

const id = process.env.POST_ID || await page.evaluate( async ( p ) => {
	const r = await fetch( '/wp-json/wp/v2/docs?per_page=1&search=' + encodeURIComponent( p.split( '/' ).pop() ), { headers: { 'X-WP-Nonce': window.wpApiSettings?.nonce || '' } } );
	const list = await r.json();
	const hit = list.find( ( x ) => x.meta?._wp_docs_path === p + '/' ) || list[ 0 ];
	return hit?.id;
}, page_path );
console.log( 'post', id );
await page.goto( base + '/wp-admin/post.php?post=' + id + '&action=edit' );
await page.waitForFunction( () => window.wp?.data?.select( 'core/block-editor' )?.getBlocks().length > 0, null, { timeout: 30000 } );
await page.waitForTimeout( 1500 );
try { await page.click( 'button[aria-label="Close"]', { timeout: 2000 } ); } catch ( e ) {}

const notices = await page.evaluate( () => wp.data.select( 'core/notices' ).getNotices().map( ( n ) => n.status + ': ' + n.content ) );
console.log( 'notices on load:', notices );

// Change the first paragraph through the editor's store, as typing would.
const changed = await page.evaluate( ( t ) => {
	const sel = wp.data.select( 'core/block-editor' );
	const block = sel.getBlocks().find( ( b ) => b.name === 'core/paragraph' );
	const content = block.attributes.content.toString ? block.attributes.content.toString() : block.attributes.content;
	wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( block.clientId, { content: content + t } );
	return block.clientId;
}, text );
await page.waitForTimeout( 500 );
await page.click( 'button.editor-post-publish-button__button, button.editor-post-publish-button' );
await page.waitForFunction( () => ! wp.data.select( 'core/editor' ).isSavingPost(), null, { timeout: 60000 } );
await page.waitForTimeout( 1500 );
const after = await page.evaluate( () => ( {
	dirty: wp.data.select( 'core/editor' ).isEditedPostDirty(),
	notices: wp.data.select( 'core/notices' ).getNotices().map( ( n ) => n.status + ': ' + n.content ),
	error: wp.data.select( 'core' ).getLastEntitySaveError( 'postType', 'docs', wp.data.select( 'core/editor' ).getCurrentPostId() )?.message || null,
} ) );
console.log( 'after save:', JSON.stringify( after, null, 2 ) );
await page.screenshot( { path: process.env.SHOT || 'tools/shots/editor-save.png' } );
await browser.close();
