#!/usr/bin/env bash
#
# script-guard.sh — egy állítás a fejlesztői szkriptek épségéről, böngésző nélkül.
#
# HONNAN JÖN EZ A FÁJL. Eredetileg négy állítást ellenőrzött a böngészős tesztkészlet mellett.
# Három azért szűnt meg, mert a mért dolog maga tűnt el, nem azért, mert fölöslegessé vált — ezt
# érdemes megkülönböztetni, ha valaki később a git-történetben találkozik vele:
#
#   1. a spec-leképezés állítása — a leképezés és a hozzá tartozó szkript is kikerült a repóból
#      a BL-43 lezárásával (2026-08-04). Nincs mit mappelni.
#   2. „a regiszter-pin friss" — a `docs/` 2026-08-04-én az iCloudba került (BL-45), a CI pedig
#      nem lát be oda. Ez az állítás egy nappal élte túl a hármat, és a BL-45 írja le, mit
#      veszítettünk vele.
#   3. „a regiszter verziószáma egyetlen helyen áll" — a fájl, amiben állt, a BL-43-mal kikerült.
#      Nincs többé verziószám, ami két helyre szétcsúszhatna.
#
# AMI MEGMARADT, ÉS AMIÉRT MEGÉRI FUTTATNI. 2026-08-03-án EGY NAP ALATT KÉT szkript vesztette el a
# futtathatóságát, és egyik esetben sem szólt semmi. Az egyik szkript a commitban ment 100755-ről
# 100644-re, és a dokumentált hívása `Permission denied`-del állt meg. A `gates.sh` ugyanígy 100644
# volt, és a `./tools/gates.sh` elbukott rajta.
#
# Miért nem vette észre a másik három őr: MIND A HÁROM a fájlok TARTALMÁT olvasta (sed, grep), és a
# tartalom mindkét esetben hibátlan volt. Egy szkript, amit nem lehet elindítani, tartalmilag
# tökéletes. Ez a fajta hiba ezért csendes, és csak akkor derül ki, amikor valaki be akarja írni a
# parancsot.
#
# Használat:
#     bash tools/script-guard.sh
#
# Kilépési kód: 0, ha az állítás áll; 1, ha sérül.

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PLUGIN_DIR" || exit 2

status=0

# --------------------------------------------------------------------------- minden szkript indítható
for script in $(find tools -name '*.sh' -type f | sort); do
    if [ ! -x "$script" ]; then
        notexec="${notexec:-}$script "
    fi
done

if [ -n "${notexec:-}" ]; then
    echo "  HIBA: ezek a szkriptek nem futtathatók:"
    printf '    %s\n' ${notexec}
    echo "    Javítás: chmod +x <fájl>, és a módváltozást commitolni kell - a jogosultság a gitben áll."
    status=1
else
    echo "  minden tools/**.sh futtatható ($(find tools -name '*.sh' -type f | wc -l | tr -d ' ') db)"
fi

exit "$status"
