<?php
/**
 * Change (f): provenance links for the theme.
 *
 * @package WordPressdotorg\Docs
 */

defined( 'WPINC' ) || die();

/**
 * Edit, source and issue links of a docs page, with the imported commit and time.
 *
 * Keys follow the theme's expectations; the short aliases `edit`, `issue` and `source` are included
 * for convenience. Values are empty strings when unknown (local imports have no commit).
 *
 * @param int|WP_Post|null $post Post.
 * @return array{edit_url: string, issue_url: string, source_url: string, commit: string, imported: string, edit: string, issue: string, source: string}
 */
function wporg_docs_page_links( $post = null ) {
	$post  = get_post( $post );
	$links = array(
		'edit_url'   => '',
		'issue_url'  => '',
		'source_url' => '',
		'commit'     => '',
		'imported'   => '',
	);
	if ( $post && \WordPressdotorg\Docs\POST_TYPE === $post->post_type ) {
		foreach ( array(
			'edit_url'   => '_wporg_docs_edit_url',
			'issue_url'  => '_wporg_docs_issue_url',
			'source_url' => '_wporg_docs_source_url',
			'commit'     => '_wporg_docs_commit',
			'imported'   => '_wporg_docs_imported',
		) as $key => $meta_key ) {
			$value         = get_post_meta( $post->ID, $meta_key, true );
			$links[ $key ] = is_string( $value ) && 'null' !== $value ? $value : '';
		}
	}
	$links['edit']   = $links['edit_url'];
	$links['issue']  = $links['issue_url'];
	$links['source'] = $links['source_url'];

	/**
	 * Filters the page links.
	 *
	 * @param array        $links Links.
	 * @param WP_Post|null $post  Post.
	 */
	return apply_filters( 'wporg_docs_page_links', $links, $post );
}
