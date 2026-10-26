#!/bin/bash
# Runs the site in WordPress Studio (native PHP, no Docker) from this checkout:
#
#   npm run studio:setup
#
# Creates the Studio site if it does not exist (WordPress 7.1.3, PHP 8.3, native runtime, access to files
# outside the site directory), links plugins/ and themes/ from this repository into it, writes an mu-plugin
# with the wp-docs constants, installs Jetpack and Code Syntax Block, then activates everything and imports
# the manifest with tools/env/setup.sh. Safe to run again: it only adds what is missing.
#
# Environment: STUDIO_SITE (default ~/Studio/wordpress-documentation), WP_DOCS_CONTENT (default the content
# checkout next to this repository). Pass a manifest path to import something else (see tools/env/setup.sh).
set -e
source "$(dirname "${BASH_SOURCE[0]}")/studio.sh"

if [ ! -f "$CONTENT/manifest.json" ]; then
	echo "No content checkout at $CONTENT (clone ndiego/wordpress-documentation next to this repository, or set WP_DOCS_CONTENT)." >&2
	exit 1
fi
CONTENT="$(cd "$CONTENT" && pwd)"

if [ ! -f "$SITE/wp-config.php" ]; then
	echo "Creating the Studio site at $SITE"
	studio_cli create --path "$SITE" --name "WordPress Documentation" --wp 7.1.3 --php 8.3 \
		--runtime native --file-access all-files \
		--admin-username admin --admin-password password --skip-browser
else
	studio_cli start --path "$SITE" --skip-browser || true
fi

# The plugins and the theme are symlinks into this repository, so edits show up without copying.
for plugin in wp-docs wporg-markdown; do
	ln -sfn "$HERE/plugins/$plugin" "$SITE/wp-content/plugins/$plugin"
done
ln -sfn "$HERE/themes/wp-docs" "$SITE/wp-content/themes/wp-docs"

# The constants that .wp-env.json sets for wp-env. An mu-plugin, so Studio's own edits to wp-config.php
# never touch them.
cat > "$SITE/wp-content/mu-plugins/wp-docs-local.php" <<PHP
<?php
/**
 * Plugin Name: WordPress Documentation (local constants)
 * Description: Points the wp-docs importer at the content checkout. Written by tools/studio/setup.sh.
 */
defined( 'WP_DOCS_MANIFEST' ) || define( 'WP_DOCS_MANIFEST', '$CONTENT/manifest.json' );
defined( 'WP_DOCS_PREFER_LOCAL' ) || define( 'WP_DOCS_PREFER_LOCAL', true );
defined( 'WP_DOCS_WEBHOOK_SECRET' ) || define( 'WP_DOCS_WEBHOOK_SECRET', 'local-webhook-secret' );
defined( 'JETPACK_DEV_DEBUG' ) || define( 'JETPACK_DEV_DEBUG', true );
PHP

wp() {
	"$STUDIO" wp --path="$SITE" "$@"
}
if ! wp plugin is-installed jetpack; then
	wp plugin install jetpack --version=16.3.1
fi
if ! wp plugin is-installed code-syntax-block; then
	# The plugin is closed in the directory; the zip is still there.
	wp plugin install https://downloads.wordpress.org/plugin/code-syntax-block.3.2.1.zip
fi

WP="$STUDIO wp --path=$SITE" bash "$HERE/tools/env/setup.sh" "$@"
