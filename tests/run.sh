#!/usr/bin/env bash
# Run all three test layers and print a combined result.
# Prereq: tests/config.env (run setup.sh first). For the browser layer, have
# playwright resolvable (npm i playwright in tests/, or export NODE_PATH).
DIR="$(cd "$(dirname "$0")" && pwd)"
[ -f "$DIR/config.env" ] || { echo "no config.env - run: bash tests/setup.sh <wp-path> <base-url>"; exit 1; }
set -a; . "$DIR/config.env"; set +a
fail=0

echo "### Layer 1: PoW core ###"
php "$DIR/pow-core.php" || fail=1

echo; echo "### Layer 2: server gate (agent / HTTP) ###"
php "$DIR/agent.php" || fail=1

echo; echo "### Layer 3: browser (Playwright) ###"
if ( cd "$DIR" && node -e "require.resolve('playwright')" ) 2>/dev/null; then
  ( cd "$DIR" && node browser.mjs ) || fail=1
else
  echo "SKIP: playwright not found. Install it (npm i playwright && npx playwright install firefox)"
  echo "      or export NODE_PATH to a node_modules that has it, then re-run."
fi

echo
[ $fail -eq 0 ] && echo "==> ALL LAYERS PASSED" || echo "==> SOME LAYERS FAILED"
exit $fail
