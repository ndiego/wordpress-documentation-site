# The demo, in ten minutes

A walkthrough for showing the proof of concept to people. Each step names what to open and what it proves. The
static snapshot is at https://wpdocs.space.fast/ (same pages, same scripts, no server behind it); the live import
runs on the WordPress site when one is deployed, and locally on http://localhost:8889 after `npm run setup`.

## 1. The front door (one minute)

Open the home. Point out: the WordPress.org global header and footer are the real ones, unchanged; everything
between them is new. Four doors, a numbered "New to WordPress?" path, a WordPress and AI strip with honest
"Planned" chips, the block reference as a row of counts. Toggle dark mode in the docs bar.

What it proves: the site is a WordPress block theme on the WordPress.org header and footer, not a static site
generator pretending to be WordPress.

## 2. A tutorial page (two minutes)

Open Write and edit, then Create a post. Point out the eyebrow (TUTORIAL), the lede, the right rail with "On this
page" and the Copy page button, the screenshots (rendered by the toolkit from a spec, not taken by hand), the
previous and next cards, "Last updated", "Edit on GitHub" and "Report a problem".

Press `⌘K` (or `Ctrl+K`), type "publish", pick a result. Press `/` on any page to open it again.

What it proves: the layout people expect from a 2026 documentation site, built from nine small server-rendered
blocks and three plain scripts, with the whole site searchable from the keyboard.

## 3. The page as Markdown (one minute)

On the same page, open the Copy page menu and choose View as Markdown. Then show the same thing at the URL level:
add `.md` to the page URL on the WordPress site (`/docs/write/create-a-post.md`); the snapshot serves it as
`/docs/write/create-a-post/markdown.txt`. Open `/llms.txt`, then `/docs/index.json`.

What it proves: every page is a first-class Markdown document for agents, with front matter, absolute links and a
site-wide index, served by the WordPress site itself.

## 4. The source (two minutes)

Click Edit on GitHub. Show the content repository: the folder tree under `docs/` is the structure, the front
matter is six fields, `manifest.json` is generated. Open `STRUCTURE.md` for the information architecture and the
map from today's 321 HelpHub articles. Open `.github/workflows/publish.yml`: on merge, CI validates, rebuilds the
manifest and pings the site.

What it proves: the write path is a pull request, reviewed, with CI, and the site needs no editor of its own.

## 5. The import (two minutes, live site or local)

Change a sentence in a page on a branch, merge it (or, locally, edit the file and run `npm run import`). Reload
the page. Show `wp wp-docs status` or the webhook response.

What it proves: WordPress stays the renderer and the publishing system; the stock WordPress.org Markdown
importer does the work, with one subclass.

## 6. Screenshots that re-render (one minute)

Open `screenshots/shots/create-a-post.yaml` in the content repository: twelve lines per image. Say that a
WordPress release means re-rendering and reviewing a diff, not retaking screenshots by hand.

## 7. WordPress and AI (one minute)

Open the AI section. The first page is real (how to use the documentation with an assistant); the rest are
placeholders with a banner and an outline. Say that the section exists so the project can fill it in as the
MCP adapter and the Abilities API land, instead of adding it as an afterthought.

## What to say it is not

- Not a proposal to leave WordPress: the site is WordPress, the importer is WordPress.org's.
- Not finished content: 218 of the 237 pages are ported HelpHub articles, cleaned up but not rewritten. The
  19 new pages show the voice; the rest is the backlog.
- Not the unified site: user documentation only. The docs bar's "Developer docs" and "Code reference" links
  show where the same shell would take the handbooks and the Code Reference next.

## Links

- Content: https://github.com/ndiego/wordpress-documentation
- Site: https://github.com/ndiego/wordpress-documentation-site
- Snapshot: https://wpdocs.space.fast/
- Background: https://github.com/ndiego/wp-docs-platform (the platform proposal) and
  https://github.com/ndiego/wp-documentation-migration (the HelpHub conversion)
