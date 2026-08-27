#!/usr/bin/env bash
# Build a Moodle Marketplace ZIP for ArtQTML: top-level folder artqtml/, lang/en only.
# Output: dist/local_artqtml-<version>.zip only. No unprefixed artqtml.zip alias.
# Previous versioned zips are moved to dist/old/.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(grep -E '^\s*\$plugin->version' "$ROOT/version.php" | head -1 | sed -E 's/[^0-9]//g')"
if [[ -z "$VERSION" ]]; then
  echo "Could not read \$plugin->version from version.php" >&2
  exit 1
fi
OUTDIR="${1:-"$ROOT/dist"}"
mkdir -p "$OUTDIR"
STAGING="$(mktemp -d)"
trap 'rm -rf "$STAGING"' EXIT

DEST="$STAGING/artqtml"
mkdir -p "$DEST"

# Copy runtime tree, then remove Marketplace excludes.
rsync -a \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude '.cursor/' \
  --exclude '.agents/' \
  --exclude '.claude/' \
  --exclude '.devtools/' \
  --exclude 'node_modules/' \
  --exclude 'screens/' \
  --exclude 'assets/' \
  --exclude 'tests/' \
  --exclude 'tools/' \
  --exclude 'dist/' \
  --exclude 'amd/src/' \
  --exclude 'CLAUDE.md' \
  --exclude 'CHANGES.md' \
  --exclude 'REVIEW-FINDINGS.md' \
  --exclude 'skills-lock.json' \
  --exclude 'phpcs.xml' \
  --exclude 'phpstan.neon' \
  --exclude 'phpstan-bootstrap.php' \
  --exclude 'phpunit.xml' \
  --exclude '.semgrepignore' \
  --exclude '.DS_Store' \
  --exclude '*.lic' \
  --exclude '*.pem' \
  "$ROOT/" "$DEST/"

# Marketplace: English lang only (repo may still contain hu for development).
if [[ -d "$DEST/lang" ]]; then
  find "$DEST/lang" -mindepth 1 -maxdepth 1 -type d ! -name 'en' -exec rm -rf {} +
fi
if [[ ! -f "$DEST/lang/en/local_artqtml.php" ]]; then
  echo "Missing lang/en/local_artqtml.php in staging" >&2
  exit 1
fi

# Drop empty leftover dirs / maps if any
find "$DEST" -name '.DS_Store' -delete

ZIPNAME="local_artqtml-${VERSION}.zip"
ZIPPATH="$OUTDIR/$ZIPNAME"
rm -f "$ZIPPATH"
(cd "$STAGING" && zip -qr "$ZIPPATH" artqtml)

# Sanity checks
python3 - << PY
import zipfile, sys
z = zipfile.ZipFile("$ZIPPATH")
names = z.namelist()
assert any(n.startswith("artqtml/") for n in names), "top-level must be artqtml/"
assert "artqtml/version.php" in names
assert "artqtml/lang/en/local_artqtml.php" in names
assert "artqtml/COPYING.txt" in names
assert "artqtml/README.md" in names
assert "artqtml/CHANGES.md" not in names
assert "artqtml/REVIEW-FINDINGS.md" not in names
# Allow directory entries artqtml/lang/ and artqtml/lang/en/; forbid other lang packs.
bad_lang = [
    n for n in names
    if n.startswith("artqtml/lang/")
    and n not in ("artqtml/lang/", "artqtml/lang/en/")
    and not n.startswith("artqtml/lang/en/")
]
assert not bad_lang, bad_lang
forbidden_prefixes = (
    "artqtml/tests/",
    "artqtml/tools/",
    "artqtml/.git/",
    "artqtml/CLAUDE.md",
    "artqtml/BACKLOG.md",
    "artqtml/CHANGES.md",
    "artqtml/REVIEW-FINDINGS.md",
    "artqtml/license.php",
)
for f in forbidden_prefixes:
    hits = [n for n in names if n == f or n.startswith(f)]
    assert not hits, f"forbidden in zip: {f} -> {hits[:5]}"
print("OK", "$ZIPPATH", "files", len(names))
PY

# dist/ root = current local_artqtml-<version>.zip only; previous versioned zips → dist/old/.
mkdir -p "$OUTDIR/old"
for prev in "$OUTDIR"/local_artqtml-*.zip; do
  [[ -f "$prev" ]] || continue
  if [[ "$(basename "$prev")" != "$ZIPNAME" ]]; then
    mv "$prev" "$OUTDIR/old/"
  fi
done
rm -f "$OUTDIR/artqtml.zip"

echo "Wrote $ZIPPATH"
