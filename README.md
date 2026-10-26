# WordPress documentation site

A proof of concept: a WordPress site that imports the WordPress user documentation from Markdown on GitHub and
renders it with a modern documentation layout under the WordPress.org global header and footer. Every page is
also served as Markdown for agents, with `llms.txt` and a JSON index.

This repository is the site: a plugin, a block theme and a local environment. The words live in the content
repository, [ndiego/wordpress-documentation](https://github.com/ndiego/wordpress-documentation) (Markdown under
`docs/`, screenshots, and a generated `manifest.json`). The site imports that manifest; nobody edits
documentation in wp-admin. `CONTRACT.md` is the agreement between the plugin, the theme and the content, and
`DESIGN.md` is the brief for the theme.

```
plugins/wp-docs/          the wp-docs post type, the importer, redirects, Markdown and agent endpoints,
                          the webhook, WP-CLI, theme helpers, the WordPress.org header and footer
plugins/wporg-markdown/   the stock WordPress.org Markdown importer, vendored unchanged (SOURCE.md)
themes/wp-docs/           the block theme
tools/env/                wp-env setup script, .htaccess, sample content
tools/studio/             the same site in WordPress Studio, without Docker
.wp-env.json              the local environment
```

## How the two repositories fit together

1. A writer changes Markdown in the content repository and opens a pull request. Its CI validates the pages and
   regenerates `manifest.json`.
2. On merge to `trunk`, the content repository's CI calls this site's webhook
   (`POST /wp-json/wp-docs/v1/import`). The cron import every 15 minutes is the fallback.
3. The plugin reads `manifest.json`, fetches each page's Markdown from raw.githubusercontent.com (with ETags, so
   unchanged pages cost a 304), converts it to blocks, sideloads images, and stores the Markdown for agents.
4. The theme renders the pages from the plugin's helpers.

Locally the content checkout is mounted into wp-env and read from disk instead (`WP_DOCS_PREFER_LOCAL`), so a
change shows up on the next import without a push.

## Run it locally

Two ways, same plugin, theme and import. wp-env is the reference environment (it is what CI and the static export
use); WordPress Studio is the quicker one for day-to-day tweaking, with no Docker and native PHP.

Requirements for either: Node 24 and the content repository checked out next to this one
(`../wordpress-documentation`). wp-env also needs Docker Desktop.

### WordPress Studio

```bash
npm run studio:setup   # creates ~/Studio/wordpress-documentation, links plugins/ and themes/, imports
```

The script runs the [Studio CLI](https://developer.wordpress.com/docs/developer-tools/studio/) (`studio` on
the PATH, or the one inside Studio.app) to create a site with WordPress 7.1.3 and PHP 8.3 on the native runtime,
with file access outside the site directory so it can follow the symlinks into this repository and read the
content checkout. It writes the three constants below (and `JETPACK_DEV_DEBUG`) into an mu-plugin,
`wp-content/mu-plugins/wp-docs-local.php`, installs Jetpack 16.3.1 and Code Syntax Block 3.2.1, and runs the same
`tools/env/setup.sh` as wp-env. Run it again any time; it only adds what is missing and re-imports.

The site opens at the URL the script prints (`http://localhost:8882/docs/` on the first run) and shows up in the
Studio app. Theme and plugin edits are live because the directories are symlinks. Day to day:

```bash
npm run studio:import                        # wp wp-docs import (only changed pages)
npm run studio:wp -- wp-docs import --force  # re-import every page
npm run studio:wp -- wp-docs status
BASE_URL=http://localhost:8882 npm run shots
```

`STUDIO_SITE` and `WP_DOCS_CONTENT` override the site directory and the content checkout.

### wp-env

```bash
export PATH="$HOME/.docker/bin:$PATH"     # Docker Desktop's CLI; `open -a Docker` if `docker info` fails
source ~/.nvm/nvm.sh && nvm use 24
npm ci
npm run start      # wp-env on http://localhost:8889 (admin / password)
npm run setup      # activates plugins and theme, pretty permalinks, imports the manifest
```

Then open http://localhost:8889/docs/. Day to day:

```bash
npm run import                                   # wp wp-docs import (only changed pages)
npx wp-env run cli wp wp-docs import --force     # re-import every page
npx wp-env run cli wp wp-docs status
npx wp-env run cli cat /tmp/wp-errors.log        # PHP notices from WP-CLI (the web container has its own:
npx wp-env run wordpress cat /tmp/wp-errors.log  # same path in the wordpress container)
```

`.wp-env.json` pins WordPress 7.1.3, PHP 8.3, Jetpack 16.3.1 (its Markdown parser is what the importer uses) and
Code Syntax Block 3.2.1. It mounts `../wordpress-documentation` at `wp-content/wp-docs-content` and sets:

| Constant | Local value |
|---|---|
| `WP_DOCS_MANIFEST` | `/var/www/html/wp-content/wp-docs-content/manifest.json` |
| `WP_DOCS_PREFER_LOCAL` | `true` |
| `WP_DOCS_WEBHOOK_SECRET` | `local-webhook-secret` |

### Sample content

`tools/env/sample-content/` is a stand-in for the content repository: eight pages in two sections that exercise
every content feature (front matter, all five GitHub alert types, code with languages, a table, `<kbd>`,
`<details>`, a relative image in `images/`, relative links, a placeholder page, `redirect_from`) and a
`manifest.json` in the same format. Import it instead of the content repository with a manifest override:

```bash
npx wp-env run cli wp wp-docs import --manifest=/var/www/html/wp-docs-tools/sample-content/manifest.json
# or, for a fresh site:
npx wp-env run cli bash wp-docs-tools/setup.sh /var/www/html/wp-docs-tools/sample-content/manifest.json
```

The override lasts one run: the content checkout becomes the manifest's directory, and the next normal import (or
the cron) goes back to `WP_DOCS_MANIFEST` and unpublishes the sample pages that are not in it. To keep the sample
as the configured manifest, put `{"config": {"WP_DOCS_MANIFEST": "/var/www/html/wp-docs-tools/sample-content/manifest.json"}}`
in `.wp-env.override.json` (ignored by git) and run `npm run start` again.

## Editing in WordPress

Writers who prefer the editor can use it: a save on a docs page is written to the content repository before it
is stored, so GitHub stays the source of truth and the site never shows something the repository does not say.

- Editors and administrators (`wp_docs_push`, mapped from `edit_others_posts`) commit straight to the branch. The
  page is live right away; the import that follows converges the stored content on the canonical conversion.
- Everyone else who can edit gets a branch and a pull request; the page changes when it is merged. Set
  `WP_DOCS_GITHUB_MODE` to `review` to put everyone on that path.
- The Markdown written back is a minimal diff: unchanged paragraphs, lists and tables stay byte for byte, and
  the result is verified by converting it again and comparing it with what was saved. A block that would not
  survive the trip refuses the save and says which one. `php plugins/wp-docs/tests/run-roundtrip.php` runs
  that check over every page of the content checkout (236 of 237 pass; the one left is a ported list whose
  paragraph wrapping the parser decides in a way the writer does not model).
- Slug, parent, order, status and deletion stay on GitHub, and new pages start there too. The editor only
  offers the blocks that round-trip and no styling options.

The site needs `WP_DOCS_GITHUB_TOKEN` (contents and pull requests write access on the content repository);
`tools/studio/setup.sh` takes it from the environment or from `gh auth token`. `wp wp-docs push <page>` shows the
diff a push would commit for the stored content, and `--commit` commits it. CONTRACT.md describes the whole
flow.

## Endpoints

| URL | Returns |
|---|---|
| `/docs/` and `/` | the home (the manifest's `index`, rendered by the theme) |
| `/docs/<path>/` | a page |
| `/docs/<path>.md`, `/docs/index.md`, `?output_format=md`, `Accept: text/markdown` | the page as Markdown: front matter (`title`, `description`, `type`, `url`, `source`), then the body with its H1, links and images absolute |
| `/llms.txt` | title, summary, one line per page grouped by section |
| `/llms-full.txt` | every page's Markdown in sidebar order, separated by `---` |
| `/docs/index.json` | `{ generated, pages: [...] }` in sidebar order |
| `/wp-json/wp-docs/v1/search-index` | the search palette's index |
| `POST /wp-json/wp-docs/v1/import` | the import webhook |
| old WordPress.org paths from `redirect_from` | 301 to the page |

Details, constants and filters: `plugins/wp-docs/README.md`.

## Plugin

`plugins/wp-docs/` (plugin name "WordPress Documentation") is a subclass of the stock importer in
`plugins/wporg-markdown/`, which stays byte-identical to the WordPress.org copy. It registers the `docs` post
type, imports the manifest, writes the meta the theme reads, serves the endpoints above, runs the webhook and the
cron, and provides `wp wp-docs import|status|convert`. It also loads copies of the WordPress.org global header,
footer and fonts from `wporg-mu-plugins` (unchanged, `plugins/wp-docs/vendor/PATCHES.md`), so
`wporg/global-header` and `wporg/global-footer` work on a site outside the WordPress.org network: the logo and
"Get WordPress" go to wordpress.org, and the header's search searches WordPress.org.

It began as the hub plugin of [ndiego/wporg-docs](https://github.com/ndiego/wporg-docs) and dropped what this
site does not have (tabs, audiences, spokes, per-section manifests, offline manifests).

Tests run with plain PHP, no WordPress install:

```bash
cd plugins/wp-docs
tests/fetch-deps.sh && php tests/run-unit.php && php tests/run-fixtures.php
```

## Theme

See themes/wp-docs/README.md

## Deploying to WordPress.com

Untested end to end. What it would take, on a Business plan site (plugins and themes can be installed, and
GitHub Deployments are available):

1. **Code.** Connect this repository with GitHub Deployments (Settings > Hosting or the site's Deployments page)
   three times, or once with the advanced (workflow) mode: `plugins/wp-docs` to `wp-content/plugins/wp-docs`,
   `plugins/wporg-markdown` to `wp-content/plugins/wporg-markdown`, `themes/wp-docs` to
   `wp-content/themes/wp-docs`. Nothing needs a build step. Exclude `plugins/wp-docs/tests/`.
2. **Plugins.** Jetpack is already there on WordPress.com (the importer needs its Markdown library, loaded from
   `JETPACK__PLUGIN_DIR`; untested on WordPress.com). Install Code Syntax Block from the plugin directory.
   Activate `wporg-markdown`, then `wp-docs`, then the theme. Set permalinks to "Post name".
3. **Constants.** In `wp-config.php` (over SFTP or SSH), or in a small must-use plugin if editing
   `wp-config.php` is not available:
   - `WP_DOCS_WEBHOOK_SECRET`: a long random string;
   - `WP_DOCS_GITHUB_TOKEN`: optional, a fine-grained read-only token for the content repository, so pages show
     the commit they were imported from;
   - leave `WP_DOCS_MANIFEST` unset (the default is the raw GitHub URL of `manifest.json` on `trunk`) and never set
     `WP_DOCS_PREFER_LOCAL` there.
4. **First import.** `wp wp-docs import` over SSH (WP-CLI is available on Business sites), or call the webhook.
   The first run creates every page and sideloads every image; on this machine 237 pages took about 11 seconds
   from local files, longer from GitHub.
5. **Webhook.** In the content repository, store the same secret as an Actions secret and add a step after the
   manifest is committed on `trunk`:

   ```bash
   curl -fsS -X POST -H "X-WP-Docs-Secret: $WP_DOCS_WEBHOOK_SECRET" https://<site>/wp-json/wp-docs/v1/import
   ```

   The import runs inside the request. A large change set could exceed the host's request time limit; the cron
   run every 15 minutes picks up whatever was left (untested on WordPress.com).

Untested on WordPress.com: all of the above, in particular whether `/llms.txt` and `/llms-full.txt` at the site
root reach WordPress (they are rewrite rules, not files), how the edge cache treats `Vary: Accept` on page URLs
(the `.md` URLs do not depend on it), and the request time limit for the webhook.

## Static export

`npm run export` (`node tools/export-static.mjs`) crawls the running site (default `--base http://localhost:8889`)
into `static/` (gitignored) for Spacefast or any static host. It is read-only against the site. Measured on
2026-10-26: 237 pages, 237 Markdown files, 107 assets, 341 redirects, about 32 MB, 8 seconds.

- Pages: every `/docs/` page as `<path>/index.html` (from `/docs/index.json` and the links on each page), the home
  as `/index.html` and `/docs/index.html`, and the 404 template (from `/docs/does-not-exist/`) as `/404.html`.
- Markdown: Spacefast does not serve `.md` but does serve `.txt`, so each page's `<path>.md` is saved as
  `<path>/markdown.txt`, and every `.md` URL is rewritten to it: the `rel="alternate"` link, View as Markdown,
  `data-md-url` (what Copy page fetches), the Open in ChatGPT and Claude prompts, `/llms.txt`,
  `/llms-full.txt` and `/docs/index.json`.
- Search: `/wp-json/wp-docs/v1/search-index` is saved as `/docs/search-index.json` and the docs bar's
  `data-index-url` points at it (no fallback route, no server search). Search forms that post to this site are
  removed (the 404 page's); the home hero field stays without an action (it only opens the palette); the global
  header's wordpress.org search is untouched.
- Assets on this host (stylesheets, scripts, script modules and the import map, fonts, uploads, Prism's language
  components for the languages used) are downloaded; absolute URLs become root-relative and `?ver=` is dropped.
  Remote images stay remote. Font files the vendored global fonts stylesheet declares but that are not vendored
  are listed as "not failures": the site does not have them either and no page uses them.
- Shared fragments: inline stylesheets over 1 KB become hashed files under `/shared/css/`, and the sidebar list
  (identical on every page once the current-page markers are removed) becomes one hashed script under `/shared/`
  that writes it during parsing, followed by an inline script that marks the current page and opens its
  ancestors before `sidebar.js` runs. Pages are about 105 KB instead of 215 KB. A page whose sidebar differs
  keeps its own copy and is reported.
- `sf.jsonc`: `cleanUrls`, the title and description, and the plugin's `redirect_from` map as 301s, read from the
  `wp_docs_redirects` option with `npx wp-env run cli wp eval` (the plugin has no endpoint for it), or from
  `--redirects map.json` (`{ "/old/path": "/docs/new/" }`).
- `--public-url https://example.com` makes URLs absolute in the agent files, the Markdown, canonical links and
  the AI prompts. Without it they are root-relative, and a small inline script puts the host's origin into the
  prompt and "Report a problem" links at runtime.

Failures (non-200 pages, Markdown, assets, agent files, redirects) are listed at the end; the script exits
non-zero only when it crashes. Checked on 2026-10-26 by serving `static/` with `python3 -m http.server` and
opening the home, a section landing and four leaf pages in Playwright: no failed requests or 404s, none to
the wp-env site, the sidebar state identical to the live site's on each page, the palette returns results,
Copy page fetches `markdown.txt` and fills the clipboard. Not tested on Spacefast itself.

## Status

2026-10-26.

- Plugin: done for the proof of concept. All 237 pages of the content repository import with no PHP notices and
  no warnings; a second import changes nothing (0 updated, posts and meta checksums identical). Every endpoint
  above answers on http://localhost:8889. `redirect_from` paths 301. Unit tests (95 assertions) and fixtures
  (7 Markdown files, 21 conversions: blocks, classic and agent Markdown) pass.
- Not covered by tests: the WordPress side of the importer (exercised by hand in wp-env only), the GitHub
  (non-local) fetch path with this content repository, commit SHA lookups, WordPress.com.
- Theme: see its README.
