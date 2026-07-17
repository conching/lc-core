#!/usr/bin/env bash
# LC Core — build the distributable plugin zip.
# Gates on tests/run.sh (php -l + python checks + unit tests), then stages the
# tree minus .distignore entries and zips it as dist/lc-core-<version>.zip with
# a top-level lc-core/ directory (standard WP plugin layout).

set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$(pwd)"

VERSION=$(sed -n 's/^ \* Version:[[:space:]]*//p' lc-core.php | head -1 | tr -d '[:space:]')
if [ -z "$VERSION" ]; then
	echo "FATAL: could not read Version from lc-core.php" >&2
	exit 1
fi

echo "== Gate: tests =="
bash tests/run.sh

echo
echo "== Staging lc-core v${VERSION} =="
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/lc-core"

# rsync with .distignore as the exclude list (blank lines/comments stripped).
EXCLUDES=$(mktemp)
grep -v '^\s*#' .distignore | grep -v '^\s*$' > "$EXCLUDES"
rsync -a --exclude-from="$EXCLUDES" ./ "$STAGE/lc-core/"
rm -f "$EXCLUDES"

mkdir -p dist
ZIP="$ROOT/dist/lc-core-${VERSION}.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -rq "$ZIP" lc-core )

echo
echo "== Contents =="
unzip -l "$ZIP"
echo "Built: dist/lc-core-${VERSION}.zip"
