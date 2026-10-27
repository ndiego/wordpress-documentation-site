# The contract between the plugin, the theme and the content

Everything in this repository depends on the rules here. Change this file together with every part it binds.

## Repositories

| Repo | Owns |
|---|---|
| [ndiego/wordpress-documentation](https://github.com/ndiego/wordpress-documentation) | the Markdown, `manifest.json`, screenshots. Default branch `trunk`. |
| this repo, `plugins/wp-docs/` | the `docs` post type, the importer (a subclass of the vendored WordPress.org `wporg-markdown` importer), redirects, the Markdown and agent endpoints, the webhook, the vendored WordPress.org global header and footer, WP-CLI commands |
| this repo, `plugins/wporg-markdown/` | the stock WordPress.org importer, vendored unchanged (`SOURCE.md`) |
| this repo, `themes/wp-docs/` | the block theme: templates, the sidebar, table of contents, page actions, search palette, home and section landings, styles |
| this repo, `.wp-env.json`, `tools/`, `package.json` | the local environment, setup, static export, screenshots of the site |

## Post type

- Slug `docs`, hierarchical, `rewrite => ['slug' => 'docs', 'with_front' => false]`, REST base `docs`,
  `has_archive` true, supports title, editor, excerpt, revisions, page-attributes, custom-fields.
- URL of a page: `/docs/<path>` where `path` is the page's path from the manifest (`write/create-a-post/`), stored
  in `_wp_docs_path` and served by the plugin's permalink filter and rewrite rule. `post_parent` mirrors the
  folder tree (the manifest `parent`). A section landing (`get-started/`) is a top-level post whose children are
  the section's pages. The `/docs/` archive and the front page both show the home.
- Posts are matched by `_wp_docs_id` on import, never by slug. A page that leaves the manifest becomes a draft
  with `_wp_docs_removed` set; it is never deleted.

## Post meta written by the importer (read by the theme)

| Key | Value |
|---|---|
| `_wp_docs_id` | stable page id (the manifest `id`, default: the path without trailing slash) |
| `_wp_docs_path` | the URL path under `/docs/`, trailing slash |
| `_wp_docs_section` | first path segment (`write`); empty for the home |
| `_wp_docs_type` | `tutorial`, `guide`, `concept`, `reference` |
| `_wp_docs_status` | `""`, `placeholder` or `draft` |
| `_wp_docs_description` | the front matter description (also the post excerpt) |
| `_wp_docs_updated` | `YYYY-MM-DD` from front matter, or `""` |
| `_wp_docs_source_url` | `https://github.com/ndiego/wordpress-documentation/blob/trunk/docs/<file>` |
| `_wp_docs_edit_url` | the matching `/edit/trunk/` URL |
| `_wp_docs_issue_url` | prefilled new-issue URL (title and body carry page URL, path, commit) |
| `_wp_docs_commit` | commit SHA of the imported revision, or `""` |
| `_wp_docs_imported` | ISO 8601 time of the last import |
| `_wp_docs_markdown` | the page's Markdown as served to agents: a front matter block (`title`, `description`, `type`, `url`, `source`), then the body with the H1, with relative links rewritten to absolute site URLs and relative images to their sideloaded URLs |
| `_wp_docs_redirect_from` | JSON list of root-relative paths that 301 here |
| `_wp_docs_blob_sha` | Git blob SHA of the Markdown file as imported (the reverse sync compares it with the branch head) |
| `_wp_docs_last_push`, `_wp_docs_pull_request` | JSON records of the last commit pushed from the editor, and of the open pull request proposed from it |
| bookkeeping | `_wp_docs_manifest`, `_wp_docs_removed`, `_wp_docs_output_mode`, `_wp_docs_transform`, `_wp_docs_applies_to` |

Options: `wp_docs_last_import` (`{time, pages, warnings}`), `wp_docs_redirects` (path => post id).

## Manifest format (`manifest.json` in the content repo)

The stock importer format: an object keyed by the page key, parents before children, each entry with `title`,
`slug`, `parent` (parent key or `null`), `order`, `markdown_source` (a `github.com/.../blob/trunk/docs/...` URL),
plus the extensions `id`, `path`, `section`, `type`, `status`, `description`, `updated`, `redirect_from`,
`applies_to`. Keys are the path without trailing slash (`write/create-a-post`); the home is `index`
(`path: ""`, rendered by the theme's front page, imported as the top-level post `_wp_docs_path = ""`).

Where the plugin reads it: the `WP_DOCS_MANIFEST` constant (a URL or a file path; default the raw GitHub URL of
`manifest.json` on `trunk`). For local work, `.wp-env.json` mounts the content checkout at `wp-content/wp-docs-content`
and `WP_DOCS_PREFER_LOCAL` reads files from it instead of GitHub (so unpushed content can be previewed).

## Endpoints the plugin serves

| URL | Returns |
|---|---|
| `/docs/<path>.md`, also `/docs/<path>/?output_format=md` and `Accept: text/markdown` on the page URL | `text/markdown; charset=utf-8`: `_wp_docs_markdown`. The page's `<head>` carries `<link rel="alternate" type="text/markdown" href="...md">`. |
| `/llms.txt` | the llms.txt index: title, one-paragraph summary, then one `- [Title](url.md): description` line per page grouped by section with `## Section` headings |
| `/llms-full.txt` | every page's Markdown concatenated, in sidebar order, separated by a line of `---` |
| `/docs/index.json` | `{ generated, pages: [ { id, title, path, url, md_url, section, type, status, description, parent, order, source_url, edit_url, updated, imported } ] }` in sidebar order |
| `/wp-json/wp-docs/v1/search-index` | `[ { title, path, url, section, type, breadcrumb, description, headings: [ { text, anchor } ] } ]`, cached per import, for the search palette |
| `POST /wp-json/wp-docs/v1/import` | the webhook: `X-WP-Docs-Secret` must equal `WP_DOCS_WEBHOOK_SECRET`; runs the import (manifest stage immediately, Markdown stage immediately) and returns `{ imported, warnings }` |
| old WordPress.org paths in `redirect_from` | 301 to the page |

Cron: the import runs every 15 minutes (filterable, `wp_docs_cron_interval`; `false` disables). WP-CLI:
`wp wp-docs import [--force]`, `wp wp-docs status`.

## What the theme expects

- A template `single-docs.html`, `archive-docs.html` (the home at `/docs/`), `front-page.html` (the same
  home at `/`), `search.html` scoped to `docs`, `404.html`.
- Theme helper functions live in the plugin so the theme stays thin: `wp_docs_get_tree()` (the whole tree from one
  cached query: nodes with id, title, parent, path, url, type, status, description, order; children by parent),
  `wp_docs_get_page_meta( $post )` (the meta above as an array), `wp_docs_get_breadcrumbs( $post )`,
  `wp_docs_get_adjacent( $post )` (prev and next in sidebar order), `wp_docs_markdown_url( $post )`.
- Blocks the theme renders (server-rendered, registered by the theme, not in the inserter):
  `wp-docs/sidebar`, `wp-docs/toc`, `wp-docs/page-actions`, `wp-docs/page-header` (type eyebrow, title,
  description, status banner), `wp-docs/page-footer` (helpful prompt, prev/next, last updated, edit, report),
  `wp-docs/section-cards` (children of the current page as cards), `wp-docs/home` (the doors and the strips).
- The global header and footer come from the plugin: `wporg/global-header` and `wporg/global-footer` are
  registered by the vendored block when the plugin is active. The theme places them in `parts/header.html` and
  `parts/footer.html` and must render acceptably when they are absent (plain fallbacks).

## Callouts

GitHub alerts in the Markdown become `wporg/notice`-style markup in the blocks output mode:
`<div class="wp-block-wporg-notice is-<type>-notice"><div class="wp-block-wporg-notice__icon"></div><div class="wp-block-wporg-notice__content"><p>...</p></div></div>`
with types `info` (NOTE), `tip` (TIP), `alert` (IMPORTANT, WARNING) and `warning` (CAUTION). The theme styles
these four classes; nothing in the theme depends on the wporg notice block being registered.

## Editing in WordPress: the reverse sync

GitHub stays the source of truth. The block editor is a second way to write to it (`plugins/wp-docs/inc/class-sync.php`):

- A save in the editor is intercepted before it is stored (`rest_pre_insert_docs`). Only the title, the description
  (excerpt) and the content can change here; slug, parent, order and status changes are refused (they live in the
  folder tree and the front matter).
- The page's Markdown is fetched from the branch head and its blob SHA must equal `_wp_docs_blob_sha` (recorded at
  import). Otherwise the save is refused: the site is behind GitHub and the next import catches up.
- The saved blocks are merged into that Markdown (`inc/transforms/chunks.php`, `markdown-out.php`, `reverse.php`):
  the source is split into chunks, each chunk is converted on its own so its blocks are known, unchanged chunks are
  kept byte for byte, changed blocks are written back. The result is converted again and compared with the saved
  blocks; a mismatch refuses the save and names the block.
- Users with `wp_docs_push` (granted to administrators and editors on activation; `wp_docs_propose` to those plus
  authors and contributors; both are ordinary capabilities a site can move between roles) commit straight to the
  branch (compare-and-swap on the file, retried when the branch moves). The WordPress save then goes through: the
  page is live at once, `_wp_docs_last_push` records the commit, and the import that follows (webhook from the
  content repository's CI, plus a one-off cron run three minutes later) converges the stored content on the canonical
  conversion. Everyone else gets a branch `wp-docs/<user>/<page>` and a pull request; the save is refused with the
  pull request's address (`_wp_docs_pull_request`), and the page changes when it merges and the site imports it.
  `WP_DOCS_GITHUB_MODE = 'review'` makes everyone propose.
- A page written in the editor becomes a file when it is published: it needs a parent section (a page with its own
  folder), gets `docs/<section>/<slug>.md` with front matter from the title, excerpt and order, is committed (or
  proposed) like an edit, and the post is marked (`_wp_docs_id`, `_wp_docs_path`, source) so the next import adopts
  it. Until the repository's CI has rebuilt the manifest, the importer keeps a page pushed from the editor while its
  file exists on the branch instead of unpublishing it.
- Images uploaded in the editor are committed next to the page under `images/`; their attachments get
  `_wp_docs_image_source` and `_wp_docs_image_hash` so the next import reuses them.
- The editor only offers the blocks that round-trip (`inc/editor.php`: paragraph, heading, list, quote, code,
  preformatted, table, image, separator, details, HTML, notice) and no colors, typography or spacing. Docs posts cannot
  be deleted in WordPress. Title, excerpt and content changes from any other write path (quick edit, XML-RPC) are
  dropped, except from the importer and WP-CLI.
- Constants: `WP_DOCS_GITHUB_TOKEN` (a token with contents and pull requests write access; without it every save is
  refused with a link to GitHub), `WP_DOCS_GITHUB_BRANCH` (default: the manifest's ref), `WP_DOCS_GITHUB_MODE`.
  WP-CLI: `wp wp-docs push <page> [--commit] [--user=<login>]` shows (or commits) the Markdown diff for the stored
  content.

## Rules

1. Facts over inference in every note; mark anything untested as untested.
2. Pinned versions everywhere (npm, wp-env sources with a ref, WordPress zip).
3. Prose says "WordPress.org"; URLs stay lowercase. No em-dashes in prose or UI strings.
4. The plugin never depends on the theme. The theme depends on the plugin's helpers and degrades without them.
