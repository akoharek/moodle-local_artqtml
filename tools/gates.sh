#!/usr/bin/env bash
#
# Mind a három kaput lefuttatja EGY paranccsal, és a teljes kimenetet FÁJLBA is kiírja.
#
# Miért van ez a fájl. A statikus kapuk és a PHPUnit futtatása a fejlesztő gépén történik (a
# konténerben van a PHP), az eredményt viszont eddig kézzel kellett átmásolni oda, ahol dolgozunk
# vele. Egy 80 soros phpcs+phpmd kimenet átmásolása többe kerül, mint maga a futtatás — és a
# hosszú kimenetet a másolás párszor el is vágta, amiből "két hiba, egy felsorolva" lett.
#
# Ez a szkript ezt szünteti meg: a teljes kimenet a
#
#     tools/.gates-last.txt
#
# fájlba kerül (a repóban, de .gitignore-olva), a terminálban pedig csak egy pár soros összegzés
# marad. A futtatás után elég annyit mondani, hogy lefutott.
#
# Használat:
#     cd ~/projektek/moodle/local/artqtml && bash tools/gates.sh          # mind a három kapu
#     cd ~/projektek/moodle/local/artqtml && bash tools/gates.sh --static # csak phpcs + phpstan
#
# A PHPUnit környezet újrainicializálása (kb. egy perc) NEM fut le minden alkalommal: a szkript
# megjegyzi, milyen plugin-verzió mellett futott utoljára, és csak akkor indítja újra, ha a
# version.php azóta emelkedett. Ez az a csapda, ami miatt a PHPUnit egyébként egy félrevezető
# Moodle-hibaüzenettel áll meg verzióemelés után.
#
# Kilépési kód: 0, ha minden lefuttatott kapu zöld; 1, ha bármelyik hibát talált.

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MOODLE_DOCKER_DIR="${MOODLE_DOCKER_DIR:-$HOME/projektek/moodle-docker}"
LOG="$PLUGIN_DIR/tools/.gates-last.txt"
STAMP="$PLUGIN_DIR/tools/.phpunit-initialised-at"

COMPOSE=(docker compose -f base.yml -f db.mariadb.yml -f webserver.port.yml)

static_only=0
[ "${1:-}" = "--static" ] && static_only=1

if [ ! -d "$MOODLE_DOCKER_DIR" ]; then
    echo "HIBA: a moodle-docker könyvtár nem található: $MOODLE_DOCKER_DIR" >&2
    exit 2
fi

# A napló minden futásnál elölről kezdődik: a "legutóbbi állapot" kérdésre egy fájl feleljen,
# ne egy növekvő napló, amiben meg kell keresni, melyik blokk a mai.
{
    echo "# tools/gates.sh — $(date '+%Y-%m-%d %H:%M:%S')"
    echo "# plugin verzió: $(grep -oE '\$plugin->version[[:space:]]*=[[:space:]]*[0-9]+' \
        "$PLUGIN_DIR/version.php" | grep -oE '[0-9]+$')"
    echo
} > "$LOG"

status=0

# --------------------------------------------------------------------------- 1-2. statikus kapuk
echo "==> statikus kapuk (phpcs, phpstan)…"
bash "$PLUGIN_DIR/tools/check.sh" >> "$LOG" 2>&1
static_status=$?
[ "$static_status" -ne 0 ] && status=1

if [ "$static_only" -eq 1 ]; then
    echo >> "$LOG"
    echo "# a PHPUnit ebben a futásban nem indult (--static)" >> "$LOG"
else
    # ----------------------------------------------------------------------- 3. PHPUnit
    cd "$MOODLE_DOCKER_DIR" || exit 2

    version="$(grep -oE '\$plugin->version[[:space:]]*=[[:space:]]*[0-9]+' \
        "$PLUGIN_DIR/version.php" | grep -oE '[0-9]+$')"
    lastinit="$(cat "$STAMP" 2>/dev/null || echo '')"

    if [ "$version" != "$lastinit" ]; then
        echo "==> PHPUnit környezet újrainicializálása (a verzió $lastinit -> $version)…"
        {
            echo
            echo "==> admin/tool/phpunit/cli/init.php (a plugin verziója emelkedett)"
        } >> "$LOG"
        if "${COMPOSE[@]}" exec -T webserver php admin/tool/phpunit/cli/init.php >> "$LOG" 2>&1; then
            echo "$version" > "$STAMP"
        else
            echo "    az init.php elbukott — lásd a naplót" >&2
            status=1
        fi
    fi

    echo "==> PHPUnit…"
    {
        echo
        echo "==> PHPUnit (local_artqtml_testsuite)"
    } >> "$LOG"
    "${COMPOSE[@]}" exec -T webserver php vendor/bin/phpunit \
        --testsuite local_artqtml_testsuite >> "$LOG" 2>&1 || status=1
fi

# --------------------------------------------------------------------------- összegzés
echo
if [ "$static_status" -eq 0 ]; then
    echo "  statikus kapuk: RENDBEN"
else
    echo "  statikus kapuk: VAN MIT JAVÍTANI"
    # A figyelmeztetés 2026-08-02 óta ugyanúgy bukást jelent, mint a hiba (BL-15), ezért az
    # összegzésnek is mutatnia kell - különben a terminál üres marad egy piros kapu alatt.
    grep -E '^ *[0-9]+ \| (ERROR|WARNING)|^ \[ERROR\]' "$LOG" | head -8 | sed 's/^/    /'
fi

if [ "$static_only" -eq 0 ]; then
    phpunitline="$(grep -E '^(OK|FAILURES|ERRORS|Tests:)' "$LOG" | tail -1)"
    echo "  PHPUnit: ${phpunitline:-nem futott le — lásd a naplót}"
fi

echo
echo "  a teljes kimenet: tools/.gates-last.txt"
exit "$status"
