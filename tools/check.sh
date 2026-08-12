#!/usr/bin/env bash
#
# A helyi statikus ellenőrzés — ugyanaz, amit a CI futtat, nem valami hasonló.
#
# Miért van ez a fájl (BL-15): a phpcs és a phpstan a CI megjelenéséig kézzel futott, utána
# csendben abbamaradt. 2026-07-29-én három phpcs hiba egy-egy push-körrel *később* derült ki,
# mindhárom ugyanaz a PSR12 szabály. A parancsok nem hiányoztak — csak fejből kellett őket
# újragépelni, és ez a szokás halála. Egy 30 másodperces ellenőrzés helyett 16 perces kört
# fizettünk.
#
# Mit ellenőriz ezen felül (BL-21): hogy MELYIK eszközkészletet futtatta, és hogy a phpstan
# verziója egyezik-e azzal, amit a CI használ. Ez a két kérdés eddig senkinek nem tűnt volna fel,
# amíg egy build el nem bukik — és akkor már késő.
#
# Használat:
#     cd ~/projektek/moodle/local/artqtml && bash tools/check.sh
#
# Felülírható környezeti változók:
#     MOODLE_DOCKER_DIR   a moodle-docker könyvtára (alap: ~/projektek/moodle-docker)
#     AIQ_TOOLCHAIN       az eszközkészlet helye a KONTÉNEREN BELÜL, a Moodle gyökeréhez képest
#                         (alap: .devtools). Lásd tools/TOOLCHAIN.md.
#
# Kilépési kód: 0, ha mindkét ellenőrzés hibátlan; 1, ha bármelyik hibát talált. Mindkettő
# lefut akkor is, ha az első elbukik — a teljes képet akarjuk látni, nem az első megállást.

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MOODLE_DOCKER_DIR="${MOODLE_DOCKER_DIR:-$HOME/projektek/moodle-docker}"
AIQ_TOOLCHAIN="${AIQ_TOOLCHAIN:-.devtools}"
PLUGIN_PATH="local/artqtml"

COMPOSE=(docker compose -f base.yml -f db.mariadb.yml -f webserver.port.yml)

if [ ! -d "$MOODLE_DOCKER_DIR" ]; then
    echo "HIBA: a moodle-docker könyvtár nem található: $MOODLE_DOCKER_DIR" >&2
    echo "Add meg a MOODLE_DOCKER_DIR környezeti változóval, ha máshol áll." >&2
    exit 2
fi

cd "$MOODLE_DOCKER_DIR" || exit 2

if ! "${COMPOSE[@]}" ps --status running --quiet webserver | grep -q .; then
    echo "HIBA: a webserver konténer nem fut." >&2
    echo "Indítsd el: cd $MOODLE_DOCKER_DIR && ${COMPOSE[*]} up -d" >&2
    exit 2
fi

# Egy segéd, hogy a konténerhívás egy helyen álljon.
inweb() { "${COMPOSE[@]}" exec -T webserver "$@"; }

# --------------------------------------------------------------------------- az eszközkészlet
#
# BL-21: az eszközök ma a Moodle SAJÁT vendor/ könyvtárában ülnek, mert kézzel bekerültek a Moodle
# composer.json-jába. Ez rossz hely — egy Moodle-frissítés vagy egy visszaállítás szó nélkül elviszi
# őket. A cél egy külön könyvtár, ami nem a Moodle-é.
#
# Ez a szkript MINDKETTŐVEL működik, és mindig kiírja, melyiket használta. Így a költöztetés napján
# nem törik el, és utána sem lehet véletlenül a régire visszacsúszni anélkül, hogy szólna.

# Prefer the plugin's own tools/devtools (phpstan.neon includes spaze rules from there).
# Fall back to AIQ_TOOLCHAIN (.devtools) for phpcs/phpmd bins, then Moodle vendor/.
PLUGIN_DEVTOOLS="$PLUGIN_PATH/tools/devtools"
if inweb test -x "$PLUGIN_DEVTOOLS/vendor/bin/phpstan" 2>/dev/null; then
    TOOLS="$PLUGIN_DEVTOOLS/vendor/bin"
    TOOLS_NOTE="plugin tools/devtools ($PLUGIN_DEVTOOLS) — ez a kívánatos állapot"
    TOOLS_OK=true
elif inweb test -x "$AIQ_TOOLCHAIN/vendor/bin/phpcs" 2>/dev/null; then
    TOOLS="$AIQ_TOOLCHAIN/vendor/bin"
    TOOLS_NOTE="külön eszközkészlet ($AIQ_TOOLCHAIN) — phpstan.neon spaze include-hoz kell a plugin tools/devtools vendor"
    TOOLS_OK=true
else
    TOOLS="vendor/bin"
    TOOLS_NOTE="a MOODLE SAJÁT vendor/ könyvtára"
    TOOLS_OK=false
fi

echo "==> Eszközkészlet: $TOOLS  —  $TOOLS_NOTE"
if [ "$TOOLS_OK" = false ]; then
    echo "    FIGYELEM (BL-21): az eszközök a Moodle fájában vannak. Egy Moodle-frissítés vagy egy"
    echo "    'git checkout -- .' nyom nélkül elviszi őket. A visszaállítás módja: tools/TOOLCHAIN.md"
fi
echo

status=0

# phpstan.neon includes spaze rules from this path; without it analyse fails immediately.
if ! inweb test -f "$PLUGIN_DEVTOOLS/vendor/spaze/phpstan-disallowed-calls/extension.neon" 2>/dev/null; then
    echo "HIBA: hiányzik a spaze/phpstan-disallowed-calls a $PLUGIN_DEVTOOLS/vendor alatt."
    echo "Telepítés: lásd tools/TOOLCHAIN.md (composer install a plugin tools/devtools-ben)."
    echo
    status=1
fi

# --------------------------------------------------------------------------- phpcs
echo "==> PHP CodeSniffer (Moodle standard, $PLUGIN_PATH/phpcs.xml)"
phpcs_out="$(inweb php "$TOOLS/phpcs" --standard="$PLUGIN_PATH/phpcs.xml" "$PLUGIN_PATH" 2>&1)"
phpcs_code=$?

if [ -n "$phpcs_out" ]; then
    echo "$phpcs_out"
else
    echo "    üres kimenet — nincs se hiba, se figyelmeztetés"
fi

# A két szám külön is kiírva, mert a bukás okát a nyers phpcs-kimenetből kikeresni lassabb, mint
# elolvasni. 2026-08-02 óta MINDKETTŐ bukást jelent: a phpcs.xml-ből kikerült az
# `ignore_warnings_on_exit`, miután a fa nulla figyelmeztetéssel állt, tehát a szigorítás
# ingyen volt (BL-15).
phpcs_warnings="$(printf '%s\n' "$phpcs_out" | grep -c ' WARNING ')"
phpcs_errors="$(printf '%s\n' "$phpcs_out" | grep -c ' ERROR ')"
echo "    hiba: $phpcs_errors, figyelmeztetés: $phpcs_warnings (mindkettő bukást jelent)"
[ "$phpcs_code" -ne 0 ] && status=1

echo

# --------------------------------------------------------------------------- phpstan
echo "==> PHPStan (level 5, Moodle-aware, $PLUGIN_PATH/phpstan.neon)"
if inweb php -d memory_limit=2G "$TOOLS/phpstan" analyse \
    --configuration="$PLUGIN_PATH/phpstan.neon" --no-progress; then
    :
else
    status=1
fi

echo

# --------------------------------------------------------------------------- phpmd
#
# Ez NEM kapu, és szándékosan nem az. A phpcs és a phpstan azon bukik el, aminek van helyes
# válasza — vagy jól van formázva, vagy nem; vagy létezik az a metódus, vagy nem. A phpmd viszont
# küszöbökön dolgozik ("tíznél több elágazás sok"), és ezek a küszöbök önkényesek. Kapuként a build
# olyasmin bukna el, amiről a fejlesztő ránézésre azt mondja, hogy így van jól — az ilyen kaput
# pedig pár hét alatt megtanulja mindenki megkerülni.
#
# Mérőműszernek viszont jó, és egyszer már bizonyított: a god-class refaktorálásnál (2026-07-25)
# ez mérte ki a három célfájl komplexitását előtte és utána, 16 találattal.
#
# Ezért: lefut, kiírja, amit talált, és NEM állítja a kilépési kódot. A CI-ben nem fut.

echo "==> PHPMD (codesize + design) — tájékoztató, nem kapu"
if inweb test -x "$TOOLS/phpmd" 2>/dev/null; then
    # Exclude db/upgrade.php: Moodle requires a long chain of `if ($oldversion < X)` blocks,
    # one per past version. phpmd reports huge complexity on that shape and would drown real hits.
    phpmd_out="$(inweb php "$TOOLS/phpmd" "$PLUGIN_PATH" text codesize,design \
        --exclude "$PLUGIN_PATH/tools/*,$PLUGIN_PATH/tests/*,$PLUGIN_PATH/node_modules/*,$PLUGIN_PATH/db/upgrade.php" 2>&1)"

    phpmd_hits="$(printf '%s\n' "$phpmd_out" | grep -c "$PLUGIN_PATH")"
    if [ "$phpmd_hits" -eq 0 ]; then
        echo "    nincs komplexitás-találat"
    else
        printf '%s\n' "$phpmd_out"
        echo "    $phpmd_hits találat — ezek ítélet kérdései, nem hibák. A kapu nem bukik rajtuk."
    fi
else
    echo "    nincs telepítve ebben az eszközkészletben — lásd tools/TOOLCHAIN.md"
fi

echo

# --------------------------------------------------------------------------- verzió-egyezés
#
# BL-21: a CI és a helyi futás ugyanazt a phpstan verziót kell hogy használja. Ha szétcsúsznak,
# visszatér a "helyben zöld, CI-n piros" — és arról megint csak egy bukott build szólna.
# Ezt itt derítjük ki, a push ELŐTT, nem utána.

local_ver="$(inweb php "$TOOLS/phpstan" --version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"
ci_ver="$(grep -oE 'PHPSTAN_VERSION:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' \
          "$PLUGIN_DIR/.github/workflows/ci.yml" 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)"

echo "==> PHPStan verzió — helyi: ${local_ver:-ismeretlen}, CI: ${ci_ver:-nincs rögzítve}"
if [ -n "$local_ver" ] && [ -n "$ci_ver" ] && [ "$local_ver" != "$ci_ver" ]; then
    echo "    ELTÉRÉS: a két oldal más verziót futtat. Ilyenkor a helyi zöld nem jelent CI-zöldet."
    echo "    Igazítsd össze: ci.yml PHPSTAN_VERSION, illetve a helyi eszközkészlet."
    status=1
fi

echo
# --------------------------------------------------------------------------- szkriptőrök
# A böngészős tesztkészlet kikerült a repóból; ami megmaradt belőle, az egyetlen, böngészőt nem
# igénylő állítás: minden tools/**.sh futtatható. Ez itt fut, ahol tényleg fut.
echo "==> Szkriptőr (a tools/ shell szkriptek futtathatósága)"
if ! bash "$PLUGIN_DIR/tools/script-guard.sh"; then
    status=1
fi

echo
# --------------------------------------------------------------------------- összegzés
if [ "$status" -eq 0 ]; then
    echo "MINDEN RENDBEN."
else
    echo "VAN MIT JAVÍTANI — lásd fent."
fi

# Amit ez a szkript NEM futtat, és miért:
#
# - PHPUnit. Külön kapu, és van egy csapdája: **minden plugin-verzióemelés érvényteleníti a
#   helyi PHPUnit környezetet.** A Moodle a `core_component::get_all_versions_hash()`-t
#   hasonlítja, ami minden komponens verzióját fedi, a pluginét is — ezért verzióemelés után
#   előbb `admin/tool/phpunit/cli/init.php` kell, ami kb. egy perc. Ha ide keverednénk, ez a
#   szkript a fele időben egy félrevezető Moodle-hibaüzenettel állna meg.
# - phplint. A CI futtatja, de a phpcs ugyanazokat a fájlokat parse-olja; szintaktikai hibán
#   a phpcs is elhasal.
#
# A phpmd fut, de nem kapuként — az indoklás a saját szakaszánál áll.

exit "$status"
