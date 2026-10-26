# The contract between the plugin, the theme and the content

Everything in this repository depends on the rules here. Change this file together with every part it binds.

## Repositories

| Repo | Owns |
|---|---|
| [ndiego/wordpress-documentation](https://github.com/ndiego/wordpress-documentation) | the Markdown, `manifest.json`, screenshots. Default branch `trunk`. |
| this repo, `plugins/wp-docs/` | the `wp-docs` post type, the importer (a subclass of the vendored WordPress.org `wporg-markdown` importer), redirects, the Markdown and agent endpoints, the webhook, the vendored WordPress.org global header and footer, WP-CLI commands |
| this repo, `plugins/wporg-markdown/` | the stock WordPress.org importer, vendored unchanged (`SOURCE.md`) |
| this repo, `themes/wp-docs/` | the block theme: templates, the sidebar, table of contents, page actions, search palette, home and section landings, styles |
| this repo, `.wp-env.json`, `tools/`, `package.json` | the local environment, setup, static export, screenshots of the site |

## Post type

- Slug `wp-docs`, hierarchical, `rewrite => ['slug' => 'docs', 'with_front' => false]`, REST base `docs`,
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

- A template `single-wp-docs.html`, `archive-wp-docs.html` (the home at `/docs/`), `front-page.html` (the same
  home at `/`), `search.html` scoped to `wp-docs`, `404.html`.
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

## Rules

1. Facts over inference in every note; mark anything untested as untested.
2. Pinned versions everywhere (npm, wp-env sources with a ref, WordPress zip).
3. Prose says "WordPress.org"; URLs stay lowercase. No em-dashes in prose or UI strings.
4. The plugin never depends on the theme. The theme depends on the plugin's helpers and degrades without them.
