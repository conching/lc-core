#!/usr/bin/env bash
# LC Core — test runner: lint every PHP file, syntax-check the Python tools,
# then run the standalone unit tests (no WordPress required).
# Exit 0 only if everything passes.

set -u
cd "$(dirname "$0")/.."

FAIL=0
PYCACHE_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/lc-core-pycache.XXXXXX")
trap 'rm -rf "$PYCACHE_ROOT"' EXIT

echo "=== php -l ==="
while IFS= read -r -d '' f; do
	php -l "$f" || FAIL=1
done < <(find . -name '*.php' -not -path './dist/*' -not -path './.git/*' -print0)

echo
echo "=== python3 syntax check ==="
for f in tools/normalize_workbook.py tools/normalize_lib.py tests/test_normalize_lib.py tests/test_normalize_e2e.py; do
	if PYTHONPYCACHEPREFIX="$PYCACHE_ROOT" python3 -m py_compile "$f"; then
		echo "No syntax errors detected in $f"
	else
		FAIL=1
	fi
done

echo
echo "=== PHP unit tests (inc/import-lib.php) ==="
php tests/test-import-lib.php || FAIL=1
php tests/test-kses.php || FAIL=1

echo
echo "=== Python unit tests (tools/normalize_lib.py) ==="
python3 tests/test_normalize_lib.py || FAIL=1

echo
echo "=== Python e2e test (tools/normalize_workbook.py) ==="
python3 tests/test_normalize_e2e.py || FAIL=1

echo
if [ "$FAIL" -eq 0 ]; then
	echo "ALL CHECKS PASSED"
else
	echo "FAILURES — see above"
fi
exit "$FAIL"
