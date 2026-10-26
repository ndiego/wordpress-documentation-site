# Design brief for the wp-docs theme

The goal: a documentation site that is unmistakably WordPress.org (its global header and footer, its type and
palette) and otherwise feels like the best documentation sites of 2026: Linear, Vercel, Stripe, Spacefast,
Cloudflare. Calm, dense where it should be, fast, keyboard friendly, readable on a phone, and built for agents as
much as for people.

## Layout

```
┌──────────────────────────────────────────────────────────────────────────┐
│ WordPress.org global header (dark, from the vendored block)              │
├──────────────────────────────────────────────────────────────────────────┤
│ Docs bar: "Documentation" wordmark · [Search ⌘K ........] · theme · GitHub │  sticky, white, 1px border
├──────────────┬──────────────────────────────────────┬────────────────────┤
│ Sidebar      │ Breadcrumbs                          │ Copy page ▾        │
│ (sticky)     │ GUIDE (eyebrow)                      │ On this page       │
│ Get started  │ # Create a post                      │  · Before you start│
│  ├ What is…  │ Description lede                     │  · Add a title     │
│  ├ Install   │                                      │  · Add blocks      │
│ Write & edit │ Content (max 760px)                  │  · Publish         │
│  …           │                                      │                    │
│              │ Was this page helpful? [Yes] [No]    │                    │
│              │ ← Previous            Next →         │                    │
│              │ Last updated · Edit on GitHub · Report│                    │
├──────────────┴──────────────────────────────────────┴────────────────────┤
│ WordPress.org global footer                                              │
└──────────────────────────────────────────────────────────────────────────┘
```

Widths: sidebar 260px, content column up to 760px of prose, right rail 220px, page max 1320px, 24px gutters.
Below 1100px the right rail collapses into a "On this page" disclosure above the content and the page actions
move into the page header. Below 782px the sidebar becomes a drawer opened from a "Menu" button in the docs bar.

## Pieces

**Docs bar.** Left: a small W mark plus "Documentation" (links to `/docs/`), then a secondary link set, muted:
"User docs" (current, bold), "Developer docs" and "Code reference" as external links to developer.wordpress.org.
This shows where the unified site is going without pretending the other two exist here. Center or right: the search
trigger, a pill with a magnifier, "Search documentation" and a `⌘K` kbd. Right: the color scheme toggle (system,
light, dark) and a GitHub link to the content repo.

**Sidebar.** Groups are the top-level folders, in order, each with a label and a chevron; groups are open by default
and remember their state in `sessionStorage`. Inside a group, pages are a plain list; a page with children is a row
with its own chevron that expands in place. The current page has a tinted background and a 2px blueberry bar on
the left, and its ancestors are expanded. Rows are 32px tall, 14px text, 8px radius. The list scrolls on its own
(not the page) and the current row is scrolled into view on load. The sidebar is one server-rendered block
(`wp-docs/sidebar`) from `wp_docs_get_tree()`, cached per import, with a small Interactivity API or vanilla
module for the chevrons, the drawer and scroll-into-view. No JS is needed to read it.

**Page header.** Eyebrow with the page type (TUTORIAL, GUIDE, CONCEPT, REFERENCE) in 12px uppercase tracking,
tinted per type; H1; the description as a lede in 18px muted text; for `status: placeholder` or `draft`, a banner
under the lede ("This page is a placeholder. It describes something planned; nothing on it is shipped yet." with a
link to the issue tracker) in the warning tint.

**Content.** Inter at 16px/1.65, 760px measure. Headings get an anchor link that appears on hover and a scroll
margin for the sticky bars. Callouts: the four `wporg/notice` classes drawn as a left-bordered panel with an icon
and a title (Note, Tip, Important, Warning), tinted. Code blocks: `code-syntax-block` output, 14px IBM Plex Mono,
a language label and a copy button in the top-right corner, 8px radius, 1px border, light gray background. Inline
code: 0.9em, light gray pill. Tables: full width, 1px borders, header row tinted, horizontal scroll on narrow
screens. Images: 8px radius, 1px border, max-width 100%, centered figure captions in 14px muted. `kbd`: a small
key cap. Lists with comfortable spacing. Links blueberry with underline on hover.

**Right rail.** A "Copy page" split button: the main action copies the page's Markdown (fetched from the `.md`
URL) to the clipboard and changes to "Copied" for two seconds; the chevron opens a menu: View as Markdown (the
`.md` URL), Open in ChatGPT, Open in Claude (both prefilled with "Read https://…/page.md and help me with it"),
Edit on GitHub, Report a problem. Below it "On this page": H2 and H3 of the content, scroll-spy highlighting, 13px.

**Page footer.** "Was this page helpful?" with Yes and No buttons that thank the reader (client-side only; the
real site would post to a feedback endpoint). Then prev/next cards (label "Previous" or "Next", page title) in
sidebar order. Then a meta row: "Last updated <date>", "Edit on GitHub", "Report a problem", "View source" (the
commit when known).

**Home (`/` and `/docs/`).** A hero with "WordPress documentation", a one-line promise, and a large search field.
Four door cards (Get started, Write and edit, Design your site, Manage your site) with an icon, a sentence and
three top links each. A "New to WordPress?" strip linking the Get started path in order. A "WordPress and AI"
strip with the section's pages. A "Block reference" row. A "Classic WordPress" footnote link. Everything is built
from the tree, so a new page shows up without touching the theme.

**Section landing.** The page header, the README prose, then `wp-docs/section-cards`: the children as cards
(title, description, type chip), with grandchildren listed under a group card. Groups within a section render
the same way.

**Search palette.** `⌘K` / `Ctrl+K` / `/`: a modal with an input, results grouped by section, each with the
breadcrumb, a matched heading shown as `# heading`, keyboard navigation, recent picks in `localStorage`. The index
comes from the plugin's `search-index` endpoint. Start from the hub's `search-palette.js` and `.css`
(`~/Developer/wporg-docs/site/themes/wporg-docs-2026/assets/`) and simplify: there is one tab now, no scopes.

**Color scheme.** Light is the default and must look finished first. Dark is supported through CSS custom
properties on `:root` and `[data-scheme="dark"]`, chosen by the toggle (persisted in `localStorage`) or
`prefers-color-scheme`. The global header and footer are dark in both schemes. Code blocks, callouts, cards and
the sidebar all take their colors from the tokens; nothing is hard-coded twice.

## Tokens (theme.json presets and CSS custom properties)

Palette, from WordPress.org: blueberry `#3858e9`, deep blueberry `#213fd4`, blueberry 2 `#1d35b4`,
blueberry 3 (tint) `#eef1fc`, charcoal 1 `#1e1e1e`, charcoal 2 `#23282d`, charcoal 3 `#40464d`,
charcoal 4 `#656a71`, charcoal 5 `#979aa1`, light grey 1 `#f6f6f6`, light grey 2 `#f0f0f0`, light grey 3
`#e0e0e0`, white `#ffffff`, acid green `#33f078`, lemon `#fff972`, pomegrade `#ff2d2e`.
Semantic tokens: `--wp-docs-bg`, `--wp-docs-bg-subtle`, `--wp-docs-fg`, `--wp-docs-fg-muted`,
`--wp-docs-border`, `--wp-docs-accent`, `--wp-docs-accent-tint`, `--wp-docs-code-bg`, and the four callout tints.

Type: Inter (the vendored WordPress.org global fonts stylesheet registers `wporg-global-fonts`; the plugin
enqueues it) for everything, IBM Plex Mono for code. Headings: H1 36/1.2 600, H2 26/1.25 600 with a top margin
of 2.5em, H3 20/1.3 600, H4 16 600. Body 16/1.65. Small 14, micro 13.

Spacing: 4px scale. Radius: 8px for cards and inputs, 6px for buttons, 4px for chips. Shadows: none, except the
search modal and open menus. Motion: 150ms ease on hover states and the chevrons; respect
`prefers-reduced-motion`.

Icons: inline SVG from `@wordpress/icons` (copy, chevronDown, chevronRight, search, external, edit, info,
lightbulb, warning, check), stroke based.

## Accessibility and performance

Semantic landmarks (`header`, `nav aria-label="Documentation"`, `main`, `aside aria-label="On this page"`,
`footer`); skip link; visible focus rings (2px blueberry, 2px offset); the sidebar and palette are keyboard
operable; the color scheme toggle announces its state; contrast at AA on every token pair in both schemes.
No framework. One stylesheet, two small scripts (sidebar and palette), no web fonts beyond the WordPress.org ones,
no third-party requests from the theme. Lighthouse performance at 95 or above on a leaf page.

## Non-goals for this prototype

No versions switcher, no locale switcher, no comments. No admin-side editor UI for the blocks (they are template
only). No build step: plain CSS and JS files, PHP-rendered blocks.
