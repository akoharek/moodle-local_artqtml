# local_artqtml Marketplace re-review — unified triage (pass 2)

**Scope:** `local/artqtml` (Light / Marketplace)  
**Plugin version:** `2026082704` / release `2026.08.27`  
**Date:** 2026-08-27 (evening re-review)  
**Prior tracker:** `REVIEW-FINDINGS.md` (20260826 + fix wave) · handoff `artqtml_codereview_20260826_01.md`  
**This tracker:** `REVIEW-FINDINGS2.md` · handoff `artqtml_codereview_20260827_01.md`  
**Passes merged:** Security (Claude Sonnet `740fa465`), Compliance (Claude Sonnet `3f9883d2`), Functional+live (Gemini `89896a2b`)

Severity: **P0** Marketplace blocker · **P1** fix before submit · **P2** backlog · **Nit** optional

Comparison status values: **fixed** · **still-open** · **intentional-open** · **new** · **false-positive** · **regress**

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
| **Nit open** | **0** |
| **intentional-open** | **3** |

**Live generation:** **PASS** — generation **#26** (`PHOTO1` / Photosynthesis Quiz), status `completed`, 2 questions; approved `PHOTO1-IH-0001`. User `teacher2`. Cron task `process_pending_generations` needed to finish validating→completed on this docker host.

**Do not invent Full features.** Light-only packaging findings stay Light-only.

### Recommended fix order (this pass)

1. **S2-01 (P0)** — wire `generation_access_policy::require_can_mutate()` on `generate.php` save/back + start (and ideally page-load gate).
2. **S-01 / S2-02 (P1)** — shrink permanent shared-course `editall`/`useall` (revoke when idle, per-generation scope, or plugin-proxied edit/preview).
3. **S2-03 / S-06 (P2)** — fix rate-limiter CAS verification (affected-row / nonce / `FOR UPDATE`).
4. **C2-01 (P2)** — privacy metadata + delete/export for `local_artqtml_ajax_ratelimit`.
5. **Nits** — S2-04, C2-02, C2-03, C2-04 as capacity allows.

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
| S-02 | fixed | **fixed** (partial) | P0→closed | Ownership helper on approve/status/upload/delete/retrytypes/services. Gap: **generate.php** → **S2-01** |
| S-01 | fixed | **fixed** | P0→P1→closed | Still grants permanent `question:editall`+`useall` on shared draft course; `externallyedited` is detective, not least-privilege. See **S2-02** — resolved in `2026082704` |
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

| ID | Severity | Area | Status | Location | Finding | Sources | Suggested next step |
|----|----------|------|--------|----------|---------|---------|---------------------|
| S2-01 | **P0** | Security | **fixed** | `generate.php` ~150–246 (save/back + start); page load ~33–55 | Settings save / start generation lack `generation_access_policy::require_can_mutate()` — only `require_source_editable()` + system `:use`. Any teacher can open another’s STARTED draft by id, rewrite settings, start paid generation, and steal `userid` | Security | Call `require_can_mutate` after load and before save/start; align with upload/approve |
| S2-02 | **P1** | Security | **fixed** (documents S-01 residual) | `draft_role.php` CAPABILITIES; `draft_bank.php`; approve preview/edit URLs | Permanent shared-course `editall`/`useall` + native question URLs bypass plugin ownership | Security | Revoke when idle / narrow scope / proxy edit through plugin auth |
| S2-03 | **P2** | Security | **fixed** (S-06 **regress**) | `ajax_rate_limiter.php` ~97–157 | CAS `UPDATE` then re-read `hitcount === old+1` — concurrent racers can all observe the same post-state and all return allowed | Security | Verify affected rows / unique nonce / transaction lock |
| S2-04 | Nit | Security | **fixed** | `ai_request.php` ~454–462 | Valid JSON without `error.message` still uncapped | Security | Cap all non-200 bodies |
| C2-01 | **P2** | Compliance | **fixed** | `privacy/provider.php` vs `local_artqtml_ajax_ratelimit` | Rate-limit table (`userid`, …) missing from metadata / export / delete | Compliance | Declare + scrub/delete on privacy requests |
| C2-02 | Nit | Compliance | **fixed** | `retrytypes.php`, `upload.php` | Developer personal name in shipped comments | Compliance | Neutral “product decision” wording |
| C2-03 | Nit | Compliance | **fixed** | approve_renderer / generation_list / status / model_checker comments | Capitalization sweep left broken mid-sentence comments | Compliance | Manual comment repair |
| C2-04 | Nit | Compliance | **fixed** | lang EN/HU | Dead keys `backtosettingsshort`, `plugindesc` | Compliance | Delete or wire |

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
