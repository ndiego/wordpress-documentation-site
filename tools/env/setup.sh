#!/bin/bash
# Runs inside the wp-env cli container (`npm run setup`), cwd /var/www/html.
# Activates the plugins and the theme, sets pretty permalinks (the importer needs them) and imports the
# manifest from WP_DOCS_MANIFEST (the mounted content checkout). Pass a manifest to import something else:
#   npx wp-env run cli bash wp-docs-tools/setup.sh /var/www/html/wp-docs-tools/sample-content/manifest.json
set -e

wp plugin activate wporg-markdown jetpack code-syntax-block wp-docs
wp theme activate wp-docs
wp option update blogname 'WordPress Documentation'
wp option update blogdescription 'Learn how to use WordPress'
wp option update timezone_string 'UTC'
wp rewrite structure '/%postname%/'
wp rewrite flush

if ! wp post-type get wp-docs --field=name >/dev/null 2>&1; then
	echo "The wp-docs post type is not registered (is plugins/wp-docs active?). Stopping."
	exit 1
fi

if [ -n "$1" ]; then
	wp wp-docs import --force --manifest="$1"
else
	wp wp-docs import --force
fi
wp rewrite flush
wp wp-docs status
echo "Done: $(wp option get home)/docs/"
