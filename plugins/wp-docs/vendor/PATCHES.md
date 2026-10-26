# Vendored code

| Directory | Source | Files |
|---|---|---|
| `global-header-footer/` | [WordPress/wporg-mu-plugins](https://github.com/WordPress/wporg-mu-plugins) `mu-plugins/blocks/global-header-footer/` | PHP, `build/`, `images/`, `js/`; `src/` and `postcss/` dropped |
| `global-fonts/` | [WordPress/wporg-mu-plugins](https://github.com/WordPress/wporg-mu-plugins) `mu-plugins/global-fonts/` | `index.php`, `helper-functions.php`, `style.css`, `Inter/`, `IBMPlexMono/`; the other font families are not vendored |

## Patches

- `global-fonts/style.css`: the three `@import` lines for the Noto Serif JP, KR and SC stylesheets are removed, because those families (54 MB) are not vendored and the imports returned 404 on every page and blocked rendering. Everything else is unchanged.

## Shims (in `../inc/global-header-footer.php`, not in the vendored files)

- The copies are loaded only when the real `wporg-mu-plugins` functions are not defined, and the filter
  `wp_docs_load_global_header_footer` can turn them off.
- `wporg-global-fonts` is enqueued on the front end.
- The header and footer REST routes (`/wp-json/global-header-footer/v1/...`, used by Trac, the Codex and
  Planet) are not registered: they rewrite asset URLs to s.w.org paths that do not exist for this copy.
- The `wp_head` preconnect to fonts.googleapis.com and fonts.gstatic.com is removed (the block theme does not
  load Open Sans).
- `$_SERVER['HTTP_HOST']` and `$_SERVER['REQUEST_URI']` are filled from the home URL before the header renders
  outside a web request (WP-CLI), because `set_current_item_class()` reads them.
- A last `render_block_core/search` filter makes sure a search block with a `formAction` (only the header's)
  posts to that URL (https://wordpress.org/search/do-search.php). The vendored
  `swap_header_search_action()` already does this; the shim is a guard.

Checked on this site, without a shim: the logo links to https://wordpress.org/, both "Get WordPress" buttons to
https://wordpress.org/download/, every footer link is an absolute WordPress.org (or partner) URL, and the admin
bar changes in `admin-bar.php` return early in wp-admin.

To update: copy the two directories again from wporg-mu-plugins trunk (keep `build/`), then check the list
above against `blocks.php`, `header.php` and `footer.php`.
