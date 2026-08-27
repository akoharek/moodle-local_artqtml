# local_artqtml Marketplace code review — unified triage

**Scope:** `local/artqtml` (Light / Marketplace)  
**Updated:** 2026-08-27 (F-03 fixed — IH hints persist to question bank; F-02 false positive — bulk move Light scope; matrix updated; C-19 fixed; C-18 fixed; C-17 fixed; C-16 fixed; C-15 fixed; C-14 fixed; C-11/C-12/C-13 fixed; C-10 fixed; C-09 fixed in tracker)  
**Passes merged:** Security (Sonnet `a2368d28`), Compliance (Sonnet `7072ded4`), Functional (Gemini `1db629c4`); prior tracker retained for stable IDs  
**Status:** Triage only — no product code changed in this pass

Severity: **P0** Marketplace blocker · **P1** fix before submit · **P2** backlog · **Nit** optional

---

## Executive summary

| Severity | Count | Role |
|----------|------:|------|
| **P0** | **5** | Marketplace blockers |
| **P1** | **9** | Fix before HQ / Marketplace submit |
| **P2** | **5** | Backlog after submit-critical work |
| **Nit** | **9** | Optional polish |

**Marketplace blockers (P0):** (1) ~~missing object-level ownership~~ **S-02 fixed**, (2) ~~permanent draft-course editall/useall~~ **S-01 fixed (2026082602)**, (3) missing `@copyright` / phpcs exclude vs moodle.org CI, (4) missing `MOODLE_INTERNAL` guards, (5) fresh install blocked until admin sets hidden draft course (`draftcourseid=0` / `is_configured()`).

**Later (P1–Nit):** privacy metadata, Full-leak lang/comments, ~~FAILED-state UX loop~~ **F-08 fixed**, ~~Move button crash~~ **F-01 false positive (misreported — not a fatal)**, ~~bulk move label mismatch~~ **F-02 false positive (bulk move in Light scope; matrix updated)**, rate-limiter/logging hygiene, AMD/CLI leftovers, schema/label nits.

**Recommended fix order**

1. **Ownership policy** — one auth helper: owner or manage capability on upload / source / status abort·retry / approve·delete·move (and narrow `retrytypes` IDOR).  
2. ~~**Draft role** — stop permanent `moodle/question:editall`+`useall`; revoke when unused.~~ **Done (2026082602): preview-only role + external-edit lock**
3. **Copyright + `MOODLE_INTERNAL`** — drop `CopyrightTagMissing` exclude; add guards (C-01, C-02).  
4. **Fresh-install draft course** — allow first-run without preconfigured hidden course (F-07).  
5. **UX / compliance P1** — ~~FAILED misleading back-to-settings~~ **F-08 fixed**, ~~Move-without-categories crash~~ **F-01 false positive (retracted)**, privacy metadata, dead Full lang strings, reparent upgrade, ticket-ID scrub.  
6. **P2 / Nit** as capacity allows; re-audit touched security surfaces after ownership + draft-role fixes.

---

## Findings (sorted P0 → Nit)

| ID | Severity | Area | Status | Location | Finding | Sources | Suggested next step |
|----|----------|------|--------|----------|---------|---------|---------------------|
| S-02 | P0 | Security | **fixed** | `upload.php`; `generation_source_service.php`; `status.php` (abort/retry); `approve.php`; approval / deletion / move services | Mutating another user’s generation needs only system `local/artqtml:use` — no ownership / manage check (same root cause across endpoints) | Security (elevated); prior S-02/S-03 | Shared object-level auth: require owner or elevated manage capability before any mutate |
| S-01 | P0 | Security | **fixed** | `classes/local/draft_role.php`; `classes/observer.php`; approve UI/services | Draft role no longer grants native edit; external Moodle edit locks question (`externallyedited`); approve/move blocked; generation delete still allowed (owner/`:manageall`) | Security (elevated); prior S-01 | Documented 2026082602 — holding-area model replaces permanent editall grant |
| C-02 | P0 | Compliance | open | most PHP; `phpcs.xml` | Missing `@copyright`; phpcs excludes `CopyrightTagMissing` but moodle.org CI will not | Compliance; prior C-02 | Add copyright tags; remove exclude so local CI matches HQ |
| C-01 | P0 | Compliance | open | `lib.php`; `db/upgrade.php`; `db/install.php`; `db/uninstall.php` | Missing `defined('MOODLE_INTERNAL') \|\| die();` | Compliance; prior C-01 | Add guard after file docblock |
| F-07 | P0 | Functional | open | config / `is_configured()`; generate entry | Fresh install blocked while `draftcourseid=0` until admin sets hidden draft course | Functional (new P0) | Bootstrap or auto-create draft course / allow first-run without admin pre-config |
| S-04 | P1 | Security | open | generate/validate tasks; status trait; logs/events | Raw provider error bodies logged / stored unbounded | Security (elevated from P2); prior S-04 | Bound length; store codes/sanitized snippets only |
| S-06 | P1 | Security | open | `ajax_rate_limiter.php` | Non-atomic cache read-modify-write | Security (elevated from P2); prior S-06 | Use lock API or atomic counter |
| C-03 | P1 | Compliance | open | `classes/privacy/provider.php` | Incomplete `get_metadata()` vs stored/exported personal fields | Compliance; prior C-03 | Declare all personal columns + matching lang strings |
| C-04 | P1 | Compliance | open | privacy lang; `scrub_user_references()` | Deletion copy promises diagnostic wipe; code only nulls `userid` | Prior C-04 (not re-disputed) | Align privacy text with actual deletion behaviour |
| C-05 | P1 | Compliance | open | `lang/en` (+ hu) | ~20 dead Full-leak strings (`aiinstruction*`, `diagnosticsmode*`, `settingdebug*`, …) | Compliance; prior C-05 | Delete unused keys; keep Light-only vocabulary |
| C-07 | P1 | Compliance | open | `draft_category_reparent.php`; `db/upgrade.php` | Reparent helper claimed / present but not called from upgrade | Compliance; prior C-07 | Invoke from current upgrade step or remove dead claim |
| C-08 | P1 | Compliance | open | install.xml; get_status; process_pending; WS/comments | Spec/ticket IDs in shipped comments (`C-01`, `M-08`, …) | Compliance; prior C-08 | Scrub ticket/spec IDs from release tree |
| C-06 | P1 | Compliance | open | `db/install.xml` | Schema COMMENT still Bloom / free-text and/or Hungarian (Full leak) | Compliance; prior C-06 | English COMMENT describing easy/medium/hard only |
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
| F-04 | P2 | Functional | open | question_schema / save_questions_task | `difficulty_label` unconstrained from AI | Functional (re-verified); prior F-04 | Canonicalize to easy/medium/hard |
| F-05 | P2 | Functional | open | status.php / generate.php | Retry does not rewrite userid / re-apply draft role like start | Functional (re-verified); prior F-05 | Owner-only retry; grant draft role as on start |
| F-06 | P2 | Functional | open | test_connection / model_checker | Connection test probes every model sync (timeout risk) | Functional (re-verified); prior F-06 | Fast key check; optional async full sweep |
| F-09 | P2 | Functional | open | approve / bank link UI | “Open” link gated on draft bank caps (users without caps cannot open) | Functional (new) | Gate on view capability appropriate for Light, or clarify UX |
| F-10 | P2 | Functional | open | missing_types / retry | Retry missing-types count wrong across difficulties | Functional (new) | Recount per difficulty / type matrix |
| C-N1 | Nit | Compliance | open | `db/install.xml` | Weak `COMMENT="Column"` / VERSION drift vs plugin | Compliance; prior C-N1 | Meaningful COMMENTs; align VERSION |
| C-N2 | Nit | Compliance | open | `question_types.php` | “spec” wording / weak docblocks | Prior C-N2 | Tighten docs |
| C-N3 | Nit | Compliance | open | `textcounter.js` | Stale path to status.js | Prior C-N3 | Fix or remove reference |
| C-N4 | Nit | Compliance | open | privacy lang | Unused `privacy:metadata` string vs non-null provider | Prior C-N4 | Align strings with provider |
| C-N5 | Nit | Compliance | open | `version.php` | `release` stuck at `1.0.0` across version bumps | Compliance; prior C-N5 | Bump release with version |
| C-N6 | Nit | Compliance | open | text extract helper | `extract_text` Helper docblock incomplete / stale | Compliance (new) | Refresh docblock |
| S-N1 | Nit | Security | open | `draft_role.php` | Capability context comment inaccurate | Security (new) | Correct comment only |
| F-N1 | Nit | Functional | open | missing API key path | Non-admin missing-key message always Claude-specific | Functional (re-verified); prior F-N1 | Provider-agnostic or provider-aware copy |

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
| **S-08**, **S-N1**, **C-N6** | New from Security / Compliance nits |
| Unchanged open from prior tracker | C-01–C-08 as listed; F-04–F-06, F-N1 re-verified still open (**F-01 false positive / retracted**; **F-02 false positive / closed — matrix updated** 2026-08-27; **F-03 fixed** 2026-08-27; **F-08 fixed** 2026-08-26; **S-07 wontfix** 2026-08-27; **C-09 fixed** 2026-08-27; **C-10 fixed** 2026-08-27; **C-11 fixed** 2026-08-27; **C-12 fixed** 2026-08-27; **C-13 fixed** 2026-08-27; **C-14 fixed** 2026-08-27; **C-15 fixed** 2026-08-27; **C-16 fixed** 2026-08-27; **C-17 fixed** 2026-08-27; **C-18 fixed** 2026-08-27; **C-19 fixed** 2026-08-27) |

Prior note: REVIEW-FINDINGS tracked **C-01, C-02, C-05, C-07, C-08** as still open — confirmed retained above (**C-10 fixed** 2026-08-27; **C-16 fixed** 2026-08-27; **C-17 fixed** 2026-08-27; **C-19 fixed** 2026-08-27).

---

## Pass sources

| Pass | Agent | Role |
|------|-------|------|
| Security | `a2368d28-d6e9-4bb1-b38d-4e188cedcc95` | Ownership, draft role, rate limit, logging |
| Compliance | `7072ded4-bbec-4cd4-8ccb-16c2f2657e7e` | Copyright, MOODLE_INTERNAL, privacy, Full leaks |
| Functional | `1db629c4-e2c2-40b4-b717-837327920666` | Fresh install, FAILED loop, Move, UX P2/Nit |
| Prior triage | This file (pre-merge) | Stable S-/C-/F- IDs |

Do not treat this document as a fix commit — implement in product code separately; re-run targeted security review after S-01 / S-02.
