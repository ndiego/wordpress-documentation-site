# wp-docs: the hub plugin

Registers the `wp-docs` post type and imports the hub's manifests (`manifest/*.json`) into it with a
subclass of the WordPress.org Markdown importer (`../wporg-markdown/`, vendored unchanged). The importer
improvements the hub needs are separate, switchable transforms; `IMPORTER-CHANGES.md` is the upstream
proposal for them.

## Requirements

- WordPress 6.5+ (tested on 7.1.3), PHP 8.1+ (tested on 8.3 in wp-env and 8.4 natively), pretty
  permalinks.
- **`wporg-markdown`** active (this repository's `site/plugins/wporg-markdown/`).
- **Jetpack** active. The stock importer converts Markdown with `WPCom_GHF_Markdown_Parser`, defined in
  `jetpack/_inc/lib/markdown/gfm.php` (it extends `MarkdownExtra_Parser` from
  `jetpack/_inc/lib/markdown/extra.php`). The importer loads it itself from
  `JETPACK__PLUGIN_DIR . '/_inc/lib/markdown.php'` (wporg-markdown `inc/class-importer.php` line 441), so
  the Jetpack plugin must be active for `JETPACK__PLUGIN_DIR` to be defined; the Markdown module does not
  need to be enabled and no Jetpack connection is needed. Without it every page fails with
  "Jetpack Markdown is missing on system." The site's `.wp-env.json` installs Jetpack 16.3.1.
- Optional: `wporg-mu-plugins` for the `wporg/notice` block the callouts use (mounted by the site's
  wp-env config), and Code Syntax Block for highlighting.

## Running an import locally

The site's wp-env mounts this plugin, the manifests at `wp-content/wp-docs-manifest`, the content at
`wp-content/wp-docs-content`, and the repository root at `/var/www/html/wp-docs-repo`. From `site/`:

```bash
npx wp-env run cli wp wp-docs import --all            # committed manifests (github.com sources)
npx wp-env run cli wp wp-docs import --all --local    # offline manifests from scripts/assemble.py
npx wp-env run cli wp wp-docs import --manifest=themes --force
npx wp-env run cli wp wp-docs status
npx wp-env run cli wp wp-docs convert wp-content/wp-docs-content/blocks/README.md --mode=blocks
```

- The committed manifests point at `https://github.com/ndiego/wordpress-documentation/blob/trunk/...`. Until those
  files are pushed, add `define( 'WP_DOCS_PREFER_LOCAL', true );` and hub files are read from the
  repository checkout instead of raw.githubusercontent.com. Spoke files still come from GitHub.
- `--local` reads `.spokes/manifest/*.json` (written by `scripts/assemble.py`) from the repository
  checkout. There `markdown_source` is a repository path (`content/...` or `.spokes/<spoke>/...`), read
  from disk with no ETag (every run re-imports), and `source_url` supplies the GitHub URL for the edit
  and issue links. `all.json` is skipped when per-section files exist.

A full `--all --local` import of the 1,296 hub pages takes about 50 seconds natively.

## WP-CLI

| Command | What it does |
|---|---|
| `wp wp-docs import [--manifest=<name>] [--all] [--force] [--local]` | Manifest stage, then Markdown stage for the same pages (in order), then removals, then redirect and path lookups. `--force` ignores stored ETags. Without `--manifest`, imports all. |
| `wp wp-docs status [--format=<format>]` | Pages per section (published, draft, removed, blocks, classic), pages removed from the manifest, the last import, the next scheduled run, the manifest location, the output mode. |
| `wp wp-docs convert <file> [--mode=<mode>]` | Converts one Markdown file with the pipeline and prints the result (no images or link lookups). |

A recurring cron event, `wp_docs_import`, runs the full ordered import every 15 minutes (DevHub's
`15_minutes` schedule). Filter `wp_docs_cron_interval` changes it; return false to disable it.

## Output modes

`wp_docs_output_mode` (default `blocks`):

- `blocks`: the parser's HTML is run through `wpautop( $html, false )` once and converted to block
  markup: paragraphs, headings, `core/code` (`<pre class="wp-block-code"><code lang="php"
  class="language-php">`), lists, tables, quotes, images, separators, details, `wporg/notice` for alerts,
  `core/html` for anything else. No content is left outside a block (checked on every page; a page that
  failed the check would be saved in classic mode with a warning).
- `classic`: the stock importer's classic HTML; paragraphs come from `wpautop` at render time. Alerts
  become GitHub's `markdown-alert` markup.

## Filters and constants

| Filter | Default | Purpose |
|---|---|---|
| `wp_docs_output_mode` | `blocks` | `blocks` or `classic`, per post ID |
| `wp_docs_transform_alerts` | true | (a) GitHub alerts to notices |
| `wp_docs_transform_front_matter` | true | (b) strict front matter parsing |
| `wp_docs_transform_images` | true | (c) sideload repository images |
| `wp_docs_transform_links` | true | (d) rewrite links to Markdown files |
| `wp_docs_redirects_enabled` | true | (e) serve `redirect_from` 301s |
| `wp_docs_handle_removed` | true | (h) unpublish pages that left the manifest |
| `wp_docs_manifest_title_wins` | true | the manifest `title` beats the file's H1 |
| `wp_docs_classic_callout_format` | `github` | classic alerts: `github`, `notice`, `shortcode`, `callout` |
| `wp_docs_callout_block` | `wporg/notice` | blocks alerts: `wporg/notice`, `wporg/callout`, `core/group` |
| `wp_docs_alert_type_map` | NOTE info, TIP tip, IMPORTANT alert, WARNING alert, CAUTION warning | alert type to notice type |
| `wp_docs_manifest_location` | `WP_DOCS_MANIFEST` or `wp-content/wp-docs-manifest` | directory, file, or URLs of manifests |
| `wp_docs_repo_path` | `WP_DOCS_REPO_PATH` or `ABSPATH . 'wp-docs-repo'` | base for repository-relative sources |
| `wp_docs_repo_aliases` | `ndiego/wordpress-documentation` at the repo root, ref `trunk` | which GitHub repository a local path belongs to |
| `wp_docs_github_token` | `WP_DOCS_GITHUB_TOKEN` | enables commit SHA lookups |
| `wp_docs_webhook_secret` | `WP_DOCS_WEBHOOK_SECRET` | enables the webhook |
| `wp_docs_redirects` | stored lookup | old path to post ID map |
| `wp_docs_page_links` | meta | the array `wp_docs_page_links()` returns |
| `wp_docs_cron_interval` | `15_minutes` | recurring import schedule, false to disable |

Other constants: `WP_DOCS_USE_LOCAL_MANIFESTS` (always use the offline manifests),
`WP_DOCS_PREFER_LOCAL` (serve hub files from the checkout), `WP_DOCS_HUB_REPO` and
`WP_DOCS_HUB_REF` (the hub repository alias).

## What the importer stores

Post type `wp-docs` (hierarchical, rewrite base `docs`, REST base `docs`, archive at `/docs/`). A page's
URL is `/docs/<path>/` from the manifest `path` (`_wp_docs_path`); `post_parent` comes from `parent`.
Existing posts are matched by page id (`_wp_docs_id`), so renames and moves keep the post.

Meta (all registered with `show_in_rest`, string values, lists as JSON): the AGENTS.md contract keys
`_wp_docs_id`, `_section`, `_audience`, `_source_url`, `_edit_url`, `_issue_url`, `_commit` (empty
when unknown), `_imported`, `_generated`, `_applies_to`; and plugin bookkeeping `_wp_docs_path`,
`_manifest`, `_redirect_from`, `_redirect_external`, `_removed`, `_output_mode`, `_transform`.
Attachments carry `_wp_docs_image_hash` and `_wp_docs_image_source`.

`wp_docs_page_links( $post )` returns `edit_url`, `issue_url`, `source_url`, `commit`, `imported`
(and the aliases `edit`, `issue`, `source`).

## Webhook

`POST /wp-json/wp-docs/v1/import` with `X-WP-Docs-Secret: <secret>` or GitHub's
`X-Hub-Signature-256`, body `{"manifest": "themes", "force": false}` (manifest optional). Returns 202 and
schedules the import immediately. Disabled until a secret is set.

```bash
curl -X POST -H "X-WP-Docs-Secret: $SECRET" -H 'Content-Type: application/json' \
  -d '{"manifest":"themes"}' https://example.org/wp-json/wp-docs/v1/import
```

## Tests

The conversion code is plain PHP and is tested without WordPress or a database:

```bash
tests/fetch-deps.sh          # once: WordPress 7.1.3 core files and Jetpack 16.3.1's Markdown library
php tests/run-unit.php       # front matter, alerts, sources, links, images, manifests, redirects, blocks
php tests/run-fixtures.php   # tests/fixtures/*.md -> *.blocks.html and *.classic.html
php tests/run-fixtures.php --update   # rewrite the expected files after an intended change
```

The fixture runner uses the real Jetpack parser and core's `wpautop`, and checks every blocks output
with core's `WP_Block_Parser` for content outside blocks.

## Files

```
wp-docs.php              bootstrap
inc/post-type.php           post type and meta
inc/class-importer.php      the subclass of WordPressdotorg\Markdown\Importer
inc/functions.php           output mode, repository aliases, commit lookup
inc/pipeline.php            order of the transforms inside the stock importer's hooks
inc/sources.php             GitHub and local source URLs (pure)
inc/manifest.php            manifest normalising, link lookup, redirect parsing (pure)
inc/transforms/*.php        (a) alerts, (b) front matter, (c) images, (d) links, (g) blocks (pure)
inc/local-sources.php       manifest loading; local files served through pre_http_request
inc/permalinks.php          /docs/<path>/ URLs
inc/redirects.php           (e) redirect_from 301s
inc/rest.php                (i) webhook
inc/template-tags.php       (f) wp_docs_page_links()
inc/cli.php                 WP-CLI commands
IMPORTER-CHANGES.md         the upstream proposal
tests/                      plain-PHP tests and fixtures
```
