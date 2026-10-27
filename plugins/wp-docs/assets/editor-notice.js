/**
 * The block editor on a doc: the GitHub panel in the Doc sidebar (where the doc lives, what a save does,
 * the links), the pre-publish check that a new doc has a place in the tree, and the pull request notice.
 *
 * Data comes from wpDocsEditor (editor-notice.php).
 */
( function ( wp, data ) {
	if ( ! wp || ! wp.data || ! data ) {
		return;
	}
	var el = wp.element.createElement;
	var notices = wp.data.dispatch( 'core/notices' );
	var editor = wp.data.dispatch( 'core/editor' );
	var LOCK = 'wp-docs-parent';

	/* The GitHub panel in the Doc sidebar: where the page lives, what a save does, the links. */
	function link( href, text ) {
		return el( 'a', { href: href, target: '_blank', rel: 'noreferrer noopener' }, text );
	}
	function GitHubPanel() {
		var pull = data.pull;
		var push = data.last_push;
		var rows = [];
		if ( data.managed ) {
			rows.push( el( 'p', { key: 'where' }, 'This doc is ', link( data.source_url, data.source_path ), ' in ', data.repo, ' (', data.branch, ').' ) );
			if ( 'commit' === data.mode ) {
				rows.push( el( 'p', { key: 'mode' }, 'Saving commits your change and publishes it here right away. Rename, move, reorder or unpublish the doc on GitHub.' ) );
			} else if ( 'pull-request' === data.mode ) {
				rows.push( el( 'p', { key: 'mode' }, 'Saving opens a pull request with your change (or updates yours). The doc changes here when it is merged.' ) );
			} else {
				rows.push( el( wp.components.Notice, { key: 'mode', status: 'warning', isDismissible: false }, 'This site cannot write to GitHub, so changes made here are refused. Edit the doc on GitHub.' ) );
			}
			var links = [ link( data.edit_url, 'Edit on GitHub' ), ' \u00b7 ', link( data.history_url, 'History' ) ];
			if ( pull && pull.url ) {
				links.push( ' \u00b7 ', link( pull.url, 'Pull request #' + pull.number ) );
			}
			if ( push && push.url ) {
				links.push( ' \u00b7 ', link( push.url, 'Last commit ' + push.commit.slice( 0, 7 ) ) );
			}
			rows.push( el( 'p', { key: 'links' }, links ) );
		} else {
			rows.push( el( 'p', { key: 'new' }, 'This doc is not in the content repository yet. Publishing writes it there as a Markdown file, in the section chosen under Doc location.' ) );
		}
		return el( wp.editor.PluginDocumentSettingPanel, { name: 'wp-docs-github', title: 'GitHub', className: 'wp-docs-github-panel' }, rows );
	}

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

	if ( ! wp.plugins || ! wp.editor || ! wp.editor.PluginDocumentSettingPanel ) {
		return;
	}
	if ( data.managed ) {
		wp.plugins.registerPlugin( 'wp-docs', {
			render: function () {
				return el( GitHubPanel );
			},
		} );
		return;
	}

	/* Pre-publish check for a doc written here: it needs a section (or the top level) before it can get a
	   file. The Publish button stays disabled until one is chosen in the panel. */
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
		// The `pages` icon of the Gutenberg icon library (@wordpress/icons), the same one the admin menu uses.
		var icon = el( 'svg', { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24, fill: 'none', stroke: 'currentColor', strokeWidth: 1.5, 'aria-hidden': 'true' },
			el( 'path', { d: 'M7.75 20.25H19C19.6904 20.25 20.25 19.6904 20.25 19V5.75' } ),
			el( 'path', { d: 'M7.5 6.25H14.5M7.5 9.75H14.5M7.5 13.25H14.5M17.25 16V4C17.25 3.30964 16.6904 2.75 16 2.75H6C5.30964 2.75 4.75 3.30964 4.75 4L4.75 16C4.75 16.6904 5.30964 17.25 6 17.25H16C16.6904 17.25 17.25 16.6904 17.25 16Z' } )
		);
		return el(
			wp.editor.PluginPrePublishPanel,
			{ title: 'Doc location', initialOpen: true, icon: icon },
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
			return el( wp.element.Fragment, null, el( GitHubPanel ), el( Panel ) );
		},
	} );
} )( window.wp, window.wpDocsEditor );
