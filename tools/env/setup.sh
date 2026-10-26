#!/bin/bash
# Activates the plugins and the theme, sets pretty permalinks (the importer needs them) and imports the
# manifest from WP_DOCS_MANIFEST (the content checkout). Runs inside the wp-env cli container
# (`npm run setup`, cwd /var/www/html) or, with WP set to another WP-CLI command, anywhere else
# (tools/studio/setup.sh sets WP="studio wp --path=<site>"). Pass a manifest to import something else:
#   npx wp-env run cli bash wp-docs-tools/setup.sh /var/www/html/wp-docs-tools/sample-content/manifest.json
set -e
# WP may be a command with arguments (no spaces inside them); word splitting is intended.
wpcli() { ${WP:-wp} "$@"; }

wpcli plugin activate wporg-markdown jetpack code-syntax-block wp-docs
wpcli theme activate wp-docs
wpcli option update blogname 'WordPress Documentation'
wpcli option update blogdescription 'Learn how to use WordPress'
wpcli option update timezone_string 'UTC'
wpcli rewrite structure '/%postname%/'
wpcli rewrite flush

if ! wpcli post-type get wp-docs --field=name >/dev/null 2>&1; then
	echo "The wp-docs post type is not registered (is plugins/wp-docs active?). Stopping."
	exit 1
fi

if [ -n "$1" ]; then
	wpcli wp-docs import --force --manifest="$1"
else
	wpcli wp-docs import --force
fi
wpcli rewrite flush
wpcli wp-docs status
echo "Done: $(wpcli option get home)/docs/"
