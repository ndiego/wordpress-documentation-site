/**
 * Page actions: "Copy page" (the page's Markdown), its menu, the color scheme control, the
 * "Was this page helpful?" buttons and a copy button on every code block.
 */
( function () {
	const SCHEME_KEY = 'wp-docs-scheme';
	const root = document.documentElement;

	/* Clipboard, with a fallback for browsers without the async API (or without permission). */
	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( ( resolve, reject ) => {
			const area = document.createElement( 'textarea' );
			area.value = text;
			area.setAttribute( 'readonly', '' );
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild( area );
			area.select();
			try {
				document.execCommand( 'copy' ) ? resolve() : reject( new Error( 'copy' ) );
			} catch ( e ) {
				reject( e );
			}
			area.remove();
		} );
	}

	function flash( button, label, ok, done, error ) {
		button.classList.toggle( 'is-done', ok );
		if ( label ) {
			label.textContent = ok ? done : error;
		}
		window.clearTimeout( button._wpDocsTimer );
		button._wpDocsTimer = window.setTimeout( () => {
			button.classList.remove( 'is-done' );
			if ( label ) {
				label.textContent = label.getAttribute( 'data-label-default' ) || label.textContent;
			}
		}, 2000 );
	}

	/* Copy page and the menu. */
	const markdownCache = {};
	function fetchMarkdown( url ) {
		if ( ! markdownCache[ url ] ) {
			markdownCache[ url ] = window.fetch( url, { headers: { Accept: 'text/markdown' } } ).then( ( r ) => {
				if ( ! r.ok ) {
					throw new Error( r.status );
				}
				return r.text();
			} );
		}
		return markdownCache[ url ];
	}

	function closeMenus( except ) {
		document.querySelectorAll( '[data-wp-docs-actions] .wp-docs-actions__more[aria-expanded="true"]' ).forEach( ( button ) => {
			if ( button === except ) {
				return;
			}
			button.setAttribute( 'aria-expanded', 'false' );
			document.getElementById( button.getAttribute( 'aria-controls' ) ).hidden = true;
		} );
	}

	document.querySelectorAll( '[data-wp-docs-actions]' ).forEach( ( actions ) => {
		const url = actions.getAttribute( 'data-md-url' );
		const copy = actions.querySelector( '[data-copy-page]' );
		const label = copy.querySelector( '.wp-docs-actions__label' );
		const more = actions.querySelector( '.wp-docs-actions__more' );
		const menu = actions.querySelector( '[role="menu"]' );
		const items = () => Array.from( menu.querySelectorAll( '[role="menuitem"]' ) );

		if ( ! url ) {
			copy.hidden = true;
		}
		copy.addEventListener( 'click', () => {
			fetchMarkdown( url )
				.then( copyText )
				.then( () => flash( copy, label, true, label.getAttribute( 'data-label-done' ) ) )
				.catch( () => flash( copy, label, false, '', label.getAttribute( 'data-label-error' ) ) );
		} );
		// Warm the Markdown on hover so the copy is instant (and keeps the user gesture for Safari).
		copy.addEventListener( 'pointerenter', () => url && fetchMarkdown( url ).catch( () => {} ), { once: true } );

		function open( focusFirst ) {
			closeMenus( more );
			more.setAttribute( 'aria-expanded', 'true' );
			menu.hidden = false;
			if ( focusFirst ) {
				items()[ 0 ]?.focus();
			}
		}
		function close( focusButton ) {
			more.setAttribute( 'aria-expanded', 'false' );
			menu.hidden = true;
			if ( focusButton ) {
				more.focus();
			}
		}
		more.addEventListener( 'click', () => ( more.getAttribute( 'aria-expanded' ) === 'true' ? close() : open( false ) ) );
		more.addEventListener( 'keydown', ( event ) => {
			if ( event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ' ) {
				event.preventDefault();
				open( true );
			}
		} );
		menu.addEventListener( 'keydown', ( event ) => {
			const list = items();
			const index = list.indexOf( document.activeElement );
			if ( event.key === 'ArrowDown' || event.key === 'ArrowUp' ) {
				event.preventDefault();
				const next = ( index + ( event.key === 'ArrowDown' ? 1 : -1 ) + list.length ) % list.length;
				list[ next ].focus();
			} else if ( event.key === 'Home' || event.key === 'End' ) {
				event.preventDefault();
				list[ event.key === 'Home' ? 0 : list.length - 1 ].focus();
			} else if ( event.key === 'Escape' ) {
				event.preventDefault();
				close( true );
			} else if ( event.key === 'Tab' ) {
				close( false );
			}
		} );
		menu.addEventListener( 'click', ( event ) => {
			if ( event.target.closest( '[role="menuitem"]' ) ) {
				close( false );
			}
		} );
	} );

	document.addEventListener( 'click', ( event ) => {
		if ( ! event.target.closest( '[data-wp-docs-actions]' ) ) {
			closeMenus();
		}
	} );

	/* Color scheme: System (follows the OS), Light, Dark. Applied before paint by the inline head script. */
	function storedScheme() {
		try {
			const value = window.localStorage.getItem( SCHEME_KEY );
			return value === 'light' || value === 'dark' ? value : 'system';
		} catch ( e ) {
			return 'system';
		}
	}

	function applyScheme( value ) {
		if ( value === 'light' || value === 'dark' ) {
			root.setAttribute( 'data-scheme', value );
		} else {
			root.removeAttribute( 'data-scheme' );
		}
		try {
			if ( value === 'system' ) {
				window.localStorage.removeItem( SCHEME_KEY );
			} else {
				window.localStorage.setItem( SCHEME_KEY, value );
			}
		} catch ( e ) {
			// The choice lasts for this page only.
		}
		document.querySelectorAll( '[data-scheme-value]' ).forEach( ( button ) => {
			button.setAttribute( 'aria-pressed', button.getAttribute( 'data-scheme-value' ) === value ? 'true' : 'false' );
		} );
	}

	const initial = storedScheme();
	document.querySelectorAll( '[data-scheme-value]' ).forEach( ( button ) => {
		button.setAttribute( 'aria-pressed', button.getAttribute( 'data-scheme-value' ) === initial ? 'true' : 'false' );
		button.addEventListener( 'click', () => applyScheme( button.getAttribute( 'data-scheme-value' ) ) );
	} );

	/* Was this page helpful? Client-side only in the prototype. */
	document.querySelectorAll( '[data-wp-docs-feedback]' ).forEach( ( box ) => {
		const thanks = box.querySelector( '.wp-docs-feedback__thanks' );
		box.querySelectorAll( '[data-feedback]' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				const value = button.getAttribute( 'data-feedback' );
				thanks.textContent = thanks.getAttribute( 'data-thanks-' + value ) || '';
				box.classList.add( 'is-answered' );
			} );
		} );
	} );

	/* Copy buttons on code blocks. */
	const COPY_ICON = '<svg class="wp-docs-icon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path fill-rule="evenodd" clip-rule="evenodd" d="M5 4.5h11a.5.5 0 0 1 .5.5v11a.5.5 0 0 1-.5.5H5a.5.5 0 0 1-.5-.5V5a.5.5 0 0 1 .5-.5ZM3 5a2 2 0 0 1 2-2h11a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5Zm17 3v10.75c0 .69-.56 1.25-1.25 1.25H6v1.5h12.75a2.75 2.75 0 0 0 2.75-2.75V8H20Z"/></svg>';
	const CHECK_ICON = '<svg class="wp-docs-icon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M16.7 7.1l-6.3 8.5-3.3-2.5-.9 1.2 4.5 3.4L17.9 8z"/></svg>';
	document.querySelectorAll( '.wp-docs-code' ).forEach( ( frame ) => {
		const bar = frame.querySelector( '.wp-docs-code__bar' );
		const code = frame.querySelector( 'pre' );
		if ( ! bar || ! code ) {
			return;
		}
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'wp-docs-code__copy';
		button.innerHTML = COPY_ICON + '<span>Copy</span>';
		button.setAttribute( 'aria-label', 'Copy code' );
		bar.appendChild( button );
		button.addEventListener( 'click', () => {
			copyText( code.innerText.replace( /\n$/, '' ) ).then(
				() => {
					button.classList.add( 'is-done' );
					button.innerHTML = CHECK_ICON + '<span>Copied</span>';
					window.setTimeout( () => {
						button.classList.remove( 'is-done' );
						button.innerHTML = COPY_ICON + '<span>Copy</span>';
					}, 2000 );
				},
				() => {}
			);
		} );
	} );
} )();
