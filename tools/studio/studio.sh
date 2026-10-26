#!/bin/bash
# The Studio CLI for the documentation site, found wherever Studio put it. Every other script here sources this.
#   studio_cli                      the CLI command
#   SITE                            the Studio site directory (STUDIO_SITE, default ~/Studio/wordpress-documentation)
#   CONTENT                         the content checkout (WP_DOCS_CONTENT, default ../wordpress-documentation)
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SITE="${STUDIO_SITE:-$HOME/Studio/wordpress-documentation}"
CONTENT="${WP_DOCS_CONTENT:-$HERE/../wordpress-documentation}"

if command -v studio >/dev/null 2>&1; then
	STUDIO="$(command -v studio)"
elif [ -x /Applications/Studio.app/Contents/Resources/bin/studio-cli.sh ]; then
	STUDIO=/Applications/Studio.app/Contents/Resources/bin/studio-cli.sh
else
	echo "WordPress Studio is not installed (https://developer.wordpress.com/studio/). Install it, or enable" >&2
	echo "Settings > General > Studio CLI for terminal and open a new terminal." >&2
	exit 1
fi

studio_cli() {
	"$STUDIO" "$@"
}
