/**
 * Export the local WordPress site as static files for a Spacefast (or any static) host.
 *
 * Crawls every /docs/ page from the running wp-env site, saves each as <path>/index.html plus its Markdown
 * variant as <path>/markdown.txt (the host does not serve .md files), downloads every asset the pages and stylesheets reference (CSS, scripts,
 * script modules from the import map, fonts, images on this host), rewrites absolute local URLs to
 * root-relative ones, drops `?ver=` cache busters, points "View as Markdown" and Copy Page at the .md
 * files, removes the search form (there is no server to search), and writes an sf.jsonc with the
 * same-host redirects the hub plugin serves (old wordpress.org paths) plus / -> /docs/.
 *
 * Usage: node tools/export-static.mjs [--base http://localhost:8888] [--out static] [--redirects redirects.json]
 *        redirects.json is `{ "/old/path/": "/docs/new/path/" }`, produced by tools/env/export-redirects.php.
 */
import { mkdir, writeFile, readFile, stat, readdir, rm } from 'node:fs/promises';
import path from 'node:path';

const args = Object.fromEntries( process.argv.slice( 2 ).map( ( a, i, all ) => a.startsWith( '--' ) ? [ a.slice( 2 ), all[ i + 1 ] ] : [] ).filter( ( x ) => x.length ) );
const BASE = ( args.base || 'http://localhost:8888' ).replace( /\/$/, '' );
const OUT = path.resolve( args.out || 'static' );
const REDIRECTS = args.redirects ? path.resolve( args.redirects ) : null;
const CONCURRENCY = 8;

const pages = new Map(); // path -> html
const assets = new Map(); // local path -> Buffer
const queuedPages = new Set();
const queuedAssets = new Set();
const failures = [];

const isLocal = ( u ) => u.startsWith( BASE + '/' ) || u === BASE;
const toLocalPath = ( u ) => {
	const url = new URL( u );
	return url.pathname;
};

function normalizeAssetUrl( u ) {
	const url = new URL( u, BASE );
	url.search = '';
	url.hash = '';
	return url.href;
}

async function fetchText( u, accept = 'text/html' ) {
	const res = await fetch( u, { headers: { Accept: accept, 'User-Agent': 'wporg-docs export' }, redirect: 'manual' } );
	return res;
}

async function savePage( p ) {
	const url = BASE + p;
	const res = await fetchText( url );
	if ( res.status !== 200 ) {
		failures.push( `${ p } -> ${ res.status }` );
		return;
	}
	let html = await res.text();
	// Markdown variant.
	try {
		const md = await fetchText( url + '?output_format=md', 'text/markdown' );
		if ( md.status === 200 ) {
			// Spacefast does not serve .md or .markdown files; .txt is served as text/plain.
			await writeOut( path.join( p, 'markdown.txt' ), await md.text() );
		}
	} catch ( e ) {
		failures.push( `${ p } md: ${ e.message }` );
	}
	// Discover links to other docs pages.
	for ( const m of html.matchAll( /href="(https?:\/\/[^"]+|\/[^"]*)"/g ) ) {
		let href = m[ 1 ];
		if ( href.startsWith( '/' ) ) href = BASE + href;
		if ( ! isLocal( href ) ) continue;
		const u = new URL( href );
		if ( ! u.pathname.startsWith( '/docs/' ) || u.search || u.pathname.includes( '.' ) ) continue;
		const target = u.pathname.endsWith( '/' ) ? u.pathname : u.pathname + '/';
		if ( ! queuedPages.has( target ) ) {
			queuedPages.add( target );
			pageQueue.push( target );
		}
	}
	// Discover assets.
	for ( const m of html.matchAll( /(?:src|href)=["']([^"']+\.(?:css|js|mjs|woff2?|ttf|otf|png|jpe?g|gif|svg|webp|ico|json)(?:\?[^"']*)?)["']/gi ) ) {
		queueAsset( m[ 1 ] );
	}
	for ( const m of html.matchAll( /srcset="([^"]+)"/g ) ) {
		for ( const part of m[ 1 ].split( ',' ) ) queueAsset( part.trim().split( /\s+/ )[ 0 ] );
	}
	// url() references inside inlined stylesheets (notice icons, for example).
	for ( const style of html.matchAll( /<style[^>]*>([\s\S]*?)<\/style>/g ) ) {
		for ( const m of style[ 1 ].matchAll( /url\(\s*['"]?([^'")]+)['"]?\s*\)/g ) ) {
			if ( ! m[ 1 ].startsWith( 'data:' ) && ! m[ 1 ].startsWith( '#' ) ) queueAsset( m[ 1 ] );
		}
	}
	for ( const m of html.matchAll( /<script type="importmap">(.*?)<\/script>/gs ) ) {
		try {
			const map = JSON.parse( m[ 1 ] );
			for ( const v of Object.values( map.imports || {} ) ) queueAsset( v );
		} catch ( e ) {
			failures.push( `${ p } importmap: ${ e.message }` );
		}
	}
	for ( const m of html.matchAll( /<link[^>]+rel=["']modulepreload["'][^>]+href=["']([^"']+)["']/g ) ) queueAsset( m[ 1 ] );
	// Paths inside inline scripts (the emoji loader writes its URL with escaped slashes).
	for ( const m of html.replace( /\\\//g, '/' ).matchAll( /["']((?:https?:\/\/[^"'/]+)?\/wp-(?:includes|content)\/[\w./-]+\.(?:js|css|json))(?:\?[^"']*)?["']/g ) ) queueAsset( m[ 1 ] );
	pages.set( p, html );
}

function queueAsset( raw ) {
	if ( ! raw || raw.startsWith( 'data:' ) ) return;
	let href = raw;
	if ( href.startsWith( '//' ) ) href = 'http:' + href;
	if ( href.startsWith( '/' ) ) href = BASE + href;
	if ( ! isLocal( href ) ) return;
	const norm = normalizeAssetUrl( href );
	if ( queuedAssets.has( norm ) ) return;
	queuedAssets.add( norm );
	assetQueue.push( norm );
}

async function saveAsset( u ) {
	const res = await fetch( u, { headers: { 'User-Agent': 'wporg-docs export' } } );
	if ( res.status !== 200 ) {
		failures.push( `${ u } -> ${ res.status }` );
		return;
	}
	const buf = Buffer.from( await res.arrayBuffer() );
	const p = toLocalPath( u );
	assets.set( p, buf );
	// Follow references inside stylesheets and script modules.
	if ( /\.css$/.test( p ) ) {
		const css = buf.toString( 'utf8' );
		for ( const m of css.matchAll( /url\(\s*['"]?([^'")]+)['"]?\s*\)/g ) ) {
			const ref = m[ 1 ];
			if ( ref.startsWith( 'data:' ) || ref.startsWith( '#' ) ) continue;
			queueAsset( new URL( ref, u ).href );
		}
	} else if ( /\.m?js$/.test( p ) ) {
		const js = buf.toString( 'utf8' );
		for ( const m of js.matchAll( /(?:from|import)\s*\(?\s*["'](\.{1,2}\/[^"']+)["']/g ) ) {
			queueAsset( new URL( m[ 1 ], u ).href );
		}
		// Files a script loads itself (font stylesheets, lazy scripts), written as quoted paths.
		for ( const m of js.matchAll( /["'`]((?:\.{0,2}\/)?[\w./-]+\.(?:css|js|woff2?))["'`]/g ) ) {
			try { queueAsset( new URL( m[ 1 ], u ).href ); } catch ( e ) { /* not a URL */ }
		}
	}
}

async function writeOut( rel, data ) {
	const file = path.join( OUT, rel );
	await mkdir( path.dirname( file ), { recursive: true } );
	await writeFile( file, data );
}

function rewrite( text ) {
	return text
		.replace( /\?output_format=md/g, 'markdown.txt' )
		.replace( new RegExp( BASE.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '/', 'g' ), '/' )
		.replace( new RegExp( BASE.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ), 'g' ), '' )
		.replace( /(\.(?:css|js|mjs|woff2?|ttf|otf|png|jpe?g|gif|svg|webp|ico|json))\?ver=[^"'\s)]+/g, '$1' )
		.replace( /<form role="search"[\s\S]*?<\/form>/g, '' )
		// The ⌘K palette reads its index from a JSON file on the static site, and has no native search to fall back to.
		.replace( /data-index-url="[^"]*"/g, 'data-index-url="/docs/search-index.json"' )
		.replace( / data-search-url="[^"]*"/g, '' );
}

// Loaded by the global-fonts script from a path it builds at runtime (per language), so the scan cannot
// see them; they exist on the live site.
const EXTRA_ASSETS = [
	'/wp-content/mu-plugins/wporg-mu-plugins/mu-plugins/global-fonts/NotoSerif/NotoSerifJP/style.css',
	'/wp-content/mu-plugins/wporg-mu-plugins/mu-plugins/global-fonts/NotoSerif/NotoSerifKR/style.css',
	'/wp-content/mu-plugins/wporg-mu-plugins/mu-plugins/global-fonts/NotoSerif/NotoSerifSC/style.css',
	'/wp-content/mu-plugins/wporg-mu-plugins/mu-plugins/global-fonts/NotoSerif/NotoSerifTC/style.css',
];
// The search palette's index, as one JSON file.
async function exportSearchIndex() {
	const res = await fetch( BASE + '/wp-json/wporg-docs-2026/v1/search-index' );
	if ( ! res.ok ) {
		console.warn( `search index: ${ res.status }` );
		return;
	}
	await writeOut( '/docs/search-index.json', await res.text() );
	console.log( 'Wrote /docs/search-index.json' );
}

const pageQueue = [ '/docs/' ];
queuedPages.add( '/docs/' );
const assetQueue = [];

async function drain( queue, fn ) {
	const workers = Array.from( { length: CONCURRENCY }, async () => {
		while ( queue.length ) {
			const item = queue.shift();
			try {
				await fn( item );
			} catch ( e ) {
				failures.push( `${ item }: ${ e.message }` );
			}
		}
	} );
	await Promise.all( workers );
}

// Start from an empty output directory so pages removed or moved since the last export do not linger
// (the Spacefast state in .spacefast/ is kept).
try {
	for ( const entry of await readdir( OUT ) ) {
		if ( entry !== '.spacefast' ) {
			await rm( path.join( OUT, entry ), { recursive: true, force: true } );
		}
	}
} catch ( e ) {
	// No previous export.
}
console.log( `Crawling ${ BASE }/docs/ ...` );
await drain( pageQueue, savePage );
while ( pageQueue.length ) await drain( pageQueue, savePage ); // links found late
for ( const extra of EXTRA_ASSETS ) queueAsset( extra );
console.log( `${ pages.size } pages. Fetching assets ...` );
await drain( assetQueue, saveAsset );
await exportSearchIndex();
while ( assetQueue.length ) await drain( assetQueue, saveAsset );
console.log( `${ assets.size } assets.` );

/*
 * Shared fragments. Every page carries the same inlined stylesheets (about 69 KB) and the same per-tab
 * sidebar (about 108 KB, differing only in which rows are marked current and open). The static build
 * moves both into cached files: stylesheets become <link>s, and the sidebar becomes a script that writes
 * the tab's list during parsing (so the Interactivity API still hydrates it) followed by a short inline
 * script that marks the current page and opens its ancestors before hydration runs.
 */
import { createHash } from 'node:crypto';
const sharedCss = new Map(); // id -> { file, css }
const sidebars = new Map(); // tab -> normalized nav html
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

function normalizeSidebar( nav ) {
	return nav
		.replace( / aria-current="page"/g, '' )
		.replace( / is-on-path/g, '' )
		.replace( / is-current/g, '' )
		.replace( /&quot;open&quot;:true/g, '&quot;open&quot;:false' )
		.replace( /aria-expanded="true"/g, 'aria-expanded="false"' )
		.replace( /<ul id="(wporg-docs-sidebar-group-\d+)" class="wporg-docs-sidebar__list" data-wp-bind--hidden/g, '<ul id="$1" class="wporg-docs-sidebar__list" hidden data-wp-bind--hidden' );
}

const MARK = `<script>(function(){try{var n=document.currentScript.previousElementSibling;if(!n||!n.classList.contains("wp-block-wporg-docs-sidebar")){return;}var path=location.pathname;if(path.slice(-1)!=="/"){path+="/";}var links=n.querySelectorAll(".wporg-docs-sidebar__body a[href]");var cur=null;for(var i=0;i<links.length;i++){var a=links[i];var lp=new URL(a.href).pathname;if(lp.slice(-1)!=="/"){lp+="/";}if(lp===path){cur=a;break;}}if(!cur){return;}cur.setAttribute("aria-current","page");var li=cur.closest("li.has-children");while(li){li.classList.add("is-on-path");var b=li.querySelector(":scope > button");var u=li.querySelector(":scope > ul");if(b){b.setAttribute("aria-expanded","true");}if(u){u.removeAttribute("hidden");}try{var c=JSON.parse(li.getAttribute("data-wp-context")||"{}");c.open=true;li.setAttribute("data-wp-context",JSON.stringify(c));}catch(e){}li=li.parentElement?li.parentElement.closest("li.has-children"):null;}var s=cur.closest("li.wporg-docs-sidebar__section");if(s){s.classList.add("is-current");}}catch(e){}})();</script>`;

function shareSidebar( html, pagePath ) {
	const m = html.match( /<nav [^>]*class="[^"]*wp-block-wporg-docs-sidebar[^"]*"[\s\S]*?<\/nav>/ );
	if ( ! m ) return html;
	const tabMatch = m[ 0 ].match( /data-tab="([a-z]+)"/ );
	const tab = tabMatch ? tabMatch[ 1 ] : 'docs';
	const normalized = normalizeSidebar( m[ 0 ] );
	if ( ! sidebars.has( tab ) ) {
		sidebars.set( tab, normalized );
	} else if ( sidebars.get( tab ) !== normalized ) {
		sidebarMismatch.push( pagePath );
		return html; // keep this page's own copy
	}
	return html.replace( m[ 0 ], `<script src="/shared/sidebar-${ tab }.js"></script>${ MARK }` );
}

let bytes = 0;
for ( const [ p, raw ] of pages ) {
	const html = shareSidebar( shareStyles( raw ), p );
	const out = rewrite( html );
	bytes += Buffer.byteLength( out );
	await writeOut( path.join( p, 'index.html' ), out );
}
for ( const [ file, css ] of sharedCss ) {
	const out = rewrite( css );
	bytes += Buffer.byteLength( out );
	await writeOut( file, out );
}
for ( const [ tab, nav ] of sidebars ) {
	const out = `document.write(${ JSON.stringify( rewrite( nav ) ) });\n`;
	bytes += Buffer.byteLength( out );
	await writeOut( `/shared/sidebar-${ tab }.js`, out );
}
if ( sidebarMismatch.length ) {
	failures.push( `${ sidebarMismatch.length } pages kept their own sidebar (markup differed after normalizing): ${ sidebarMismatch.slice( 0, 5 ).join( ', ' ) }` );
}
for ( const [ p, buf ] of assets ) {
	const out = /\.(css|m?js)$/.test( p ) ? Buffer.from( rewrite( buf.toString( 'utf8' ) ) ) : buf;
	bytes += out.length;
	await writeOut( p, out );
}

// Spacefast config: / -> /docs/, old wordpress.org paths -> new pages, clean URLs.
const redirects = [ { source: '/', destination: '/docs/', status: 302 } ];
if ( REDIRECTS ) {
	try {
		const map = JSON.parse( await readFile( REDIRECTS, 'utf8' ) );
		for ( const [ from, to ] of Object.entries( map ) ) {
			if ( from !== to ) redirects.push( { source: from.replace( /\/$/, '' ) || '/', destination: to, status: 301 } );
		}
	} catch ( e ) {
		failures.push( `redirects: ${ e.message }` );
	}
}
const config = {
	$schema: 'https://spacefast.com/schemas/sf.json',
	name: 'WordPress.org documentation prototype',
	cleanUrls: true,
	listing: false,
	meta: { title: 'WordPress.org documentation prototype', description: 'A prototype of one documentation structure for WordPress.org: Markdown on GitHub, imported into WordPress, rendered with the WordPress.org theme.' },
	redirects,
};
await writeOut( 'sf.jsonc', JSON.stringify( config, null, '\t' ) + '\n' );
// A root index.html: the host redirects / to /docs/ (sf.jsonc), this covers hosts that do not, and the
// Spacefast CLI picks the publish root by where it finds an index.html.
await writeOut( 'index.html', '<!doctype html><meta charset="utf-8"><title>WordPress.org documentation prototype</title><meta http-equiv="refresh" content="0; url=/docs/"><p><a href="/docs/">Documentation</a></p>\n' );

const mdCount = pages.size;
console.log( `Wrote ${ pages.size } pages (+${ mdCount } Markdown files), ${ assets.size } assets, ${ sharedCss.size } shared stylesheets, ${ sidebars.size } shared sidebars, ${ redirects.length } redirects, ${ ( bytes / 1048576 ).toFixed( 1 ) } MB to ${ OUT }` );
if ( failures.length ) {
	console.log( `${ failures.length } failures:` );
	for ( const f of failures.slice( 0, 40 ) ) console.log( '  ' + f );
}
