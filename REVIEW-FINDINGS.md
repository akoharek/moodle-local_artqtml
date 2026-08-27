# local_artqtml Marketplace code review — unified triage

**Scope:** `local/artqtml` (Light / Marketplace)  
**Updated:** 2026-08-27 (tracker sync: C-01/C-02/C-06/F-07 closed in code; C-04/C-05/C-07/C-08 fixed 2026-08-27; S-06, C-03, S-04 fixed; all P0/P1 audit items resolved or wontfix/FP; **0 open submit-critical findings**)
**Passes merged:** Security (Sonnet `a2368d28`), Compliance (Sonnet `7072ded4`), Functional (Gemini `1db629c4`); prior tracker retained for stable IDs  
**Status:** Submit-critical audit items **closed in code** — awaiting CI green on `fix/s02-manageall-capability` (`b9c99e9`)

Severity: **P0** Marketplace blocker · **P1** fix before submit · **P2** backlog · **Nit** optional

---

## Executive summary

| Severity | Count | Role |
|----------|------:|------|
| **P0** | **0 open** (5 fixed) | Marketplace blockers — all resolved |
| **P1** | **0 open** (8 fixed, 1 false positive) | Fix before HQ / Marketplace submit — all resolved |
| **P2** | **0 open** (fixed / wontfix / false positive) | Backlog after submit-critical work |
| **Nit** | **0 open** (all fixed) | Optional polish |

**Marketplace blockers (P0):** all resolved — (1) ~~missing object-level ownership~~ **S-02 fixed**, (2) ~~permanent draft-course editall/useall~~ **S-01 fixed (2026082602)**, (3) ~~missing `@copyright` / phpcs exclude~~ **C-02 fixed (`4a6e759`)**, (4) ~~missing `MOODLE_INTERNAL` guards~~ **C-01 fixed**, (5) ~~fresh install blocked~~ **F-07 fixed (`848f7aa`)** — post-install redirect + setup checklist (by design, not auto-create course).

**Later (P1–Nit):** all resolved — ~~privacy metadata~~ **C-03 fixed**, ~~privacy deletion copy~~ **C-04 fixed**, ~~Full-leak lang~~ **C-05 fixed**, ~~reparent dead code~~ **C-07 fixed (option B)**, ~~ticket-ID scrub~~ **C-08 fixed**, ~~schema COMMENT~~ **C-06 fixed**, ~~FAILED UX~~ **F-08 fixed**, ~~S-04/S-06~~ **fixed**, ~~F-01/F-02~~ **false positive**, P2/Nit all closed.

**Recommended fix order** — all steps complete; next gate is CI + Marketplace ZIP/submit.

1. ~~**Ownership policy**~~ **S-02/S-08 fixed**  
2. ~~**Draft role**~~ **S-01 fixed (2026082602)**  
3. ~~**Copyright + `MOODLE_INTERNAL`**~~ **C-01/C-02 fixed**  
4. ~~**Fresh-install onboarding**~~ **F-07 fixed (`848f7aa`)**  
5. ~~**UX / compliance P1**~~ **all fixed / FP / wontfix**  
6. ~~**P2 / Nit**~~ **all closed**

---

## Findings (sorted P0 → Nit)

| ID | Severity | Area | Status | Location | Finding | Sources | Suggested next step |
|----|----------|------|--------|----------|---------|---------|---------------------|
| S-02 | P0 | Security | **fixed** | `upload.php`; `generation_source_service.php`; `status.php` (abort/retry); `approve.php`; approval / deletion / move services | Mutating another user’s generation needs only system `local/artqtml:use` — no ownership / manage check (same root cause across endpoints) | Security (elevated); prior S-02/S-03 | Shared object-level auth: require owner or elevated manage capability before any mutate |
| S-01 | P0 | Security | **fixed** | `classes/local/draft_role.php`; `classes/observer.php`; approve UI/services | Draft role no longer grants native edit; external Moodle edit locks question (`externallyedited`); approve/move blocked; generation delete still allowed (owner/`:manageall`) | Security (elevated); prior S-01 | Documented 2026082602 — holding-area model replaces permanent editall grant |
| C-02 | P0 | Compliance | **fixed** | most PHP; `phpcs.xml` | Missing `@copyright`; phpcs excludes `CopyrightTagMissing` but moodle.org CI will not | Compliance; prior C-02; fixed `4a6e759` | `@copyright` on all shipped PHP; no `CopyrightTagMissing` exclude in `phpcs.xml` |
| C-01 | P0 | Compliance | **fixed** | `lib.php`; `db/upgrade.php`; `db/install.php`; `db/uninstall.php` | Missing `defined('MOODLE_INTERNAL') \|\| die();` | Compliance; prior C-01; fixed `4a6e759` | Guard on all shipped PHP; exceptions: `tools/*`, `phpstan-bootstrap.php`, one test |
| F-07 | P0 | Functional | **fixed** | `plugin_setup.php`; `db/install.php`; settings banners | Fresh install blocked while `draftcourseid=0` until admin sets hidden draft course | Functional (new P0); fixed `848f7aa` | Post-install redirect + setup checklist — by design, not auto-create course |
| S-04 | P1 | Security | **fixed** | `ai_request.php`; generate/validate tasks; `model_checker.php` | Raw provider error bodies logged / stored unbounded | Security (elevated from P2); prior S-04; fixed 2026-08-27 | `error_message_from_body()`: JSON `error.message` unchanged; non-JSON bodies capped at 500 chars |
| S-06 | P1 | Security | **fixed** | `ajax_rate_limiter.php`; `db/install.xml`; `db/upgrade.php` | Non-atomic cache read-modify-write | Security (elevated from P2); prior S-06; fixed 2026-08-27 | DB table + optimistic UPDATE; removed MUC `ajax_ratelimit` cache def (C-19) |
| C-03 | P1 | Compliance | **fixed** | `classes/privacy/provider.php`; lang EN/HU | Incomplete `get_metadata()` vs stored/exported personal fields | Compliance; prior C-03; fixed 2026-08-27 | All personal columns declared + matching lang strings |
| C-04 | P1 | Compliance | **fixed** | privacy lang; `scrub_user_references()` | Deletion copy promised diagnostic wipe; code only nulls `userid` | Prior C-04; fixed 2026-08-27 | Privacy metadata string aligned to Light scrub behaviour (EN+HU) |
| C-05 | P1 | Compliance | **fixed** | `lang/en` (+ hu) | ~20 dead Full-leak strings (`aiinstruction*`, `diagnosticsmode*`, `settingdebug*`, …) | Compliance; prior C-05; fixed 2026-08-27 | Deleted 26 unused Full-leak keys (Light only); EN/HU parity restored |
| C-07 | P1 | Compliance | **fixed** | `draft_category_reparent.php`; `db/upgrade.php` | Reparent helper claimed / present but not called from upgrade | Compliance; prior C-07; **product decision 2026-08-26 (option B)** | Removed dead reparent helper + tests — plugin never deployed; Full keeps upgrade step |
| C-08 | P1 | Compliance | **fixed** | install.xml; get_status; process_pending; WS/comments | Spec/ticket IDs in shipped comments (`C-01`, `M-08`, …) | Compliance; prior C-08; fixed 2026-08-27 | Scrubbed remaining audit IDs from shipped PHP/tests/comments |
| C-06 | P1 | Compliance | **fixed** | `db/install.xml` | Schema COMMENT still Bloom / free-text and/or Hungarian (Full leak) | Compliance; prior C-06; fixed C-N1 | English COMMENT: easy/medium/hard only |
| F-01 | P1 | Functional | **false positive** (misreported) | `approve_renderer.php`; `approve.php` | Historical claim: Move without category → `required_param` fatal. **Verified:** soft `\core\notification::error(errornocategory)` + redirect (~158–161); empty `$categoryoptions` hides Move; no per-row Move. Provenance: GPT `bc2f036b` → Gemini `1db629c4` → triage. Optional Nit only: empty choosedots → intentional soft notification. `errornocategory` may be HU-only (not F-01). | Functional re-check 2026-08-27; prior F-01 | **Do not treat as crash.** No product fix for alleged fatal; skip as open P1 |
| F-08 | P1 | Functional | **fixed** | `status.php`; lang | FAILED UI promised settings edit via “Back to settings” but `generate.php` rejected non-STARTED (bounce loop) | Functional (new); fixed 2026-08-26 (option C) | `generationfailed` copy retry-only; secondary button → list (`backtolist` / `index.php`) |
| S-05 | P2 | Security | **fixed** | generation_list; approve_renderer; `lib.php`; delete/modelaction | State-changing GET + sesskey in URL | Security; prior S-05; fixed 2026-08-27 | POST forms + `data_submitted()` on mutate scripts |
| S-07 | P2 | Security | **wontfix** | `text_extractor.php` | Up to ~64 MiB TXT held in memory (`get_content()`); theoretical OOM on tight `memory_limit` | Security; prior S-07; **product decision 2026-08-27** | **No code change.** Accepted risk: `:use`-only, TXT-only, `maxfiles=1`, per-file 64 MiB cap + merged `source_text_limit`; normal teacher uploads KB–low MB. Revisit only if shared-hosting memory incidents |
| S-08 | P2 | Security | **fixed** | `retrytypes.php` | Narrower IDOR / missing ownership on retry-types path | Security (new) | Same ownership helper as S-02 |
| C-10 | P2 | Compliance | **fixed** | `version.php` / README | `requires` labelled wrong (4.5.0 vs 4.5.1 wording) | Compliance; prior C-10 | Aligned docs to Moodle 4.5.0; kept `2024100700` (2026-08-27) |
| C-12 | P2 | Compliance | **fixed** | `lib.php`; `upload.php` | Plain `$PAGE->requires->js()` instead of AMD | Compliance; prior C-12 | Migrated admintest, textcounter, continuebutton, uploadcancel to `amd/src/` + `js_call_amd` (2026-08-27) |
| C-15 | P2 | Compliance | **fixed** | get_status / status UI | Dead `tokenwarningmessage` WS field always empty | Compliance; prior C-15; fixed 2026-08-27 | Removed field + UI |
| C-16 | P2 | Compliance | **fixed** | `setting_configtext_percentage.php` | Unused class | Compliance; prior C-16; fixed 2026-08-27 | Deleted class + `errorpercentagerange` lang keys |
| C-17 | P2 | Compliance | **fixed** | migrate CLI / component_rename | `migrate_from_aiquizgen` CLI ships in Marketplace ZIP | Compliance; prior C-17; fixed 2026-08-27 | Deleted migration CLI, `component_rename` class, install hook, and PHPUnit test |
| C-19 | P2 | Compliance | **fixed** | `db/caches.php`; `ajax_rate_limiter.php` | “security finding #7” leftover comment | Compliance; prior C-19; fixed 2026-08-27 | Neutral technical comments only |
| C-09 | P2 | Compliance | **fixed** | file headers | `@license` URL had `Www.gnu.org` instead of `www.gnu.org` | Prior C-09; mass-replaced in C-02 copyright sweep (`4a6e759`) | Verified: zero `Www.gnu.org` in PHP tree (2026-08-27) |
| C-11 | P2 | Compliance | **fixed** | `lib.php` nav | Magic `extend_navigation` while Hooks API already used | Prior C-11 | Migrated to `primary_extend` hook listener (2026-08-27) |
| C-13 | P2 | Compliance | **fixed** | approve/generate comments | Hungarian inline comments in shipped PHP | Prior C-13 | English comments (PHPCS capitalization) |
| C-14 | P2 | Compliance | **fixed** | `settings.php` | Dual-edition / stripped-Full wording | Prior C-14 | Removed Full-edition prompt-template note from file docblock (2026-08-27) |
| C-18 | P2 | Compliance | **fixed** | `prompt_defaults.php` | Comment claims upgrade seeds prompts; upgrade does not | Prior C-18; fixed 2026-08-27 | File docblock corrected: install-only seed via `prompt_seed::apply()`; empty values only |
| F-02 | P2 | Functional | **false positive** (closed) | approve_renderer / approve.php | Historical claim: “Move selected” label but moves only one row. **Verified:** Light supports bulk move via checkbox + “Move selected”; `question_move_service::move_selected()` handles multiple approved rows. Prior finding misread single-row-only behaviour. **`light_full_funkcio_matrix_l.md` updated:** bulk move **Igen** in Light. | Functional re-check 2026-08-27; prior F-02; matrix decision | **Closed / false positive.** Bulk move by design; matrix + test docs aligned |
| F-03 | P2 | Functional | **fixed** | generate_form / question_form_builder | IH hints UI present but hints not persisted | Functional (re-verified); prior F-03; fixed 2026-08-27 (option B) | `supports_hints('IH')` — hints flow through `question_form_builder` to Moodle `question_hints` |
| F-04 | P2 | Functional | **fixed** | question_schema / save_questions_task / approve_renderer | `difficulty_label` unconstrained from AI | Functional (re-verified); prior F-04; fixed 2026-08-27 | `difficulty_label` helper + schema enum; normalise on save; localised approve display |
| F-05 | P2 | Functional | **fixed** | status.php / generate.php | Retry did not re-apply draft role like start (userid rewrite intentionally unchanged) | Functional (re-verified); prior F-05; fixed 2026-08-27 | `draft_role::grant($USER->id)` on retry after `draft_bank::create()`, mirroring generate.php start path |
| F-06 | P2 | Functional | **wontfix** | test_connection / model_checker | Connection test probes every model sync (timeout risk) | Functional (re-verified); prior F-06; **product decision 2026-08-27** | **By design / no code change.** Connection test intentionally probes all listed models synchronously to filter dropdown to working models only; cost accepted per code comments (same spirit as S-07 wontfix) |
| F-09 | P2 | Functional | **wontfix** | approve / bank link UI | “Open” link gated on draft bank caps (users without caps cannot open) | Functional (new); **product decision 2026-08-27** | **By design / no code change.** Preview, Edit and Open share `$candraftpreviewquestions` (draft `question:useall` + mutate). Normal teacher flow has draft_role from start/retry; edge case (manageall view without draft cap) accepted |
| F-10 | P2 | Functional | **fixed** | missing_types / retry | Retry missing-types count wrong across difficulties | Functional (new); fixed 2026-08-27 | Per-level matrix shortfall from saved questions; narrowed_settings + describe |
| C-N1 | Nit | Compliance | **fixed** | `db/install.xml` | Weak `COMMENT="Column"` / VERSION drift vs plugin | Compliance; prior C-N1; fixed 2026-08-27 | Meaningful COMMENTs; VERSION aligned to 20260827 |
| C-N2 | Nit | Compliance | **fixed** | `question_types.php` | “spec” wording / weak docblocks | Prior C-N2; fixed 2026-08-27 | Plugin-oriented docblocks |
| C-N3 | Nit | Compliance | **fixed** | `textcounter.js` | Stale path to status.js | Prior C-N3; fixed 2026-08-27 | AMD path comments aligned; status.js @copyright added |
| C-N4 | Nit | Compliance | **fixed** | privacy lang | Unused `privacy:metadata` string vs non-null provider | Prior C-N4; fixed 2026-08-27 | Metadata copy + claude/gemini link strings |
| C-N5 | Nit | Compliance | **fixed** | `version.php` | `release` stuck at `1.0.0` across version bumps | Compliance; prior C-N5; fixed 2026-08-27 | release 2026.08.27 |
| C-N6 | Nit | Compliance | **fixed** | text extract helper | `extract_text` Helper docblock incomplete / stale | Compliance (new); fixed 2026-08-27 | Web service docblock refreshed |
| S-N1 | Nit | Security | **fixed** | `draft_role.php` | Capability context comment inaccurate | Security (new); fixed 2026-08-27 | Header reflects editall + externallyedited lock |
| F-N1 | Nit | Functional | **fixed** | missing API key path | Non-admin missing-key message always Claude-specific | Functional (re-verified); prior F-N1; fixed 2026-08-27 | Provider-aware errormissingapikey(s) |

---

## ID map / dedup notes

| Stable ID | Absorbed / related |
|-----------|-------------------|
| **S-02** | Prior S-02 + S-03 + approve/delete/move ownership (Security P0); S-08 is narrower sibling on `retrytypes.php` |
| **S-01** | Prior S-01; Security elevated to P0 |
| **S-04**, **S-06** | Prior P2; Security elevated to P1 |
| **F-07**, **F-08**, **F-09**, **F-10** | New from Functional pass |
| **S-07** | Prior S-07; **wontfix 2026-08-27** — product decision: no memory-cap hardening (64 MiB TXT + existing guards accepted) |
| **C-09** | Prior C-09; **fixed** with C-02 header sweep (`4a6e759`) — `Www.gnu.org` → `www.gnu.org` |
| **C-10** | Prior C-10; **fixed 2026-08-27** — docs aligned to Moodle 4.5.0; `$plugin->requires` stays `2024100700` |
| **C-11** | Prior C-11; **fixed 2026-08-27** — `primary_extend` hook; legacy `extend_navigation` removed |
| **C-12** | Prior C-12; **fixed 2026-08-27** — legacy `js/` scripts migrated to AMD + `js_call_amd` |
| **C-13** | Prior C-13; **fixed 2026-08-27** — Hungarian inline PHP comments translated to English |
| **C-15** | Prior C-15; **fixed 2026-08-27** — removed dead `tokenwarningmessage` WS field and status UI |
| **C-16** | Prior C-16; **fixed 2026-08-27** — deleted unused `setting_configtext_percentage` class + `errorpercentagerange` lang keys |
| **C-17** | Prior C-17; **fixed 2026-08-27** — removed `migrate_from_aiquizgen` CLI, `component_rename`, install hook, and PHPUnit test |
| **C-14** | Prior C-14; **fixed 2026-08-27** — removed Full-edition prompt-template wording from `settings.php` file docblock |
| **F-02** | Prior F-02; **false positive / closed 2026-08-27** — bulk move Light in scope; `light_full_funkcio_matrix_l.md` updated; checkbox + “Move selected” + `move_selected()` |
| **F-06** | Prior F-06; **wontfix 2026-08-27** — by design: connection test probes all listed models synchronously to filter dropdown to working models only; cost accepted (same spirit as S-07) |
| **F-09** | Prior F-09; **wontfix 2026-08-27** — by design: Open/Preview/Edit gated on draft preview capability; normal flow has draft_role from start/retry |
| **S-08**, **S-N1**, **C-N6** | New from Security / Compliance nits |
| **C-01**, **C-02** | Prior C-01/C-02; **fixed `4a6e759`** — MOODLE_INTERNAL + @copyright sweep |
| **F-07** | Prior F-07; **fixed `848f7aa`** — post-install redirect + setup checklist (not auto-create course) |
| **C-04**, **C-05**, **C-06**, **C-07**, **C-08** | Prior P1 compliance; **all fixed 2026-08-27** (`b9c99e9`, C-N1 for C-06) |
| Unchanged open from prior tracker | **None** — all audit IDs closed (fixed / wontfix / false positive) as of 2026-08-27 |

---

## Pass sources

| Pass | Agent | Role |
|------|-------|------|
| Security | `a2368d28-d6e9-4bb1-b38d-4e188cedcc95` | Ownership, draft role, rate limit, logging |
| Compliance | `7072ded4-bbec-4cd4-8ccb-16c2f2657e7e` | Copyright, MOODLE_INTERNAL, privacy, Full leaks |
| Functional | `1db629c4-e2c2-40b4-b717-837327920666` | Fresh install, FAILED loop, Move, UX P2/Nit |
| Prior triage | This file (pre-merge) | Stable S-/C-/F- IDs |

Do not treat this document as a fix commit — implement in product code separately; re-run targeted security review after S-01 / S-02. **2026-08-27:** all submit-critical items implemented; remaining gate is CI + Marketplace packaging/submit.
