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

	/* The GitHub panel in the Doc sidebar: rows like the Doc panel's (label, value), then the actions. */
	var external = el( 'svg', { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 16, height: 16, fill: 'none', stroke: 'currentColor', strokeWidth: 1.5, 'aria-hidden': 'true', className: 'wp-docs-github-panel__external' },
		el( 'path', { d: 'M9.5 6.25H6.5C5.80964 6.25 5.25 6.80964 5.25 7.5V17.5C5.25 18.1904 5.80964 18.75 6.5 18.75H16.5C17.1904 18.75 17.75 18.1904 17.75 17.5V14.5M11.5 12.5L18.75 5.25M12.5 5.25H18.75V11.5' } ) );
	var pencil = el( 'svg', { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24, style: { fill: 'none' }, stroke: 'currentColor', strokeWidth: 1.5, 'aria-hidden': 'true' },
		el( 'path', { d: 'M5 19.25H12' } ), el( 'path', { d: 'M10 15L7.5 15.5L8 13L16 5L18 7L10 15Z' } ) );
	var history = el( 'svg', { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 24, height: 24, style: { fill: 'none' }, stroke: 'currentColor', strokeWidth: 1.5, 'aria-hidden': 'true' },
		el( 'path', { d: 'M7.59201 17.7565C8.8135 18.6933 10.3417 19.25 12 19.25C16.0041 19.25 19.25 16.0041 19.25 12C19.25 7.99594 16.0041 4.75 12 4.75C7.99594 4.75 4.75 7.99593 4.75 12L4.75 14M14.75 14.75L12.25 12.25L12.25 8M2 12L4.75 14L7.5 12' } ) );

	/* A label and value row, laid out like the Doc panel's own rows (the editor's PostPanelRow is not
	   exported, so this is the same markup and classes). */
	function Row( props ) {
		return el( wp.components.__experimentalHStack, { className: 'editor-post-panel__row' },
			el( 'div', { className: 'editor-post-panel__row-label' }, props.label ),
			el( 'div', { className: 'editor-post-panel__row-control' }, props.children ) );
	}
	function out( href, text ) {
		return el( 'a', { href: href, target: '_blank', rel: 'noreferrer noopener', className: 'wp-docs-github-panel__link' }, text, external );
	}
	function GitHubPanel() {
		var rows = [];
		if ( data.managed ) {
			var file = data.source_path.split( '/' ).pop();
			rows.push( el( Row, { key: 'file', label: 'File' }, out( data.source_url, file ) ) );
			rows.push( el( Row, { key: 'branch', label: 'Branch' }, out( 'https://github.com/' + data.repo + '/tree/' + data.branch, data.branch ) ) );
			rows.push( el( Row, { key: 'save', label: 'On save' },
				'commit' === data.mode ? 'Commits and publishes' : ( 'pull-request' === data.mode ? 'Opens a pull request' : 'Refused (no GitHub access)' ) ) );
			if ( data.last_push && data.last_push.url ) {
				rows.push( el( Row, { key: 'commit', label: 'Last commit' }, out( data.last_push.url, data.last_push.commit.slice( 0, 7 ) ) ) );
			}
			if ( data.pull && data.pull.url ) {
				rows.push( el( Row, { key: 'pull', label: 'Pull request' }, out( data.pull.url, '#' + data.pull.number ) ) );
			}
			rows.push( el( wp.components.__experimentalHStack, { key: 'actions', spacing: 2, justify: 'flex-start', className: 'wp-docs-github-panel__actions' },
				el( wp.components.Button, { variant: 'secondary', size: 'compact', icon: pencil, href: data.edit_url, target: '_blank', rel: 'noreferrer noopener' }, 'Edit on GitHub' ),
				el( wp.components.Button, { variant: 'secondary', size: 'compact', icon: history, href: data.history_url, target: '_blank', rel: 'noreferrer noopener' }, 'History' ) ) );
			if ( 'none' === data.mode ) {
				rows.push( el( wp.components.Notice, { key: 'none', status: 'warning', isDismissible: false }, 'This site cannot write to GitHub. Edit the doc there; changes made here are refused.' ) );
			}
		} else {
			rows.push( el( Row, { key: 'file', label: 'File' }, el( 'span', { className: 'wp-docs-github-panel__muted' }, 'Written on publish' ) ) );
			rows.push( el( Row, { key: 'branch', label: 'Branch' }, out( 'https://github.com/' + data.repo + '/tree/' + data.branch, data.branch ) ) );
			rows.push( el( Row, { key: 'save', label: 'On publish' }, 'commit' === data.mode ? 'Commits and publishes' : 'Opens a pull request' ) );
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
