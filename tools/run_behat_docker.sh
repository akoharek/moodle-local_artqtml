#!/usr/bin/env bash
#
# Behat a moodle-docker webserver + selenium konténerben — ugyanaz a szándék, mint a CI behat job.
#
# Használat (a plugin gyökeréből):
#   bash tools/run_behat_docker.sh
#   bash tools/run_behat_docker.sh --init          # kényszerített behat init
#   bash tools/run_behat_docker.sh --tags '@local_artqtm&&@approve'
#
# Felülírható:
#   MOODLE_DOCKER_DIR   moodle-docker könyvtár (alap: ~/projektek/moodle-docker)
#
# Kilépés: 0 zöld, 1 bukás, 2 környezet hiányzik.

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SHORT="$(basename "$PLUGIN_DIR")"
MOODLE_DOCKER_DIR="${MOODLE_DOCKER_DIR:-$HOME/projektek/moodle-docker}"
STAMP="$PLUGIN_DIR/tools/.behat-initialised-at"
TAG="@local_${PLUGIN_SHORT}"
forceinit=0

while [ $# -gt 0 ]; do
    case "$1" in
        --init) forceinit=1 ;;
        --tags)
            shift
            TAG="${1:-}"
            [ -z "$TAG" ] && { echo "HIBA: --tags után kell egy Behat tag kifejezés." >&2; exit 2; }
            ;;
        -h|--help)
            sed -n '3,14p' "$0"
            exit 0
            ;;
        *)
            echo "Ismeretlen kapcsoló: $1" >&2
            exit 2
            ;;
    esac
    shift
done

if [ ! -d "$MOODLE_DOCKER_DIR" ]; then
    echo "HIBA: a moodle-docker könyvtár nem található: $MOODLE_DOCKER_DIR" >&2
    exit 2
fi

COMPOSE=(docker compose -f base.yml -f db.mariadb.yml -f webserver.port.yml)
cd "$MOODLE_DOCKER_DIR" || exit 2

running_services="$("${COMPOSE[@]}" ps --services --status running 2>/dev/null || true)"
if ! printf '%s\n' "$running_services" | grep -qx 'webserver'; then
    echo "HIBA: a moodle-docker webserver nem fut. Indítsd a stacket, majd próbáld újra." >&2
    exit 2
fi
if ! printf '%s\n' "$running_services" | grep -qx 'selenium'; then
    echo "HIBA: a selenium szolgáltatás nem fut (Behat böngésző)." >&2
    exit 2
fi

version="$(grep -oE '\$plugin->version[[:space:]]*=[[:space:]]*[0-9]+' \
    "$PLUGIN_DIR/version.php" | grep -oE '[0-9]+$')"
lastinit="$(cat "$STAMP" 2>/dev/null || echo '')"

if [ "$forceinit" -eq 1 ] || [ "$version" != "$lastinit" ]; then
    echo "==> Behat környezet inicializálása (verzió: ${lastinit:-nincs} -> $version)…"
    if "${COMPOSE[@]}" exec -T webserver php admin/tool/behat/cli/init.php; then
        echo "$version" > "$STAMP"
    else
        echo "HIBA: behat init.php elbukott." >&2
        exit 1
    fi
fi

echo "==> Behat: tags=$TAG"
# Same filter intent as CI: moodle-plugin-ci behat --tags @local_artqtm
"${COMPOSE[@]}" exec -T webserver php admin/tool/behat/cli/run.php --tags="$TAG"
exit $?
