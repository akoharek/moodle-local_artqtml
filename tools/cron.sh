#!/usr/bin/env bash
#
# A sorban álló generálásokat MIND végigfuttatja, egy paranccsal.
#
# Miért van ez a fájl. A `php admin/cli/cron.php` nem arra való, amire használtuk. Az a Moodle
# teljes óraműve: minden ütemezett feladatot megnéz, és csak azokat futtatja, amelyek ESEDÉKESEK.
# A plugin motorja, a `process_pending_generations`, ötpercenként esedékes (db/tasks.php), tehát
# egy közvetlenül a sorbaállítás után indított cron.php a legtöbbször hozzá sem nyúl a
# generáláshoz - a következő tickre kell várni. Így lett abból, hogy "lefuttattam a cront",
# rendszeresen az, hogy egyetlen tétel haladt, a többi állt.
#
# Amit ez a szkript csinál helyette: közvetlenül és azonnal lefuttatja a plugin saját feladatát,
# az esedékességet megkerülve. Az a feladat egyetlen futásban végigmegy MINDEN folyamatban lévő
# generáláson, és mindegyiket a teljes láncon viszi át - generálás -> validálás -> mentés -, tehát
# a "sorban álló összes tétel" pontosan egy hívással elintéződik.
#
# Használat:
#     cd ~/projektek/moodle/local/artqtml && bash tools/cron.sh
#     cd ~/projektek/moodle/local/artqtml && bash tools/cron.sh --passes=3
#
# A teljes kimenet:
#
#     tools/.cron-last.txt
#
# a terminálban csak egy pár soros összegzés marad - ugyanaz a megállapodás, mint a gates.sh-nál.
#
# Két csapdát külön kezel, mert mindkettő megtörtént:
#
#   * "Moodle upgrade pending" - a version.php emelése után a Moodle FELFÜGGESZTI a cront, és a
#     hibaüzenetből nem derül ki, mit kell tenni. A szkript felismeri, és kiírja a pontos
#     parancsot. Ez 2026-08-02-án egy teljes kört elvitt.
#
#   * beragadt igény (processingtoken) - egy megölt futás után a sor igényt hordoz, és onnantól
#     SEMMILYEN futás nem veszi fel többé. A szkript a végén megnevezi az ilyen sorokat és a
#     felszabadításuk parancsát; nem oldja fel magától, mert egy még valóban futó generálás
#     igényét feloldani rosszabb, mint várni.
#
# Kilépési kód: 0, ha a végén nincs több sorban álló tétel; 1, ha maradt.

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MOODLE_DOCKER_DIR="${MOODLE_DOCKER_DIR:-$HOME/projektek/moodle-docker}"
LOG="$PLUGIN_DIR/tools/.cron-last.txt"

COMPOSE=(docker compose -f base.yml -f db.mariadb.yml -f webserver.port.yml)
TASK='\local_artqtml\task\process_pending_generations'
STATE='local/artqtml/tools/generation_state.php'

passes=3
for arg in "$@"; do
    case "$arg" in
        --passes=*) passes="${arg#*=}" ;;
        *) echo "Ismeretlen kapcsoló: $arg" >&2; exit 2 ;;
    esac
done

if [ ! -d "$MOODLE_DOCKER_DIR" ]; then
    echo "HIBA: a moodle-docker könyvtár nem található: $MOODLE_DOCKER_DIR" >&2
    exit 2
fi

cd "$MOODLE_DOCKER_DIR" || exit 2

# A napló minden futásnál elölről kezdődik: a "mi volt a legutóbbi futás" kérdésre egy fájl
# feleljen, ne egy növekvő napló, amiben meg kell keresni a mai blokkot.
{
    echo "# tools/cron.sh — $(date '+%Y-%m-%d %H:%M:%S')"
    echo "# feladat: $TASK"
    echo
} > "$LOG"

# Hány folyamatban lévő sor van még - akár vár a futásra, akár épp fut.
#
# Az első változat csak az igényt NEM hordozó sorokat számolta ('igen$'), vagyis azokat, amikre a
# következő futás rá fog nézni. Ez rossz szám volt: a sor, amin a feladat ÉPPEN dolgozik, igényt
# hordoz, tehát nullának látszott. 2026-08-02-án két tétel újrafuttatásakor a kiírás "hátralévő
# 0"-t mondott, miközben az egyetlen hátralévő cella még validálás alatt állt. Harminchat cellánál
# ez elrejtőzött, mert mindig volt sok érintetlen sor mellette.
#
# Amit a szám jelent most: minden folyamatban lévő generálás, függetlenül attól, hogy fut vagy vár.
# Így a nulla azt jelenti, amit a képernyőn állít - nincs több munka.
waiting_count() {
    "${COMPOSE[@]}" exec -T webserver php "$STATE" --limit=50 2>/dev/null \
        | grep -cE '(igen|igényt hordoz)$'
}

echo "==> kiindulási állapot…"
{
    echo "==> kiindulási állapot"
    "${COMPOSE[@]}" exec -T webserver php "$STATE" --limit=50 2>&1
    echo
} >> "$LOG"

before="$(waiting_count)"
echo "    sorban álló tételek: $before"

if [ "$before" -eq 0 ]; then
    echo
    echo "  Nincs mit futtatni — egyetlen generálás sem vár."
    echo "  a teljes kimenet: tools/.cron-last.txt"
    exit 0
fi

# Elvileg egyetlen futás elég: a feladat végigmegy minden folyamatban lévő soron. A további
# köröket az indokolja, hogy egy átmeneti szolgáltatói hiba (503) egy sort a láncon belül
# megállíthat, és a következő kör újra nekifut. Több kör akkor sem árt, ha nincs rá szükség: a
# feladat egy üres sorra másodpercek alatt visszatér.
pass=0
status=1
while [ "$pass" -lt "$passes" ]; do
    pass=$((pass + 1))
    echo "==> $pass. futás…"
    {
        echo "==> $pass. futás — $(date '+%H:%M:%S')"
    } >> "$LOG"

    # Háttérben indítjuk, és 15 másodpercenként megkérdezzük, hány sor van még hátra. Ez nem
    # kényelmi ráadás: 2026-08-02-án egy 36 cellás mérés 46 percig futott úgy, hogy sem a
    # terminál, sem a napló nem mondott semmit - a Moodle feladat kimenete a parancs végéig
    # pufferelődik. Egy óra néma futás megkülönböztethetetlen egy beragadt futástól, és András
    # négyszer kérdezett rá, jogosan. A számláló nem a feladat kimenetéből jön, hanem az
    # adatbázisból, tehát akkor is halad, ha a feladat egy hosszú AI hívásban áll.
    "${COMPOSE[@]}" exec -T webserver php admin/cli/scheduled_task.php \
        --execute="$TASK" >> "$LOG" 2>&1 &
    taskpid=$!

    started=$(date +%s)
    last=""
    while kill -0 "$taskpid" 2>/dev/null; do
        sleep 15
        kill -0 "$taskpid" 2>/dev/null || break
        now="$(waiting_count)"
        elapsed=$(( ($(date +%s) - started) / 60 ))
        if [ "$now" != "$last" ]; then
            # Új sor csak akkor, ha tényleg haladt valamit - így a képernyő a haladás
            # története lesz, nem egy villogó számláló.
            printf '    %2d perc: hátralévő %s\n' "$elapsed" "$now"
            last="$now"
        fi
    done
    wait "$taskpid"

    if grep -q 'Moodle upgrade pending' "$LOG"; then
        echo
        echo "  A Moodle felfüggesztette a cront: a plugin verziószáma emelkedett, a telepített"
        echo "  verzió viszont még a régi. Ez oldja fel:"
        echo
        echo "    cd $MOODLE_DOCKER_DIR && bin/moodle-docker-compose exec webserver \\"
        echo "      php admin/cli/upgrade.php --non-interactive"
        echo
        echo "  utána futtasd újra ezt a szkriptet."
        exit 2
    fi

    remaining="$(waiting_count)"
    echo "    hátralévő: $remaining"
    { echo "# hátralévő a $pass. futás után: $remaining"; echo; } >> "$LOG"

    if [ "$remaining" -eq 0 ]; then
        status=0
        break
    fi
done

# --------------------------------------------------------------------------- záró állapot
{
    echo "==> záró állapot"
    "${COMPOSE[@]}" exec -T webserver php "$STATE" --limit=50 --stuck 2>&1
} >> "$LOG"

echo
echo "  $before tétel várt, $pass futás, hátralévő: $remaining"

stuck="$(grep -E '^Beragadt' "$LOG" | tail -1)"
if [ -n "$stuck" ]; then
    echo "  $stuck"
    echo "  Ezeket egy megölt futás igénye tartja fogva; amíg hordozzák, egyetlen futás sem"
    echo "  veszi fel őket. Felszabadítás egyesével:"
    echo "    php $STATE --release=<id>"
fi

echo
echo "  a teljes kimenet: tools/.cron-last.txt"
exit "$status"
