/**
 * The notice in the block editor that says where a save goes, and the pull request notice.
 *
 * Data comes from wpDocsEditor (editor-notice.php): { mode, text, actions }. When a save is answered
 * with the `wp_docs_proposed` error, the editor's "Updating failed" notice is replaced by one that says
 * what happened and links to the pull request.
 */
( function ( wp, data ) {
	if ( ! wp || ! wp.data || ! data ) {
		return;
	}
	var notices = wp.data.dispatch( 'core/notices' );
	var select = wp.data.select( 'core/notices' );

	wp.domReady( function () {
		var method = 'commit' === data.mode ? 'createInfoNotice' : 'createWarningNotice';
		notices[ method ]( data.text, {
			id: 'wp-docs-maintained-on-github',
			isDismissible: false,
			actions: data.actions || [],
		} );
	} );

	// A proposal is reported by the REST API as an error (the save did not happen), which the editor shows
	// as "Updating failed." Replace that notice with one that reads as what it is.
	var marker = 'Proposed as pull request #';
	wp.data.subscribe( function () {
		var list = select.getNotices();
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
} )( window.wp, window.wpDocsEditor );
