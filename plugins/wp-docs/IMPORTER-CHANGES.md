# Proposed changes to the WordPress.org Markdown importer

Status: proposal, 2026-10-14. Written to be posted to make.wordpress.org/meta.

## Summary

Every GitHub-backed handbook on developer.wordpress.org (Block Editor, Playground, REST API, Coding
Standards, Advanced Administration, Secure Custom Fields) runs on the `wporg-markdown` plugin and a
per-handbook subclass in the wporg-developer theme. The engine works: in a 2026-10-11 spike it imported
362 pages with every parent, slug, order and title correct and no PHP notices. What it leaves to each
subclass is the part that breaks pages: links, images, callouts, front matter, redirects, removed pages.
Each handbook solves those again, differently, or not at all.

The WordPress.org Docs hub prototype (`ndiego/wordpress-documentation`) needed all of them at once for 1,296 pages
from sixteen sections and seven spoke repositories. This document lists the changes it made, says for each
one whether it needs anything upstream, and attaches small patches for the parts that are better in the
engine. Everything here runs today as a subclass of the **unmodified** importer; the patches are
proposals, not prerequisites.

How it was tested:

- The vendored importer in `site/plugins/wporg-markdown/` is byte-identical to the 2026-10-07 reference
  copy (`plugin.php`, `inc/class-importer.php`, `inc/class-editor.php`); the patches are not applied.
- The pure conversion code has 81 unit assertions and 7 Markdown fixtures (14 conversions, blocks and
  classic) run with plain PHP against the real Jetpack 16.3.1 parser and WordPress 7.1.3 `wpautop`, HTML
  API and block parser (`tests/`).
- End to end on a native WordPress 7.1.3 (SQLite, Jetpack 16.3.1, PHP 8.4): all 1,296 hub pages imported
  in 48 seconds with no PHP notices and 0 errors; every post's title, permalink and parent matched the
  manifest. Renames, removals, restores, redirects, image sideloading, the webhook and REST meta were
  exercised by hand (details under each change). The same run was repeated with all five patches applied
  to the importer: identical result.

References: "spike" is `notes/wporg-handbook-pipeline/research/04-importer-spike.md`; line numbers are in
`wporg-markdown/inc/class-importer.php` (identical to trunk as of 2026-10-07); DevHub files are in
`wporg-developer/source/wp-content/themes/wporg-developer-2023/inc/`.

| | Change | Where it lives now | Upstream? | Patch |
|---|---|---|---|---|
| a | GitHub alerts become notices | subclass (`transforms/alerts.php`) | optional, later | none |
| b | Robust front matter | subclass (`http_response` hook) | **yes** | `0001-front-matter.patch` |
| c | Repository images sideloaded once per content hash | subclass (`transforms/images.php`) | later | none |
| d | Relative Markdown links resolved from the manifest | subclass (`transforms/links.php`) | needs `0002` to be clean | `0002-transform-context.patch` |
| e | Redirects as data (`redirect_from`) | subclass (`redirects.php`) | no | none |
| f | Provenance meta and links | subclass | no | none |
| g | Blocks output mode | subclass (`transforms/blocks.php`) | discuss | none |
| h | Removed pages unpublished, never deleted | subclass | later | none |
| i | Import webhook | subclass (`rest.php`) | no (site plugin) | none |
| | Manifest seam, matching by id, `order: 0`, manifest titles | subclass overrides | **yes** | `0003`, `0004`, `0005` |

## (a) GitHub alerts become WordPress.org notices

**Today.** The parser has no notion of `> [!NOTE]`. The marker is stored as text and shows on the page,
and two alerts separated by a blank line merge into one blockquote because the parser follows
Markdown.pl blockquote rules (spike, section 3: 865 callouts on 174 pages). The Block Editor Handbook
avoids this by writing raw `<div class="callout callout-info">` HTML, which GitHub does not style.

**Change.** Two steps, so alerts can never merge:

1. In `wporg_markdown_before_transform`, each top-level alert outside a code fence (``` or ~~~) becomes
   a Markdown Extra block `<div data-wp-docs-alert="note" markdown="1">`. The parser converts the
   alert body (lists, code, links) as ordinary Markdown inside it. CommonMark lazy continuation lines
   are kept in the alert.
2. After parsing, the placeholder becomes the site's notice markup: in the blocks output mode a
   `wporg/notice` block (`wporg-mu-plugins/mu-plugins/blocks/notice`, the exact markup its `save.js`
   writes); in the classic mode GitHub's own `markdown-alert markdown-alert-note` markup with a
   `markdown-alert-title`, so the classes are the same on GitHub and on WordPress.org. Other formats are
   one filter away: the `[info]` shortcodes mu-plugins renders as notices, DevHub's
   `.callout.callout-info` div, or `wporg/callout` / `core/group` blocks.

Type map (filterable): NOTE is info, TIP is tip, IMPORTANT and WARNING are alert (yellow), CAUTION is
warning (red), matching GitHub's colors to the notice colors.

**Upstream?** Not required. Worth adding to the base class later as an opt-in, because every handbook
would benefit and Gutenberg's 41 raw callout files could then be converted to alerts that GitHub renders.

**Compatibility with the six DevHub handbooks.** None of them uses `> [!TYPE]` today (Block Editor uses
raw divs, Playground uses Docusaurus `:::note` converted by its own subclass), so turning this on
changes nothing for them until they adopt alerts.

**Risks.** `wporg/notice` stores its content as static HTML (`RichText` multiline `p`), not inner
blocks, so an alert containing a list or code is valid on the front end but shows as "unexpected
content" if someone opens it in the editor. Imported pages are not edited in wp-admin, so this is
accepted; `wporg/callout` (inner blocks) is the alternative.

## (b) Robust front matter

**Today.** Line 483 strips front matter with `preg_replace( '#^---(.+)---#Us', '', $markdown )`. The
non-greedy match ends at the first `---` anywhere, so `note: "a value with --- inside it"` corrupts the
page; a UTF-8 BOM makes `^---` fail and the front matter renders as a horizontal rule and a heading
(spike, section 2). The data itself is thrown away.

**Change.** A strict parser: front matter only when the file starts (after an optional BOM) with a line
that is exactly `---`, ending at the next line that is exactly `---` or `...`; LF or CRLF. The YAML
subset the hub uses is parsed without dependencies (the `yaml` extension is used when present). The
subclass splits it off in the `http_response` filter for the page being imported, so the stock regex
receives a body with nothing left to strip. The values are used as fallbacks: `title` (when the manifest
has none), `description` (the excerpt, replacing the stock "first line of text", line 505, which is a
heading when a page starts with one), `audience`, `redirect_from`, `applies_to`.

**Upstream: yes.** `PATCHES/0001-front-matter.patch` replaces the regex with the strict match, drops a
BOM, and fires `wporg_markdown_front_matter` with the raw YAML so subclasses can read it without hooking
HTTP. Behaviour for well-formed front matter is unchanged.

**Compatibility.** Gutenberg has no front matter; Playground's Docusaurus files and the Advanced
Administration and SCF handbooks do, all well formed, so they strip exactly as before. Files that today
start with a `---` thematic break and contain a later `---` line would no longer lose their first
section (a bug fix).

**Risks.** Low. A file that starts with `---` and has no closing fence is now left alone instead of
being stripped up to the next `---`.

## (c) Images in the repository, sideloaded once per content hash

**Today.** Relative image paths are stored as written and break on the site (spike: 2,300 images on 261
pages). The Block Editor convention is to upload by hand to the developer.wordpress.org media library
and paste absolute URLs; only Playground's subclass sideloads (`import-playground.php` lines 980 to
1170, keyed per source URL and only in production).

**Change.** Every `<img>` with a relative `src` is resolved against the Markdown file the way GitHub
resolves it, fetched raw (same host and ref as the page), and imported with `media_handle_sideload()`
once per **SHA-256 of the bytes**, stored as `_wp_docs_image_hash` on the attachment. Re-imports and
other pages that use the same bytes reuse the attachment; a changed image is a new attachment. The tag
gets the attachment URL and `wp-image-<id>` (so the blocks mode emits a real `core/image` with its id),
and keeps the alt text written in the page. WebP works (tested). Absolute image URLs are never touched.

Tested end to end: two names for the same bytes produced one attachment; a second import fetched and
reused it; a missing file was reported and left as written.

**Upstream?** Later. This is the Playground code generalised; once two handbooks need it (the Theme
Handbook migration plans to), the base class is the right home. It needs (d)'s patch to know which file
it is processing.

**Compatibility.** Opt-in per subclass; no effect on handbooks that keep absolute URLs.

**Risks.** Images are fetched on every content change of a page (to hash them). For the hub that is
cheap; a handbook with hundreds of large images on one page may prefer an ETag cache per image URL.
Old attachments are never deleted when an image changes.

## (d) Relative links to Markdown files

**Today.** Nothing is rewritten (spike: 2,254 `.md` links on 273 pages broken). The Block Editor
subclass carries six regexes for its own path conventions (`import-block-editor.php` lines 295 to 335);
Playground builds a separate map (lines 863 to 958).

**Change.** Every `<a href>` is resolved like GitHub resolves it: relative to the current file, or to
the repository root for `/path` (for a spoke checked out under `.spokes/<name>/`, that directory). The
target is looked up among the manifest entries by `markdown_source`, ignoring the ref and matching blob
URLs, raw URLs and repository paths alike, and rewritten to that page's URL with its `#fragment`. A
directory link matches its `README.md` (or `index.md`). A Markdown link that matches nothing is left as
written and logged: 76 across 1,296 hub pages, of which 28 are Docusaurus routes in the Playground spoke,
most of the rest are Gutenberg repository files that are not pages (`CONTRIBUTING.md`, `CHANGELOG.md`,
component READMEs outside the manifest), and 7 are Gutenberg-root links (`/docs/...`) in pages copied
into the hub repository. A
link to any other repository file (a `.php` example) becomes its GitHub blob URL. It uses
`WP_HTML_Tag_Processor`, so `href` text inside escaped code samples is never touched.

**Upstream: the context, yes.** The transform filters receive only the text and the post type (lines
487 and 501), so a subclass cannot know which file it is converting without overriding the protected
`update_post_from_markdown_source()` to stash the post ID (Playground does this; so does this plugin).
`PATCHES/0002-transform-context.patch` passes `$post_id` and the source URL as extra filter arguments.
Existing callbacks registered with two arguments are unaffected.

**Compatibility.** Additive. The Block Editor regexes could be replaced by this resolver, but that is a
separate change for that handbook.

**Risks.** A link to a page in another manifest only resolves when that manifest is loaded in the same
run; the hub always normalises all manifests together for this reason.

## (e) Redirects as data

**Today.** A renamed page needs a second pull request to wporg-developer adding a line to a hard-coded
array (`import-block-editor.php` lines 46 to 179, about 70 entries). The proposal to carry
`redirect_from` in the Gutenberg manifest has stalled since 2023.

**Change.** `redirect_from` travels with the page (manifest, and front matter as a fallback). Same-host
paths are stored in `_wp_docs_redirect_from`; a lookup option (path to post ID) is rebuilt after each
import, and a `template_redirect` handler sends a 301 when a request would otherwise 404. A path that is
the page's own URL is dropped. Full URLs on another host (for example old
`https://developer.wordpress.org/themes/...` pages) cannot be served by this site; they are stored in
`_wp_docs_redirect_external` for a rule on that host. Tested: an old `/documentation/article/...`
path and a renamed page's old URL both returned 301 to the current page.

**Upstream?** No. Redirects are a site concern; any subclass can do this in about 100 lines.

**Compatibility.** Independent of the importer.

**Risks.** Two pages claiming the same old path: the first published one wins and the conflict is not
reported yet (open question 5).

## (f) Provenance and page links

**Today.** The stock `Editor` only derives an edit link for `github.com/.../blob/` sources (line 194);
manifests with raw or relative sources get an edit link to the raw file, which the Block Editor subclass
patches with a `get_post_metadata` filter (lines 224 to 275). Its title filter appends an icon from the
wporg-cli plugin (line 94). There is no "report a problem" link and no record of the imported commit.

**Change.** The importer writes `_wp_docs_source_url`, `_wp_docs_edit_url`, `_wp_docs_issue_url`
(a prefilled new-issue URL whose body carries the page URL, file path and commit),
`_wp_docs_commit` (from the GitHub commits API when a token is configured, otherwise empty) and
`_wp_docs_imported` (ISO 8601), plus id, section, audience, applies-to and generated metadata, all
registered with `show_in_rest`. Offline imports take the GitHub URL from the manifest's `source_url`.
`wp_docs_page_links( $post )` returns them for the theme. The stock Editor is kept for its wp-admin
warning; its title filter is removed.

**Upstream?** No for the hub; but a `get_commit()` hook in the base class would let every handbook show
"last imported commit", which the Theme Handbook plan asks for to detect silent import stalls.

**Risks.** The commits API costs one request per changed page; unauthenticated it is limited to 60 an
hour, hence token only.

## (g) Blocks output mode

**Today.** The parser emits classic HTML and **strips `<p>`**, relying on `wpautop` at render time. Any
block comment in the content makes `has_blocks()` true, core skips `wpautop`, and the whole page loses
its paragraphs (spike, section 3). Handbooks therefore cannot mix in a single block, and the
WordPress.org block styles (code copy buttons, notice blocks) only apply when HTML happens to match.

**Change.** A `wp_docs_output_mode` filter, `blocks` (default) or `classic` (exactly today). In
blocks mode the parser output is run through `wpautop( $html, false )` once at import time (no `<br>`
for soft line breaks, as GitHub renders them) and converted with a deterministic DOM walk:

| HTML | Block |
|---|---|
| `<p>`, stray text and inline elements | core/paragraph |
| `<h1>` to `<h6>` | core/heading, with level; `{#id}` kept as the anchor |
| `<pre><code class="language-x">` | core/code as `<pre class="wp-block-code"><code lang="x" class="language-x">`, the markup Code Syntax Block saves and Prism highlights |
| `<pre>` without `<code>` | core/preformatted |
| `<ul>`, `<ol>` (with `start`) | core/list with core/list-item, nested lists as inner blocks |
| `<table>` | core/table, `align` becomes `has-text-align-*` |
| `<blockquote>` | core/quote with inner blocks |
| lone `<img>`, linked image, `<figure>` with caption | core/image (attachment id from `wp-image-<id>`) |
| `<hr>` | core/separator |
| alert placeholder | wporg/notice |
| `<details>` | core/details with inner blocks |
| anything else | core/html |

Attributes are serialised exactly as core's `serialize_block_attributes()` does. After conversion the
importer checks that no content sits outside a block (own scanner, and `WP_Block_Parser` in the tests);
if any did, that page would be saved in classic mode with a warning. In the 1,296-page run no page fell
back. HTML comments outside blocks are dropped.

**Upstream?** Discuss. The converter is about 800 lines including documentation, with no dependencies, and DevHub already writes
image blocks into classic content from Playground's subclass, so mixed content exists today. Adopting
it in the base class would end the `wpautop` trap for every handbook, but changes the stored markup of
every page; it should stay opt-in per subclass.

**Compatibility.** Opt-in. For a handbook switching to it: stored content changes once (one revision per
page), rendering is the same apart from soft line breaks no longer becoming `<br>`, and `wporg/notice`
must be available (it is on every WordPress.org site through mu-plugins).

**Risks and limits.**
- A list item that holds block content (a code sample inside a numbered step, two paragraphs in one
  item) cannot be a core/list-item, so that whole list becomes one core/html block. It renders the same;
  the code inside loses the `wp-block-code` class (Prism still highlights it).
- `wporg/notice` with lists or code inside is not editable in the block editor (see a).
- Jetpack's parser converts tabs in code to spaces and ends code with a newline (removed by the
  converter); both are parser behaviour, unchanged from today.

## (h) Removed pages

**Today.** Nothing is deleted or unpublished: a page dropped from the manifest stays live and keeps
syncing from its file, then logs an error on every run once the file is gone (spike, section 4, case E).

**Change.** After an import, published pages from the imported manifests whose id is no longer in them
become drafts with `_wp_docs_removed` set to the time; nothing is deleted. A page that returns to the
manifest is republished in place (the stock importer only sees published posts, line 149, and would
otherwise create a duplicate). `wp wp-docs status` lists removed pages. Tested: removing an entry
drafted its post; restoring the entry republished the same post ID.

**Upstream?** Later, as an opt-in in the base class; the base class keeps the manifest entry on every
post already, so it has what it needs.

**Risks.** A manifest that fails to generate a section would unpublish that section. The importer only
considers manifests that loaded and parsed, and only removes pages that came from the manifest being
imported, but a truncated file would still do damage; CI on the manifest is the guard.

## (i) Import webhook

**Today.** Two cron events per handbook every 15 minutes, independent and unordered (DevHub
`import-docs.php` lines 163 to 176), so a new page can be live and empty for one interval. Imports can
only be forced with `wp cron event run`.

**Change.** `POST /wp-json/wp-docs/v1/import` with a shared secret (`X-WP-Docs-Secret`, or GitHub's
`X-Hub-Signature-256` HMAC so a repository webhook can call it directly) schedules an immediate import of
one manifest or all of them and returns 202. One recurring event runs the manifest stage and then the
Markdown stage in order. Tested: wrong secret 403, unknown manifest 400, header and HMAC requests 202 with
the event scheduled.

**Upstream?** No; it belongs to the site. If Meta prefers no inbound endpoints, the same ordered import
is available as `wp wp-docs import`.

**Risks.** The endpoint is unauthenticated apart from the secret; it only schedules work and validates
the manifest name, so the worst case is extra imports.

## Engine fixes found along the way

These came out of running 1,296 pages through the engine. Each is a subclass override in the prototype
and a small patch.

1. **Manifest seam** (`0003-get-manifest.patch`). `import_manifest()` fetches and decodes the manifest
   inline (lines 108 to 131). The patch moves that into `protected function get_manifest()`. The hub
   needs it to merge sixteen manifests, read offline manifests from disk, and normalise entries; without
   it the subclass serves the normalised manifest to `wp_remote_get()` through `pre_http_request`.
2. **`order: 0`** (`0004-manifest-field-fixes.patch`). Line 363 tests `! empty()`, so an order changed to
   0 is ignored (spike, case H). Same patch: the title comparison at line 410 compares a slashed value
   with the stored title, so a title with a quote rewrites the post and bumps `post_modified` every run.
3. **Matching and titles** (`0005-matching-and-titles.patch`). When an entry's key matches no post, the
   importer falls back to any post with the same slug anywhere (lines 169 to 173), which moves an
   unrelated page (spike, case N). The patch adds `$match_by_slug` (default true). It also adds
   `$title_from_manifest` (default false): the first H1 always wins today (line 490) and a manifest title
   change is ignored while the file is unchanged (spike, cases C and D). In the hub, 244 spoke pages
   have an H1 that differs from the designed title.

The hub subclass goes further than the patches: it keys existing posts by a stable page id
(`_wp_docs_id`) through `get_existing_for_post()`, so a rename or a move keeps the post ID; it sets
`post_parent` by id and updates `post_name` when a slug changes (the base never does); and it serves each
page at a designed `path` from the manifest through `post_type_link` and `parse_request`, independent
of the tree. Tested: renaming a slug kept post 143 and its old URL redirected; retitling with quotes in
the title was stored once.

## Compatibility summary for the six DevHub handbooks

All five patches keep today's behaviour by default: new filter arguments are extra arguments, the new
action fires only when someone listens, `get_manifest()` does what the inline code did, and the two new
properties default to the current behaviour. The one behaviour change is `0004`: a manifest `order: 0`
is now applied and titles with quotes stop rewriting posts every run. Block Editor is the handbook most
likely to have `order: 0`; its manifest has no `order` field (array order is used, starting at 0 only
for the first entry, which is created with that order already), so no page moves.

Everything in (a) to (i) is opt-in at the subclass level.

## Open questions

1. Would Meta take `0001` to `0005` as one Trac ticket or five? They are independent and each is under
   80 lines.
2. Should the blocks output mode (g) move into the base class as an opt-in, or stay a hub feature until a
   second handbook asks for it?
3. Is an inbound webhook (i) acceptable on WordPress.org, or should imports be triggered from inside the
   network (for example a GitHub Action calling a Meta-run endpoint)?
4. Where should cross-host redirects live (old developer.wordpress.org handbook URLs pointing at
   wordpress.org/docs/)? This site stores them but cannot serve them.
5. Redirect conflicts (two pages claiming one old path) are resolved first-wins silently. Should the
   import fail, warn, or should CI own this?
6. Commit SHAs need a GitHub token on the server. Is there an existing token for the importer, or should
   the manifest generator write the SHA into the manifest instead (no API calls on import)?
7. The hub resolves ~900 spoke pages from local checkouts in development. In production each page is one
   HTTP request to raw.githubusercontent.com per changed file; is that acceptable for 1,300 pages, or
   should spokes publish a bundle?
