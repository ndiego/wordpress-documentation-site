# Sample content

A small stand-in for the content repository (`ndiego/wordpress-documentation`): eight pages in two sections
that exercise every content feature the importer handles (front matter, GitHub alerts of all five types, code
with languages, a table, `<kbd>`, `<details>`, a relative image in an `images/` folder, relative links, a
placeholder page, `redirect_from`). `manifest.json` is in the content repository's format, written by hand.

Import it with the local files preferred over GitHub (the `markdown_source` URLs point at the content
repository, where these files do not exist):

```bash
npx wp-env run cli wp wp-docs import --manifest=/var/www/html/wp-docs-tools/sample-content/manifest.json
```

The content checkout is the manifest's directory, so `docs/...` files are read from here.
