#!/usr/bin/env bash
# Grep ArtQTM Light tree for Full/spec/edition leak patterns.
# Usage: bash tools/grep-light-leaks.sh /absolute/path/to/artqtml
#        bash tools/grep-light-leaks.sh .
# Exit 0 = clean; non-zero = leaks found.
#
# Pattern list: keep in sync with artqtm-light-from-full skill grep gate.

set -euo pipefail

if [[ $# -lt 1 || -z "${1:-}" ]]; then
  echo "Usage: $0 /path/to/artqtml" >&2
  exit 2
fi

ROOT="$(cd "$1" && pwd)"
if [[ ! -d "$ROOT" ]]; then
  echo "Not a directory: $1" >&2
  exit 2
fi

PATTERNS_CS=(
  'Admin-[0-9]+'
  'FR-[0-9]+'
  'UC-[0-9]+'
  'TC-[0-9]+'
  'Full-only'
  'Not included'
  'gates? removed'
  'ArtQTML Light:'
  'ArtQTM Light:'
  'separate Full'
  'Bloom is Full'
  'döntés'
  'Marketplace edition'
  'marketplace-edition'
  'removed from Full'
  'Light only'
  'Light-only'
)

PATTERNS_CI=(
  'not included \(separate'
  'separate full product'
  'dual[- ]edition'
  'strip note'
)

EXCLUDE_ARGS=(
  --exclude-dir=.git
  --exclude-dir=node_modules
  --exclude-dir=vendor
  --exclude-dir=.claude
  --exclude-dir=.devtools
  --exclude-dir=dist
  --exclude=grep-light-leaks.sh
  --exclude=local_ci.sh
  --exclude=LOCAL_CI.md
  --exclude=run_mpc_static.sh
  --exclude='.local-ci-last.txt'
  --exclude='.gates-last.txt'
  --exclude-dir=.mpc
  # Internal review artifacts, not shipped in Marketplace ZIP.
  --exclude='REVIEW-FINDINGS*.md'
)

hits=0

run_grep() {
  local ignore_case="$1"
  local pat="$2"
  # Exit 1 from grep = no match; do not fail the script.
  if [[ "$ignore_case" == "-i" ]]; then
    if grep -RInEi "${EXCLUDE_ARGS[@]}" -e "$pat" "$ROOT" 2>/dev/null; then
      hits=$((hits + 1))
    fi
  else
    if grep -RInE "${EXCLUDE_ARGS[@]}" -e "$pat" "$ROOT" 2>/dev/null; then
      hits=$((hits + 1))
    fi
  fi
  return 0
}

echo "Scanning Light tree: $ROOT"
echo "---"

for pat in "${PATTERNS_CS[@]}"; do
  run_grep "" "$pat"
done

for pat in "${PATTERNS_CI[@]}"; do
  run_grep "-i" "$pat"
done

echo "---"
if [[ "$hits" -gt 0 ]]; then
  echo "FAIL: leak pattern group(s) matched ($hits group(s) with hits). Scrub Light only; re-run." >&2
  exit 1
fi

echo "OK: no leak patterns found."
exit 0
