#!/usr/bin/env bash
#
# AMD build freshness + ESLint zero warnings — ugyanaz a szándék, mint a CI grunt lépés.
# Moodle grunt Node 22-t vár; a host gyakran újabb. Ha a host major != 22, Docker node:22-t használ.
#
# Használat: bash tools/run_amd_gate.sh
# Kilépés: 0 zöld, 1 bukás, 2 környezet hiányzik.

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SHORT="$(basename "$PLUGIN_DIR")"
MOODLE_WWWROOT="${MOODLE_WWWROOT:-$HOME/projektek/moodle}"
ROOT_REL="local/${PLUGIN_SHORT}"

if [ ! -d "$MOODLE_WWWROOT" ]; then
    echo "HIBA: Moodle gyökér nem található: $MOODLE_WWWROOT" >&2
    exit 2
fi
if [ ! -d "$MOODLE_WWWROOT/$ROOT_REL" ]; then
    echo "HIBA: a plugin nincs a Moodle fában: $MOODLE_WWWROOT/$ROOT_REL" >&2
    exit 2
fi

host_major="$(node -v 2>/dev/null | sed -E 's/^v([0-9]+).*/\1/' || true)"
use_docker=0
if [ "$host_major" != "22" ]; then
    use_docker=1
    echo "Host Node: ${host_major:-nincs} — Moodle grunthez Node 22 kell → docker node:22"
fi

run_grunt() {
    if [ "$use_docker" -eq 1 ]; then
        docker run --rm \
            -v "$MOODLE_WWWROOT:/moodle" \
            -w /moodle \
            node:22 \
            bash -lc "npx grunt amd --root=$ROOT_REL && npx grunt eslint --root=$ROOT_REL --max-lint-warnings=0"
    else
        (
            cd "$MOODLE_WWWROOT" || exit 2
            npx grunt amd --root="$ROOT_REL"
            npx grunt eslint --root="$ROOT_REL" --max-lint-warnings=0
        )
    fi
}

if ! run_grunt; then
    echo "HIBA: grunt amd/eslint elbukott." >&2
    exit 1
fi

# Build artefacteknek egyezniük kell a forrással — ugyanaz, mint a CI git diff kapu.
if ! git -C "$PLUGIN_DIR" diff --quiet -- amd/build; then
    echo "HIBA: amd/build megváltozott a grunt után — commitold a friss min.js fájlokat." >&2
    git -C "$PLUGIN_DIR" status --short -- amd/build
    exit 1
fi

echo "AMD + ESLint: RENDBEN ($PLUGIN_SHORT)"
exit 0
