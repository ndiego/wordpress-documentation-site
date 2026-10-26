/**
 * Documentation sidebar: group and page toggles (remembered in sessionStorage), the drawer below 782px,
 * and scrolling the current page into view. The markup is complete without this file.
 */
( function () {
	const sidebar = document.querySelector( '[data-wp-docs-sidebar]' );
	if ( ! sidebar ) {
		return;
	}
	const KEY = 'wp-docs-nav';
	const scroller = sidebar.querySelector( '[data-wp-docs-sidebar-scroll]' );
	const backdrop = document.querySelector( '.wp-docs-sidebar-backdrop' );
	const menuButtons = Array.from( document.querySelectorAll( '[data-wp-docs-menu]' ) );
	const drawerQuery = window.matchMedia( '(max-width: 781px)' );
	let lastFocus = null;

	function read() {
		try {
			return JSON.parse( window.sessionStorage.getItem( KEY ) || '{}' ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function write( id, open ) {
		const state = read();
		state[ id ] = open ? 1 : 0;
		try {
			window.sessionStorage.setItem( KEY, JSON.stringify( state ) );
		} catch ( e ) {
			// The list starts from the server-rendered state next time.
		}
	}

	function setOpen( li, open ) {
		const button = li.querySelector( ':scope > div > .wp-docs-nav__toggle' );
		const list = li.querySelector( ':scope > .wp-docs-nav__list' );
		if ( ! button || ! list ) {
			return;
		}
		button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		list.hidden = ! open;
		li.classList.toggle( 'is-open', open );
	}

	// Restore what the reader opened or closed on earlier pages. The current path always stays open.
	const saved = read();
	sidebar.querySelectorAll( 'li[data-nav-id]' ).forEach( ( li ) => {
		const id = li.getAttribute( 'data-nav-id' );
		if ( li.hasAttribute( 'data-on-path' ) || ! ( id in saved ) ) {
			return;
		}
		setOpen( li, saved[ id ] === 1 );
	} );

	sidebar.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '.wp-docs-nav__toggle' );
		if ( ! button ) {
			return;
		}
		const li = button.closest( 'li[data-nav-id]' );
		const open = button.getAttribute( 'aria-expanded' ) !== 'true';
		setOpen( li, open );
		write( li.getAttribute( 'data-nav-id' ), open );
	} );

	// Bring the current page into view inside the sidebar's own scroll area, without moving the page.
	function revealCurrent() {
		const current = sidebar.querySelector( '[aria-current="page"]' );
		if ( ! current || ! scroller || scroller.scrollHeight <= scroller.clientHeight ) {
			return;
		}
		const area = scroller.getBoundingClientRect();
		const rect = current.getBoundingClientRect();
		if ( rect.top < area.top + 16 || rect.bottom > area.bottom - 16 ) {
			scroller.scrollTop += rect.top - area.top - scroller.clientHeight / 3;
		}
	}
	revealCurrent();

	// The drawer.
	function focusables() {
		return Array.from( sidebar.querySelectorAll( 'a[href], button:not([hidden])' ) ).filter( ( el ) => el.offsetParent !== null );
	}

	function openDrawer() {
		lastFocus = document.activeElement;
		sidebar.classList.add( 'is-open' );
		document.documentElement.classList.add( 'has-drawer-open' );
		if ( backdrop ) {
			backdrop.hidden = false;
		}
		menuButtons.forEach( ( b ) => b.setAttribute( 'aria-expanded', 'true' ) );
		sidebar.setAttribute( 'role', 'dialog' );
		sidebar.setAttribute( 'aria-modal', 'true' );
		window.requestAnimationFrame( () => {
			revealCurrent();
			const close = sidebar.querySelector( '[data-wp-docs-menu-close]' );
			( close || focusables()[ 0 ] )?.focus();
		} );
	}

	function closeDrawer( restoreFocus = true ) {
		if ( ! sidebar.classList.contains( 'is-open' ) ) {
			return;
		}
		sidebar.classList.remove( 'is-open' );
		document.documentElement.classList.remove( 'has-drawer-open' );
		if ( backdrop ) {
			backdrop.hidden = true;
		}
		menuButtons.forEach( ( b ) => b.setAttribute( 'aria-expanded', 'false' ) );
		sidebar.removeAttribute( 'role' );
		sidebar.removeAttribute( 'aria-modal' );
		if ( restoreFocus && lastFocus && lastFocus.focus ) {
			lastFocus.focus();
		}
	}

	menuButtons.forEach( ( button ) => {
		button.addEventListener( 'click', () => ( sidebar.classList.contains( 'is-open' ) ? closeDrawer() : openDrawer() ) );
	} );
	document.querySelectorAll( '[data-wp-docs-menu-close]' ).forEach( ( el ) => el.addEventListener( 'click', () => closeDrawer() ) );

	document.addEventListener( 'keydown', ( event ) => {
		if ( ! sidebar.classList.contains( 'is-open' ) ) {
			return;
		}
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			closeDrawer();
		} else if ( event.key === 'Tab' ) {
			const items = focusables();
			if ( ! items.length ) {
				return;
			}
			const first = items[ 0 ];
			const last = items[ items.length - 1 ];
			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		}
	} );

	drawerQuery.addEventListener( 'change', ( event ) => {
		if ( ! event.matches ) {
			closeDrawer( false );
		}
	} );
} )();
