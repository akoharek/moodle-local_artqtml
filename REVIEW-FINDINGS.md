# local_artqtml Marketplace code review — unified triage

**Scope:** `local/artqtml` (Light / Marketplace)  
**Updated:** 2026-08-26  
**Passes merged:** Security (Sonnet `a2368d28`), Compliance (Sonnet `7072ded4`), Functional (Gemini `1db629c4`); prior tracker retained for stable IDs  
**Status:** Triage only — no product code changed in this pass

Severity: **P0** Marketplace blocker · **P1** fix before submit · **P2** backlog · **Nit** optional

---

## Executive summary

| Severity | Count | Role |
|----------|------:|------|
| **P0** | **5** | Marketplace blockers |
| **P1** | **11** | Fix before HQ / Marketplace submit |
| **P2** | **18** | Backlog after submit-critical work |
| **Nit** | **9** | Optional polish |

**Marketplace blockers (P0):** (1) ~~missing object-level ownership~~ **S-02 fixed**, (2) ~~permanent draft-course editall/useall~~ **S-01 fixed (2026082602)**, (3) missing `@copyright` / phpcs exclude vs moodle.org CI, (4) missing `MOODLE_INTERNAL` guards, (5) fresh install blocked until admin sets hidden draft course (`draftcourseid=0` / `is_configured()`).

**Later (P1–Nit):** privacy metadata, Full-leak lang/comments, FAILED-state UX loop, Move button crash, rate-limiter/logging hygiene, AMD/CLI leftovers, schema/label nits.

**Recommended fix order**

1. **Ownership policy** — one auth helper: owner or manage capability on upload / source / status abort·retry / approve·delete·move (and narrow `retrytypes` IDOR).  
2. ~~**Draft role** — stop permanent `moodle/question:editall`+`useall`; revoke when unused.~~ **Done (2026082602): preview-only role + external-edit lock**
3. **Copyright + `MOODLE_INTERNAL`** — drop `CopyrightTagMissing` exclude; add guards (C-01, C-02).  
4. **Fresh-install draft course** — allow first-run without preconfigured hidden course (F-07).  
5. **UX / compliance P1** — FAILED abort·retry loop, Move-without-categories, privacy metadata, dead Full lang strings, reparent upgrade, ticket-ID scrub.  
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
| F-01 | P1 | Functional | open | `approve_renderer.php`; `approve.php` | Move button when no categories → `required_param` fatal | Functional (re-verified); prior F-01 | Hide/disable Move or soft-fail without required param |
| F-08 | P1 | Functional | open | `status.php`; `generate.php` | FAILED generation: status→generate rejects non-STARTED (redirect loop); Abort hidden on FAILED | Functional (new) | Allow abort/retry from FAILED; stop bounce to generate for terminal states |
| S-05 | P2 | Security | open | generation_list; approve_renderer; `lib.php` | State-changing GET + sesskey in URL | Security; prior S-05 | Prefer POST + `data_submitted()` |
| S-07 | P2 | Security | open | `text_extractor.php` | Up to ~64 MiB TXT held in memory | Security; prior S-07 | Lower cap; stream/chunk |
| S-08 | P2 | Security | **fixed** | `retrytypes.php` | Narrower IDOR / missing ownership on retry-types path | Security (new) | Same ownership helper as S-02 |
| C-10 | P2 | Compliance | open | `version.php` / README | `requires` labelled wrong (4.5.0 vs 4.5.1 wording) | Compliance; prior C-10 | Align comment and `requires` value |
| C-12 | P2 | Compliance | open | `lib.php`; `upload.php` | Plain `$PAGE->requires->js()` instead of AMD | Compliance; prior C-12 | Convert to AMD modules |
| C-15 | P2 | Compliance | open | get_status / status UI | Dead `tokenwarningmessage` WS field always empty | Compliance; prior C-15 | Remove field + UI |
| C-16 | P2 | Compliance | open | `setting_configtext_percentage.php` | Unused class | Compliance; prior C-16 | Delete |
| C-17 | P2 | Compliance | open | migrate CLI / component_rename | `migrate_from_aiquizgen` CLI ships in Marketplace ZIP | Compliance; prior C-17 | Exclude from ZIP or document one-shot-only |
| C-19 | P2 | Compliance | open | `db/caches.php` | “security finding #7” leftover comment | Compliance; prior C-19 | Scrub |
| C-09 | P2 | Compliance | open | file headers | `@license` URL has `Www` | Prior C-09 | Fix to `www.gnu.org` |
| C-11 | P2 | Compliance | open | `lib.php` nav | Magic `extend_navigation` while Hooks API already used | Prior C-11 | Move to primary_extend hook |
| C-13 | P2 | Compliance | open | approve/generate comments | Hungarian inline comments in shipped PHP | Prior C-13 | English comments (PHPCS capitalization) |
| C-14 | P2 | Compliance | open | `settings.php` | Dual-edition / stripped-Full wording | Prior C-14 | Describe Light settings only |
| C-18 | P2 | Compliance | open | `prompt_defaults.php` | Comment claims upgrade seeds prompts; upgrade does not | Prior C-18 | Fix comment or add upgrade step |
| F-02 | P2 | Functional | open | approve_renderer / approve.php | “Move selected” label but moves only one row | Functional (re-verified); prior F-02 | Rename label or implement bulk move |
| F-03 | P2 | Functional | open | generate_form / question_form_builder | IH hints UI present but hints not persisted | Functional (re-verified); prior F-03 | Persist hints or remove IH hint UI |
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
| **S-08**, **S-N1**, **C-N6** | New from Security / Compliance nits |
| Unchanged open from prior tracker | C-01–C-08, C-09–C-19 as listed; F-01–F-06, F-N1 re-verified still open |

Prior note: REVIEW-FINDINGS tracked **C-01, C-02, C-05, C-07, C-08, C-10, C-16, C-17, C-19** as still open — confirmed retained above.

---

## Pass sources

| Pass | Agent | Role |
|------|-------|------|
| Security | `a2368d28-d6e9-4bb1-b38d-4e188cedcc95` | Ownership, draft role, rate limit, logging |
| Compliance | `7072ded4-bbec-4cd4-8ccb-16c2f2657e7e` | Copyright, MOODLE_INTERNAL, privacy, Full leaks |
| Functional | `1db629c4-e2c2-40b4-b717-837327920666` | Fresh install, FAILED loop, Move, UX P2/Nit |
| Prior triage | This file (pre-merge) | Stable S-/C-/F- IDs |

Do not treat this document as a fix commit — implement in product code separately; re-run targeted security review after S-01 / S-02.
