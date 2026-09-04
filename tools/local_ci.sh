#!/usr/bin/env bash
#
# Helyi CI kapu — push előtt, ne a GitHub Actions-ön kísérletezzünk.
#
# Üzleti cél: ugyanazon a gépen derüljön ki a baj, ahol dolgozunk; a napi egy push
# csak hitelesítés legyen, ne próbálkozás.
#
# Használat (a plugin gyökeréből):
#   bash tools/local_ci.sh --fast    # gyors: stílus + logika + mpc static + termék-higiénia
#   bash tools/local_ci.sh --push    # napi push előtt: fast + PHPUnit + AMD + Semgrep
#   bash tools/local_ci.sh           # ugyanaz, mint --push
#
# Kilépés: 0 = mehet tovább; 1 = van mit javítani; 2 = környezet hiányzik.
#
# SKIP_LOCAL_CI=1  — csak András kifejezett utasítására (pl. kizárólag dokumentum commit).

set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SHORT="$(basename "$PLUGIN_DIR")"
PLUGIN_PATH="local/${PLUGIN_SHORT}"
MOODLE_DOCKER_DIR="${MOODLE_DOCKER_DIR:-$HOME/projektek/moodle-docker}"
MOODLE_WWWROOT="${MOODLE_WWWROOT:-$HOME/projektek/moodle}"
LOG="$PLUGIN_DIR/tools/.local-ci-last.txt"

mode=push
case "${1:-}" in
    --fast) mode=fast ;;
    --push|"") mode=push ;;
    -h|--help)
        sed -n '3,16p' "$0"
        exit 0
        ;;
    *)
        echo "Ismeretlen kapcsoló: $1 (használat: --fast | --push)" >&2
        exit 2
        ;;
esac

if [ "${SKIP_LOCAL_CI:-}" = "1" ]; then
    echo "SKIP_LOCAL_CI=1 — helyi CI kihagyva (explicit)."
    exit 0
fi

{
    echo "# tools/local_ci.sh — $(date '+%Y-%m-%d %H:%M:%S')"
    echo "# plugin: $PLUGIN_SHORT  mode: $mode"
    echo
} > "$LOG"

status=0
step() { echo; echo "==> $*"; echo "==> $*" >> "$LOG"; }

fail_step() {
    echo "    BUKÁS: $*"
    echo "    BUKÁS: $*" >> "$LOG"
    status=1
}

ok_step() {
    echo "    RENDBEN"
    echo "    RENDBEN" >> "$LOG"
}

# --------------------------------------------------------------------------- 0. napi push emlékeztető
step "Napi push limit (emlékeztető — nem bukás)"
if [ -d "$PLUGIN_DIR/.git" ]; then
    last_push="$(git -C "$PLUGIN_DIR" log -1 --format='%ci' origin/main 2>/dev/null || true)"
    today="$(date '+%Y-%m-%d')"
    if [ -n "$last_push" ]; then
        last_day="${last_push%% *}"
        echo "    origin/main utolsó commit dátum: $last_day"
        if [ "$last_day" = "$today" ]; then
            echo "    FIGYELEM: ma már volt push erre a repóra — új push csak András explicit felülírásával."
        else
            echo "    Ma még nem volt push — a limit szabad (ha a kapuk zöldek)."
        fi
    else
        echo "    origin/main dátum nem olvasható (offline / nincs remote)."
    fi
fi

# --------------------------------------------------------------------------- 1. gyors / teljes statikus + (push módban) PHPUnit
if [ "$mode" = "fast" ]; then
    step "Statikus kapuk (phpcs + phpstan) — tools/check.sh"
    if bash "$PLUGIN_DIR/tools/check.sh" >> "$LOG" 2>&1; then
        ok_step
    else
        fail_step "check.sh — részletek: tools/.local-ci-last.txt"
        grep -E 'ERROR|WARNING|\[ERROR\]|VAN MIT' "$LOG" | tail -15 | sed 's/^/    /'
    fi
else
    step "Statikus + PHPUnit — tools/gates.sh"
    if bash "$PLUGIN_DIR/tools/gates.sh" >> "$LOG" 2>&1; then
        ok_step
        phpunitline="$(grep -E '^(OK |FAILURES|ERRORS|Tests:)' "$PLUGIN_DIR/tools/.gates-last.txt" 2>/dev/null | tail -1 || true)"
        [ -n "$phpunitline" ] && echo "    PHPUnit: $phpunitline"
    else
        fail_step "gates.sh — részletek: tools/.gates-last.txt és tools/.local-ci-last.txt"
        grep -E 'ERROR|WARNING|FAILURES|VAN MIT' "$PLUGIN_DIR/tools/.gates-last.txt" 2>/dev/null | tail -15 | sed 's/^/    /'
    fi
fi

# --------------------------------------------------------------------------- 2. mpc static (validate + savepoints + phplint + phpdoc) — CI static job rés
step "MPC static (validate + savepoints + phplint + phpdoc)"
if bash "$PLUGIN_DIR/tools/run_mpc_static.sh" >> "$LOG" 2>&1; then
    ok_step
else
    mpc_ec=$?
    if [ "$mpc_ec" -eq 2 ]; then
        fail_step "run_mpc_static.sh — környezet (Docker / MPC telepítés); lásd tools/.local-ci-last.txt"
    else
        fail_step "run_mpc_static.sh — validate/savepoints/phplint/phpdoc; lásd tools/.local-ci-last.txt"
    fi
    grep -E 'BUKÁS|HIBA|Line |error|warning' "$LOG" | tail -25 | sed 's/^/    /'
fi

# --------------------------------------------------------------------------- 3. Light leak gate
if [ "$PLUGIN_SHORT" = "artqtml" ] && [ -x "$PLUGIN_DIR/tools/grep-light-leaks.sh" ]; then
    step "Light leak gate (Marketplace higiénia)"
    if bash "$PLUGIN_DIR/tools/grep-light-leaks.sh" "$PLUGIN_DIR" >> "$LOG" 2>&1; then
        ok_step
    else
        fail_step "grep-light-leaks.sh — tiltott minta a Light fában (lásd skill / leak kapu)"
    fi
fi

# --------------------------------------------------------------------------- 4. Bootstrap 5.2 deprecated utility (Behat scss-deprecations előrejelző)
step "Bootstrap 4 tiltott utility (ml-2 / btn-block / font-weight-bold)"
# Comment-only mentions (e.g. styles.css explaining the migration) are not Behat failures.
bootstrap_hits="$(rg -n --glob '*.{php,js,css}' '\b(ml-2|btn-block|font-weight-bold)\b' "$PLUGIN_DIR" \
    --glob '!tools/**' --glob '!tests/**' --glob '!.git/**' --glob '!amd/build/**' 2>/dev/null \
    | grep -Ev ':[0-9]+:[[:space:]]*(\*|//|/\*)' || true)"
if [ -n "$bootstrap_hits" ]; then
    printf '%s\n' "$bootstrap_hits" | head -20 | sed 's/^/    /'
    echo "$bootstrap_hits" >> "$LOG"
    fail_step "Moodle 5.2 Behat --scss-deprecations elbukna ezeken"
else
    ok_step
fi

# --------------------------------------------------------------------------- 5. Legacy js/ loader (AMD kapu előrejelző)
step "Legacy \$PAGE->requires->js(.../js/...) tiltás"
legacy_js="$(rg -n "requires->js\(.*local/${PLUGIN_SHORT}/js/" "$PLUGIN_DIR" --glob '*.php' 2>/dev/null || true)"
if [ -n "$legacy_js" ]; then
    printf '%s\n' "$legacy_js" | sed 's/^/    /'
    echo "$legacy_js" >> "$LOG"
    fail_step "CI/Moodle compliance: AMD js_call_amd kell, nem js/ mappa"
else
    ok_step
fi

# --------------------------------------------------------------------------- 6–7. csak --push: AMD + Semgrep
if [ "$mode" = "push" ]; then
    step "AMD + ESLint (Node 22 Docker, ha a host nem 22-es)"
    if bash "$PLUGIN_DIR/tools/run_amd_gate.sh" >> "$LOG" 2>&1; then
        ok_step
    else
        fail_step "run_amd_gate.sh — lásd tools/.local-ci-last.txt"
        tail -30 "$LOG" | sed 's/^/    /'
    fi

    step "Semgrep p/php (Docker)"
    if bash "$PLUGIN_DIR/tools/run_semgrep_docker.sh" >> "$LOG" 2>&1; then
        ok_step
    else
        fail_step "run_semgrep_docker.sh — SAST találat vagy Docker hiány"
        tail -20 "$LOG" | sed 's/^/    /'
    fi
fi

# --------------------------------------------------------------------------- összegzés
echo
echo "----------------------------------------"
if [ "$status" -eq 0 ]; then
    echo "HELYI CI ($mode): ZÖLD — $PLUGIN_SHORT"
    if [ "$mode" = "push" ]; then
        echo "Ha a napi push limit engedi: git push (max 1× / nap / repo)."
    fi
else
    echo "HELYI CI ($mode): PIROS — $PLUGIN_SHORT"
    echo "Javítsd helyben; ne pusholj GitHubra kísérletezni."
fi
echo "Teljes napló: tools/.local-ci-last.txt"
echo "----------------------------------------"
exit "$status"
