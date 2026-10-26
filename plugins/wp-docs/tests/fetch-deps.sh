#!/usr/bin/env bash
# Download the pinned WordPress core and Jetpack used by the plain-PHP tests into tests/.deps/.
# Only wpautop, kses/esc_url helpers, the HTML API, the block parser and Jetpack's Markdown library are loaded.
set -euo pipefail
cd "$(dirname "$0")"
WP_VERSION=7.1.3
JETPACK_VERSION=16.3.1
mkdir -p .deps
if [ ! -d .deps/wordpress ]; then
	curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" | tar xz -C .deps
fi
if [ ! -d .deps/jetpack ]; then
	curl -fsSL -o .deps/jetpack.zip "https://downloads.wordpress.org/plugin/jetpack.${JETPACK_VERSION}.zip"
	(cd .deps && unzip -q jetpack.zip 'jetpack/_inc/lib/markdown*' && rm jetpack.zip)
fi
echo "WordPress ${WP_VERSION} and Jetpack ${JETPACK_VERSION} Markdown library in tests/.deps/"
