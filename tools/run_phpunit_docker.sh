#!/usr/bin/env bash
#
# PHPUnit a moodle-docker webserver konténerben — helyi futtatás CI előtt.
#
# Használat:
#   cd ~/projektek/moodle/local/artqtml && bash tools/run_phpunit_docker.sh
#   bash tools/run_phpunit_docker.sh --pass2      # csak tests/local/ (47 fájl)
#   bash tools/run_phpunit_docker.sh --init       # kényszerített init.php
#   bash tools/run_phpunit_docker.sh --file local/artqtml/tests/observer_test.php
#
# Felülírható:
#   MOODLE_DOCKER_DIR   moodle-docker könyvtár (alap: ~/projektek/moodle-docker)
#
# Kilépési kód: 0 ha zöld, 1 ha bukás.

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MOODLE_DOCKER_DIR="${MOODLE_DOCKER_DIR:-$HOME/projektek/moodle-docker}"
STAMP="$PLUGIN_DIR/tools/.phpunit-initialised-at"

COMPOSE=(docker compose -f base.yml -f db.mariadb.yml -f webserver.port.yml)

mode=full
forceinit=0
extrafile=""

while [ $# -gt 0 ]; do
    case "$1" in
        --pass2) mode=pass2 ;;
        --init) forceinit=1 ;;
        --file)
            shift
            extrafile="${1:-}"
            [ -z "$extrafile" ] && { echo "HIBA: --file után kell egy útvonal." >&2; exit 2; }
            mode=file
            ;;
        -h|--help)
            sed -n '3,12p' "$0"
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

cd "$MOODLE_DOCKER_DIR" || exit 2

version="$(grep -oE '\$plugin->version[[:space:]]*=[[:space:]]*[0-9]+' \
    "$PLUGIN_DIR/version.php" | grep -oE '[0-9]+$')"
lastinit="$(cat "$STAMP" 2>/dev/null || echo '')"

if [ "$forceinit" -eq 1 ] || [ "$version" != "$lastinit" ]; then
    echo "==> PHPUnit környezet inicializálása (verzió: ${lastinit:-nincs} -> $version)…"
    if "${COMPOSE[@]}" exec -T webserver php admin/tool/phpunit/cli/init.php --disable-composer; then
        echo "$version" > "$STAMP"
    else
        echo "HIBA: init.php elbukott." >&2
        exit 1
    fi
fi

case "$mode" in
    full)
        echo "==> PHPUnit: local_artqtml_testsuite (368 teszt)"
        "${COMPOSE[@]}" exec -T webserver php vendor/bin/phpunit \
            --testsuite local_artqtml_testsuite
        ;;
    pass2)
        echo "==> PHPUnit: pass-2 (local/artqtml/tests/local/, 47 fájl)"
        "${COMPOSE[@]}" exec -T webserver bash -lc \
            'cd /var/www/html && vendor/bin/phpunit $(find local/artqtml/tests/local -name "*_test.php" | sort)'
        ;;
    file)
        echo "==> PHPUnit: $extrafile"
        "${COMPOSE[@]}" exec -T webserver php vendor/bin/phpunit "$extrafile"
        ;;
esac
