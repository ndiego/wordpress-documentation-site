/**
 * ⌘K search palette. Adapted from the WordPress.org Docs hub theme (wporg-docs-2026), simplified to one
 * scope. Plain script, no build step.
 *
 * Opens on ⌘K / Ctrl+K, on "/" outside a field, from the docs bar trigger, or from the home page search
 * field. The index (the plugin's /wp-json/wp-docs/v1/search-index: title, path, url, section, type,
 * breadcrumb, description, headings) is fetched once, on first open or when the browser is idle. Matching
 * is client-side: every query word must match somewhere; the title scores far above a heading, which
 * scores above the breadcrumb or description. Results are grouped by section. Recent picks are kept in
 * localStorage.
 */
( function () {
	const trigger = document.querySelector( '[data-wp-docs-search]' );
	if ( ! trigger ) {
		return;
	}
	const INDEX_URL = trigger.getAttribute( 'data-index-url' );
	const FALLBACK_URL = trigger.getAttribute( 'data-fallback-url' );
	const SEARCH_URL = trigger.getAttribute( 'data-search-url' ) || '';
	let SECTIONS = {};
	try {
		SECTIONS = JSON.parse( trigger.getAttribute( 'data-sections' ) || '{}' ) || {};
	} catch ( e ) {
		SECTIONS = {};
	}
	const RECENT_KEY = 'wp-docs-search:recent';
	const MAX_RESULTS = 24;
	const MAC = /Mac|iPhone|iPad/.test( navigator.platform || navigator.userAgent );

	const ICON_SEARCH = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M13 5c-3.3 0-6 2.7-6 6 0 1.4.5 2.7 1.3 3.7l-3.8 3.8 1.1 1.1 3.8-3.8c1 .8 2.3 1.3 3.7 1.3 3.3 0 6-2.7 6-6S16.3 5 13 5zm0 10.5c-2.5 0-4.5-2-4.5-4.5s2-4.5 4.5-4.5 4.5 2 4.5 4.5-2 4.5-4.5 4.5z"></path></svg>';
	const ICON_PAGE = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15.5 4H6v16h12V7.5L15.5 4zM16.5 18.5h-9v-13h7v3h2v10zM8.5 10h7v1.5h-7V10zm0 3h7v1.5h-7V13zm0 3h4v1.5h-4V16z"></path></svg>';
	const ICON_HASH = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9.6 4h1.5l-.7 4.25h4.5L15.6 4h1.5l-.7 4.25H20v1.5h-3.85l-.75 4.5H19v1.5h-3.85L14.4 20h-1.5l.75-4.25h-4.5L8.4 20H6.9l.75-4.25H4v-1.5h3.9l.75-4.5H5v-1.5h3.9L9.6 4Zm.55 5.75-.75 4.5h4.5l.75-4.5h-4.5Z"></path></svg>';
	const ICON_CLOCK = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3.3c-4.8 0-8.8 3.9-8.8 8.8 0 4.8 3.9 8.8 8.8 8.8 4.8 0 8.8-3.9 8.8-8.8 0-4.8-4-8.8-8.8-8.8zm0 16c-4 0-7.2-3.3-7.2-7.2C4.8 8 8 4.8 12 4.8s7.2 3.3 7.2 7.2c0 4-3.2 7.3-7.2 7.3zm.8-7.6V7h-1.5v5.4l3.4 2.2.8-1.3-2.7-1.6z"></path></svg>';

	let dialog, input, list, status;
	let index = null;
	let loading = null;
	let items = [];
	let active = -1;

	const storage = {
		read() {
			try {
				return JSON.parse( window.localStorage.getItem( RECENT_KEY ) || '[]' ) || [];
			} catch ( e ) {
				return [];
			}
		},
		write( value ) {
			try {
				window.localStorage.setItem( RECENT_KEY, JSON.stringify( value.slice( 0, 6 ) ) );
			} catch ( e ) {
				// Fine without.
			}
		},
	};

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"]/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ] ) );
	}

	function normalize( s ) {
		return String( s || '' ).toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' );
	}

	function highlight( text, words ) {
		let html = escapeHtml( text );
		for ( const w of words ) {
			if ( w.length < 2 ) {
				continue;
			}
			const re = new RegExp( '(' + w.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + ')', 'ig' );
			html = html.replace( re, '<mark>$1</mark>' );
		}
		return html;
	}

	function sectionLabel( section ) {
		return SECTIONS[ section ] || ( section ? section.replace( /-/g, ' ' ).replace( /^./, ( c ) => c.toUpperCase() ) : 'Documentation' );
	}

	/* One record per page, from the plugin's index (tolerant of a string breadcrumb or string headings). */
	function prepare( data ) {
		const records = Array.isArray( data ) ? data : ( data && ( data.pages || data.records ) ) || [];
		return records.map( ( r ) => {
			let crumbs = r.breadcrumb || [];
			if ( typeof crumbs === 'string' ) {
				crumbs = crumbs.split( /\s*[›>\/]\s*/ ).filter( Boolean );
			}
			crumbs = crumbs.map( ( c ) => ( typeof c === 'string' ? c : c.title || '' ) ).filter( Boolean );
			const headings = ( r.headings || [] ).map( ( h ) => ( typeof h === 'string' ? { text: h, anchor: '' } : h ) );
			return {
				t: r.title || '',
				u: r.url || '',
				s: r.section || '',
				b: crumbs,
				d: r.description || '',
				h: headings,
				nt: normalize( r.title ),
				nh: headings.map( ( h ) => normalize( h.text ) ),
				nb: normalize( crumbs.join( ' ' ) + ' ' + sectionLabel( r.section ) ),
				nd: normalize( r.description ),
			};
		} );
	}

	let cssReady = null;
	function loadCss() {
		if ( ! cssReady ) {
			cssReady = new Promise( ( resolve ) => {
				if ( ! window.wpDocsPaletteCss ) {
					resolve();
					return;
				}
				const link = document.createElement( 'link' );
				link.id = 'wp-docs-search-palette-css';
				link.rel = 'stylesheet';
				link.href = window.wpDocsPaletteCss;
				link.onload = link.onerror = () => resolve();
				document.head.appendChild( link );
				// Never hold the palette back for long.
				window.setTimeout( resolve, 400 );
			} );
		}
		return cssReady;
	}

	function build() {
		loadCss();
		dialog = document.createElement( 'dialog' );
		dialog.className = 'wp-docs-search';
		dialog.setAttribute( 'aria-label', 'Search documentation' );
		dialog.innerHTML =
			'<form class="wp-docs-search__form" role="search">' + ICON_SEARCH +
			'<input class="wp-docs-search__input" type="search" placeholder="Search documentation" autocomplete="off" autocorrect="off" spellcheck="false" aria-label="Search documentation" aria-controls="wp-docs-search-results" aria-expanded="true" role="combobox" aria-autocomplete="list">' +
			'<button type="button" class="wp-docs-search__close" aria-label="Close search"><kbd>esc</kbd></button>' +
			'</form>' +
			'<ul class="wp-docs-search__results" id="wp-docs-search-results" role="listbox" aria-label="Results"></ul>' +
			'<div class="wp-docs-search__footer"><span class="wp-docs-search__status" aria-live="polite"></span>' +
			'<span class="wp-docs-search__hint"><span><kbd>↑</kbd><kbd>↓</kbd> to navigate</span><span><kbd>↵</kbd> to open</span></span></div>';
		document.body.appendChild( dialog );
		input = dialog.querySelector( 'input' );
		list = dialog.querySelector( 'ul' );
		status = dialog.querySelector( '.wp-docs-search__status' );

		dialog.querySelector( 'form' ).addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			if ( active >= 0 && items[ active ] ) {
				choose( items[ active ] );
			} else if ( SEARCH_URL && input.value.trim() ) {
				window.location.href = SEARCH_URL + encodeURIComponent( input.value.trim() );
			}
		} );
		dialog.querySelector( '.wp-docs-search__close' ).addEventListener( 'click', close );
		input.addEventListener( 'input', () => render() );
		input.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
				e.preventDefault();
				move( e.key === 'ArrowDown' ? 1 : -1 );
			} else if ( e.key === 'Escape' ) {
				// A search input swallows the first Escape to clear itself; close on the first one.
				e.preventDefault();
				close();
			}
		} );
		list.addEventListener( 'mousemove', ( e ) => {
			const li = e.target.closest( '.wp-docs-search__item' );
			if ( li && Number( li.dataset.i ) !== active ) {
				setActive( Number( li.dataset.i ), false );
			}
		} );
		list.addEventListener( 'click', ( e ) => {
			const a = e.target.closest( 'a' );
			if ( a && ! e.metaKey && ! e.ctrlKey ) {
				e.preventDefault();
				choose( items[ Number( a.closest( 'li' ).dataset.i ) ] );
			}
		} );
		dialog.addEventListener( 'click', ( e ) => {
			if ( e.target === dialog ) {
				close();
			}
		} );
		dialog.addEventListener( 'close', () => {
			document.documentElement.classList.remove( 'has-search-open' );
		} );
	}

	function fetchIndex( url ) {
		return window.fetch( url, { credentials: 'omit' } ).then( ( r ) => {
			if ( ! r.ok ) {
				throw new Error( r.status );
			}
			return r.json();
		} );
	}

	function load() {
		if ( index ) {
			return Promise.resolve( index );
		}
		if ( ! loading ) {
			if ( status ) {
				status.textContent = 'Loading the index…';
			}
			loading = fetchIndex( INDEX_URL )
				.catch( () => ( FALLBACK_URL ? fetchIndex( FALLBACK_URL ) : Promise.reject() ) )
				.then( ( data ) => {
					index = prepare( data );
					return index;
				} )
				.catch( () => {
					if ( status ) {
						status.textContent = 'The search index could not be loaded.';
					}
					index = [];
					return index;
				} );
		}
		return loading;
	}

	function open( query ) {
		if ( ! dialog ) {
			build();
		}
		cssReady.then( () => show( query ) );
	}

	function show( query ) {
		if ( ! dialog.open ) {
			document.documentElement.classList.add( 'has-search-open' );
			dialog.showModal();
		}
		if ( typeof query === 'string' && query ) {
			input.value = query;
		}
		input.focus();
		if ( ! query ) {
			input.select();
		}
		load().then( () => render() );
	}

	function close() {
		if ( dialog && dialog.open ) {
			dialog.close();
		}
	}

	function choose( item ) {
		if ( ! item ) {
			return;
		}
		const recent = storage.read().filter( ( r ) => r.u !== item.u );
		recent.unshift( { t: item.t, u: item.u, s: item.s, b: item.b || [] } );
		storage.write( recent );
		window.location.href = item.u + ( item.anchor ? '#' + item.anchor : '' );
	}

	/* Score an entry against the query words. 0 means no match. */
	function score( e, words, full ) {
		let total = 0;
		let heading = null;
		let misses = 0;
		const allowed = words.length >= 3 ? 1 : 0;
		const titleWords = e.nt.split( /[^a-z0-9.]+/ );
		for ( const w of words ) {
			let best = 0;
			if ( e.nt === full ) {
				best = 200;
			} else if ( titleWords.includes( w ) ) {
				best = 90;
			} else if ( e.nt.startsWith( w ) ) {
				best = 60;
			} else if ( e.nt.includes( ' ' + w ) ) {
				best = 45;
			} else if ( e.nt.includes( w ) ) {
				best = 30;
			}
			if ( best < 25 ) {
				e.nh.forEach( ( h, i ) => {
					if ( h.includes( w ) ) {
						const s = h.startsWith( w ) ? 26 : 20;
						if ( s > best ) {
							best = s;
						}
						if ( ! heading ) {
							heading = e.h[ i ];
						}
					}
				} );
			}
			if ( best < 15 && e.nb.includes( w ) ) {
				best = 15;
			}
			if ( best < 8 && e.nd.includes( w ) ) {
				best = 8;
			}
			if ( ! best ) {
				misses += 1;
				if ( misses > allowed ) {
					return null;
				}
				total -= 15;
				continue;
			}
			total += best;
		}
		if ( words.length > 1 && e.nt.includes( full ) ) {
			total += 40;
		}
		// A whole phrase in a heading: point straight at it.
		if ( words.length > 1 ) {
			const i = e.nh.findIndex( ( h ) => h.includes( full ) );
			if ( i >= 0 ) {
				total += 20;
				heading = e.h[ i ];
			}
		}
		total += Math.max( 0, 10 - e.nt.length / 6 );
		return total > 0 ? { s: total, heading } : null;
	}

	function search( query ) {
		const full = normalize( query ).trim();
		const words = full.split( /\s+/ ).filter( Boolean );
		if ( ! words.length ) {
			return [];
		}
		const out = [];
		for ( const e of index ) {
			const r = score( e, words, full );
			if ( r ) {
				out.push( Object.assign( {}, e, { score: r.s, heading: r.heading, anchor: r.heading ? r.heading.anchor : '' } ) );
			}
		}
		out.sort( ( a, b ) => b.score - a.score || a.t.localeCompare( b.t ) );
		// One group per section, groups ordered by their best hit.
		const top = out.slice( 0, MAX_RESULTS );
		const order = [];
		top.forEach( ( r ) => {
			if ( ! order.includes( r.s ) ) {
				order.push( r.s );
			}
		} );
		return order.flatMap( ( s ) => top.filter( ( r ) => r.s === s ) );
	}

	function render() {
		if ( ! index ) {
			return;
		}
		const query = input.value.trim();
		const words = normalize( query ).split( /\s+/ ).filter( Boolean );
		let recent = false;
		if ( query ) {
			items = search( query );
			status.textContent = items.length ? ( items.length === MAX_RESULTS ? 'Top ' + MAX_RESULTS + ' results' : items.length + ( items.length === 1 ? ' result' : ' results' ) ) : 'No results';
		} else {
			items = storage.read();
			recent = items.length > 0;
			status.textContent = index.length ? index.length.toLocaleString() + ' pages' : '';
		}
		if ( ! items.length ) {
			list.innerHTML = '<li class="wp-docs-search__empty" role="presentation">' + ( query
				? 'Nothing matches “' + escapeHtml( query ) + '”.' + ( SEARCH_URL ? ' Press <kbd>↵</kbd> to search the full text.' : '' )
				: 'Search titles, headings and descriptions across the documentation.' ) + '</li>';
			active = -1;
			input.removeAttribute( 'aria-activedescendant' );
			return;
		}
		const html = [];
		let lastGroup = null;
		items.forEach( ( item, i ) => {
			const group = recent ? 'Recent' : sectionLabel( item.s );
			if ( group !== lastGroup ) {
				html.push( '<li class="wp-docs-search__group" role="presentation">' + escapeHtml( group ) + '</li>' );
				lastGroup = group;
			}
			const crumbs = ( item.b || [] ).slice();
			if ( crumbs[ crumbs.length - 1 ] === item.t ) {
				crumbs.pop();
			}
			const meta = crumbs.map( ( c ) => highlight( c, words ) ).join( '<span class="sep">›</span>' );
			const sub = item.heading && query
				? '<span class="wp-docs-search__heading"><span class="sep">#</span>' + highlight( item.heading.text, words ) + '</span>'
				: '';
			const desc = ! sub && query && item.d ? '<span class="wp-docs-search__desc">' + highlight( item.d, words ) + '</span>' : '';
			const href = item.u + ( item.anchor ? '#' + item.anchor : '' );
			html.push(
				'<li class="wp-docs-search__item" role="option" data-i="' + i + '" id="wp-docs-search-item-' + i + '" aria-selected="false">' +
				'<a href="' + escapeHtml( href ) + '" tabindex="-1">' + ( recent ? ICON_CLOCK : ( sub ? ICON_HASH : ICON_PAGE ) ) +
				'<span class="wp-docs-search__body"><span class="wp-docs-search__title">' + highlight( item.t, words ) + '</span>' +
				( meta ? '<span class="wp-docs-search__meta">' + meta + '</span>' : '' ) + sub + desc + '</span>' +
				'<span class="wp-docs-search__enter" aria-hidden="true">↵</span></a></li>'
			);
		} );
		list.innerHTML = html.join( '' );
		setActive( 0, false );
	}

	function setActive( i, scroll ) {
		active = i;
		list.querySelectorAll( '.wp-docs-search__item' ).forEach( ( li ) => {
			const on = Number( li.dataset.i ) === i;
			li.classList.toggle( 'is-active', on );
			li.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			if ( on ) {
				input.setAttribute( 'aria-activedescendant', li.id );
				if ( scroll ) {
					li.scrollIntoView( { block: 'nearest' } );
				}
			}
		} );
	}

	function move( delta ) {
		if ( ! items.length ) {
			return;
		}
		setActive( ( active + delta + items.length ) % items.length, true );
	}

	trigger.addEventListener( 'click', () => open() );
	const kbd = trigger.querySelector( 'kbd' );
	if ( kbd ) {
		kbd.textContent = MAC ? '⌘K' : 'Ctrl K';
	}

	// The home page search field opens the palette with what was typed.
	document.querySelectorAll( '[data-wp-docs-search-form]' ).forEach( ( form ) => {
		const field = form.querySelector( 'input[type="search"]' );
		const formKbd = form.querySelector( 'kbd' );
		if ( formKbd ) {
			formKbd.textContent = MAC ? '⌘K' : 'Ctrl K';
		}
		if ( ! field ) {
			return;
		}
		const handoff = ( e ) => {
			if ( e ) {
				e.preventDefault();
			}
			const value = field.value;
			field.value = '';
			field.blur();
			open( value );
		};
		field.addEventListener( 'focus', () => handoff() );
		field.addEventListener( 'input', () => handoff() );
		form.addEventListener( 'submit', handoff );
	} );

	document.addEventListener( 'keydown', ( e ) => {
		if ( ( e.key === 'k' || e.key === 'K' ) && ( e.metaKey || e.ctrlKey ) ) {
			e.preventDefault();
			if ( dialog && dialog.open ) {
				close();
			} else {
				open();
			}
			return;
		}
		if ( e.key === '/' && ! e.metaKey && ! e.ctrlKey && ! e.altKey ) {
			const t = e.target;
			const typing = t && ( t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test( t.tagName ) );
			if ( ! typing && ! ( dialog && dialog.open ) ) {
				e.preventDefault();
				open();
			}
		}
	} );

	window.wpDocsSearch = { open };

	// Warm the index when the browser is idle, so the first search is instant.
	if ( 'requestIdleCallback' in window ) {
		window.requestIdleCallback( () => {
			loadCss();
			load();
		}, { timeout: 5000 } );
	}
} )();
