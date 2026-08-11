# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

`local_artqtml` is a Moodle **local plugin** (frankenstyle component: `local_artqtml`) that generates quiz questions using AI:

- **Claude API (Structured Outputs)** — generates question content in a defined schema.
- **Gemini API** — validates/reviews generated questions before they're accepted.

Supported question types (must map to Moodle core/standard question type plugins):
- True/False (`qtype_truefalse`)
- Single choice (`qtype_multichoice`, single-answer mode)
- Multiple choice (`qtype_multichoice`, multiple-answer mode)
- Ordering (`qtype_ordering` — not core, a contrib plugin dependency)
- Short answer (`qtype_shortanswer`)
- Essay (`qtype_essay`)

The repository currently contains only tooling/config (`.agents/`, `.claude/`) — no plugin source code exists yet. When scaffolding the plugin, follow standard Moodle local plugin structure (`version.php`, `lib.php`, `settings.php`, `classes/` with PSR-4 autoloading, `db/access.php`, `db/services.php` if exposing web services, `lang/en/local_artqtml.php`).

## Reference documents

**The product documents left this repository on 2026-08-04 (BL-45).** They live in
`~/Library/Mobile Documents/com~apple~CloudDocs/moodle_dev_projektek/artqtml-munkadokumentumok/termekdokumentumok/`,
alongside the working documents that moved out on 2026-07-30. Nothing in the plugin, the package or
CI reads them.

Plugin-agnostic Moodle checklists (gates, review steps shared across projects) live in
`~/Library/Mobile Documents/com~apple~CloudDocs/moodle_dev_projektek/general/`.

Current versions there:
- `specifikacio_v42.md` / `MoodleAI_kerdesgenerator_specifikacio_TELJES_v42.docx` — functional spec
- `technikai_melleklet_v19.md` / `..._technikai_melleklet_v19.docx` — technical appendix
- `tesztesetek_v44.md` / `tesztesetek_v44.xlsx` — test case register, 799 cases
- `manualis_bejaras_v2.md` — the manual walkthrough register, 252 points (BL-43)
- `felhasznaloi_kezikonyv_v6.md` / `MoodleAI_kerdesgenerator_kezikonyv_v6.docx` — user manual
- `mezotabla_v13.md` — field table

> The version numbers this section used to carry (v7, v4, v17) had been stale for a long time -
> the real ones were v41, v19 and v44. That is the failure mode this move removes: a second place
> naming the documents, with nothing keeping it in step.

## AI integration architecture

Two distinct external API roles — keep them separate in code:

1. **Generation (Claude API)**: takes teacher input (topic, difficulty, question type, count) and produces structured question data via Structured Outputs, matching a strict schema for each of the six question types.
2. **Validation (Gemini API)**: reviews Claude's output for correctness/quality before it's surfaced to the teacher or saved as a Moodle question.

Both API keys are secrets and must be stored via Moodle's admin settings (e.g. `admin_setting_configpasswordunmask` in `settings.php`), never hardcoded or committed. Follow the `moodle-security-audit` skill guidance for handling these credentials and any outbound HTTP calls (SSRF considerations, timeouts, error handling on API failure).

When generating Moodle question objects from AI output, each of the six question types has different required fields/structure (e.g. `qtype_multichoice` answer fraction weights, `qtype_ordering` sequence data) — validate AI output against Moodle's expected question data shape before insert, not just against the AI schema.

## Relevant skills

This project has Moodle-specific skills preloaded (`.agents/skills/`) — invoke them proactively:
- `moodle-plugin-development` — plugin scaffolding, `version.php`, capabilities, upgrade steps.
- `moodle-web-services` — if AJAX/external functions are added for the question-generation UI.
- `moodle-security-audit` — for API key handling, capability checks, input validation on AI-generated content.
- `moodle-phpunit-testing` — for the local dev environment test suite (not runnable on the hosted target).
- `moodle-amd-javascript` — for any generation-flow UI (AMD modules, `core/ajax`, templates).
- `moodle-privacy-gdpr` — if AI-generated content or prompts include personal data, a privacy provider is required.

## Deployment

The test/target Moodle instance is on shared hosting with **browser access only** — no SSH or SFTP.

- Package: `cd ~/projektek/moodle/local && VERSION=$(grep -oE "[0-9]{10}" artqtml/version.php | head -1) && rm -f ~/local_artqtml-${VERSION}.zip && zip -r ~/local_artqtml-${VERSION}.zip artqtml/ -x "artqtml/.git*" -x "artqtml/.git/*" -x "*/.DS_Store" -x "artqtml/.agents/*" -x "artqtml/.claude/*" -x "artqtml/docs/*" -x "artqtml/skills-lock.json" -x "artqtml/CLAUDE.md" -x "artqtml/tools/*" -x "artqtml/screens/*" -x "artqtml/assets/*" -x "artqtml/node_modules/*" -x "artqtml/BACKLOG.md" -x "artqtml/result.md" -x "artqtml/package.json" -x "artqtml/package-lock.json" -x "artqtml/phpcs.xml" -x "artqtml/phpunit.xml" -x "artqtml/phpstan.neon" -x "artqtml/tests/*"`
  - `screens/` holds dev-only PNG screenshots (e.g. for documentation/spec review) captured from the local Docker instance - never part of the plugin's runtime, excluded from both git (`.gitignore`) and the deployed zip via the exclude above.
  - `tools/generate_license.php` is a dev/QA-only script for generating signed test `.lic` license files (ch.10 of the functional spec) against the embedded public key in `classes/local/license_checker.php`, including a SHA-256 manifest of every plugin PHP file for file integrity checking (pass `--no-file-integrity` to omit it, for an old-style license). `tools/sign_license.php` is the older version without the file manifest - still present for old-style licenses but superseded by `generate_license.php` for new ones. Neither must ever ship in the deployed zip, hence the extra exclude above. Any plugin code change (bug fix, version bump, anything) requires regenerating and re-issuing `.lic` files to installs that have file integrity checking active, since the manifest is locked to the exact file contents at generation time.
  - The RSA private key that signs real license files is **not** stored anywhere in this repository. Only the corresponding public key is embedded (as a PEM constant) in `classes/local/license_checker.php`. Whoever holds the private key can issue valid licenses for any Moodle install, so keep it off any machine/service this repo syncs to (this directory is under iCloud Drive) and treat it as at least as sensitive as the Claude/Gemini API keys.
  - Output filename is `local_artqtml-<version>.zip` (version read straight from `version.php`, e.g. `local_artqtml-2026071617.zip`), so successive uploads never collide or get confused with a stale build.
  - **The source tree is `~/projektek/moodle/local/artqtml`.** Until 2026-07-29 this line packaged
    from an iCloud Drive copy that no longer exists, while the Local Docker Deployment section below
    already named `~/projektek` as the source - the same document contradicted itself, and a package
    built from the stale path would have shipped code that was days old.
  - The archive's top-level folder is `artqtml`, which is the directory name a `local` plugin must
    have. Earlier packages used `local_artqtml` and relied on Moodle's installer renaming it.
  - The excludes above were incomplete until 2026-07-29: `node_modules/` (52 MB), the browser
    suite's output folders and the dev config files were all being packaged, along with
    `BACKLOG.md` - the internal technical-debt list. A session handover had claimed they were
    excluded; the documented command did not exclude them.
  - `CHANGES.md` is deliberately NOT excluded: the changelog is for whoever installs the plugin.
  - **`tests/` is excluded from the package as of 2026-07-29**, and the integrity manifest excludes it
    too (`license_file_integrity.php` and `tools/generate_license.php` both list `tools`, `docs`,
    `tests` - change them together or the manifest and the check will disagree). Nothing under
    `tests/` runs at runtime. An install still holding a licence issued before this change needs a
    new `.lic`, because its manifest lists test files the package no longer contains.
  - **`tests/fixtures/licenses/*.lic` must never ship.** Those three fixtures are signed with the
    production key and verify against the public key embedded in `license_crypto.php`, and nothing
    binds a licence to a site - `issued_to_url` is stored and displayed, never compared to
    `$CFG->wwwroot`. Shipping `perpetual.lic` therefore hands every recipient an unlimited licence
    for any Moodle install. Excluding them is safe because the integrity manifest only enumerates
    `.php` files (`license_file_integrity.php`), so a missing `.lic` is not reported.
  - **Do not exclude any `.php` file from the zip.** The license file-integrity manifest enumerates
    every PHP file except `tools` and `docs` (`license_file_integrity.php`), so a PHP file present in
    the manifest but absent from the package fails integrity as "missing". That is why
    `phpstan-bootstrap.php` still ships despite being a dev-only file, and why `tests/` ships too.
  - Note: zip's `--exclude` patterns must match the full stored path (including the `artqtml/` prefix), so a bare `.agents/*` silently matches nothing and lets `.agents/`/`.claude`/`docs/` dev-tooling leak into the deployed zip. Always delete any stale zip for that same version first — `zip -r` on an existing archive only adds/updates entries, it doesn't drop files that a new exclude pattern removes.
- Install: Moodle admin → Site administration → Plugins → Install plugins → upload zip
- Update: same process, Moodle runs `db/upgrade.php` automatically
- There is no way to run Moodle CLI scripts (`admin/cli/*.php`), tail server logs, or run PHPUnit directly on that host
- Any automated testing (PHPUnit, Behat) must run against a **separate local Moodle dev environment**; the hosted instance is for manual/UI verification only
- Because there's no server shell access, debugging on the hosted instance relies on Moodle's debugging output (`$CFG->debug = DEBUG_DEVELOPER`) and admin-visible logs, not tailing files

### Reading the hosted instance's database

There is no DB console on that host either. The only way to inspect its data is the
**Configurable Reports** plugin's SQL report, which runs `SELECT` only — no `UPDATE`, `INSERT`
or `DELETE`. Any fix therefore has to go through the Moodle UI or through plugin code, never
through a hand-run statement.

Two conventions when writing a query for it (both are hard requirements, not style):

- **Use `prefix_` as the table prefix, never `mdl_`.** Configurable Reports substitutes the
  install's real prefix wherever it finds `prefix_`. A literal `mdl_` only works by accident,
  on installs that happen to use that prefix.
- **No trailing semicolon.** The report wraps the query, and a `;` breaks the wrapping.

## Local Docker Deployment

A local Moodle instance running in Docker is available for fast iteration, separate from the hosted shared-hosting target described above.

- Plugin path on host: `~/projektek/moodle/local/artqtml` — this **is** the source, changes are instant (no packaging/upload step, unlike the hosted target).
- After any PHP change, run from `~/projektek/moodle-docker`:
  `docker compose -f base.yml -f db.mariadb.yml -f webserver.port.yml exec webserver php admin/cli/upgrade.php --non-interactive`
- After DB schema changes (`db/install.xml` or `db/upgrade.php`): same upgrade command as above.
- Purge caches: `docker compose -f base.yml -f db.mariadb.yml -f webserver.port.yml exec webserver php admin/cli/purge_caches.php`
- Local Moodle URL: http://localhost:8080
- Admin credentials: `admin` / `Admin1234!`
- After code changes, no zip or upload needed — files are mounted directly.

## Architecture
- All AI API calls run in the background only, never inline in a web request. `generate.php`
  just flips a generation's status to `generating`; the
  `local_artqtml\task\process_pending_generations` scheduled task (`classes/task/`, runs every
  5 minutes via cron) picks up anything in `generating`/`validating`/`saving` and drives it
  through `generate_questions_task`/`validate_questions_task`/`save_questions_task` (plain
  processor classes, not tasks themselves). M-15: these are three genuinely separate stages -
  `generate_questions_task` only calls Claude and stores its raw output in
  `local_artqtml_generations.pendingdata`; `validate_questions_task` only calls Gemini and
  merges evaluations into that same JSON blob; nothing is written to `local_artqtml_questions`
  (nor is any real Moodle question created) until `save_questions_task` commits everything -
  real questions, validation results, M-07 semantic rejection - together in one transaction. To
  test a generation without waiting for the next cron tick, run it on demand:
  `docker compose -f base.yml -f db.mariadb.yml -f webserver.port.yml exec webserver php admin/cli/scheduled_task.php --execute='\local_artqtml\task\process_pending_generations'`
- AJAX polling from browser to check generation status (`classes/external/`)
- DB access via `$DB` global only, never PDO or raw SQL
- Every form must use `require_sesskey()` and `require_capability()`
- All user-facing strings via `get_string()` from `lang/en/local_artqtml.php`

## Forbidden patterns
- No hardcoded strings in buttons or UI elements
- No direct SQL bypassing `$DB` API
- No parallel subagent sessions
- Never use `/feedback` command (sends session data to Anthropic)
- No `require()` or `include()` outside Moodle bootstrap

## Technical Debt
See `BACKLOG.md` for known god-class/controller refactoring candidates (`approve.php`,
`classes/local/question_importer.php`, `classes/local/license_checker.php`). These are
deliberately deferred, low-priority, high-regression-risk items - do not start on them unless
explicitly asked.
