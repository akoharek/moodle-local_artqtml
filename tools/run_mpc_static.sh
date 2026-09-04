#!/usr/bin/env bash
#
# Moodle Plugin CI static lépések helyben — ugyanaz a szándék, mint a GitHub static job:
#   validate, savepoints, phplint, phpdoc (--max-warnings 0)
#
# A moodle-docker webserver konténerben fut (a host Moodle config datarootja Docker-útvonal).
# phpdoc: moodlecheck közvetlenül (az mpc Symfony Process a konténerben nem tud `php`-t indítani).
#
# Használat: bash tools/run_mpc_static.sh
# Kilépés: 0 zöld, 1 bukás, 2 környezet hiányzik.
#
# MPC cache: tools/.mpc/ (gitignore) — első futáskor composer create-project.

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SHORT="$(basename "$PLUGIN_DIR")"
PLUGIN_PATH="local/${PLUGIN_SHORT}"
MOODLE_DOCKER_DIR="${MOODLE_DOCKER_DIR:-$HOME/projektek/moodle-docker}"
MPC_HOST="$PLUGIN_DIR/tools/.mpc"
MPC_CONT="/var/www/html/${PLUGIN_PATH}/tools/.mpc"
COMPOSE=(docker compose -f base.yml -f db.mariadb.yml -f webserver.port.yml)

if [ ! -d "$MOODLE_DOCKER_DIR" ]; then
    echo "HIBA: moodle-docker nem található: $MOODLE_DOCKER_DIR" >&2
    exit 2
fi

cd "$MOODLE_DOCKER_DIR" || exit 2

if ! "${COMPOSE[@]}" ps --status running --quiet webserver | grep -q .; then
    echo "HIBA: a webserver konténer nem fut." >&2
    echo "Indítsd: cd $MOODLE_DOCKER_DIR && ${COMPOSE[*]} up -d" >&2
    exit 2
fi

inweb() { "${COMPOSE[@]}" exec -T webserver "$@"; }

# --------------------------------------------------------------------------- mpc telepítés (egyszer)
if [ ! -x "$MPC_HOST/bin/moodle-plugin-ci" ]; then
    echo "MPC telepítése tools/.mpc alá (első alkalom, ~1 perc)…"
    mkdir -p "$PLUGIN_DIR/tools"
    # npm post-install a konténerben nincs — a bináris így is megjön.
    if ! inweb bash -lc "
        set -e
        cd /var/www/html/${PLUGIN_PATH}/tools
        rm -rf .mpc
        COMPOSER_ALLOW_SUPERUSER=1 php /var/www/html/composer.phar create-project -n --no-dev \
            moodlehq/moodle-plugin-ci .mpc '^4' || true
        test -x .mpc/bin/moodle-plugin-ci
        "; then
        echo "HIBA: moodle-plugin-ci telepítés sikertelen." >&2
        exit 2
    fi
fi

PLUGIN_CONT="/var/www/html/${PLUGIN_PATH}"
status=0

run_mpc() {
    local label="$1"
    shift
    echo "  → $label"
    if inweb bash -lc "export PATH=/usr/local/bin:/usr/bin:\$PATH; cd /var/www/html; $*" ; then
        echo "    RENDBEN ($label)"
    else
        echo "    BUKÁS ($label)"
        status=1
    fi
}

# validate needs --moodle; savepoints/phplint do not (AbstractPluginCommand only).
run_mpc "validate" \
    "${MPC_CONT}/bin/moodle-plugin-ci validate --moodle /var/www/html ${PLUGIN_CONT}"

run_mpc "savepoints" \
    "${MPC_CONT}/bin/moodle-plugin-ci savepoints ${PLUGIN_CONT}"

run_mpc "phplint" \
    "${MPC_CONT}/bin/moodle-plugin-ci phplint ${PLUGIN_CONT}"

# --------------------------------------------------------------------------- phpdoc via moodlecheck (mpc Process workaround)
echo "  → phpdoc (moodlecheck, max-warnings 0)"
phpdoc_out="$(mktemp)"
if inweb bash -lc "
    set -e
    SRC=${MPC_CONT}/vendor/moodlehq/moodle-local_moodlecheck
    DEST=/var/www/html/local/moodlecheck
    if [ ! -d \"\$SRC\" ]; then
        echo 'HIBA: moodle-local_moodlecheck hiányzik az MPC vendorból.' >&2
        exit 1
    fi
    rm -rf \"\$DEST\"
    cp -a \"\$SRC\" \"\$DEST\"
    cd /var/www/html
    # Same tree mpc would scan: plugin PHP except tools/vendor noise kept out of the path list.
    FILES=\$(find ${PLUGIN_PATH} -name '*.php' \
        -not -path '*/tools/.mpc/*' \
        -not -path '*/tools/devtools/vendor/*' \
        -not -path '*/vendor/*' \
        | paste -sd, -)
    php local/moodlecheck/cli/moodlecheck.php -p=\"\$FILES\" -f=text
    rm -rf \"\$DEST\"
" > "$phpdoc_out" 2>&1; then
    :
else
    # moodlecheck often exits 0 even with findings — parse below.
    true
fi

lines=$(grep -c 'Line ' "$phpdoc_out" 2>/dev/null || true)
warns=$(grep -c '(warning)$' "$phpdoc_out" 2>/dev/null || true)
lines=${lines:-0}
warns=${warns:-0}
errors=$((lines - warns))
if [ "$errors" -gt 0 ] || [ "$warns" -gt 0 ]; then
    grep -B1 -E 'Line ' "$phpdoc_out" | head -40 | sed 's/^/    /'
    echo "    BUKÁS (phpdoc): errors=$errors warnings=$warns (CI: max-warnings 0)"
    status=1
else
    echo "    RENDBEN (phpdoc)"
fi
rm -f "$phpdoc_out"

if [ "$status" -eq 0 ]; then
    echo "MPC static (validate + savepoints + phplint + phpdoc): RENDBEN ($PLUGIN_SHORT)"
else
    echo "MPC static: BUKÁS ($PLUGIN_SHORT)" >&2
fi
exit "$status"
