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

Requirements: Docker Desktop, Node 24, and the content repository checked out next to this one
(`../wordpress-documentation`).

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
`plugins/wporg-markdown/`, which stays byte-identical to the WordPress.org copy. It registers the `wp-docs` post
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

## Status

2026-10-26.

- Plugin: done for the proof of concept. All 237 pages of the content repository import with no PHP notices and
  no warnings; a second import changes nothing (0 updated, posts and meta checksums identical). Every endpoint
  above answers on http://localhost:8889. `redirect_from` paths 301. Unit tests (95 assertions) and fixtures
  (7 Markdown files, 21 conversions: blocks, classic and agent Markdown) pass.
- Not covered by tests: the WordPress side of the importer (exercised by hand in wp-env only), the GitHub
  (non-local) fetch path with this content repository, commit SHA lookups, WordPress.com.
- Theme: see its README.
