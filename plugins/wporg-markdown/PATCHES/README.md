# Proposed patches to wporg-markdown (not applied)

The plugin in the parent directory is the WordPress.org Markdown importer copied unchanged (see
`../SOURCE.md`; byte-identical to `notes/reference/wporg-markdown/` in wp-documentation-migration,
copied 2026-10-07). The upstream layout is kept: `plugin.php` requires `inc/class-editor.php` and
`inc/class-importer.php`.

**None of these patches is applied.** The hub plugin (`../../wporg-docs/`) works with the unmodified
importer; each patch replaces a workaround in the hub's subclass with a small change in the engine.
Rationale, compatibility and risks are in `../../wporg-docs/IMPORTER-CHANGES.md`.

| Patch | Change | Replaces in the hub subclass |
|---|---|---|
| `0001-front-matter.patch` | Strict front matter match, BOM dropped, `wporg_markdown_front_matter` action | the `http_response` filter that splits front matter |
| `0002-transform-context.patch` | `$post_id` and source URL passed to `wporg_markdown_before_transform` / `after_transform` | overriding `update_post_from_markdown_source()` to know the current page |
| `0003-get-manifest.patch` | Manifest fetch moved to `protected get_manifest()` | serving the merged manifest through `pre_http_request` |
| `0004-manifest-field-fixes.patch` | `order: 0` applied; unchanged quoted titles no longer rewrite the post | the `update_post_from_manifest()` override's order and comparison code |
| `0005-matching-and-titles.patch` | `$match_by_slug` and `$title_from_manifest` switches | the slug-hijack guard and the title override |
| `all-combined.patch` | 0001 to 0005 in one diff | |

The numbered patches apply in order (`patch -p1` from the plugin directory); applying them in sequence
gives the same file as `all-combined.patch`. All defaults keep today's behaviour except 0004's two bug
fixes. Checked on 2026-10-14: each patched file passes `php -l`, and a full hub import with all five
applied gave the same result as with the unmodified importer.
