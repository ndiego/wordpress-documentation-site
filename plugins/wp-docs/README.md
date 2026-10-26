# wp-docs: the WordPress Documentation plugin

Registers the `wp-docs` post type and imports the content repository's `manifest.json`
([ndiego/wordpress-documentation](https://github.com/ndiego/wordpress-documentation)) into it with a subclass of the
WordPress.org Markdown importer (`../wporg-markdown/`, vendored unchanged). It serves every page as Markdown for
agents, with `llms.txt`, `llms-full.txt`, a JSON index and a search index, gives the theme its helper functions, and
provides the WordPress.org global header and footer blocks. `../../CONTRACT.md` is the specification; this file
describes the implementation.

It started as a copy of the hub plugin of [ndiego/wporg-docs](https://github.com/ndiego/wporg-docs) (`wporg-docs`).
`IMPORTER-CHANGES.md` is that project's upstream proposal for the importer changes, kept for reference.

## Requirements

- WordPress 6.5+ (tested on 7.1.3), PHP 8.1+ (tested on 8.3 in wp-env and 8.4 for the unit tests), pretty
  permalinks.
- **`wporg-markdown`** active (`../wporg-markdown/`).
- **Jetpack** active (tested with 16.3.1). The stock importer converts Markdown with `WPCom_GHF_Markdown_Parser`
  from `jetpack/_inc/lib/markdown.php`, loaded through `JETPACK__PLUGIN_DIR`; the Markdown module does not need
  to be enabled and no connection is needed. Without it every page fails with "Jetpack Markdown is missing on
  system."
- Optional: Code Syntax Block for highlighting (`core/code` blocks carry `class="language-<lang>"`).

## Constants

| Constant | Default | Purpose |
|---|---|---|
| `WP_DOCS_MANIFEST` | `https://raw.githubusercontent.com/ndiego/wordpress-documentation/trunk/manifest.json` | The manifest: a URL or a file path. |
| `WP_DOCS_PREFER_LOCAL` | off | Read content repository files (`raw.githubusercontent.com/<WP_DOCS_REPO>/...`) from the local checkout when they exist there, so unpushed content can be previewed. |
| `WP_DOCS_CONTENT_PATH` | the manifest's directory when the manifest is a file, else `wp-content/wp-docs-content` | The local content checkout. |
| `WP_DOCS_REPO`, `WP_DOCS_REF` | `ndiego/wordpress-documentation`, `trunk` | The repository the local checkout stands for. |
| `WP_DOCS_WEBHOOK_SECRET` | none (webhook disabled) | Shared secret of `POST /wp-json/wp-docs/v1/import`. |
| `WP_DOCS_GITHUB_TOKEN` | none | Enables `_wp_docs_commit` (one GitHub commits API request per changed page). |

## WP-CLI

| Command | What it does |
|---|---|
| `wp wp-docs import [--force] [--manifest=<url-or-path>]` | Manifest stage, then Markdown stage for every page in manifest order, then removals, then the path and redirect lookups. `--force` ignores stored ETags. `--manifest` imports another manifest for this run only. |
| `wp wp-docs status [--format=<format>]` | Pages per section (published, draft, removed, blocks, classic), pages removed from the manifest, the last import, the next scheduled run, the manifest and the content checkout. |
| `wp wp-docs convert <file> [--mode=<mode>]` | Converts one Markdown file with the pipeline and prints the block markup (no images or link lookups). |

The cron event `wp_docs_import` runs the import every 15 minutes (filter `wp_docs_cron_interval`; return false to
disable it). One import runs at a time (a 10 minute lock in the transient `wp_docs_import_lock`).

## What an import does

1. Reads the manifest and normalises it (`inc/manifest.php`): keys become parent/slug chains for the stock
   importer, the URL path comes from `path`, the entry with `path: ""` (key `index`) is the home.
2. Republishes pages that come back after being removed, then runs the stock manifest stage with the normalised
   manifest served from memory. Posts are matched by `_wp_docs_id`, never by slug.
3. Runs the Markdown stage for every page at once, in manifest order: the stock fetch (with ETags; local files get
   an MD5 ETag too, so an unchanged file is a 304), then this plugin's transforms inside the stock hooks
   (`inc/pipeline.php`): front matter, GitHub alerts to `wporg/notice` markup, links to Markdown files to site URLs,
   relative images sideloaded once per SHA-256, GitHub-style heading ids, block markup.
4. Writes the meta in CONTRACT.md, including `_wp_docs_markdown` (`inc/transforms/agent-markdown.php`).
5. Unpublishes pages that left the manifest (draft plus `_wp_docs_removed`), rebuilds the path and redirect
   lookups, and stores the summary in `wp_docs_last_import`, which every cache is keyed by.

A page with an image that could not be imported is fetched again on the next run. SVG images are allowed for
the importer's own uploads (the content repository is trusted).

## Endpoints

| URL | Returns |
|---|---|
| `/docs/<path>.md`, `/docs/index.md` (the home), `?output_format=md`, `Accept: text/markdown` | `_wp_docs_markdown` as `text/markdown; charset=utf-8`, with a `Link: rel="canonical"` header |
| `/llms.txt`, `/llms-full.txt` | `text/plain; charset=utf-8` |
| `/docs/index.json` | the page index, sidebar order, home first |
| `GET /wp-json/wp-docs/v1/search-index` | the search palette's index; records also carry `section_title` and `status`; `breadcrumb` is a list of ancestor titles from the section landing down to the parent |
| `POST /wp-json/wp-docs/v1/import` | the webhook; header `X-WP-Docs-Secret` or GitHub's `X-Hub-Signature-256`; optional body `{"force": true}`; runs the import and answers `{imported, warnings, pages, unchanged, errors, removed, time}` (409 while another import runs) |
| old paths in `redirect_from` | 301 to the page, when the request would otherwise 404 |

Docs pages print `<link rel="alternate" type="text/markdown">` and send `Vary: Accept`.

## Helpers for the theme

`inc/tree.php` and `inc/template-tags.php` (global functions; the theme checks `function_exists()`):

| Function | Returns |
|---|---|
| `wp_docs_get_tree()` | `nodes` (post ID => id, doc_id, title, parent, path, url, md_url, section, type, status, description, order, depth), `children` (parent post ID => child IDs; key 0 lists the sections, the home is not in it), `sections`, `order` (sidebar order, without the home), `home` (post ID) |
| `wp_docs_get_page_meta( $post )` | the meta without the prefix, plus `url`, `markdown_url`, `is_home`; lists decoded |
| `wp_docs_get_breadcrumbs( $post )` | `[ { id, title, url, current } ]` from "Documentation" (`/docs/`) to the page |
| `wp_docs_get_adjacent( $post )` | `{ prev, next }`: tree nodes in sidebar order, or null |
| `wp_docs_markdown_url( $post )` | the `.md` URL |
| `wp_docs_page_links( $post )` | `edit_url`, `issue_url`, `source_url`, `commit`, `imported` |

Headings in imported content carry GitHub's ids (`## Add a title` is `id="add-a-title"`), so the table of
contents, the search index and links written against GitHub share one set of anchors.

## The WordPress.org header and footer

`inc/global-header-footer.php` loads `vendor/global-header-footer/blocks.php` (registers `wporg/global-header` and
`wporg/global-footer`) and `vendor/global-fonts/index.php` (registers `wporg-global-fonts`, enqueued on the front
end). The vendored files are unchanged; `vendor/PATCHES.md` lists the shims.

## Filters

| Filter | Default | Purpose |
|---|---|---|
| `wp_docs_manifest_location` | `WP_DOCS_MANIFEST` or the default URL | the manifest |
| `wp_docs_content_path` | see `WP_DOCS_CONTENT_PATH` | the local checkout |
| `wp_docs_repo_aliases` | the content repository at the checkout root | which repository the checkout stands for |
| `wp_docs_output_mode` | `blocks` | `blocks` or `classic`, per post ID |
| `wp_docs_transform_alerts`, `_front_matter`, `_images`, `_links` | true | switch a transform off |
| `wp_docs_redirects_enabled`, `wp_docs_handle_removed` | true | redirects, unpublishing removed pages |
| `wp_docs_manifest_title_wins` | true | the manifest `title` beats the file's H1 |
| `wp_docs_classic_callout_format`, `wp_docs_callout_block`, `wp_docs_alert_type_map` | `github`, `wporg/notice`, NOTE info, TIP tip, IMPORTANT and WARNING alert, CAUTION warning | callout markup |
| `wp_docs_github_token`, `wp_docs_webhook_secret` | the constants | |
| `wp_docs_cron_interval` | `15_minutes` | false disables the cron |
| `wp_docs_llms_summary` | two sentences | the llms.txt summary |
| `wp_docs_page_meta`, `wp_docs_page_links` | | what the helpers return |
| `wp_docs_load_global_header_footer` | true | load the vendored header and footer |

Action `wp_docs_imported` fires after every import with the summary.

## Tests

The conversion code is plain PHP and is tested without WordPress or a database:

```bash
tests/fetch-deps.sh          # once: WordPress 7.1.3 core files and Jetpack 16.3.1's Markdown library
php tests/run-unit.php       # front matter, alerts, anchors, sources, links, images, manifest (with index), Markdown for agents, redirects, blocks
php tests/run-fixtures.php   # tests/fixtures/*.md -> *.blocks.html, *.classic.html and *.agent.md
php tests/run-fixtures.php --update   # rewrite the expected files after an intended change
```

## Files

```
wp-docs.php                    bootstrap
inc/post-type.php              post type and meta
inc/class-importer.php         the subclass of WordPressdotorg\Markdown\Importer
inc/functions.php              manifest location, repository, commit lookup, caching
inc/local-sources.php          manifest loading; local files served through pre_http_request
inc/pipeline.php               order of the transforms inside the stock importer's hooks
inc/sources.php                GitHub and local source URLs (pure)
inc/manifest.php               manifest normalising, link lookup, redirect parsing (pure)
inc/transforms/*.php           alerts, front matter, images, links, anchors, blocks, agent Markdown (pure)
inc/permalinks.php             /docs/<path>/ URLs
inc/redirects.php              redirect_from 301s
inc/agents.php                 .md, llms.txt, llms-full.txt, index.json, search index
inc/rest.php                   the webhook
inc/cli.php                    WP-CLI
inc/tree.php, template-tags.php  helpers for the theme
inc/editor-notice.php          "maintained on GitHub" notice in the block editor
inc/global-header-footer.php   loads and adjusts the vendored WordPress.org header, footer and fonts
vendor/                        WordPress.org global header, footer and fonts (PATCHES.md)
tests/                         plain-PHP tests and fixtures
```
