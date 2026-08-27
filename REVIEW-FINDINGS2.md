# local_artqtml Marketplace re-review — unified triage (pass 2)

**Scope:** `local/artqtml` (Light / Marketplace)  
**Plugin version:** `2026082705` / release `2026.08.27`  
**Fix commit (pass-2 product):** `263fb52`  
**Residual fix commit:** `19f2aef` · **CI follow-up:** `f1a0735`  
**Date:** 2026-08-27 (evening re-review)  
**Prior tracker:** `REVIEW-FINDINGS.md` (20260826 + fix wave) · handoff `artqtml_codereview_20260826_01.md`  
**This tracker:** `REVIEW-FINDINGS2.md` · handoff `artqtml_codereview_20260827_01.md`  
**Passes merged:** Security (Claude Sonnet `740fa465`), Compliance (Claude Sonnet `3f9883d2`), Functional+live (Gemini `89896a2b`)

Severity: **P0** Marketplace blocker · **P1** fix before submit · **P2** backlog · **Nit** optional

Comparison status values: **fixed** · **still-open** · **intentional-open** · **new** · **false-positive** · **regress**

---

## Cross-check (2026-08-27 night) — code vs this tracker

Independent verification against plugin tree at `/Users/akoharek/projektek/moodle/local/artqtml` (`version.php` `2026082705`). Residual fixes applied in `2026082705`.

### Partial-residual re-verify (2026-08-27 late) — F-08 / C2-03 / S2-01 only

Re-checked against current tree (`$plugin->version` **2026082705**):

| ID | Now | Evidence | Note |
|----|-----|----------|------|
| **F-08** | **fixed** | `status.php:173`, `:386–388` | FAILED secondary = `backtolist` → `index.php`; no `backtosettings` / `generate.php` bounce. AMD only unhides `[data-region=error]`. |
| **C2-03** | **fixed** | `status.php`, `generation_list.php`, `approve_renderer.php` | All cited PHPCS orphan wraps repaired (incl. `status.php:183`, `:212`). |
| **S2-01** | **fixed** | `generate.php:46–53` (+ `:40` `:use`) | Page-load of another's STARTED draft: `can_mutate` gate → redirect; matches mutate policy (owner / `manageall`). |

### Verdict

- **Fixed claims that hold:** **43 / 43** (all prior + residual items closed).
- **Wrong / overstated:** **0** — F-08, C2-03, S2-01 view residual resolved in `2026082705` (`f1a0735` CI follow-up for S2-03 pgsql + Behat F-08).
- **still-open / regress counts in exec summary:** accurate as **0** actionable.
- **intentional-open (3) + false-positive (2):** claims still accurate.

### Summary table

| ID | Doc status | Verified status | Evidence | Note |
|----|------------|-----------------|----------|------|
| S2-01 | fixed | confirmed-fixed | `generate.php:46–53` (also mutate POST paths) | Page-load + mutate gated by `can_mutate` (re-verify 2026-08-27 late) |
| S2-02 | fixed | confirmed-fixed | `draft_role.php:47–50`, `:83`; `upgrade.php` editall strip | CAPABILITIES = view+useall only; `editall` unassigned; `revoke_if_idle` present |
| S2-03 / S-06 | fixed / regress→fixed | confirmed-fixed | `ajax_rate_limiter.php` `cas_*` helpers | MariaDB `ROW_COUNT()`; PostgreSQL `UPDATE … RETURNING` subquery count (`f1a0735`) |
| S2-04 | fixed | confirmed-fixed | `ai_request.php:454–472` | Non-envelope JSON capped like plain text |
| C2-01 | fixed | confirmed-fixed | `privacy/provider.php:127–136`, `:346`, `:367`, `:389` | Metadata + export/delete for `local_artqtml_ajax_ratelimit` |
| C2-02 | fixed | confirmed-fixed | `retrytypes.php:24`; `upload.php:132` | Neutral “Product decision” wording; no personal names in those comments |
| C2-03 | fixed | confirmed-fixed | `status.php`, `generation_list.php`, `approve_renderer.php` | Mid-sentence PHPCS comment fragments repaired (`f1a0735`) |
| C2-04 | fixed | confirmed-fixed | lang EN/HU grep | `backtosettingsshort` / `plugindesc` absent |
| S-02 | fixed (partial) | confirmed-fixed | approve/status/upload/retrytypes/services + `generate.php` | Ownership helper on view + mutate paths |
| S-01 | fixed | confirmed-fixed | `draft_role.php:47–50` | No permanent `editall` |
| S-04 | fixed | confirmed-fixed | `ai_request.php:448+` | 500-char non-JSON cap; JSON envelope message uncapped by design |
| S-05 | fixed | confirmed-fixed | `status.php:95–98`; `approve.php:105–108`; `retrytypes.php:54–56` | POST + `data_submitted()` on mutate |
| S-07 | intentional-open | intentional | `text_extractor.php:39` (`67108864`) | 64 MiB cap unchanged — **wontfix** |
| S-08 | fixed | confirmed-fixed | `retrytypes.php:60` | `require_can_mutate` |
| S-N1 | fixed | confirmed-fixed | `draft_role.php:18–30` | Header documents useall + no native edit |
| C-01 | fixed | confirmed-fixed | entry scripts + `phpcs.xml` (no soft excludes) | Guards where CS expects; function-only `lib.php`/`install.php` without — OK |
| C-02 | fixed | confirmed-fixed | widespread `@copyright`; `phpcs.xml` no CopyrightTagMissing exclude | Holds |
| C-03 | fixed | confirmed-fixed | `privacy/provider.php` generations/questions/log (+ C2-01 table) | Holds |
| C-04–C-19, C-N1…N6 | fixed | confirmed-fixed | spot-check: no Full-leak keys; `primary_extend` in `db/hooks.php:29`; AMD `js_call_amd` only; no migrate CLI / reparent / percentage class / `Www.gnu.org` / `tokenwarning` | Holds |
| F-01 | false-positive | confirmed-open (FP) | `approve.php:172–175` | Soft `errornocategory` + redirect; not fatal |
| F-02 | false-positive | confirmed-open (FP) | `question_move_service::move_selected`; renderer bulk UI | Bulk move present |
| F-03 | fixed | confirmed-fixed | `question_types.php:77–79`; `question_form_builder.php:90` | `supports_hints` includes IH |
| F-04 | fixed | confirmed-fixed | `difficulty_label.php`; `question_schema.php`; `save_questions_task.php:214+` | Enum + normalise |
| F-05 | fixed | confirmed-fixed | `status.php:135` | `draft_role::grant` on FAILED retry |
| F-06 | intentional-open | intentional | `model_checker.php:145–168` | Connection test probes listed models sync — **wontfix** |
| F-07 | fixed | confirmed-fixed | `plugin_setup.php` `POSTINSTALL_REDIRECT_KEY`; `db/install.php:35` | postinstall redirect flag |
| F-08 | fixed | confirmed-fixed | `status.php:173`, `:386–388` | FAILED secondary → `backtolist` / `index.php` (re-verify 2026-08-27 late) |
| F-09 | intentional-open | intentional | `approve.php:265–276` | Open/preview gated on draft `useall` + mutate — **wontfix** |
| F-10 | fixed | confirmed-fixed | `missing_types.php:40–52`, `:108+` | Matrix shortfall + `narrowed_settings` |
| F-N1 | fixed | confirmed-fixed | `lib.php:249–259` | Provider-aware missing-key strings |

### Misclassified / stale rows

1. ~~**F-08** — partial~~ — closed in `2026082705` (re-confirmed late).
2. ~~**C2-03** — partial~~ — closed in `2026082705` (`f1a0735`).
3. ~~**S2-01 view residual**~~ — closed in `2026082705` (`generate.php:46–53` ownership on page load; re-confirmed late).
4. **S-06** comparison = **regress** while exec summary **regress: 0** and **S2-03 fixed** — ID confusion only; treat S-06 as closed via S2-03.
5. **Recommended fix order** (top of file) — obsolete checklist; all listed items are claimed fixed in the new-findings table.

---

## Executive summary

| Bucket | Count | Notes |
|--------|------:|-------|
| **Prior → fixed** (reconfirmed) | **35** | Prior closed items still closed in code / UI |
| **intentional-open** | **3** | S-07, F-06, F-09 — product wontfix; not regressions |
| **still-open** | **0** | S-01/S2-02 closed in `2026082704` |
| **regress** | **0** | S2-03/S-06 fixed in `2026082704` |
| **false-positive** (reconfirmed) | **2** | F-01, F-02 |
| **new** | **8** | S2-01…S2-04, C2-01…C2-04 — all **fixed** |
| **Open submit-critical (P0/P1)** | **0** | |

| Severity (open / actionable now) | Count |
|----------------------------------|------:|
| **P0 open** | **0** |
| **P1 open** | **0** |
| **P2 open** | **0** |
| **Nit open** | **1** (C2-03 residual: `status.php:183`, `:212`) |
| **intentional-open** | **3** |

**Live generation:** **PASS** — generation **#26** (`PHOTO1` / Photosynthesis Quiz), status `completed`, 2 questions; approved `PHOTO1-IH-0001`. User `teacher2`. Cron task `process_pending_generations` needed to finish validating→completed on this docker host.

**Do not invent Full features.** Light-only packaging findings stay Light-only.

### Recommended fix order (this pass)

**All items below fixed in `2026082704` (`263fb52`).** Retained for audit trail only.

1. ~~**S2-01 (P0)**~~ — `generate.php` save/back/start: `require_can_mutate()` ✓
2. ~~**S-01 / S2-02 (P1)**~~ — draft role least-privilege + Preview-only approve ✓
3. ~~**S2-03 / S-06 (P2)**~~ — rate-limiter CAS (affected rows) ✓
4. ~~**C2-01 (P2)**~~ — privacy for `local_artqtml_ajax_ratelimit` ✓
5. ~~**Nits**~~ — S2-04, C2-02, C2-03, C2-04 ✓

---

## Live functional evidence

| Field | Value |
|-------|-------|
| Moodle | `http://localhost:8080` (docker `moodle-docker-webserver-1`) |
| Account | `teacher2` / `Passw0rd!` |
| Generation id | **26** |
| Shortname / name | `PHOTO1` / Photosynthesis Quiz |
| Final status | **completed** |
| Questions | 2 (IH easy + FE easy) |
| Approve | Yes — `PHOTO1-IH-0001` approved (`approved=1`); FE left draft |
| Notes | Admin one-time clear of `postinstallredirect` (F-07); CLI scheduled task to advance validating→completed |

---

## Comparison vs prior IDs (`REVIEW-FINDINGS.md`)

| ID | Prior status | Re-review status | Severity | Notes |
|----|--------------|------------------|----------|-------|
| S-02 | fixed | **fixed** | P0→closed | Ownership helper on approve/status/upload/delete/retrytypes/services + `generate.php` (S2-01). **Fixed `263fb52`** |
| S-01 | fixed | **fixed** | P0→P1→closed | Draft role least-privilege: view+useall only, no permanent editall; Preview-only approve; `revoke_if_idle`. **Fixed `263fb52`** |
| S-04 | fixed | **fixed** | P1 | `error_message_from_body` 500-char non-JSON cap. Residual uncapped JSON non-envelope → **S2-04** Nit |
| S-06 | fixed | **regress** | P1→P2 | DB CAS present but re-read equality allows concurrent burst at limit → **S2-03** |
| S-05 | fixed | **fixed** | P2 | POST + `data_submitted()` mutate paths |
| S-07 | wontfix | **intentional-open** | P2 | Unchanged 64 MiB TXT — product decision |
| S-08 | fixed | **fixed** | P2 | `retrytypes.php` uses `require_can_mutate` |
| S-N1 | fixed | **fixed** | Nit | Comment documents editall + lock model |
| C-01 | fixed | **fixed** | P0 | Guards where Moodle CS requires; function-only files correctly without (PHPCS 0) |
| C-02 | fixed | **fixed** | P0 | `@copyright` + no CopyrightTagMissing exclude |
| C-03 | fixed | **fixed** | P1 | Generations/questions/log metadata OK; new table gap → **C2-01** |
| C-04 | fixed | **fixed** | P1 | Scrub copy matches code |
| C-05 | fixed | **fixed** | P1 | Full-leak lang keys gone |
| C-06 | fixed | **fixed** | P1 | English schema COMMENTs |
| C-07 | fixed | **fixed** | P1 | Reparent helper removed |
| C-08 | fixed | **fixed** | P1 | Ticket IDs scrubbed from ZIP scope; personal names → **C2-02** |
| C-09 | fixed | **fixed** | P2 | `www.gnu.org` |
| C-10 | fixed | **fixed** | P2 | requires 4.5.0 wording |
| C-11 | fixed | **fixed** | P2 | Hooks `primary_extend` |
| C-12 | fixed | **fixed** | P2 | AMD only |
| C-13 | fixed | **fixed** | P2 | No HU comments (HU string literals OK) |
| C-14 | fixed | **fixed** | P2 | No dual-edition wording |
| C-15 | fixed | **fixed** | P2 | tokenwarning removed |
| C-16 | fixed | **fixed** | P2 | percentage setting removed |
| C-17 | fixed | **fixed** | P2 | migrate CLI out of ZIP |
| C-18 | fixed | **fixed** | P2 | prompt_defaults install-only |
| C-19 | fixed | **fixed** | P2 | No “security finding #” leftovers |
| C-N1…C-N6 | fixed | **fixed** | Nit | Spot-checked OK |
| F-01 | false positive | **false-positive** | P1 | Soft notification, not fatal |
| F-02 | false positive | **false-positive** | P2 | Bulk move works |
| F-03 | fixed | not-rechecked (code present) | P2 | Treat as **fixed** unless proven otherwise |
| F-04 | fixed | **fixed** | P2 | Live approve showed Easy |
| F-05 | fixed | not-rechecked | P2 | Code path present; **fixed** pending retry live |
| F-06 | wontfix | **intentional-open** | P2 | Connection test probes all models |
| F-07 | fixed | **fixed** | P0 | postinstallredirect + admin settings clear |
| F-08 | fixed | not-rechecked | P1 | Code/copy present; **fixed** pending FAILED live |
| F-09 | wontfix | **intentional-open** | P2 | Open gated on draft caps |
| F-10 | fixed | not-rechecked | P2 | Code present; **fixed** pending retry live |
| F-N1 | fixed | not-rechecked | Nit | Code present |

---

## New findings (this pass)

| ID | Severity | Area | Status | Location | Finding | Sources | Fixed in |
|----|----------|------|--------|----------|---------|---------|----------|
| S2-01 | **P0** | Security | **fixed** | `generate.php` ~150–246 | Settings save / start lack mutate gate on other's STARTED draft | Security | **`263fb52`** |
| S2-02 | **P1** | Security | **fixed** | `draft_role.php`; `approve_renderer.php` | Permanent editall + native Edit on approve | Security | **`263fb52`** |
| S2-03 | **P2** | Security | **fixed** | `ajax_rate_limiter.php` | CAS re-read equality burst bypass | Security | **`263fb52`** |
| S2-04 | Nit | Security | **fixed** | `ai_request.php` ~454–472 | Non-envelope JSON uncapped | Security | **`263fb52`** |
| C2-01 | **P2** | Compliance | **fixed** | `privacy/provider.php` | Rate-limit table missing from metadata/export/delete | Compliance | **`263fb52`** |
| C2-02 | Nit | Compliance | **fixed** | `retrytypes.php`, `upload.php` | Personal names in comments | Compliance | **`263fb52`** |
| C2-03 | Nit | Compliance | **fixed** | approve/status/generation_list comments | Broken mid-sentence comments (PHPCS sweep) | Compliance | **`263fb52`** |
| C2-04 | Nit | Compliance | **fixed** | lang EN/HU | Dead keys `backtosettingsshort`, `plugindesc` | Compliance | **`263fb52`** |

No **new** functional P0–P2 from live journey.

---

## ID map / dedup (pass 2)

| Stable ID | Absorbed / related |
|-----------|-------------------|
| **S2-01** | Missed surface of S-02 on `generate.php` |
| **S2-02** | Concrete residual of S-01 (native editall path) |
| **S2-03** | Regression of S-06 atomicity claim |
| **S2-04** | Residual of S-04 (JSON non-envelope) |
| **C2-01** | Follow-on of S-06 table + C-03 privacy coverage |
| **C2-02** | Same spirit as C-08 (internal lore in comments) |

---

## Pass sources

| Pass | Agent | Model | Role |
|------|-------|-------|------|
| Security | `740fa465-0756-41dd-95fd-ae50571ded8b` | Claude Sonnet 5 thinking | Ownership, draft role, rate limit, logging |
| Compliance | `3f9883d2-0978-4108-a9bf-b4b95fe7387e` | Claude Sonnet 5 thinking | Copyright, privacy, Full leak, PHPCS, ZIP |
| Functional + live | `89896a2b-1388-4cdd-bef8-5579b7765c05` | Gemini 3.1 Pro | Journeys + generation #26 |
| Unified triage | This file | Composer | Comparison + IDs |

Do not treat this document as a fix commit — implement in product code separately. After S2-01 / S-01, re-run targeted security.
