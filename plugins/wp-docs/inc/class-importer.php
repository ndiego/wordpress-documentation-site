<?php
/**
 * The hub importer: a subclass of the WordPress.org Markdown importer (`wporg-markdown`).
 *
 * Imports `manifest/*.json` into the `wp-docs` post type. Everything the stock importer does is kept
 * (manifest stage, Markdown stage, ETags, title from the first H1, kses); this class only fills in the
 * abstract methods, overrides four protected methods with calls to the parent, and hooks the
 * transforms into the stock filters. See IMPORTER-CHANGES.md for what each change does and which ones
 * would be better upstream.
 *
 * @package WP_Docs
 */

namespace WP_Docs;

use WP_CLI;
use WP_Error;
use WP_Post;
use WP_Query;
use WordPressdotorg\Markdown\Editor;
use WordPressdotorg\Markdown\Importer as Markdown_Importer;

defined( 'WPINC' ) || die();

/**
 * Hub importer.
 */
class Importer extends Markdown_Importer {

	/**
	 * Bump when a transform changes, so stored ETags stop short-circuiting re-imports.
	 */
	const TRANSFORM_VERSION = 1;

	/**
	 * Cron hook for the full, ordered import (manifest stage, then Markdown stage).
	 */
	const CRON_HOOK = 'wp_docs_import';

	/**
	 * Option holding the last import summary.
	 */
	const LAST_IMPORT_OPTION = 'wp_docs_last_import';

	/**
	 * Singleton.
	 *
	 * @var Importer|null
	 */
	protected static $instance = null;

	/**
	 * URL the stock importer fetches the manifest from (served from memory, see local-sources.php).
	 *
	 * @var string
	 */
	protected $manifest_url = '';

	/**
	 * Every normalised manifest entry, keyed by path (all manifests, for link resolution).
	 *
	 * @var array<string,array>
	 */
	protected $entries = array();

	/**
	 * Manifest key by page id (all manifests).
	 *
	 * @var array<string,string>
	 */
	protected $keys_by_id = array();

	/**
	 * Link lookup: match key => path.
	 *
	 * @var array<string,string>
	 */
	protected $source_map = array();

	/**
	 * The page being imported in the Markdown stage.
	 *
	 * @var array|null { post_id, doc, source, fetch_url, front_matter, report }
	 */
	protected $current = null;

	/**
	 * Images already handled in this request: fetch URL => attachment.
	 *
	 * @var array<string,array|null>
	 */
	protected $image_cache = array();

	/**
	 * Warnings collected during a run.
	 *
	 * @var string[]
	 */
	protected $warnings = array();

	/**
	 * Get the singleton.
	 *
	 * @return Importer
	 */
	public static function instance() {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	/**
	 * Hook everything up.
	 */
	public function init() {
		add_filter( 'cron_schedules', array( $this, 'filter_cron_schedules' ) );
		add_action( 'init', array( $this, 'register_cron_jobs' ) );
		add_action( self::CRON_HOOK, array( $this, 'cron_import' ), 10, 2 );

		add_filter( 'wporg_markdown_before_transform', array( $this, 'before_transform' ), 10, 2 );
		add_filter( 'wporg_markdown_after_transform', array( $this, 'after_transform' ), 10, 2 );
		add_filter( 'wporg_markdown_check_etags', array( $this, 'check_etags' ) );
		add_filter( 'http_response', array( $this, 'filter_markdown_response' ), 10, 3 );
		add_filter( 'wp_insert_post_data', array( $this, 'filter_post_data' ), 10, 2 );
		add_filter( 'get_edit_post_link', array( $this, 'filter_edit_link' ), 11, 2 );

		// The stock Editor: "maintained on GitHub" warning in wp-admin and the front-end edit link.
		// Its title filter is removed: it appends an icon from the wporg-cli plugin, and the theme
		// renders its own page links (wp_docs_page_links()).
		$editor = new Editor( $this );
		$editor->init();
		remove_filter( 'the_title', array( $editor, 'filter_the_title_edit_link' ), 10 );
		remove_action( 'wp_head', array( Editor::class, 'render_edit_button_style' ) );
	}

	/**
	 * Base URL of all pages: the post type's rewrite base.
	 *
	 * @return string
	 */
	protected function get_base() {
		return home_url( '/docs/' );
	}

	/**
	 * Manifest URL for the current run.
	 *
	 * @return string
	 */
	protected function get_manifest_url() {
		return $this->manifest_url;
	}

	/**
	 * Post type.
	 *
	 * @return string
	 */
	public function get_post_type() {
		return POST_TYPE;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Running an import.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Import one manifest or all of them, in order: manifest stage, Markdown stage, removals, redirects.
	 *
	 * @param array $args {
	 *     @type string|null $manifest Manifest name (file name without .json). Null for all.
	 *     @type bool        $force    Ignore stored ETags.
	 *     @type bool        $local    Use the offline manifests (manifest/local/).
	 * }
	 * @return array|WP_Error Summary.
	 */
	public function run( array $args = array() ) {
		$args = array_merge(
			array(
				'manifest' => null,
				'force'    => false,
				'local'    => null,
			),
			$args
		);

		if ( ! get_option( 'permalink_structure' ) ) {
			return $this->fail( 'Pretty permalinks are required: the stock importer matches posts by permalink path. Run `wp rewrite structure /%postname%/`.' );
		}

		$loaded = Manifests::load( $args['local'] );
		if ( is_wp_error( $loaded ) ) {
			return $this->fail( $loaded->get_error_message() );
		}
		if ( $args['manifest'] && ! isset( $loaded['manifests'][ $args['manifest'] ] ) ) {
			return $this->fail( sprintf( 'Unknown manifest "%s". Available: %s', $args['manifest'], implode( ', ', array_keys( $loaded['manifests'] ) ) ) );
		}

		$this->warnings    = $loaded['warnings'];
		$this->image_cache = array();
		$normalized        = Manifest\normalize( $loaded['manifests'], $this->existing_paths() );
		$this->warnings    = array_merge( $this->warnings, $normalized['warnings'] );
		$this->entries     = $normalized['entries'];
		$this->source_map  = Manifest\source_map( $this->entries, repo_aliases() );
		$this->keys_by_id  = array();
		foreach ( $this->entries as $key => $doc ) {
			$this->keys_by_id[ (string) $doc['id'] ] = $key;
		}

		$names    = $args['manifest'] ? array( $args['manifest'] ) : array_keys( $loaded['manifests'] );
		$selected = array_filter( $this->entries, static fn( $doc ) => in_array( $doc['_manifest'], $names, true ) );

		foreach ( $this->warnings as $warning ) {
			$this->log( $warning, 'warning' );
		}

		$force_filter = static fn() => false;
		if ( $args['force'] ) {
			add_filter( 'wporg_markdown_check_etags', $force_filter, 99 );
		}

		$restored = $this->restore_returning_pages( $selected );

		// Manifest stage: the stock importer, fed the normalised manifest.
		$this->manifest_url = Local_Sources::register_manifest(
			$args['manifest'] ? $args['manifest'] : 'all',
			Manifest\for_importer( $selected )
		);
		$result = $this->import_manifest();
		if ( is_wp_error( $result ) ) {
			return $this->fail( $result->get_error_message() );
		}

		// Markdown stage, immediately and in manifest order (the stock cron runs the two stages as
		// independent events, so a new page can be live and empty for one interval).
		$counts = array(
			'updated'   => 0,
			'unchanged' => 0,
			'errors'    => 0,
		);
		foreach ( $selected as $path => $doc ) {
			$post = $this->find_post( $path, $doc );
			if ( ! $post ) {
				$this->log( "No post for {$path} after the manifest stage.", 'warning' );
				++$counts['errors'];
				continue;
			}
			$ret = $this->update_post_from_markdown_source( $post->ID );
			if ( is_wp_error( $ret ) ) {
				$this->log( "{$path}: " . $ret->get_error_message(), 'warning' );
				++$counts['errors'];
			} elseif ( true === $ret ) {
				$this->log( "Updated {$path} (post {$post->ID})." );
				++$counts['updated'];
			} else {
				++$counts['unchanged'];
			}
		}

		$removed = $this->handle_removed( $names, $selected );
		Permalinks::rebuild();
		Redirects::rebuild();

		if ( $args['force'] ) {
			remove_filter( 'wporg_markdown_check_etags', $force_filter, 99 );
		}

		$summary = array(
			'time'      => gmdate( 'c' ),
			'manifests' => $names,
			'pages'     => count( $selected ),
			'updated'   => $counts['updated'],
			'unchanged' => $counts['unchanged'],
			'errors'    => $counts['errors'],
			'restored'  => $restored,
			'removed'   => $removed,
			'warnings'  => count( $this->warnings ),
		);
		update_option( self::LAST_IMPORT_OPTION, $summary, false );

		return $summary;
	}

	/**
	 * Cron and webhook entry point.
	 *
	 * @param string|null $manifest Manifest name or null for all.
	 * @param bool        $force    Ignore ETags.
	 */
	public function cron_import( $manifest = null, $force = false ) {
		$this->run(
			array(
				'manifest' => $manifest ? (string) $manifest : null,
				'force'    => (bool) $force,
			)
		);
	}

	/**
	 * Add the 15 minute schedule DevHub uses, if missing (DevHub_Docs_Importer::filter_cron_schedules()).
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public function filter_cron_schedules( $schedules ) {
		if ( empty( $schedules['15_minutes'] ) ) {
			$schedules['15_minutes'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => 'Every 15 minutes',
			);
		}
		return $schedules;
	}

	/**
	 * Schedule the recurring import (DevHub_Docs_Importer::register_cron_jobs(), one ordered event
	 * instead of two independent ones).
	 */
	public function register_cron_jobs() {
		/**
		 * Filters the cron interval of the recurring import. Return false to disable it.
		 *
		 * @param string|false $interval Schedule name. Default '15_minutes'.
		 */
		$interval = apply_filters( 'wp_docs_cron_interval', '15_minutes' );
		if ( ! $interval ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}
		if ( ! in_array( $interval, array_keys( wp_get_schedules() ), true ) ) {
			$interval = '15_minutes';
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), $interval, self::CRON_HOOK );
		}
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Overrides of the stock importer (each calls the parent).
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * New post: the stock creation, then the hub meta.
	 *
	 * @param array    $doc         Manifest entry.
	 * @param int|null $post_parent Parent post ID.
	 * @return WP_Post|false
	 */
	protected function create_post_from_manifest_doc( $doc, $post_parent = null ) {
		$post = parent::create_post_from_manifest_doc( $doc, $post_parent );
		if ( $post ) {
			$this->write_manifest_meta( $post->ID, $doc );
		} else {
			$this->log( "Could not create a post for {$doc['_key']}.", 'warning' );
		}
		return $post;
	}

	/**
	 * Existing post: guard against the stock slug fallback, then the stock update, then the hub meta.
	 *
	 * The stock importer matches an entry to an existing post by path, then by slug alone
	 * (class-importer.php lines 169-173). With sixteen sections that share slugs such as `overview`,
	 * the slug fallback would move one section's page onto another's (spike 04, case N). When the
	 * matched post carries a different page id, a new post is created instead.
	 *
	 * @param int   $post_id Matched post.
	 * @param array $doc     Manifest entry.
	 * @return bool
	 */
	protected function update_post_from_manifest_doc( $post_id, $doc ) {
		$existing_id = (string) get_post_meta( $post_id, '_wp_docs_id', true );
		if ( '' !== $existing_id && isset( $doc['id'] ) && $existing_id !== (string) $doc['id'] ) {
			$this->log( "Entry {$doc['_key']} matched post {$post_id} (page {$existing_id}) by slug only; creating a new post instead.", 'warning' );
			$manifest = Local_Sources::get_manifest_payload( $this->manifest_url );
			return $this->process_manifest_doc( $doc, $manifest ? $manifest : array() );
		}

		$did_update = parent::update_post_from_manifest_doc( $post_id, $doc );
		$this->write_manifest_meta( $post_id, $doc );
		return $did_update;
	}

	/**
	 * Manifest-derived fields. Adds the front matter title as a fallback, and applies `order: 0`,
	 * which the stock importer ignores because it tests `! empty()` (line 363).
	 *
	 * @param int   $post_id   Post ID.
	 * @param mixed $title     See parent.
	 * @param bool  $do_update See parent.
	 * @return array
	 */
	protected function update_post_from_manifest( $post_id, $title = false, $do_update = true ) {
		$entry = get_post_meta( $post_id, $this->manifest_entry_meta_key, true );
		$entry = is_array( $entry ) ? $entry : array();

		/**
		 * Filters whether the manifest `title` is authoritative. The stock importer lets the file's
		 * first H1 win (line 490) and ignores manifest title changes while the file is unchanged
		 * (spike 04, cases C and D). The H1 is still removed from the body either way.
		 *
		 * @param bool $wins    Default true.
		 * @param int  $post_id Post ID.
		 */
		if ( ! empty( $entry['title'] ) && apply_filters( 'wp_docs_manifest_title_wins', true, $post_id ) ) {
			$title = (string) $entry['title'];
		} elseif ( null === $title && $this->current && ! empty( $this->current['front_matter']['title'] ) ) {
			$title = $this->current['front_matter']['title'];
		}

		$post_data = parent::update_post_from_manifest( $post_id, $title, false );
		$post      = get_post( $post_id );

		// `order: 0` is ignored by the stock importer (`! empty()`, line 363).
		if ( isset( $entry['order'] ) && 0 === (int) $entry['order'] && ! isset( $post_data['menu_order'] ) ) {
			$post_data['menu_order'] = 0;
		}

		// Parent by page id rather than get_page_by_path() (line 392), so renamed slugs keep the tree.
		unset( $post_data['post_parent'] );
		if ( $entry ) {
			if ( empty( $entry['parent'] ) ) {
				$post_data['post_parent'] = 0;
			} else {
				$parent_id = $this->post_id_for_key( (string) $entry['parent'] );
				if ( $parent_id && $parent_id !== (int) $post_id ) {
					$post_data['post_parent'] = $parent_id;
				}
			}
			// A renamed slug: the stock importer never updates post_name.
			if ( ! empty( $entry['slug'] ) && $post && $entry['slug'] !== $post->post_name && 'index' !== $entry['slug'] ) {
				$post_data['post_name'] = (string) $entry['slug'];
			}
		}

		if ( $do_update && $post_data ) {
			foreach ( $post_data as $field => $value ) {
				// The parent slashes titles; compare unslashed so unchanged titles with quotes do not
				// rewrite the post (and bump post_modified) on every run.
				if ( wp_unslash( $value ) == $post->$field ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Same comparison as the parent.
					unset( $post_data[ $field ] );
				}
			}
			if ( $post_data ) {
				$post_data['ID'] = $post_id;
				$this->log( "Updated {$post_id} from manifest source" );
				wp_update_post( $post_data );
			}
		}

		return $post_data;
	}

	/**
	 * Existing posts are keyed by the manifest key of their page id, so the stock importer's lookup
	 * (line 169) matches by `_wp_docs_id`: slugs and paths can change without creating a new post.
	 * Posts whose id is not in the manifests get a key that matches nothing; posts with no id (pages
	 * adopted from an earlier site) keep the stock permalink-path key.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	protected function get_existing_for_post( WP_Post $post ) {
		$id = (string) get_post_meta( $post->ID, '_wp_docs_id', true );
		if ( '' === $id ) {
			return parent::get_existing_for_post( $post );
		}
		$key = $this->keys_by_id[ $id ] ?? 'wp-docs-unmatched/' . $post->ID;
		return array( $key, array( 'post_id' => $post->ID ) );
	}

	/**
	 * Post ID of the page a manifest key refers to.
	 *
	 * @param string $key Manifest key.
	 * @return int
	 */
	protected function post_id_for_key( $key ) {
		$id = isset( $this->entries[ $key ]['id'] ) ? (string) $this->entries[ $key ]['id'] : '';
		if ( '' === $id ) {
			$post = get_page_by_path( $key, OBJECT, $this->get_post_type() );
			return $post ? (int) $post->ID : 0;
		}
		$posts = get_posts(
			array(
				'post_type'      => $this->get_post_type(),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_wp_docs_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $posts ? (int) $posts[0] : 0;
	}

	/**
	 * Markdown stage for one post, with the page context the transform filters need (the stock filters
	 * only receive the text and the post type).
	 *
	 * @param int $post_id Post ID.
	 * @return bool|WP_Error
	 */
	protected function update_post_from_markdown_source( $post_id ) {
		$entry = get_post_meta( $post_id, $this->manifest_entry_meta_key, true );
		$doc   = is_array( $entry ) ? $entry : array();
		$path  = $doc['_key'] ?? '';
		if ( $path && isset( $this->entries[ $path ] ) ) {
			$doc = $this->entries[ $path ];
		}

		$source = Sources\parse( (string) $this->get_markdown_source( $post_id ) );

		$this->current = array(
			'post_id'      => (int) $post_id,
			'doc'          => $doc,
			'source'       => $source,
			'fetch_url'    => $source ? Sources\fetch_url( $source ) : '',
			'front_matter' => null,
			'report'       => array(),
		);

		try {
			$result = parent::update_post_from_markdown_source( $post_id );
			if ( true === $result ) {
				$this->after_content_update( $post_id );
			}
			return $result;
		} finally {
			$this->current = null;
		}
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Hooks into the stock importer.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Transform (b): split front matter from the raw response before the stock regex sees it.
	 *
	 * The stock importer strips front matter with a fragile regex (line 483) and never exposes it. This
	 * runs on the HTTP response of the page being imported, so the stock code receives a body that
	 * starts at the Markdown (no BOM, no front matter) and the parsed data is kept for the page.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @param array          $args     Request args.
	 * @param string         $url      Request URL.
	 * @return array|WP_Error
	 */
	public function filter_markdown_response( $response, $args, $url ) {
		if ( ! $this->current || is_wp_error( $response ) || ! $this->current['fetch_url'] ) {
			return $response;
		}
		if ( 0 !== strpos( preg_replace( '/[?&]v=\d+$/', '', (string) $url ), $this->current['fetch_url'] ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $response;
		}

		/**
		 * Filters whether front matter is parsed by the plugin (transform b). When false, the stock
		 * regex strips it and its values are not used.
		 *
		 * @param bool $enabled Default true.
		 * @param int  $post_id Post being imported.
		 */
		if ( ! apply_filters( 'wp_docs_transform_front_matter', true, $this->current['post_id'] ) ) {
			return $response;
		}

		list( $data, $body )             = Transforms\Front_Matter\split( (string) wp_remote_retrieve_body( $response ) );
		$this->current['front_matter']   = Transforms\Front_Matter\normalize( $data );
		$response['body']                = $body;

		return $response;
	}

	/**
	 * Markdown stage hook.
	 *
	 * @param string $markdown  Markdown.
	 * @param string $post_type Post type.
	 * @return string
	 */
	public function before_transform( $markdown, $post_type ) {
		if ( $this->get_post_type() !== $post_type || ! $this->current ) {
			return $markdown;
		}
		return Pipeline\before_parse( $markdown, $this->pipeline_options() );
	}

	/**
	 * HTML stage hook.
	 *
	 * @param string $html      HTML.
	 * @param string $post_type Post type.
	 * @return string
	 */
	public function after_transform( $html, $post_type ) {
		if ( $this->get_post_type() !== $post_type || ! $this->current ) {
			return $html;
		}

		$options = $this->pipeline_options();
		$report  = array();
		$out     = Pipeline\after_parse( $html, $options, $report );

		$path = $this->current['doc']['_key'] ?? $this->current['post_id'];
		foreach ( array_unique( $report['unresolved_links'] ) as $href ) {
			$this->log( "{$path}: link {$href} matches no manifest entry; left as written.", 'warning' );
		}
		foreach ( array_unique( $report['failed_images'] ) as $src ) {
			$this->log( "{$path}: image {$src} could not be imported; left as written.", 'warning' );
		}
		if ( 'blocks' === $options['mode'] && $report['freeform'] ) {
			$this->log( "{$path}: block conversion left content outside blocks; saving classic HTML instead.", 'warning' );
			$options['mode'] = 'classic';
			$out             = Pipeline\after_parse( $html, $options );
		}
		$this->current['report']        = $report;
		$this->current['mode']          = $options['mode'];
		$this->current['first_para']    = 'blocks' === $options['mode'] ? first_paragraph_text( $out ) : '';

		return $out;
	}

	/**
	 * Bypass stored ETags when the transforms changed since the page was last imported.
	 *
	 * @param bool $check Check ETags.
	 * @return bool
	 */
	public function check_etags( $check ) {
		if ( ! $check || ! $this->current ) {
			return $check;
		}
		return get_post_meta( $this->current['post_id'], '_wp_docs_transform', true ) === $this->transform_signature();
	}

	/**
	 * Excerpt: the front matter description, else the first paragraph. The stock excerpt is the first
	 * line of the tag-stripped HTML (line 505), which is a heading when the page starts with one, and
	 * empty for block markup.
	 *
	 * @param array $data    Slashed post data.
	 * @param array $postarr Raw post array.
	 * @return array
	 */
	public function filter_post_data( $data, $postarr ) {
		if ( ! $this->current || empty( $postarr['ID'] ) || (int) $postarr['ID'] !== $this->current['post_id'] || ! isset( $postarr['post_content'] ) ) {
			return $data;
		}
		$description = $this->current['front_matter']['description'] ?? '';
		if ( '' === $description && ! empty( $this->current['first_para'] ) ) {
			$description = $this->current['first_para'];
		}
		if ( '' !== $description ) {
			$data['post_excerpt'] = wp_slash( sanitize_text_field( $description ) );
		}
		return $data;
	}

	/**
	 * Front-end edit link: the GitHub edit URL from the meta (the stock Editor only knows github.com
	 * sources and would link local sources to the local fetch URL).
	 *
	 * @param string $link    Link.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function filter_edit_link( $link, $post_id ) {
		if ( is_admin() || get_post_type( $post_id ) !== $this->get_post_type() ) {
			return $link;
		}
		$edit = (string) get_post_meta( $post_id, '_wp_docs_edit_url', true );
		return '' !== $edit ? $edit : $link;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Meta and provenance (f).
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Write the hub meta derived from the manifest entry.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $doc     Manifest entry.
	 */
	protected function write_manifest_meta( $post_id, $doc ) {
		$fm = $this->current && $this->current['post_id'] === (int) $post_id ? (array) $this->current['front_matter'] : array();

		$audience   = ! empty( $doc['audience'] ) ? (array) $doc['audience'] : ( $fm['audience'] ?? array() );
		$audience   = array_values( array_intersect( array( 'user', 'developer', 'contributor' ), array_map( 'strval', $audience ) ) );
		$applies_to = ! empty( $doc['applies_to'] ) ? (array) $doc['applies_to'] : ( $fm['applies_to'] ?? array() );
		$generated  = ! empty( $doc['generated'] ) ? $doc['generated'] : ( $fm['generated'] ?? null );
		$redirects  = array_merge( (array) ( $doc['redirect_from'] ?? array() ), (array) ( $fm['redirect_from'] ?? array() ) );
		$redirects  = Manifest\split_redirects( $redirects, strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		// A redirect must never point at the page's own URL.
		$own                = Manifest\normalize_redirect_path( '/docs/' . ( $doc['_path'] ?? $doc['_key'] ?? '' ) );
		$redirects['paths'] = array_values( array_diff( $redirects['paths'], array( $own ) ) );

		$meta = array(
			'_wp_docs_id'                => (string) ( $doc['id'] ?? $doc['_key'] ?? '' ),
			'_wp_docs_section'           => (string) ( $doc['section'] ?? strtok( (string) ( $doc['_key'] ?? '' ), '/' ) ),
			'_wp_docs_audience'          => wp_json_encode( $audience ),
			'_wp_docs_applies_to'        => wp_json_encode( array_values( array_map( 'strval', $applies_to ) ) ),
			'_wp_docs_generated'         => $generated ? wp_json_encode( $generated ) : '',
			'_wp_docs_tab'               => (string) ( $doc['tab'] ?? '' ),
			'_wp_docs_manifest'          => (string) ( $doc['_manifest'] ?? '' ),
			'_wp_docs_path'              => (string) ( $doc['_path'] ?? $doc['_key'] ?? '' ),
			'_wp_docs_redirect_from'     => wp_json_encode( $redirects['paths'] ),
			'_wp_docs_redirect_external' => wp_json_encode( $redirects['external'] ),
		);
		$meta = array_merge( $meta, $this->link_meta( $post_id ) );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
	}

	/**
	 * Source, edit and issue URLs for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string>
	 */
	protected function link_meta( $post_id ) {
		$source = Sources\parse( (string) $this->get_markdown_source( $post_id ) );
		$entry  = get_post_meta( $post_id, $this->manifest_entry_meta_key, true );
		$github = ( is_array( $entry ) && ! empty( $entry['source_url'] ) ) ? Sources\parse( (string) $entry['source_url'] ) : null;
		if ( ! $github || 'github' !== $github['kind'] ) {
			$github = $source ? Sources\to_github( $source, repo_aliases() ) : null;
		}
		if ( ! $github ) {
			return array(
				'_wp_docs_source_url' => '',
				'_wp_docs_edit_url'   => '',
				'_wp_docs_issue_url'  => '',
			);
		}
		$commit = (string) get_post_meta( $post_id, '_wp_docs_commit', true );

		return array(
			'_wp_docs_source_url' => Sources\blob_url( $github ),
			'_wp_docs_edit_url'   => Sources\edit_url( $github ),
			'_wp_docs_issue_url'  => Sources\issue_url( $github, (string) get_permalink( $post_id ), get_the_title( $post_id ), '' !== $commit ? $commit : null ),
		);
	}

	/**
	 * After the content of a page changed: commit, import time, transform signature, front matter meta.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function after_content_update( $post_id ) {
		$github = ! empty( $this->current['doc']['_github'] ) ? $this->current['doc']['_github'] : $this->current['source'];
		$commit = $github ? fetch_commit_sha( $github ) : null;
		if ( $commit ) {
			update_post_meta( $post_id, '_wp_docs_commit', $commit );
		} else {
			delete_post_meta( $post_id, '_wp_docs_commit' );
		}
		update_post_meta( $post_id, '_wp_docs_imported', gmdate( 'c' ) );
		update_post_meta( $post_id, '_wp_docs_transform', $this->transform_signature() );
		update_post_meta( $post_id, '_wp_docs_output_mode', $this->current['mode'] ?? '' );

		// Front matter can fill fields the manifest left empty, and the issue URL carries the commit.
		$this->write_manifest_meta( $post_id, $this->current['doc'] );
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Images (c).
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Fetch an image from the repository and import it once per content hash.
	 *
	 * @param array  $target Parsed image source.
	 * @param string $alt    Alt text.
	 * @return array|null { url, id }
	 */
	public function import_image( array $target, string $alt ) {
		$fetch_url = Sources\fetch_url( $target );
		if ( array_key_exists( $fetch_url, $this->image_cache ) ) {
			return $this->image_cache[ $fetch_url ];
		}
		$this->image_cache[ $fetch_url ] = null;

		$response = wp_remote_get( $fetch_url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$bytes = (string) wp_remote_retrieve_body( $response );
		if ( '' === $bytes ) {
			return null;
		}
		$hash = hash( 'sha256', $bytes );

		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => '_wp_docs_image_hash', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$id = $found ? (int) $found[0] : 0;

		if ( ! $id ) {
			if ( ! function_exists( 'media_handle_sideload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			$name = sanitize_file_name( basename( $target['path'] ) );
			$tmp  = wp_tempnam( $name );
			if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return null;
			}
			$id = media_handle_sideload(
				array(
					'name'     => $name,
					'tmp_name' => $tmp,
				),
				$this->current ? $this->current['post_id'] : 0
			);
			if ( is_wp_error( $id ) ) {
				wp_delete_file( $tmp );
				$this->log( "Sideload of {$target['path']} failed: " . $id->get_error_message(), 'warning' );
				return null;
			}
			update_post_meta( $id, '_wp_docs_image_hash', $hash );
			update_post_meta( $id, '_wp_docs_image_source', Sources\canonical_url( $target ) );
			$this->log( "Imported image {$target['path']} as attachment {$id}." );
		}

		// The attachment's own alt is set once; each <img> keeps the alt written in its page.
		if ( '' !== $alt && '' === (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
		}

		$url = wp_get_attachment_url( $id );
		return $this->image_cache[ $fetch_url ] = $url ? array( // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
			'url' => $url,
			'id'  => $id,
		) : null;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Removed pages (h).
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Pages that left the manifest become drafts with a `_wp_docs_removed` flag. Never deleted.
	 *
	 * @param string[] $names    Manifests imported in this run.
	 * @param array    $selected Their entries.
	 * @return string[] Paths of the pages unpublished.
	 */
	protected function handle_removed( array $names, array $selected ) {
		/**
		 * Filters whether pages missing from the manifest are unpublished.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'wp_docs_handle_removed', true ) ) {
			return array();
		}

		$ids = array();
		foreach ( $selected as $doc ) {
			$ids[ (string) $doc['id'] ] = true;
		}

		$query = new WP_Query(
			array(
				'post_type'      => $this->get_post_type(),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_wp_docs_manifest',
						'value'   => $names,
						'compare' => 'IN',
					),
				),
			)
		);

		$removed = array();
		foreach ( $query->posts as $post ) {
			$id = (string) get_post_meta( $post->ID, '_wp_docs_id', true );
			if ( isset( $ids[ $id ] ) ) {
				continue;
			}
			wp_update_post(
				array(
					'ID'          => $post->ID,
					'post_status' => 'draft',
				)
			);
			update_post_meta( $post->ID, '_wp_docs_removed', gmdate( 'c' ) );
			$path      = (string) get_post_meta( $post->ID, '_wp_docs_path', true );
			$removed[] = $path ? $path : (string) $post->ID;
			$this->log( "Unpublished {$path} (post {$post->ID}): no longer in the manifest.", 'warning' );
		}
		return $removed;
	}

	/**
	 * Republish drafts flagged as removed whose page id is back in the manifest. The stock importer
	 * only sees published posts (line 149) and would otherwise create a duplicate.
	 *
	 * @param array $selected Entries of this run.
	 * @return int Number restored.
	 */
	protected function restore_returning_pages( array $selected ) {
		$restored = 0;
		foreach ( $selected as $doc ) {
			$posts = get_posts(
				array(
					'post_type'      => $this->get_post_type(),
					'post_status'    => 'draft',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'   => '_wp_docs_id',
							'value' => (string) $doc['id'],
						),
						array(
							'key'     => '_wp_docs_removed',
							'compare' => 'EXISTS',
						),
					),
				)
			);
			if ( $posts ) {
				wp_update_post(
					array(
						'ID'          => $posts[0],
						'post_status' => 'publish',
					)
				);
				delete_post_meta( $posts[0], '_wp_docs_removed' );
				$this->log( "Republished {$doc['_key']} (post {$posts[0]}): back in the manifest." );
				++$restored;
			}
		}
		return $restored;
	}

	/*
	 * ---------------------------------------------------------------------------------------------
	 * Helpers.
	 * ---------------------------------------------------------------------------------------------
	 */

	/**
	 * Pipeline options for the current page, from the filters.
	 *
	 * @return array
	 */
	protected function pipeline_options() {
		$post_id = $this->current ? $this->current['post_id'] : 0;
		$entries = $this->entries;
		$map     = $this->source_map;
		$aliases = repo_aliases();
		$base    = $this->get_base();

		return array(
			/**
			 * Filters whether GitHub alerts become notices (transform a).
			 *
			 * @param bool $enabled Default true.
			 * @param int  $post_id Post being imported.
			 */
			'alerts'         => (bool) apply_filters( 'wp_docs_transform_alerts', true, $post_id ),
			/**
			 * Filters whether links to Markdown files are rewritten to site URLs (transform d).
			 *
			 * @param bool $enabled Default true.
			 * @param int  $post_id Post being imported.
			 */
			'links'          => (bool) apply_filters( 'wp_docs_transform_links', true, $post_id ),
			/**
			 * Filters whether repository images are sideloaded (transform c).
			 *
			 * @param bool $enabled Default true.
			 * @param int  $post_id Post being imported.
			 */
			'images'         => (bool) apply_filters( 'wp_docs_transform_images', true, $post_id ),
			'mode'           => output_mode( $post_id ),
			/**
			 * Filters the classic-mode callout markup: github (GitHub's `markdown-alert` markup),
			 * notice (the wporg/notice HTML), shortcode (`[info]...[/info]`) or callout
			 * (`<div class="callout callout-info">`).
			 *
			 * @param string $format  Default 'github'.
			 * @param int    $post_id Post being imported.
			 */
			'classic_format' => (string) apply_filters( 'wp_docs_classic_callout_format', 'github', $post_id ),
			/**
			 * Filters the blocks-mode callout block: wporg/notice, wporg/callout or core/group.
			 *
			 * @param string $block   Default 'wporg/notice'.
			 * @param int    $post_id Post being imported.
			 */
			'callout_block'  => (string) apply_filters( 'wp_docs_callout_block', 'wporg/notice', $post_id ),
			/**
			 * Filters the GitHub alert type to notice type map.
			 *
			 * @param array $map     note, tip, important, warning, caution => info, tip, alert, alert, warning.
			 * @param int   $post_id Post being imported.
			 */
			'type_map'       => (array) apply_filters( 'wp_docs_alert_type_map', Transforms\Alerts\default_type_map(), $post_id ),
			'base'           => $this->current ? $this->current['source'] : null,
			'lookup'         => Manifest\lookup_callback( $entries, $map, $aliases, $base ),
			'import_image'   => array( $this, 'import_image' ),
			'file_url'       => static function ( array $target ) use ( $aliases ) {
				$gh = Sources\to_github( $target, $aliases );
				return $gh ? Sources\blob_url( $gh ) : null;
			},
		);
	}

	/**
	 * Signature of the transform configuration, stored per post to invalidate ETags when it changes.
	 *
	 * @return string
	 */
	protected function transform_signature() {
		$options = $this->pipeline_options();
		unset( $options['base'], $options['lookup'], $options['import_image'], $options['file_url'] );
		return self::TRANSFORM_VERSION . ':' . md5( wp_json_encode( $options ) . wp_json_encode( $this->source_map ) );
	}

	/**
	 * Paths of existing published posts (for parents defined in another manifest).
	 *
	 * @return string[]
	 */
	protected function existing_paths() {
		global $wpdb;
		return array_map(
			'strval',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_wp_docs_path' AND p.post_type = %s AND p.post_status = 'publish'",
					$this->get_post_type()
				)
			)
		);
	}

	/**
	 * Find the post for a manifest path.
	 *
	 * @param string $path Path.
	 * @param array  $doc  Entry.
	 * @return WP_Post|null
	 */
	protected function find_post( $path, $doc ) {
		$posts = get_posts(
			array(
				'post_type'      => $this->get_post_type(),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => '_wp_docs_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $doc['id'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( $posts ) {
			return $posts[0];
		}
		$post = get_page_by_path( $path, OBJECT, $this->get_post_type() );
		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * Log to WP-CLI when available, else to the PHP error log for warnings.
	 *
	 * @param string $message Message.
	 * @param string $level   log|warning.
	 */
	protected function log( $message, $level = 'log' ) {
		if ( 'warning' === $level ) {
			$this->warnings[] = $message;
		}
		if ( class_exists( 'WP_CLI' ) ) {
			'warning' === $level ? WP_CLI::warning( $message ) : WP_CLI::log( $message );
		} elseif ( 'warning' === $level && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'wp-docs: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Report a fatal problem.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	protected function fail( $message ) {
		$this->log( $message, 'warning' );
		return new WP_Error( 'wp-docs-import', $message );
	}
}
