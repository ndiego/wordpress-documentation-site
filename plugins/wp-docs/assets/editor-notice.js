/**
 * The block editor on a doc: the notice that says where a save goes, the pre-publish check that a new
 * doc has a place in the tree, and the pull request notice.
 *
 * Data comes from wpDocsEditor (editor-notice.php): { mode, text, actions, managed, sections, home }.
 */
( function ( wp, data ) {
	if ( ! wp || ! wp.data || ! data ) {
		return;
	}
	var el = wp.element.createElement;
	var notices = wp.data.dispatch( 'core/notices' );
	var editor = wp.data.dispatch( 'core/editor' );
	var LOCK = 'wp-docs-parent';

	/* The notice. */
	wp.domReady( function () {
		var method = 'commit' === data.mode ? 'createInfoNotice' : 'createWarningNotice';
		notices[ method ]( data.text, {
			id: 'wp-docs-maintained-on-github',
			isDismissible: false,
			actions: data.actions || [],
		} );
	} );

	/* A proposal comes back from the REST API as an error (the save did not happen), which the editor
	   shows as "Updating failed." Replace that notice with one that reads as what it is. */
	var marker = 'Proposed as pull request #';
	wp.data.subscribe( function () {
		var list = wp.data.select( 'core/notices' ).getNotices();
		for ( var i = 0; i < list.length; i++ ) {
			var notice = list[ i ];
			if ( 'error' !== notice.status || 'string' !== typeof notice.content || notice.content.indexOf( marker ) < 0 ) {
				continue;
			}
			var match = notice.content.match( /pull request #(\d+) \((https?:\/\/[^)\s]+)\)/ );
			notices.removeNotice( notice.id );
			notices.createInfoNotice( notice.content.replace( /^Updating failed\.\s*/, '' ).replace( / \((https?:\/\/[^)\s]+)\)/, '' ), {
				id: 'wp-docs-proposed',
				isDismissible: true,
				actions: match ? [ { label: 'Pull request #' + match[ 1 ], url: match[ 2 ] } ] : [],
			} );
		}
	} );

	/* Pre-publish check for a doc written here: it needs a section (or the top level) before it can get a
	   file. The Publish button stays disabled until one is chosen in the panel. */
	if ( data.managed || ! wp.plugins || ! wp.editor || ! wp.editor.PluginPrePublishPanel ) {
		return;
	}
	var allowed = {};
	( data.sections || [] ).forEach( function ( s ) {
		allowed[ s.id ] = true;
	} );
	if ( data.home ) {
		allowed[ data.home ] = true;
	}

	function hasPlace( parent ) {
		return !! allowed[ parent ];
	}

	var locked = false;
	wp.data.subscribe( function () {
		var sel = wp.data.select( 'core/editor' );
		var opened = sel.isPublishSidebarOpened && sel.isPublishSidebarOpened();
		var shouldLock = !! opened && ! hasPlace( sel.getEditedPostAttribute( 'parent' ) );
		if ( shouldLock === locked ) {
			return; // Dispatching from a subscriber re-enters it; only act on a change.
		}
		locked = shouldLock;
		if ( shouldLock ) {
			editor.lockPostSaving( LOCK );
		} else {
			editor.unlockPostSaving( LOCK );
		}
	} );

	function Panel() {
		var parent = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'parent' );
		}, [] );
		var editPost = wp.data.useDispatch( 'core/editor' ).editPost;
		var options = [ { label: 'Choose a place', value: '' } ];
		if ( data.home ) {
			options.push( { label: 'Top level', value: String( data.home ) } );
		}
		( data.sections || [] ).forEach( function ( s ) {
			options.push( { label: new Array( s.depth + 1 ).join( ' ' ) + s.title, value: String( s.id ) } );
		} );
		var ok = hasPlace( parent );
		return el(
			wp.editor.PluginPrePublishPanel,
			{ title: 'Where the doc lives', initialOpen: true, icon: 'book-alt' },
			el( 'p', null, ok
				? 'Publishing writes this doc to the content repository as a Markdown file in the section below, and it goes live here.'
				: 'Choose the section this doc belongs to. Publishing writes it to the content repository as a Markdown file there.' ),
			el( wp.components.SelectControl, {
				label: 'Section',
				value: ok ? String( parent ) : '',
				options: options,
				__nextHasNoMarginBottom: true,
				onChange: function ( value ) {
					editPost( { parent: value ? parseInt( value, 10 ) : 0 } );
				},
			} )
		);
	}

	wp.plugins.registerPlugin( 'wp-docs', {
		render: function () {
			return el( Panel );
		},
	} );
} )( window.wp, window.wpDocsEditor );
