<?php
/**
 * The reverse sync: a save in the block editor becomes a commit (or a pull request) in the content
 * repository, before WordPress stores it.
 *
 * GitHub stays the source of truth. The editor is a second way to write to it:
 *
 * 1. The block editor saves through the REST API. `rest_pre_insert_docs` runs before anything is
 *    written and sees the new title, excerpt and content next to the stored post.
 * 2. Only the title, the description (excerpt) and the content may change here. Slug, parent, order
 *    and status are the page's place in the tree and change on GitHub (the folder and the front
 *    matter); a save that touches them is refused with a message.
 * 3. The page's Markdown is fetched from the branch head. Its blob SHA must equal the one stored at
 *    import: if GitHub moved since this site last imported the page, the save is refused rather than
 *    merged blind (the next import brings the page up to date).
 * 4. The saved blocks are merged into that Markdown (transforms/reverse.php): unchanged chunks stay
 *    byte for byte, changed blocks are written back, and the result is converted again and compared
 *    with the saved blocks. A page that would not import back exactly is refused with the block
 *    named, so the site never shows something the repository does not say.
 * 5. Images uploaded in the editor are added to the commit next to the page (`images/`), and their
 *    attachments are marked so the next import finds them instead of sideloading them again.
 * 6. A user with `wp_docs_push` commits straight to the content branch (compare-and-swap on the
 *    file, retried when the branch moves). The WordPress save then goes through: the page is live at
 *    once, and the import that follows (the repository's CI pings the webhook; a one-off cron run is
 *    scheduled as a fallback) converges the stored content on the canonical conversion.
 *    A user without it gets a branch and a pull request instead, and the save is refused with the
 *    pull request's address: the page changes when the pull request merges and the site imports it.
 *
 * Capabilities: `wp_docs_push` is granted to anyone with `edit_others_posts` (editors and
 * administrators) unless a role sets it explicitly; the `wp_docs_user_can_push` filter and the
 * WP_DOCS_GITHUB_MODE constant (`live`, the default, or `review`: everyone proposes) override it.
 * Everyone who can edit the post can propose.
 *
 * Other write paths (quick edit, XML-RPC, direct `wp_update_post()`) are guarded: a change to the
 * title, excerpt or content of a docs post that did not come through here is dropped, except from
 * the importer and WP-CLI.
 *
 * @package WP_Docs
 */

namespace WP_Docs;

use WP_Error;
use WP_Post;
use WP_REST_Request;

defined( 'WPINC' ) || die();

/**
 * Reverse sync.
 */
class Sync {

	/**
	 * Per request: post id => what to record after the save goes through.
	 *
	 * @var array<int,array>
	 */
	protected static $pending = array();

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'rest_pre_insert_' . POST_TYPE, array( __CLASS__, 'before_rest_save' ), 10, 2 );
		add_action( 'rest_after_insert_' . POST_TYPE, array( __CLASS__, 'after_rest_save' ), 10, 3 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'guard_other_writes' ), 20, 2 );
		add_filter( 'user_has_cap', array( __CLASS__, 'default_caps' ), 10, 4 );
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Modes and permissions.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * `wp_docs_push` defaults to `edit_others_posts`; `wp_docs_propose` to `edit_posts`.
	 *
	 * @param array    $allcaps All capabilities.
	 * @param string[] $caps    Required primitive capabilities.
	 * @param array    $args    Arguments.
	 * @param \WP_User $user    User.
	 * @return array
	 */
	public static function default_caps( $allcaps, $caps, $args, $user ) {
		if ( ! isset( $allcaps['wp_docs_push'] ) && ! empty( $allcaps['edit_others_posts'] ) ) {
			$allcaps['wp_docs_push'] = true;
		}
		if ( ! isset( $allcaps['wp_docs_propose'] ) && ! empty( $allcaps['edit_posts'] ) ) {
			$allcaps['wp_docs_propose'] = true;
		}
		return $allcaps;
	}

	/**
	 * The site-wide mode: `live` (pushers commit to the branch) or `review` (everyone proposes).
	 *
	 * @return string
	 */
	public static function site_mode() {
		$mode = defined( 'WP_DOCS_GITHUB_MODE' ) ? (string) WP_DOCS_GITHUB_MODE : 'live';
		/**
		 * Filters the reverse sync mode: `live` or `review`.
		 *
		 * @param string $mode Mode.
		 */
		$mode = (string) apply_filters( 'wp_docs_github_mode', $mode );
		return 'review' === $mode ? 'review' : 'live';
	}

	/**
	 * How a user's save reaches GitHub: `commit`, `pull-request`, or `none` (no token configured).
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function mode_for_user( $user_id ) {
		if ( '' === GitHub::token() ) {
			return 'none';
		}
		$can_push = 'live' === self::site_mode() && user_can( $user_id, 'wp_docs_push' );
		/**
		 * Filters whether a user's saves commit straight to the content branch.
		 *
		 * @param bool $can_push Default: `wp_docs_push` in live mode.
		 * @param int  $user_id  User ID.
		 */
		$can_push = (bool) apply_filters( 'wp_docs_user_can_push', $can_push, $user_id );
		return $can_push ? 'commit' : 'pull-request';
	}

	/**
	 * The branch saves go to: WP_DOCS_GITHUB_BRANCH, else the ref the manifest sources point at.
	 *
	 * @return string
	 */
	public static function branch() {
		if ( defined( 'WP_DOCS_GITHUB_BRANCH' ) && WP_DOCS_GITHUB_BRANCH ) {
			return (string) WP_DOCS_GITHUB_BRANCH;
		}
		return content_repo()['ref'];
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * The REST save.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Before the editor's save is written.
	 *
	 * @param \stdClass|WP_Error $prepared Post data about to be written.
	 * @param WP_REST_Request    $request  Request.
	 * @return \stdClass|WP_Error
	 */
	public static function before_rest_save( $prepared, $request ) {
		if ( is_wp_error( $prepared ) || Importer::instance()->is_running() ) {
			return $prepared;
		}
		if ( false !== strpos( (string) $request->get_route(), '/autosaves' ) ) {
			return $prepared;
		}
		if ( defined( 'WP_DOCS_SYNC_DISABLED' ) && WP_DOCS_SYNC_DISABLED ) {
			return $prepared;
		}

		$post = empty( $prepared->ID ) ? null : get_post( (int) $prepared->ID );
		if ( ! $post ) {
			return self::before_create( $prepared, $request );
		}
		if ( 'publish' !== $post->post_status ) {
			return $prepared; // Drafts and unpublished pages are not on the site; nothing to sync yet.
		}

		$changes = self::changes( $post, $prepared );
		if ( $changes['structure'] ) {
			return new WP_Error(
				'wp_docs_structure',
				sprintf(
					'Change %s on GitHub: a page\'s place in the tree comes from its folder and front matter. Only the title, the description and the content can change here.',
					implode( ', ', $changes['structure'] )
				),
				array( 'status' => 409 )
			);
		}
		if ( ! $changes['content'] && ! $changes['title'] && ! $changes['description'] ) {
			return $prepared; // Nothing the repository would see (for example a meta-only save).
		}

		$mode = self::mode_for_user( get_current_user_id() );
		if ( 'none' === $mode ) {
			$edit = (string) get_post_meta( $post->ID, '_wp_docs_edit_url', true );
			return new WP_Error( 'wp_docs_no_github', 'This site cannot write to GitHub (WP_DOCS_GITHUB_TOKEN is not set). Edit the page on GitHub instead: ' . $edit, array( 'status' => 409 ) );
		}

		$built = self::build( $post, $prepared, $changes );
		if ( is_wp_error( $built ) ) {
			return $built;
		}

		$user   = wp_get_current_user();
		$author = array(
			'name'  => $user->display_name ? $user->display_name : $user->user_login,
			'email' => $user->user_email ? $user->user_email : $user->user_login . '@users.noreply.wordpress.org',
		);
		$files  = array_merge( array( $built['path'] => $built['raw'] ), $built['images'] );
		$expect = array( $built['path'] => $built['blob_sha'] );
		foreach ( $built['images'] as $image_path => $bytes ) {
			$expect[ $image_path ] = ''; // Must not exist yet.
		}
		$title   = $built['title'];
		$summary = self::summary( $built );
		$message = sprintf( "docs(%s): %s\n\n%s\n\nEdited in WordPress: %s", $built['key'], $title, $summary, get_permalink( $post ) );

		if ( 'commit' === $mode ) {
			$result = $built['github']->push( self::branch(), $files, $expect, $message, $author );
			if ( is_wp_error( $result ) ) {
				return self::github_error( $result, $post );
			}
			self::$pending[ $post->ID ] = array(
				'raw'    => $built['raw'],
				'images' => $built['image_attachments'],
				'push'   => array(
					'commit' => $result['commit'],
					'branch' => self::branch(),
					'user'   => $user->user_login,
					'time'   => gmdate( 'c' ),
				),
			);
			return $prepared;
		}

		// Pull request: one branch per page and user, refreshed on every save.
		$result = self::propose( $built, $files, $expect, $message, $author, $user, $post, $title, $summary );
		if ( is_wp_error( $result ) ) {
			return self::github_error( $result, $post );
		}
		return new WP_Error(
			'wp_docs_proposed',
			sprintf( 'Proposed as pull request #%d (%s). The page changes here when the pull request is merged and the site imports it. Keep editing and save again to update the pull request.', $result['number'], $result['html_url'] ),
			array(
				'status'       => 409,
				'pull_request' => $result['html_url'],
			)
		);
	}

	/**
	 * After the save was written: record the commit, mark images, refresh the agent Markdown, and
	 * schedule a one-off import as the fallback for the webhook.
	 *
	 * @param WP_Post         $post     Post.
	 * @param WP_REST_Request $request  Request.
	 * @param bool            $creating Creating.
	 */
	public static function after_rest_save( $post, $request, $creating ) {
		$pending = self::$pending[ $post->ID ] ?? null;
		if ( ! $pending ) {
			return;
		}
		unset( self::$pending[ $post->ID ] );

		update_post_meta( $post->ID, '_wp_docs_last_push', wp_json_encode( $pending['push'] ) );
		update_post_meta( $post->ID, '_wp_docs_commit', $pending['push']['commit'] );
		update_post_meta( $post->ID, '_wp_docs_description', sanitize_text_field( $post->post_excerpt ) );
		delete_post_meta( $post->ID, '_wp_docs_pull_request' );
		foreach ( $pending['images'] as $attachment_id => $source ) {
			update_post_meta( $attachment_id, '_wp_docs_image_source', $source['url'] );
			update_post_meta( $attachment_id, '_wp_docs_image_hash', $source['hash'] );
		}
		if ( ! empty( $pending['meta'] ) ) {
			foreach ( $pending['meta'] as $key => $value ) {
				update_post_meta( $post->ID, $key, $value );
			}
		}
		Importer::instance()->refresh_agent_markdown( $post->ID, $pending['raw'] );
		// The stock importer compares ETags: drop the stored one so the next run fetches this page.
		delete_post_meta( $post->ID, 'wporg_markdown_etag' );

		if ( ! wp_next_scheduled( Importer::CRON_HOOK ) || wp_next_scheduled( Importer::CRON_HOOK ) > time() + 180 ) {
			wp_schedule_single_event( time() + 180, Importer::CRON_HOOK );
		}

		/**
		 * Fires after a save in the editor was pushed to GitHub.
		 *
		 * @param int   $post_id Post ID.
		 * @param array $push    { commit, branch, user, time }.
		 */
		do_action( 'wp_docs_pushed', $post->ID, $pending['push'] );
	}

	/**
	 * Which of the fields the repository knows about differ between the stored post and the save.
	 *
	 * @param WP_Post   $post     Stored post.
	 * @param \stdClass $prepared Save.
	 * @return array { title: bool, description: bool, content: bool, structure: string[] }
	 */
	protected static function changes( WP_Post $post, $prepared ) {
		$structure = array();
		if ( isset( $prepared->post_name ) && '' !== $prepared->post_name && $prepared->post_name !== $post->post_name ) {
			$structure[] = 'the slug';
		}
		if ( isset( $prepared->post_parent ) && (int) $prepared->post_parent !== (int) $post->post_parent ) {
			$structure[] = 'the parent';
		}
		if ( isset( $prepared->menu_order ) && (int) $prepared->menu_order !== (int) $post->menu_order ) {
			$structure[] = 'the order';
		}
		if ( isset( $prepared->post_status ) && $prepared->post_status !== $post->post_status ) {
			$structure[] = 'the status';
		}
		return array(
			'title'       => isset( $prepared->post_title ) && trim( (string) $prepared->post_title ) !== trim( $post->post_title ),
			'description' => isset( $prepared->post_excerpt ) && trim( (string) $prepared->post_excerpt ) !== trim( $post->post_excerpt ),
			'content'     => isset( $prepared->post_content ) && self::normalize_content( (string) $prepared->post_content ) !== self::normalize_content( $post->post_content ),
			'structure'   => $structure,
		);
	}

	/**
	 * Content compared without the differences the editor's serializer introduces by itself.
	 *
	 * @param string $content Block markup.
	 * @return string
	 */
	protected static function normalize_content( $content ) {
		$blocks = Transforms\Markdown_Out\parse( $content );
		return implode( "\n", array_map( 'WP_Docs\Transforms\Reverse\fingerprint', $blocks ) );
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Building the file.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * The merged Markdown file for a save, verified, with the images to add.
	 *
	 * @param WP_Post   $post     Stored post.
	 * @param \stdClass $prepared Save.
	 * @param array     $changes  From changes().
	 * @return array|WP_Error {
	 *     github: GitHub, path: string, key: string, title: string, raw: string, blob_sha: string,
	 *     images: path => bytes, image_attachments: id => { url, hash }, stats: array
	 * }
	 */
	public static function build( WP_Post $post, $prepared, ?array $changes = null ) {
		if ( null === $changes ) {
			$changes = self::changes( $post, $prepared );
		}
		if ( 'blocks' !== (string) get_post_meta( $post->ID, '_wp_docs_output_mode', true ) ) {
			return new WP_Error( 'wp_docs_classic', 'This page was imported as classic HTML (its Markdown has content the block converter cannot hold); edit it on GitHub.', array( 'status' => 409 ) );
		}

		$importer = Importer::instance();
		$context  = $importer->reverse_context( $post->ID );
		$source   = $context['source'];
		$aliases  = repo_aliases();
		$github   = $source ? Sources\to_github( $source, $aliases ) : null;
		if ( ! $github ) {
			return new WP_Error( 'wp_docs_no_source', 'This page has no GitHub source to write to.', array( 'status' => 409 ) );
		}
		$client = new GitHub( $github['owner'], $github['repo'], GitHub::token() );
		$branch = self::branch();

		$file = $client->file( $github['path'], $branch );
		if ( is_wp_error( $file ) ) {
			return self::github_error( $file, $post );
		}
		if ( ! $file ) {
			return new WP_Error( 'wp_docs_no_source', "The page's file {$github['path']} is not on the {$branch} branch.", array( 'status' => 409 ) );
		}
		$stored_sha = (string) get_post_meta( $post->ID, '_wp_docs_blob_sha', true );
		if ( '' !== $stored_sha && $stored_sha !== $file['sha'] ) {
			return new WP_Error( 'wp_docs_stale', 'This page changed on GitHub after it was last imported here. Wait for the next import (or run it), reload the editor and make the change again.', array( 'status' => 409 ) );
		}

		$parts   = Transforms\Reverse\split_file( $file['content'] );
		$options = $context['options'];
		$convert = static fn( string $markdown ) => Pipeline\convert_body( $markdown, $options );
		$live    = Transforms\Markdown_Out\parse( (string) ( $prepared->post_content ?? $post->post_content ) );

		// Images uploaded in the editor are not in the repository yet: add them next to the page.
		$images      = array();
		$attachments = array();
		$image_paths = self::collect_images( $live, $github['path'], $images, $attachments );

		$out_options = array(
			'link'  => self::link_resolver( $github['path'] ),
			'image' => self::image_resolver( $github['path'], $image_paths ),
		);

		$merged = Transforms\Reverse\merge( $parts['body'], $live, $convert, $out_options );
		$check  = Transforms\Reverse\verify( $merged['markdown'], $live, $convert );
		if ( $check ) {
			return new WP_Error(
				'wp_docs_roundtrip',
				sprintf(
					'Block %d (%s) cannot be written back to Markdown exactly, so this save was not made. Undo that change, or make it on GitHub.',
					$check['index'] + 1,
					self::block_label( $check['expected'] )
				),
				array(
					'status' => 409,
					'block'  => $check,
				)
			);
		}

		$title = $changes['title'] ? trim( (string) $prepared->post_title ) : $post->post_title;
		if ( $changes['title'] ) {
			$parts['title']        = $title;
			$parts['front_matter'] = Transforms\Reverse\set_front_matter_field( $parts['front_matter'], 'title', $title );
		}
		if ( $changes['description'] ) {
			$parts['front_matter'] = Transforms\Reverse\set_front_matter_field( $parts['front_matter'], 'description', trim( (string) $prepared->post_excerpt ) );
		}
		$raw = Transforms\Reverse\join_file( $parts, $merged['markdown'] );

		return array(
			'github'            => $client,
			'path'              => $github['path'],
			'key'               => (string) ( $context['doc']['_key'] ?? get_post_meta( $post->ID, '_wp_docs_id', true ) ),
			'title'             => $title,
			'raw'               => $raw,
			'blob_sha'          => $file['sha'],
			'images'            => $images,
			'image_attachments' => $attachments,
			'stats'             => array_merge( $merged, array( 'markdown' => null ) ),
			'before'            => $file['content'],
		);
	}

	/**
	 * Attachments used by the saved image blocks that did not come from the repository.
	 *
	 * @param array  $blocks      Live blocks.
	 * @param string $page_path   Repository path of the page.
	 * @param array  $files       Receives path => bytes.
	 * @param array  $attachments Receives attachment id => { url (canonical source), hash }.
	 * @return array Attachment URL => repository path.
	 */
	protected static function collect_images( array $blocks, $page_path, array &$files, array &$attachments ) {
		$map = array();
		$dir = dirname( $page_path );
		$dir = '.' === $dir ? '' : $dir . '/';
		$repo = content_repo();

		$walk = static function ( array $list ) use ( &$walk, &$map, &$files, &$attachments, $dir, $repo ) {
			foreach ( $list as $block ) {
				if ( 'core/image' === $block['blockName'] && ! empty( $block['attrs']['id'] ) ) {
					$id  = (int) $block['attrs']['id'];
					$url = wp_get_attachment_url( $id );
					if ( $url && '' === (string) get_post_meta( $id, '_wp_docs_image_source', true ) && ! isset( $map[ $url ] ) ) {
						$file = get_attached_file( $id );
						if ( $file && is_readable( $file ) ) {
							$name = sanitize_file_name( basename( $file ) );
							$path = $dir . 'images/' . $name;
							$i    = 1;
							while ( isset( $files[ $path ] ) ) {
								$path = $dir . 'images/' . pathinfo( $name, PATHINFO_FILENAME ) . '-' . ( ++$i ) . '.' . pathinfo( $name, PATHINFO_EXTENSION );
							}
							$bytes                = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
							$files[ $path ]       = $bytes;
							$map[ $url ]          = $path;
							$attachments[ $id ] = array(
								'url'  => 'https://github.com/' . $repo['repo'] . '/blob/' . $repo['ref'] . '/' . $path,
								'hash' => hash( 'sha256', $bytes ),
							);
						}
					}
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( $blocks );

		return $map;
	}

	/**
	 * Site URLs of docs pages become relative Markdown links to their source files.
	 *
	 * @param string $page_path Repository path of the page being written.
	 * @return callable fn( string $href ): ?string
	 */
	public static function link_resolver( $page_path ) {
		$base = wp_docs_path_url( '' );
		return static function ( string $href ) use ( $base, $page_path ): ?string {
			$fragment = '';
			if ( false !== ( $pos = strpos( $href, '#' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
				$fragment = substr( $href, $pos );
				$href     = substr( $href, 0, $pos );
			}
			if ( 0 !== strpos( $href, $base ) ) {
				return null;
			}
			$path = trim( substr( $href, strlen( $base ) ), '/' );
			$post = Permalinks::post_for_path( $path );
			if ( ! $post ) {
				return null;
			}
			$source = Sources\parse( (string) get_post_meta( $post, 'wporg_markdown_source', true ) );
			if ( ! $source ) {
				return null;
			}
			return self::relative_path( $page_path, $source['path'] ) . $fragment;
		};
	}

	/**
	 * Attachment URLs become relative image paths: repository images by their recorded source, new
	 * uploads by the path they are committed to.
	 *
	 * @param string $page_path Repository path of the page being written.
	 * @param array  $uploads   Attachment URL => repository path (from collect_images()).
	 * @return callable fn( string $src ): ?string
	 */
	public static function image_resolver( $page_path, array $uploads ) {
		$aliases = repo_aliases();
		return static function ( string $src ) use ( $page_path, $uploads, $aliases ): ?string {
			if ( isset( $uploads[ $src ] ) ) {
				return self::relative_path( $page_path, $uploads[ $src ] );
			}
			$id = attachment_url_to_postid( $src );
			if ( ! $id && preg_match( '#^(.*)-\d+x\d+(\.[a-z0-9]+)$#i', $src, $m ) ) {
				$id = attachment_url_to_postid( $m[1] . $m[2] );
			}
			if ( ! $id ) {
				return null;
			}
			$source = Sources\parse( (string) get_post_meta( $id, '_wp_docs_image_source', true ) );
			$github = $source ? Sources\to_github( $source, $aliases ) : null;
			return $github ? self::relative_path( $page_path, $github['path'] ) : null;
		};
	}

	/**
	 * A relative path from one repository file to another, the way the Markdown links are written.
	 *
	 * @param string $from File the link is in.
	 * @param string $to   Target file.
	 * @return string
	 */
	public static function relative_path( $from, $to ) {
		$from_dir = dirname( $from );
		$from     = '.' === $from_dir ? array() : explode( '/', $from_dir );
		$to       = explode( '/', $to );
		while ( $from && $to && $from[0] === $to[0] ) {
			array_shift( $from );
			array_shift( $to );
		}
		return str_repeat( '../', count( $from ) ) . implode( '/', $to );
	}

	/**
	 * A line describing the merge for the commit message.
	 *
	 * @param array $built From build().
	 * @return string
	 */
	protected static function summary( array $built ) {
		$s     = $built['stats'];
		$parts = array();
		if ( $s['rewritten'] ) {
			$parts[] = sprintf( '%d block%s changed', $s['rewritten'], 1 === $s['rewritten'] ? '' : 's' );
		}
		if ( $s['added'] ) {
			$parts[] = sprintf( '%d added', $s['added'] );
		}
		if ( $s['removed'] ) {
			$parts[] = sprintf( '%d removed', $s['removed'] );
		}
		if ( $built['images'] ) {
			$parts[] = sprintf( '%d image%s uploaded', count( $built['images'] ), 1 === count( $built['images'] ) ? '' : 's' );
		}
		return $parts ? ucfirst( implode( ', ', $parts ) ) . '.' : 'Title or description changed.';
	}

	/**
	 * A readable block name for a message.
	 *
	 * @param string $name Block name.
	 * @return string
	 */
	protected static function block_label( $name ) {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		if ( $type && ! empty( $type->title ) ) {
			return $type->title;
		}
		return '' === $name ? 'classic content' : $name;
	}

	/**
	 * A GitHub error as an editor message.
	 *
	 * @param WP_Error $error Error.
	 * @param WP_Post  $post  Post.
	 * @return WP_Error
	 */
	protected static function github_error( WP_Error $error, WP_Post $post ) {
		$code = $error->get_error_code();
		if ( 'wp_docs_stale' === $code ) {
			return new WP_Error( 'wp_docs_stale', sprintf( '%s changed on GitHub while you were editing. Wait for the next import, reload the editor and make the change again.', $error->get_error_message() ), array( 'status' => 409 ) );
		}
		return new WP_Error( $code, 'The change could not be written to GitHub. ' . $error->get_error_message(), array( 'status' => 502 ) );
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Pull requests.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Commit to the page's proposal branch for this user and open or refresh its pull request.
	 *
	 * @param array    $built   From build().
	 * @param array    $files   Files to commit.
	 * @param array    $expect  Expected blob SHAs on the content branch.
	 * @param string   $message Commit message.
	 * @param array    $author  Author.
	 * @param \WP_User $user    User.
	 * @param WP_Post  $post    Post.
	 * @param string   $title   Page title.
	 * @param string   $summary Summary line.
	 * @return array|WP_Error The pull request { number, html_url }.
	 */
	protected static function propose( array $built, array $files, array $expect, $message, array $author, $user, WP_Post $post, $title, $summary ) {
		$client = $built['github'];
		$base   = self::branch();
		$branch = 'wp-docs/' . sanitize_title( $user->user_login ) . '/' . str_replace( '/', '-', $built['key'] );

		$head = $client->branch_head( $branch );
		if ( is_wp_error( $head ) ) {
			return $head;
		}
		if ( null === $head ) {
			$base_head = $client->branch_head( $base );
			if ( is_wp_error( $base_head ) || null === $base_head ) {
				return is_wp_error( $base_head ) ? $base_head : new WP_Error( 'github_404', "The branch {$base} does not exist." );
			}
			$created = $client->create_ref( $branch, $base_head );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
		}

		// The file on the proposal branch may already carry this user's earlier proposal; the check
		// against the base is what matters, so the push replaces the file without a per-file expectation.
		$result = $client->push( $branch, $files, array(), $message, $author );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$body = sprintf(
			"Proposed from the WordPress editor by %s.\n\nPage: %s\n\n%s\n\nMerging this pull request publishes the change; the site imports it on the next sync.",
			$user->display_name,
			get_permalink( $post ),
			$summary
		);
		$pull = $client->open_pull( $branch );
		if ( is_wp_error( $pull ) ) {
			return $pull;
		}
		if ( $pull ) {
			$updated = $client->update_pull( (int) $pull['number'], "docs({$built['key']}): {$title}", $body );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		} else {
			$pull = $client->create_pull( $branch, $base, "docs({$built['key']}): {$title}", $body );
			if ( is_wp_error( $pull ) ) {
				return $pull;
			}
		}

		update_post_meta(
			$post->ID,
			'_wp_docs_pull_request',
			wp_json_encode(
				array(
					'url'    => $pull['html_url'],
					'number' => (int) $pull['number'],
					'branch' => $branch,
					'user'   => $user->user_login,
					'time'   => gmdate( 'c' ),
				)
			)
		);

		return array(
			'number'   => (int) $pull['number'],
			'html_url' => (string) $pull['html_url'],
		);
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * New pages.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * A page created in the editor: it gets a file next to its parent's children when it is published.
	 * Drafts are local until then.
	 *
	 * @param \stdClass       $prepared Post data.
	 * @param WP_REST_Request $request  Request.
	 * @return \stdClass|WP_Error
	 */
	protected static function before_create( $prepared, $request ) {
		if ( ( $prepared->post_status ?? 'draft' ) !== 'publish' ) {
			return $prepared;
		}
		return new WP_Error( 'wp_docs_create', 'New pages start on GitHub: add the Markdown file to the content repository (its folder decides where the page goes). Save this as a draft to keep the text here meanwhile.', array( 'status' => 409 ) );
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Other write paths.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Drop title, excerpt and content changes that did not come through the editor's REST save.
	 *
	 * @param array $data    Slashed post data.
	 * @param array $postarr Raw post array.
	 * @return array
	 */
	public static function guard_other_writes( $data, $postarr ) {
		if ( POST_TYPE !== ( $data['post_type'] ?? '' ) || empty( $postarr['ID'] ) ) {
			return $data;
		}
		if ( Importer::instance()->is_running() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $data;
		}
		$post = get_post( (int) $postarr['ID'] );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return $data;
		}
		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $field ) {
			if ( isset( $data[ $field ] ) && wp_unslash( $data[ $field ] ) !== $post->$field ) {
				$data[ $field ] = wp_slash( $post->$field );
			}
		}
		return $data;
	}
}
