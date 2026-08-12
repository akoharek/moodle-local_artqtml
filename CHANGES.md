# Changelog — ArtQTML (`local_artqtml`)

Newest release first. Version numbers match `version.php` `$plugin->version`;
the number in parentheses is `$plugin->release`.

Scope: IH / FE / SR; paste + plain `.txt`; Claude + Gemini BYOK; review / approve;
easy / medium / hard; thin admin.

## 2026-08-12 — `2026081206` (1.0.0)

**Comment scrub for Moodle.org review**

- Remove spec IDs (Admin-/Val-/TC-/Gen-/Glob-/…), decision/history essays, and
  dated “why we chose X” commentary from PHP/JS/XML (and matching AMD builds).
- Keep short comments that explain non-obvious algorithms or Moodle API quirks.
- `phpcs.xml` stays a slim Moodle ruleset; `SUPPORT.md` remains deleted (tracker +
  BYOK live in README).

## 2026-08-12 — `2026081205` (1.0.0)

**Neutralize product-variant wording in comments and strings**

- PHP/JS/XML comments, tests, and `phpcs.xml` describe current behavior only —
  no edition framing or historical strip notes.
- Drop unused monthly token-budget language strings; clarify source-text limit copy.
- README title is `ArtQTML (local_artqtml)` without an edition suffix.

## 2026-08-12 — `2026081204` (1.0.0)

**HQ review docs: positive scope only**

- README describes what ships (paste/TXT, IH/FE/SR, BYOK, tracker + reviewer smoke)
  with no absence lists or product-variant wording.
- Delete redundant `SUPPORT.md` (tracker/BYOK live in README; Teams/SMTP setup is
  maintainer-only and not required for Marketplace).
- Delete in-repo `BACKLOG.md` and `CLAUDE.md` (dev clutter).
- `COPYRIGHT.txt` title simplified to `ArtQTML (local_artqtml)`.
- Neutralize remaining product-variant hints in comments, lang strings, and tests.

## 2026-08-12 — `2026081203` (1.0.0)

**Marketplace copy: claim only shipping features**

- README and listing docs describe scope only (paste/TXT, IH/FE/SR, BYOK,
  review/approve, difficulty scale).
- `COPYRIGHT.txt` title line simplified to `ArtQTML (local_artqtml)`.
- Remove in-repo `BACKLOG.md` (local-only archive outside the tree;
  Marketplace ZIP already excluded it).

## 2026-08-12 — `2026081202` (1.0.0)

**Marketplace docs/comments: scope-aligned wording**

- `CHANGES.md` rewritten as an honest changelog for this plugin.
- Code comments in shipping PHP/XML updated so type codes, upload path, and admin
  capabilities describe IH/FE/SR and TXT-only.

## 2026-08-12 — `2026081201` (1.0.0)

**Marketplace copy: TXT-only and lang pack cleanup**

- User-facing EN/HU strings and reviewer docs aligned with paste + `.txt` and
  IH/FE/SR only.
- Dead unused language keys removed from the lang packs shipped in the repo.
- README clarifies BYOK smoke path for Moodle.org review.

## 2026-08-12 — `2026081200` (1.0.0)

**Marketplace-ready: STABLE maturity and Moodle 4.5–5.2 supported**

- `$plugin->maturity = MATURITY_STABLE`.
- `$plugin->supported = [405, 502]` after smoke PASS on those majors.

## 2026-08-11 — `2026081101` (1.0.0)

**Marketplace ZIP packaging**

- `tools/package_marketplace_zip.sh` builds an `artqtml/` top-level ZIP with
  English lang only (dev HU pack stays in the repo).
- README, COPYING, and PHPUnit coverage for the IH/FE/SR matrix.

## 2026-08-11 — `2026081100` (1.0.0)

**Marketplace feature set**

- Feature set: IH / FE / SR; source input is paste + plain `.txt`.
- Single-question move and thin admin settings (BYOK, models, draft course).
- GPL `COPYRIGHT.txt` for Marketplace distribution.

## 2026-08-10 — `2026081011` (1.0.0)

**Security-policy follow-ups (RISK_*, date filter, POST abort/retry, API key harden)**

- `db/access.php`: `local/artqtml:use` and `:configure` declare Moodle
  `riskbitmask` values
  (`RISK_SPAM|RISK_XSS|RISK_PERSONAL` /
  `RISK_CONFIG|RISK_XSS|RISK_DATALOSS|RISK_PERSONAL`).
- Date filter on the generation list: HTML5 `Y-m-d` via `PARAM_TEXT` + strict
  `parse_filter_date()` (no loose `strtotime`); invalid values cleared from the
  form display.
- Abort / retry (status) and abort-delete (generate) are POST + sesskey only —
  no state change via GET+sesskey URLs; retry-missing-types button likewise.
- API key decrypt failure no longer falls back to treating ciphertext as
  plaintext; runtime and admin UI treat the key as unset until re-saved
  (debugging notice for admins).

## 2026-08-10 — toolchain

**Semgrep CI + PHPStan disallowed-calls**

- Semgrep workflow (`p/php`) for private-repo SAST.
- PHPStan uses the `tools/devtools` Composer binary and loads
  `spaze/phpstan-disallowed-calls` dangerous / execution / insecure rules.
- Intentional `sha1()` in the duplicate detector documented; `mt_rand()` →
  `random_int()`.

## 2026-08-10 — `2026081010` (1.0.0)

**Per-page custom CSS editor removed**

- The custom CSS editor (`css_editor.php`, `custom_css`) is gone.
- Appearance follows the Moodle theme; upgrade deletes `css_*` config rows.
- Plugin `styles.css` remains.

## 2026-08-10 — `2026081007` (1.0.0)

**`security_filter` re-run before generate / validate (defense-in-depth)**

- Before the Claude call (`generate_questions_task`) and before the Gemini call
  (`validate_questions_task`, source re-read from DB), `security_filter` runs again.
- On a hit: no AI call; generation rolls back to `started` (draft / pending cleanup
  like Abort); the teacher can reopen upload.
- User-facing EN+HU message without filter internals; admin log:
  `security_filter_blocked`.
- `generate.php` also checks before Start and redirects to upload.
- PHPUnit: poisoned source → no Claude/Gemini, rollback to `started`.

## 2026-08-10 — `2026081006` (1.0.0)

**Optional PHP debug file log**

- When enabled, selected diagnostics can write under
  `$CFG->dataroot/local_artqtml/debug.log` (directory created as needed).
- API / diagnostic traffic remains in `local_artqtml_log`.
- PHPUnit covers path resolution and ignoring legacy config.

## 2026-08-10 — `2026081005` (1.0.0)

**Capability split + delete only own + `:use`**

- `local/artqtml:use` → teacher use (list, generate, approve, status, own delete).
  Does not grant admin settings.
- `local/artqtml:configure` → admin settings only (settings, model action,
  test_connection). Does not grant generation UI or delete.
- Both (e.g. manager) → both areas; neither replaces the other.
- Generation delete: `:use` + owner only.
- Settings tree registers for `:configure` holders (not only `hassiteconfig`).
- PHPUnit: capability separation + delete policy.

## 2026-08-10 — `2026081004` (1.0.0)

**No “according to the text” / “szöveg szerint” in question stems**

- Generator prompt always forbids source-meta wording in the question or options.
- Cleaner strips a leading clause when the model still emits one; otherwise the
  semantic validator rejects.
- Validator wording instruction flags the same issue as `needs_review`.

## 2026-08-10 — `2026081003` (1.0.0)

**Shortname help matches real validation**

- Help / tooltip and format error describe the real rule: up to 8 ASCII letters or
  digits, uppercased on save — not Moodle course-shortname rules.

## 2026-08-10 — `2026081002` (1.0.0)

**Partial generation: why questions are missing (on the panel)**

- The partial status panel shows short user-facing reasons from existing
  `local_artqtml_log` events (`type_generation_failed`, `question_rejected`,
  Claude undershoot) — no extra API call.
- Raw technical rejection text stays in the log only.

## 2026-08-10 — `2026081001` (1.0.0)

**Approve page: back to generations list**

- `approve.php` header includes **Back to list** → `/local/artqtml/index.php`
  (plain GET, no sesskey).

## 2026-08-07 — `2026080703` (1.0.0)

**Frankenstyle rename: `local_aiquizgen` → `local_artqtml`**

- Plugin directory, component, DB tables (`local_artqtml_*`), capabilities, web
  services, scheduled tasks, AMD modules, and lang files use **artqtml** /
  **ArtQTML**.
- Existing installs: `install` / `upgrade` (and `cli/migrate_from_aiquizgen.php`)
  rename tables and Moodle registry rows.

## Earlier history

Shared bugfixes and security work that still apply (source-text limits,
AJAX rate limits, generation locking, partial status, draft bank, privacy
provider, etc.) are reflected in the timeline above.
