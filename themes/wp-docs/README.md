# wp-docs: the documentation theme

A standalone block theme (no parent theme, no build step) that renders the `wp-docs` post type under the
WordPress.org global header and footer with a documentation layout: a sticky docs bar, a sidebar tree, the
page with its type, description and status, a right rail with "Copy page" and "On this page", and a page
footer with feedback, previous and next, and provenance links. The design brief is `../../DESIGN.md`; the
data it reads is described in `../../CONTRACT.md`.

## What is in it

```
theme.json            palette, type, spacing presets; body styles read the semantic tokens
style.css             theme header only
functions.php         setup, block registration, assets, small filters (below)
inc/data.php          data access: the plugin's helpers, with fallbacks to post meta
inc/anchors.php       heading ids and anchor links, the table of contents
inc/render.php        markup shared by blocks (page actions, TOC list, chips)
inc/search.php        search limited to wp-docs, fallback search index route
inc/icons.php         inline SVG icons (@wordpress/icons paths, plus sun, moon, GitHub)
blocks/<name>/        nine server-rendered blocks (block.json + render.php)
parts/                header.html (global header, docs bar), footer.html (global footer)
templates/            single-wp-docs, archive-wp-docs and front-page (the home), search, 404, index
assets/style.css      the only stylesheet that blocks rendering
assets/search-palette.css   the palette dialog, loaded by search-palette.js when idle or on first open
assets/sidebar.js     toggles (sessionStorage), drawer, scroll the current page into view
assets/page-actions.js      Copy page, its menu, feedback, code copy buttons
assets/toc.js         scroll-spy for "On this page" (single pages only)
assets/search-palette.js    the ⌘K palette
```

Everything is plain CSS and JavaScript, enqueued only on the front end, with `defer`. A tiny inline script
in `<head>` adds `html.js` before the first paint; anything that needs scripts (Copy page, feedback, the palette
trigger, sidebar chevrons) is hidden without it.
The sidebar, breadcrumbs, page header, TOC, cards and home are complete HTML from the server.

### Filters in functions.php

| Hook | What it does |
|---|---|
| `pre_render_block` | `wporg/global-header` and `wporg/global-footer` render a plain dark bar and footer when the vendored blocks are not registered |
| `render_block_core/code` | wraps code blocks in a frame with a language label (from `lang` or `language-*`); the copy button is added by `page-actions.js` |
| `render_block` (paragraph, list, table, heading, html, details) and the code filter | encode `[` and `]` inside `<code>` so a shortcode written as an example (`[gallery ids="1,2"]`) is shown, not run by `do_shortcode()` |
| `render_block_core/heading` | keeps the heading's `id` (the plugin writes GitHub ids at import), computes the same id when missing, and appends the hover anchor link on H2 to H4. Only inside the main post content of a docs page |
| `render_block_core/group` | names the right rail landmark `aside aria-label="On this page"` |
| `single_template_hierarchy` | the home post (`_wp_docs_path = ""`) renders with the home template wherever it is reached |
| `pre_get_posts` | front-end search is limited to `wp-docs` |
| `wp_head` | the `html.js` script; a meta description from the page's description |
| `wp_enqueue_scripts` | dequeues code-syntax-block's Prism colors (the theme colors Prism tokens from its own custom properties) |

## Blocks

All are `apiVersion` 3, `inserter: false`, rendered by `render.php`, and read the queried post (they are
template blocks, not content blocks). Each renders nothing when it has nothing to show.

| Block | Inputs | Renders |
|---|---|---|
| `wp-docs/docs-bar` | the tree (section names for the palette), `wp_docs_theme_repo_url` filter | Menu button (below 782px), W mark and "Documentation", User docs / Developer docs / Code reference, the search trigger (with the index URLs), GitHub |
| `wp-docs/sidebar` | the tree, the current post; attribute `drawerOnly` (bool, used by the home: drawer on phones, hidden on wider screens) | `nav#wp-docs-sidebar aria-label="Documentation"`: each section a label link with a chevron, then its pages; a page with children has its own chevron; the current page and its ancestors are open; "Planned" badge for placeholders |
| `wp-docs/breadcrumbs` | `wp_docs_get_breadcrumbs()` | Documentation › ancestors (the current page is the H1) |
| `wp-docs/page-header` | page meta (`type`, `status`, `description`, `issue_url`), TOC items | type eyebrow, H1, lede, the placeholder or draft banner; below 1100px also Copy page and an "On this page" disclosure |
| `wp-docs/page-actions` | page meta (`markdown_url`, `edit_url`, `issue_url`) | Copy page split button; menu: View as Markdown, Open in ChatGPT, Open in Claude (prompt "Read <md url> and help me with it."), Edit on GitHub, Report a problem |
| `wp-docs/toc` | H2 and H3 of the content with their ids; attribute `title` | "On this page" list with scroll-spy (needs at least two headings) |
| `wp-docs/page-footer` | page meta (`updated`, `imported`, `edit_url`, `issue_url`, `source_url`, `commit`), `wp_docs_get_adjacent()` | Was this page helpful? (client-side), Previous / Next cards, Last updated, Edit on GitHub, Report a problem, View source (at the commit when known) |
| `wp-docs/section-cards` | children of the current page; attribute `heading` | "In this section" cards: title, description, type and status chips; a child with children is a group card listing them |
| `wp-docs/home` | the tree and the home post's description | hero with the search field (opens the palette), the four doors (`get-started`, `write`, `design`, `manage`: icon, description, first three pages), New to WordPress? (Get started in order), WordPress and AI (`ai`), Blocks (`blocks` groups with counts), any other section under "More documentation", the Classic WordPress footnote (`classic`) |

### Data and fallbacks

`inc/data.php` calls `wp_docs_get_tree()`, `wp_docs_get_page_meta()`, `wp_docs_get_breadcrumbs()`,
`wp_docs_get_adjacent()`, `wp_docs_markdown_url()` and `wp_docs_path_url()` when they exist. Without the plugin
it builds the same tree shape from a `get_posts()` query and reads `_wp_docs_*` meta directly, so the theme
renders (untested beyond PHP lint without the plugin active). The palette reads
`/wp-json/wp-docs/v1/search-index`; if that route is missing, the theme registers
`/wp-json/wp-docs-theme/v1/search-index` with the same record shape and the palette falls back to it.

Heading ids follow github-slugger (lowercase; keep letters, marks, numbers, connector punctuation, spaces and
hyphens; spaces to hyphens; repeats get `-1`, `-2`). The theme uses the plugin's
`WP_Docs\Transforms\Anchors\slug()` when it is loaded and an identical copy otherwise, and always keeps an id
already in the content, so the TOC, the search index and Markdown `#anchor` links agree.

## Tokens

Presets in `theme.json`: the WordPress.org palette (`blueberry-1`, `deep-blueberry`, `blueberry-2`,
`blueberry-3`, `charcoal-1` to `charcoal-5`, `light-grey-1` to `light-grey-3`, `white`, `acid-green`, `lemon`,
`pomegrade`), font families `inter` (Inter, falling back to `system-ui`) and `monospace` (IBM Plex Mono), sizes
`micro` 13, `small` 14, `normal` 16, `lede` 18, `h4` 16, `h3` 20, `h2` 26, `h1` 36, and a 4px spacing scale
(`10` = 4px to `80` = 64px). Inter and IBM Plex Mono come from the plugin's `wporg-global-fonts` stylesheet,
which the theme's stylesheet depends on when it is registered; no other fonts load.

Semantic custom properties on `:root` in `assets/style.css`. The theme is light only: color scheme is a
WordPress.org site-level concern, not something the documentation decides for itself.

| Token | Use |
|---|---|
| `--wp-docs-bg`, `--wp-docs-bg-subtle`, `--wp-docs-bg-hover` | page, panels and inputs, hover rows |
| `--wp-docs-fg`, `--wp-docs-fg-muted` | text, secondary text |
| `--wp-docs-border`, `--wp-docs-border-strong` | rules and outlines |
| `--wp-docs-accent`, `--wp-docs-accent-hover`, `--wp-docs-accent-strong`, `--wp-docs-accent-tint` | links, current page, focus ring |
| `--wp-docs-code-bg`, `--wp-docs-code-fg`, `--wp-docs-syntax-*` | code blocks and Prism tokens |
| `--wp-docs-note[-bg]`, `--wp-docs-tip[-bg]`, `--wp-docs-important[-bg]`, `--wp-docs-warning[-bg]` | callouts (`is-info-notice`, `is-tip-notice`, `is-alert-notice`, `is-warning-notice`, and GitHub's `markdown-alert-*`) |
| `--wp-docs-type-<type>[-bg]` | tutorial, guide, concept, reference eyebrows and chips |
| `--wp-docs-bar-height`, `--wp-docs-sidebar` 260px, `--wp-docs-rail` 220px, `--wp-docs-measure` 760px, `--wp-docs-page` 1320px, `--wp-docs-gutter` 24px (16px on phones) | layout |
| `--wp-docs-radius` 8px, `--wp-docs-radius-button` 6px, `--wp-docs-radius-chip` 4px, `--wp-docs-ease` 150ms | shape and motion |

Every text and background pair above was checked at AA (4.5:1); the lowest is 4.78.

Breakpoints: below 1100px the rail goes and its actions and TOC move into the page header; below 782px the
sidebar becomes a drawer opened from the docs bar's Menu button.

## Screenshots

```sh
export PATH="$HOME/.docker/bin:$PATH"; source ~/.nvm/nvm.sh && nvm use 24
npm run start                        # http://localhost:8889
npm run shots                        # every scene into tools/shots/ (gitignored)
ONLY=palette,drawer npm run shots    # some scenes
SECTION=/docs/write/ LEAF=/docs/write/media/add-media/ QUERY=image npm run shots
```

Scenes: `home`, `section`, `leaf`, `leaf-full`, `palette`, `drawer` (390 only), `menu` (1440 only), at 1440
and 390 pixels. The script prints any response of 400 or more per page.

## Checked

- Keyboard (Playwright, 2026-10-26): skip link first; sidebar chevrons with Enter and Space; Copy page menu
  opens with ArrowDown, moves with the arrows, closes with Escape and returns focus; palette opens with `/` and Ctrl+K, arrows set `aria-activedescendant`, Enter
  navigates, Escape closes; drawer focuses its close button, traps Tab, closes on Escape and returns focus.
  Every TOC link resolves to a heading id; no horizontal scroll at 390px; no script errors.
- Lighthouse 12.6.1 on `/docs/write/create-a-post/`: mobile Performance 97, Accessibility 100; desktop
  Performance 100, Accessibility 100. The home: mobile Performance 98. Remaining failures are not the
  theme's: console 404s for three NotoSerif stylesheets imported by the vendored global fonts, and link text
  "More" / "Read More" (page titles in the sidebar).

## Known gaps

- Feedback is client-side only ("Thanks"), as the brief says; there is no endpoint.
- The palette searches titles, headings, breadcrumbs and descriptions, not full text; Enter on an empty
  result goes to the search template, which uses WordPress core's relevance order.
- Callout titles (Note, Tip, Important, Warning) are CSS generated text, so they are not translatable. IMPORTANT
  and WARNING both arrive as `is-alert-notice` and both read "Important".
- The page is about 210 KB of HTML because every page carries the whole sidebar (237 pages); it compresses well,
  but a lazy-loaded tree would be lighter at thousands of pages.
- Without JavaScript the drawer does not exist; below 782px the sidebar then follows the content.
- The no-plugin fallback path and the fallback search index were tested only by PHP lint and by rendering the
  header and footer fallbacks with the global blocks unregistered.
- Developer docs and Code reference in the docs bar are plain external links; there is no set switcher.
