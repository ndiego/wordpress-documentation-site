#!/bin/bash
# WP-CLI against the Studio site:  npm run studio:wp -- wp-docs status
source "$(dirname "${BASH_SOURCE[0]}")/studio.sh"
exec "$STUDIO" wp --path="$SITE" "$@"
