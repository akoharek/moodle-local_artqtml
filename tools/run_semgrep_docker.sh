#!/usr/bin/env bash
#
# Semgrep CE p/php — ugyanaz a SAST ruleset, mint a CI semgrep.yml.
# Docker image: semgrep/semgrep (első futáskor letöltődik).
#
# Használat: bash tools/run_semgrep_docker.sh
# Kilépés: 0 zöld, 1 találat/hiba, 2 Docker hiányzik.

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if ! command -v docker >/dev/null 2>&1; then
    echo "HIBA: docker nincs a PATH-on." >&2
    exit 2
fi

# Mirror CI: scan the plugin tree only; metrics off; error on findings.
docker run --rm \
    -v "$PLUGIN_DIR:/src" \
    -w /src \
    semgrep/semgrep \
    semgrep scan --config p/php --error --metrics=off \
    --exclude='tools' --exclude='tests' --exclude='amd/build' --exclude='.git'
