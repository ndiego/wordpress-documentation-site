#!/bin/bash
# Runs inside the wp-env cli container (`npm run setup`), cwd /var/www/html.
set -e

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

wp wp-docs import --force
wp rewrite flush
echo "Done: $(wp option get home)/docs/"
