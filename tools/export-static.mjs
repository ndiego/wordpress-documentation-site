#!/usr/bin/env node
/**
 * Export the running wp-env site as static files for Spacefast (or any static host).
 *
 * Crawls the home (`/`, also written as `/docs/index.html`), every page listed in `/docs/index.json` or
 * linked from a crawled page, and the 404 page (`/docs/does-not-exist/`, written as `/404.html`). For each
 * page it saves `<path>/index.html` and the page's Markdown (`<path without slash>.md` on the site) as
 * `<path>/markdown.txt`, because the host does not serve `.md` files. It saves the agent surface
 * (`/llms.txt`, `/llms-full.txt`, `/docs/index.json`) and the palette's index
 * (`/wp-json/wp-docs/v1/search-index` as `/docs/search-index.json`), downloads every asset on this host
 * the pages, stylesheets and scripts reference, and writes `sf.jsonc` with the plugin's `redirect_from`
 * map as 301s.
 *
 * Rewrites: every `.md` URL becomes its `markdown.txt` path (in HTML, Markdown, llms.txt and index.json,
 * plain, JSON-escaped and percent-encoded); absolute site URLs become root-relative (or `--public-url`
 * absolute in the agent files, canonical links and the ChatGPT / Claude prompts, when given); `?ver=` is
 * dropped; the palette reads `/docs/search-index.json`; search forms that post to this site are removed
 * (the home hero field is kept, it only opens the palette); the global header's wordpress.org search stays.
 *
 * Shared fragments: inline stylesheets over 1 KB move into hashed files under `/shared/css/`, and the
 * sidebar list (identical on every page once the current-page markers are removed) moves into one hashed
 * script that writes it during parsing, followed by a short inline script that marks the current page and
 * opens its ancestors before sidebar.js runs. A page whose sidebar differs keeps its own copy.
 *
 * Usage: node tools/export-static.mjs [--base http://localhost:8889] [--out static]
 *        [--public-url https://example.com] [--redirects redirects.json]
 * Without --redirects, the map is read from the `wp_docs_redirects` option with `npx wp-env run cli wp eval`
 * (read-only). Exits non-zero only on a crash; every failed fetch is listed at the end.
 */
import { mkdir, writeFile, readFile, readdir, rm } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { homedir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const args = Object.fromEntries( process.argv.slice( 2 ).map( ( a, i, all ) => a.startsWith( '--' ) ? [ a.slice( 2 ), all[ i + 1 ] ] : [] ).filter( ( x ) => x.length ) );
const BASE = ( args.base || 'http://localhost:8889' ).replace( /\/$/, '' );
const PUBLIC = ( args[ 'public-url' ] || '' ).replace( /\/$/, '' );
const OUT = path.resolve( args.out || path.join( ROOT, 'static' ) );
const REDIRECTS = args.redirects ? path.resolve( args.redirects ) : null;
const CONCURRENCY = 8;
const UA = { 'User-Agent': 'wp-docs static export' };

const pages = new Map(); // site path -> html
const markdown = new Map(); // site path -> markdown
const assets = new Map(); // local path -> Buffer
const queuedPages = new Set();
const queuedAssets = new Set();
const optionalAssets = new Set(); // fetched if they exist (Prism language components)
const cssFonts = new Set(); // font files referenced by a stylesheet (a browser fetches one only when a page uses it)
const missingFonts = [];
const failures = [];
const pageQueue = [];
const assetQueue = [];

const esc = ( s ) => s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
const isLocal = ( u ) => u === BASE || u.startsWith( BASE + '/' );

/* URL rewriting. */
const BASE_ESCAPED = BASE.replace( /\//g, '\\/' ); // as in JSON written by PHP
const BASE_ENCODED = encodeURIComponent( BASE ); // as in query strings

// `/docs/write/create-a-post` -> `/docs/write/create-a-post/markdown.txt`; the home's `/docs/index` -> `/docs/markdown.txt`.
const mdPath = ( noExt ) => ( noExt === '/docs/index' ? '/docs' : noExt ) + '/markdown.txt';
const mdSourceUrl = ( p ) => BASE + ( p === '/docs/' ? '/docs/index' : p.replace( /\/$/, '' ) ) + '.md';

/**
 * Rewrites site URLs in any text. `origin` replaces the site origin: '' for root-relative URLs (HTML), or
 * the public URL in files read by agents.
 */
function rewriteUrls( text, origin = '' ) {
	const encOrigin = encodeURIComponent( origin );
	const escOrigin = origin.replace( /\//g, '\\/' );
	return text
		// .md URLs: plain, JSON-escaped, percent-encoded.
		.replace( new RegExp( esc( BASE ) + '(/docs(?:/[\\w-]+)*)\\.md\\b', 'g' ), ( m, p ) => origin + mdPath( p ) )
		.replace( new RegExp( esc( BASE_ESCAPED ) + '((?:\\\\/[\\w-]+)+)\\.md\\b', 'g' ), ( m, p ) => escOrigin + mdPath( p.replace( /\\\//g, '/' ) ).replace( /\//g, '\\/' ) )
		.replace( new RegExp( esc( BASE_ENCODED ) + '((?:%2F[\\w-]+)+)\\.md\\b', 'g' ), ( m, p ) => encOrigin + encodeURIComponent( mdPath( decodeURIComponent( p ) ) ) )
		// The site origin.
		.replace( new RegExp( esc( BASE_ESCAPED ) + '(?=\\\\/)', 'g' ), escOrigin )
		.replace( new RegExp( esc( BASE ) + '(?=/)', 'g' ), origin )
		.replace( new RegExp( esc( BASE ), 'g' ), origin || '/' )
		.replace( new RegExp( esc( BASE_ENCODED ), 'g' ), encOrigin )
		// Cache busters.
		.replace( /(\.(?:css|js|mjs|woff2?|ttf|otf|png|jpe?g|gif|svg|webp|avif|ico|json))\?ver=[^"'\s)&]+/g, '$1' );
}

// Root-relative `.md` paths in prose (llms.txt describes the URL scheme).
function rewriteAgentText( text ) {
	return rewriteUrls( text, PUBLIC )
		.replace( /at its URL plus `\.md`/g, 'at `markdown.txt` under its URL' )
		.replace( /(^|[\s(\["'])(\/docs(?:\/[\w-]+)*)\.md\b/gm, ( m, pre, p ) => pre + mdPath( p ) );
}

// Without --public-url the ChatGPT / Claude prompts and the issue link carry a root-relative URL; make it
// absolute with the host's origin at runtime.
const PROMPT_FIX = '<script>document.querySelectorAll(\'a[href*="%20%2Fdocs"]\').forEach(function(a){a.setAttribute("href",a.getAttribute("href").replace(/%20%2Fdocs/g,"%20"+encodeURIComponent(location.origin)+"%2Fdocs"));});</script>';

function rewriteHtml( html, sitePath ) {
	html = html
		// Head links to things a static host does not have.
		.replace( /<link rel="alternate" type="application\/rss\+xml"[^>]*>\n?/g, '' )
		.replace( /<link rel="alternate" title="oEmbed[^>]*>\n?/g, '' )
		.replace( /<link rel="alternate" title="JSON"[^>]*>\n?/g, '' )
		.replace( /<link rel="(?:https:\/\/api\.w\.org\/|EditURI)"[^>]*>\n?/g, '' )
		.replace( /<link rel='shortlink'[^>]*>\n?/g, '' )
		// Search forms that post to this site: the hero field only opens the palette, so it stays without an action.
		.replace( /<form\b[^>]*>[\s\S]*?<\/form>/g, ( form ) => {
			const open = form.match( /^<form\b[^>]*>/ )[ 0 ];
			const action = open.match( /action="([^"]*)"/ );
			if ( ! action || ! isLocal( action[ 1 ].replace( /\/$/, '' ) || BASE ) ) {
				return form;
			}
			if ( open.includes( 'data-wp-docs-search-form' ) ) {
				return form.replace( open, open.replace( / action="[^"]*"/, '' ).replace( / method="get"/, '' ).replace( '<form', '<form onsubmit="return false"' ) );
			}
			return '';
		} )
		// The palette: one JSON file, no fallback route, no server search.
		.replace( /data-index-url="[^"]*"/g, 'data-index-url="/docs/search-index.json"' )
		.replace( /\s+data-fallback-url="[^"]*"/g, '' )
		.replace( /\s+data-search-url="[^"]*"/g, '' );
	if ( PUBLIC ) {
		html = html.replace( /<link rel="canonical" href="[^"]*"/, `<link rel="canonical" href="${ PUBLIC }${ sitePath }"` );
	}
	html = rewriteUrls( html );
	if ( ! PUBLIC && html.includes( '%20%2Fdocs' ) ) {
		html = html.replace( '</body>', PROMPT_FIX + '\n</body>' );
	}
	return html;
}

/* Fetching. */
async function get( p, accept ) {
	return fetch( p.startsWith( 'http' ) ? p : BASE + p, { headers: { ...UA, ...( accept ? { Accept: accept } : {} ) }, redirect: 'manual' } );
}

function queueAsset( raw, optional = false ) {
	if ( ! raw || raw.startsWith( 'data:' ) || raw.startsWith( '#' ) ) return;
	let href = raw.replace( /&#0?38;|&amp;/g, '&' );
	if ( href.startsWith( '//' ) ) href = 'http:' + href;
	if ( href.startsWith( '/' ) ) href = BASE + href;
	if ( ! isLocal( href ) ) return;
	const url = new URL( href );
	url.search = '';
	url.hash = '';
	if ( queuedAssets.has( url.href ) ) return;
	queuedAssets.add( url.href );
	if ( optional ) optionalAssets.add( url.href );
	assetQueue.push( url.href );
}

function queuePage( p ) {
	if ( ! p.endsWith( '/' ) ) p += '/';
	if ( queuedPages.has( p ) ) return;
	queuedPages.add( p );
	pageQueue.push( p );
}

function scanHtml( html, label ) {
	// Links to other docs pages.
	for ( const m of html.matchAll( /href=["'](https?:\/\/[^"']+|\/[^"']*)["']/g ) ) {
		const href = m[ 1 ].startsWith( '/' ) ? BASE + m[ 1 ] : m[ 1 ];
		if ( ! isLocal( href ) ) continue;
		const u = new URL( href.replace( /&#0?38;/g, '&' ) );
		if ( u.pathname.startsWith( '/docs/' ) && ! u.search && ! u.pathname.includes( '.' ) && ! /\/feed\/$/.test( u.pathname ) ) queuePage( u.pathname );
	}
	// Assets.
	for ( const m of html.matchAll( /(?:src|href)=["']([^"']+\.(?:css|js|mjs|woff2?|ttf|otf|png|jpe?g|gif|svg|webp|avif|ico)(?:\?[^"']*)?)["']/gi ) ) queueAsset( m[ 1 ] );
	for ( const m of html.matchAll( /srcset=["']([^"']+)["']/g ) ) {
		for ( const part of m[ 1 ].split( ',' ) ) queueAsset( part.trim().split( /\s+/ )[ 0 ] );
	}
	for ( const style of html.matchAll( /<style[^>]*>([\s\S]*?)<\/style>/g ) ) scanCss( style[ 1 ], BASE + '/' );
	for ( const m of html.matchAll( /style="([^"]*url\([^"]*)"/g ) ) scanCss( m[ 1 ].replace( /&quot;/g, '"' ), BASE + '/' );
	for ( const m of html.matchAll( /<script[^>]*type="importmap"[^>]*>([\s\S]*?)<\/script>/g ) ) {
		try {
			for ( const v of Object.values( JSON.parse( m[ 1 ] ).imports || {} ) ) queueAsset( v );
		} catch ( e ) {
			failures.push( `${ label } importmap: ${ e.message }` );
		}
	}
	// Paths inside inline scripts and JSON (the emoji loader and the palette stylesheet use escaped slashes).
	for ( const m of html.replace( /\\\//g, '/' ).matchAll( /["']((?:https?:\/\/[^"'/]+)?\/wp-(?:includes|content)\/[\w./-]+\.(?:js|mjs|css))(?:\?[^"']*)?["']/g ) ) queueAsset( m[ 1 ] );
	// Prism's autoloader fetches a component per code language at runtime.
	for ( const m of html.matchAll( /class="[^"]*\blang(?:uage)?-([\w-]+)/g ) ) prismLanguages.add( m[ 1 ] );
}

function scanCss( css, from ) {
	for ( const m of css.matchAll( /url\(\s*['"]?([^'")]+)['"]?\s*\)|@import\s+['"]([^'"]+)['"]/g ) ) {
		const ref = m[ 1 ] || m[ 2 ];
		if ( ref.startsWith( 'data:' ) || ref.startsWith( '#' ) ) continue;
		try {
			const url = new URL( ref, from );
			if ( /\.(?:woff2?|ttf|otf)$/.test( url.pathname ) ) cssFonts.add( url.origin + url.pathname );
			queueAsset( url.href );
		} catch ( e ) { /* not a URL */ }
	}
}

async function savePage( p ) {
	const res = await get( p, 'text/html' );
	if ( res.status !== 200 ) {
		failures.push( `page ${ p } -> ${ res.status }${ res.headers.get( 'location' ) ? ' ' + res.headers.get( 'location' ) : '' }` );
		return;
	}
	const html = await res.text();
	scanHtml( html, p );
	pages.set( p, html );
	if ( p === '/' ) return; // the same Markdown as /docs/
	const md = await get( mdSourceUrl( p ), 'text/markdown' );
	if ( md.status === 200 ) {
		markdown.set( p, await md.text() );
	} else {
		failures.push( `markdown ${ mdSourceUrl( p ).slice( BASE.length ) } -> ${ md.status }` );
	}
}

async function saveAsset( u ) {
	const res = await fetch( u, { headers: UA } );
	if ( res.status !== 200 ) {
		if ( cssFonts.has( u ) && res.status === 404 ) {
			missingFonts.push( u.slice( BASE.length ) );
		} else if ( ! optionalAssets.has( u ) ) failures.push( `asset ${ u.slice( BASE.length ) } -> ${ res.status }` );
		return;
	}
	const buf = Buffer.from( await res.arrayBuffer() );
	const p = decodeURIComponent( new URL( u ).pathname );
	assets.set( p, buf );
	if ( /\.css$/.test( p ) ) {
		scanCss( buf.toString( 'utf8' ), u );
	} else if ( /\.m?js$/.test( p ) ) {
		const js = buf.toString( 'utf8' );
		for ( const m of js.matchAll( /(?:from|import)\s*\(?\s*["'](\.{1,2}\/[^"']+)["']/g ) ) queueAsset( new URL( m[ 1 ], u ).href );
		// Files a script loads itself, written as quoted paths.
		for ( const m of js.matchAll( /["'`]((?:\.{0,2}\/)?[\w./-]+\.(?:css|js|woff2?))["'`]/g ) ) {
			if ( m[ 1 ].includes( '/' ) ) {
				try { queueAsset( new URL( m[ 1 ], u ).href, true ); } catch ( e ) { /* not a URL */ }
			}
		}
		if ( /\/code-syntax-block\/assets\/prism\/prism\.js$/.test( p ) ) prismJs = { url: u, js };
	}
}

async function drain( queue, fn ) {
	await Promise.all( Array.from( { length: CONCURRENCY }, async () => {
		while ( queue.length ) {
			const item = queue.shift();
			try {
				await fn( item );
			} catch ( e ) {
				failures.push( `${ item }: ${ e.message }` );
			}
		}
	} ) );
}

const prismLanguages = new Set();
let prismJs = null;

// Language components (and their dependencies, from the autoloader's map in prism.js).
function queuePrismComponents() {
	if ( ! prismJs || ! prismLanguages.size ) return;
	const deps = {};
	for ( const m of prismJs.js.matchAll( /(?:^|[{,])"?([\w-]+)"?:("[\w-]+"|\["[\w",-]+"\])/g ) ) {
		deps[ m[ 1 ] ] = JSON.parse( m[ 2 ] );
	}
	const want = new Set();
	const add = ( lang ) => {
		if ( want.has( lang ) ) return;
		want.add( lang );
		for ( const d of [].concat( deps[ lang ] || [] ) ) add( d );
	};
	prismLanguages.forEach( add );
	for ( const lang of want ) queueAsset( new URL( `prism-components/prism-${ lang }.min.js`, prismJs.url ).href, true );
}

/* Output. */
async function writeOut( rel, data ) {
	const file = path.join( OUT, rel );
	await mkdir( path.dirname( file ), { recursive: true } );
	await writeFile( file, data );
	return Buffer.byteLength( data );
}

/* Shared fragments. */
const sharedCss = new Map(); // file -> css
let sidebarFile = null;
let sidebarHtml = null;
const sidebarMismatch = [];

function shareStyles( html ) {
	return html.replace( /<style id="([^"]+)"([^>]*)>([\s\S]*?)<\/style>/g, ( whole, id, attrs, css ) => {
		if ( css.length < 1024 ) return whole;
		const hash = createHash( 'sha1' ).update( css ).digest( 'hex' ).slice( 0, 8 );
		const file = `/shared/css/${ id.replace( /-inline-css$/, '' ) }-${ hash }.css`;
		sharedCss.set( file, css );
		return `<link rel="stylesheet" id="${ id }" href="${ file }" media="all">`;
	} );
}

// The `<ul class="wp-docs-nav">` list, balanced.
function findNavList( html ) {
	const start = html.indexOf( '<ul class="wp-docs-nav">' );
	if ( start < 0 ) return null;
	const re = /<ul\b|<\/ul>/g;
	re.lastIndex = start;
	let depth = 0;
	for ( let m; ( m = re.exec( html ) ); ) {
		depth += m[ 0 ] === '</ul>' ? -1 : 1;
		if ( ! depth ) return { start, end: m.index + 5 };
	}
	return null;
}

// Removes what render.php marks for the current page: aria-current, is-current, is-ancestor, data-on-path,
// is-on-path on sections, and the open state of pages with children (sections are always open).
function normalizeNav( nav ) {
	return nav
		.replace( / aria-current="page"/g, '' )
		.replace( / data-on-path/g, '' )
		.replace( /<li class="wp-docs-nav__group is-open is-on-path"/g, '<li class="wp-docs-nav__group is-open"' )
		.replace( /<li class="(wp-docs-nav__item[^"]*)"/g, ( m, cls ) => `<li class="${ cls.split( ' ' ).filter( ( c ) => ! [ 'is-current', 'is-ancestor', 'is-open' ].includes( c ) ).join( ' ' ) }"` )
		.replace( /(<div class="wp-docs-nav__row">(?:(?!<\/div>)[\s\S])*?aria-expanded=")true"/g, '$1false"' )
		.replace( /(<li class="wp-docs-nav__item[^"]*" data-nav-id="(\d+)"><div class="wp-docs-nav__row">[\s\S]*?<\/div><ul class="wp-docs-nav__list" id="wp-docs-nav-\2")>/g, '$1 hidden>' );
}

const MARK = '<script>(function(){var n=document.currentScript.previousElementSibling;if(!n||!n.classList.contains("wp-docs-nav")){return;}var p=location.pathname.replace(/index\\.html$/,"");if(p.slice(-1)!=="/"){p+="/";}var a=null,l=n.querySelectorAll("a[href]");for(var i=0;i<l.length;i++){var h=l[i].getAttribute("href");if(h.slice(-1)!=="/"){h+="/";}if(h===p){a=l[i];break;}}if(!a){return;}a.setAttribute("aria-current","page");var li=a.closest("li"),first=true;while(li&&n.contains(li)){if(li.classList.contains("wp-docs-nav__group")){li.classList.add("is-on-path");li.setAttribute("data-on-path","");}else{li.classList.add(first?"is-current":"is-ancestor");if(li.hasAttribute("data-nav-id")){li.classList.add("is-open");li.setAttribute("data-on-path","");var b=li.querySelector(":scope>div>.wp-docs-nav__toggle"),u=li.querySelector(":scope>.wp-docs-nav__list");if(b){b.setAttribute("aria-expanded","true");}if(u){u.hidden=false;}}}first=false;li=li.parentElement.closest("li");}})();</script>';

function shareSidebar( html, p ) {
	const range = findNavList( html );
	if ( ! range ) return html;
	const normalized = normalizeNav( html.slice( range.start, range.end ) );
	if ( sidebarHtml === null ) {
		sidebarHtml = normalized;
		sidebarFile = `/shared/sidebar-${ createHash( 'sha1' ).update( normalized ).digest( 'hex' ).slice( 0, 8 ) }.js`;
	} else if ( normalized !== sidebarHtml ) {
		sidebarMismatch.push( p );
		return html;
	}
	return html.slice( 0, range.start ) + `<script src="${ sidebarFile }"></script>${ MARK }` + html.slice( range.end );
}

/* Redirects: { "/old/path": "http://site/docs/new/" }. */
function readRedirects() {
	if ( REDIRECTS ) return readFile( REDIRECTS, 'utf8' ).then( JSON.parse );
	const php = '$o=array();foreach((array)get_option("wp_docs_redirects",array()) as $k=>$id){if("publish"===get_post_status($id)){$o[$k]=get_permalink($id);}}echo wp_json_encode($o);';
	const out = execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', 'eval', php ], {
		cwd: ROOT,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
		env: { ...process.env, PATH: `${ homedir() }/.docker/bin:${ process.env.PATH }` },
	} );
	const line = out.split( '\n' ).find( ( l ) => l.trim().startsWith( '{' ) );
	if ( ! line ) throw new Error( 'no JSON from wp eval' );
	return JSON.parse( line );
}

/* Run. */
try {
	for ( const entry of await readdir( OUT ) ) {
		if ( entry !== '.spacefast' ) await rm( path.join( OUT, entry ), { recursive: true, force: true } );
	}
} catch ( e ) {
	// No previous export.
}

console.log( `Crawling ${ BASE } ...` );
queuePage( '/' );
queuePage( '/docs/' );
const indexRes = await get( '/docs/index.json', 'application/json' );
const indexJson = indexRes.ok ? await indexRes.text() : null;
if ( indexJson ) {
	for ( const page of JSON.parse( indexJson ).pages || [] ) queuePage( new URL( page.url ).pathname );
} else {
	failures.push( `/docs/index.json -> ${ indexRes.status }` );
}
while ( pageQueue.length ) await drain( pageQueue, savePage );

const notFound = await get( '/docs/does-not-exist/', 'text/html' );
let notFoundHtml = null;
if ( notFound.status === 404 ) {
	notFoundHtml = await notFound.text();
	scanHtml( notFoundHtml, '404' );
} else {
	failures.push( `404 page: /docs/does-not-exist/ -> ${ notFound.status }` );
}
console.log( `${ pages.size } pages, ${ markdown.size } Markdown files. Fetching assets ...` );
while ( assetQueue.length ) {
	await drain( assetQueue, saveAsset );
	queuePrismComponents();
}
console.log( `${ assets.size } assets.` );

let bytes = 0;
const writeHtml = async ( file, raw, p ) => {
	bytes += await writeOut( file, rewriteHtml( shareSidebar( shareStyles( raw ), p ), p ) );
};
for ( const [ p, raw ] of pages ) {
	await writeHtml( p === '/' ? 'index.html' : path.join( p, 'index.html' ), raw, p );
}
if ( notFoundHtml ) await writeHtml( '404.html', notFoundHtml, '/404.html' );
for ( const [ p, md ] of markdown ) bytes += await writeOut( path.join( p, 'markdown.txt' ), rewriteAgentText( md ) );
for ( const [ file, css ] of sharedCss ) bytes += await writeOut( file, rewriteUrls( css ) );
if ( sidebarHtml !== null ) bytes += await writeOut( sidebarFile, `document.write(${ JSON.stringify( rewriteUrls( sidebarHtml ) ) });\n` );
if ( sidebarMismatch.length ) failures.push( `${ sidebarMismatch.length } pages kept their own sidebar (it differed after normalizing): ${ sidebarMismatch.slice( 0, 5 ).join( ', ' ) }` );
for ( const [ p, buf ] of assets ) {
	const out = /\.(css|m?js)$/.test( p ) ? Buffer.from( rewriteUrls( buf.toString( 'utf8' ) ) ) : buf;
	bytes += await writeOut( p, out );
}

// The agent surface and the palette's index.
const agentFiles = [ [ '/llms.txt', '/llms.txt' ], [ '/llms-full.txt', '/llms-full.txt' ], [ '/docs/index.json', '/docs/index.json' ], [ '/wp-json/wp-docs/v1/search-index', '/docs/search-index.json' ] ];
for ( const [ from, to ] of agentFiles ) {
	const text = from === '/docs/index.json' && indexJson ? indexJson : await get( from ).then( ( r ) => ( r.ok ? r.text() : ( failures.push( `${ from } -> ${ r.status }` ), null ) ) );
	if ( text === null ) continue;
	// The palette navigates to its URLs, so they stay root-relative.
	bytes += await writeOut( to, to === '/docs/search-index.json' ? rewriteUrls( text ) : rewriteAgentText( text ) );
}

// Spacefast config: old wordpress.org paths -> new pages, clean URLs. The home is served at /.
const redirects = [];
try {
	const map = await readRedirects();
	for ( const [ from, to ] of Object.entries( map ) ) {
		const dest = rewriteUrls( to );
		const source = from.replace( /\/$/, '' ) || '/';
		if ( source !== dest.replace( /\/$/, '' ) ) redirects.push( { source, destination: dest, status: 301 } );
	}
} catch ( e ) {
	failures.push( `redirects: ${ e.message.split( '\n' )[ 0 ] }` );
}
const config = {
	$schema: 'https://spacefast.com/schemas/sf.json',
	name: 'WordPress documentation',
	cleanUrls: true,
	listing: false,
	meta: { title: 'WordPress documentation', description: 'The WordPress user documentation, written in Markdown on GitHub and rendered with WordPress, with every page also available as Markdown for agents.' },
	redirects,
};
bytes += await writeOut( 'sf.jsonc', JSON.stringify( config, null, '\t' ) + '\n' );

console.log( `Wrote ${ pages.size + 1 } HTML files (${ pages.size - 1 } pages under /docs/, the home again at /, and 404.html), ${ markdown.size } markdown.txt, ${ assets.size } assets, ${ sharedCss.size } shared stylesheets, ${ sidebarHtml === null ? 0 : 1 } shared sidebar, ${ redirects.length } redirects, ${ ( bytes / 1048576 ).toFixed( 1 ) } MB to ${ OUT }` );
if ( missingFonts.length ) {
	const dirs = [ ...new Set( missingFonts.map( ( f ) => path.dirname( f ) ) ) ].sort();
	console.log( `Not failures: ${ missingFonts.length } font files declared in a stylesheet are missing on the site too (fetched by a browser only when a page uses that font), in ${ dirs.join( ', ' ) }` );
}
if ( failures.length ) {
	console.log( `${ failures.length } failures:` );
	for ( const f of failures ) console.log( '  ' + f );
} else {
	console.log( 'No failures.' );
}
