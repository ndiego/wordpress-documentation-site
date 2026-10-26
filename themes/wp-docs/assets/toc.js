/**
 * Scroll-spy for "On this page": the heading nearest above the top third of the window is current.
 */
( function () {
	const links = Array.from( document.querySelectorAll( '[data-toc-link]' ) );
	if ( ! links.length ) {
		return;
	}
	const ids = Array.from( new Set( links.map( ( a ) => decodeURIComponent( a.getAttribute( 'href' ).slice( 1 ) ) ) ) );
	const headings = ids.map( ( id ) => document.getElementById( id ) ).filter( Boolean );
	if ( ! headings.length ) {
		return;
	}
	let current = null;
	let ticking = false;

	function setCurrent( id ) {
		if ( id === current ) {
			return;
		}
		current = id;
		links.forEach( ( a ) => {
			const on = decodeURIComponent( a.getAttribute( 'href' ).slice( 1 ) ) === id;
			a.classList.toggle( 'is-active', on );
			if ( on ) {
				a.setAttribute( 'aria-current', 'location' );
			} else {
				a.removeAttribute( 'aria-current' );
			}
		} );
	}

	function update() {
		ticking = false;
		const line = window.innerHeight / 3;
		let id = headings[ 0 ].id;
		for ( const h of headings ) {
			if ( h.getBoundingClientRect().top - line <= 0 ) {
				id = h.id;
			} else {
				break;
			}
		}
		// At the end of the page the last heading may never reach the line.
		if ( window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4 ) {
			id = headings[ headings.length - 1 ].id;
		}
		setCurrent( id );
	}

	window.addEventListener( 'scroll', () => {
		if ( ! ticking ) {
			ticking = true;
			window.requestAnimationFrame( update );
		}
	}, { passive: true } );
	window.addEventListener( 'resize', update );
	update();

	// Close the mobile disclosure after a pick.
	document.querySelectorAll( '.wp-docs-toc-disclosure a' ).forEach( ( a ) => {
		a.addEventListener( 'click', () => a.closest( 'details' ).removeAttribute( 'open' ) );
	} );
} )();
