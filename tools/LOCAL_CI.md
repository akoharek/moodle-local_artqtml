# Helyi ellenőrzés — egyszerűen

**Mi ez?** Egy gomb a gépeden, ami megmondja: „mehet a GitHubra / demóra”, vagy „még javítani kell”.  
**Miért?** Hogy ne a GitHub Actions-ön (percek, várakozás) derüljön ki a hiba.

## Mit futtass

A plugin mappájában (`artqtml` vagy `artqtm`):

```bash
# Gyors kör fejlesztés közben (~2–5 perc; Docker kell)
bash tools/local_ci.sh --fast

# Napi push / Release előtt (~10–25 perc, Docker kell)
bash tools/local_ci.sh --push
```

- **Zöld** → mehet a push (ha ma még nem pusholtál erre a repóra).
- **Piros** → javíts helyben; a részletes napló: `tools/.local-ci-last.txt`.

## Mit néz

| Lépés | --fast | --push |
|-------|:------:|:------:|
| Kódstílus + logikai ellenőrzés (phpcs, phpstan) | igen | igen |
| Automatikus tesztek (PHPUnit) | — | igen |
| MPC static (validate, savepoints, phplint, phpdoc) | igen | igen |
| Light „kiszivárgás” szűrő (csak Light) | igen | igen |
| Moodle 5.2 tiltott CSS osztályok | igen | igen |
| Régi JavaScript betöltés tiltása | igen | igen |
| AMD / ESLint | — | igen |
| Biztonsági minta-kereső (Semgrep) | — | igen |

Az MPC lépések ugyanazok, mint a GitHub **static** jobban (`moodle-plugin-ci`). Első futáskor a script letölti az MPC-t a `tools/.mpc/` alá (gitignore, nem megy a repóba).

## Fontos szabályok

1. **Napi max. egy push** repónként — a script emlékeztet, ha ma már volt.
2. Ne írd: `SKIP_LOCAL_CI=1`, hacsak András kifejezetten nem kéri (pl. csak dokumentum).

## Ha elakadsz

- Docker nem fut → indítsd a Moodle stacket (`moodle-docker`).
- MPC első futás → `tools/.mpc` telepítés (~1 perc); ha elakad, töröld a `tools/.mpc` mappát és futtasd újra.
- AMD piros → a script a helyi `node:22` Docker image-et használ (Moodle grunt).
- Semgrep első futás → letölti a `semgrep/semgrep` image-et.
- phpcs/phpstan „PHP 8.4 kell” → a Moodle Docker 8.3-as; a `tools/devtools` composer `platform.php` legyen `8.3.32`, majd újratelepítés.
