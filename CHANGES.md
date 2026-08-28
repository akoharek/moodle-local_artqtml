# Changelog — ArtQTML (`local_artqtml`)

Newest release first. Version numbers match `version.php` `$plugin->version`;
the number in parentheses is `$plugin->release`.

## 2026-08-27 — `2026082705` (2026.08.27)

**Pass-2 residual fixes (REVIEW-FINDINGS2 F-08, C2-03, S2-01)**

- **F-08:** FAILED status secondary button links to `index.php` (`backtolist`), not `generate.php`.
- **S2-01:** `generate.php` view/read gated by `generation_access_policy::can_mutate()` (owner or `:manageall`).
- **C2-03:** PHPCS comment repair completed in `status.php`, `generation_list.php`, `approve_renderer.php`.
- **CI:** pgsql rate-limiter CAS via execute+re-read (no UPDATE RETURNING subquery); Behat aligned with S2-02 Preview-only approve rows.

## 2026-08-27 — `2026082704` (2026.08.27)

**Pass-2 security/compliance fixes (REVIEW-FINDINGS2 S2-01…C2-04)**

- **S2-01:** `generate.php` save/back/start paths call `generation_access_policy::require_can_mutate()`.
- **S-01 / S2-02:** Draft role least privilege (`course:view` + `question:useall` only); `revoke_if_idle()` lifecycle; native Edit link removed from approve table; upgrade `2026082704`.
- **S2-03:** AJAX rate limiter uses `get_affected_rows()` instead of re-read equality (CAS/ABA fix).
- **C2-01:** Privacy metadata/export/delete for `local_artqtml_ajax_ratelimit` (EN+HU).
- **S2-04:** Cap valid JSON non-envelope error bodies at 500 chars.
- **C2-02…C2-04:** Comment scrub, PHPCS comment repair, dead lang keys removed.

## 2026-08-27 — `2026082702` (2026.08.27)

**Audit branch: manageall, tests, packaging**

- S-02/S-08: `local/artqtml:manageall` + `generation_access_policy` on all mutate paths.
- Behat: external edit locks row; approve list keeps `questioncode` (TC-CLICK-A-002).
- CI green on Moodle 4.5.12 / 5.1.6 / 5.2.2 matrix.

## 2026-08-26 — `2026082604` (1.0.0)

**F-07: mandatory admin setup after install (no auto-create draft course)**

- Fresh install sets a one-shot redirect: configure-capable users land on **General** admin
  settings (`local_artqtml_general`).
- **Required fields** with save-time validation: draft course ID (must exist), Claude/Gemini
  API keys (on first save), Claude/Gemini model (non-empty).
- Setup checklist banner on **General**, **Generator LLM**, and **Validator LLM** tabs.
- Teachers remain blocked from new generations until setup is complete (existing behaviour).
- Light: remove accidental commercial-only `token_budget` / `license_checker` references from entry scripts.

## 2026-08-26 — `2026082602` (1.0.0)

**Draft course: no native edit + external-edit lock (S-01 redesign)**

- Draft course is a **holding area only**: the plugin draft role grants `course:view` and
  `question:useall` (preview) — **not** `question:editall`.
- Approve page: **Preview** only for unmoved questions; **Edit** link removed.
- External edit via Moodle native `question.php` while still in draft sets
  `externallyedited=1`, shows **Locked** badge, blocks approve/move/checkbox on that row.
- **Generation delete** with locked questions: allowed for owner + `:use` or `:manageall`
  (unchanged policy; only moved-out questions still block whole-generation delete).
- Upgrade `2026082602`: adds `externallyedited` column; removes `editall` from existing draft role.
- PHPUnit: `draft_question_lock_test`; observer test extended; Behat approve actions updated.

## 2026-08-26 — `2026082603` (1.0.0)

**C-01 / C-02: MOODLE_INTERNAL guards + per-file @copyright (Marketplace PHPCS)**

- Added `defined('MOODLE_INTERNAL') || die();` to all shipped PHP files (Behat/phpstan bootstrap
  excluded by design).
- Added `@copyright  2026 AR Tudásmenedzsment Kft.` to every PHP file docblock; removed
  `CopyrightTagMissing` exclude from `phpcs.xml`.
- Mass fix: `@license` URL `Www.gnu.org` → `www.gnu.org` (C-09).

## 2026-08-26 — `2026082601` (1.0.0)

**S-02 / S-08: owner-scoped mutation + `local/artqtml:manageall`**

- New capability **`local/artqtml:manageall`**: mutate any user's generation (default: manager
  archetype).
- **`local/artqtml:use`**: still opens/views any generation for collaboration; **mutation** is
  limited to own generations unless the user has `:manageall`.
- Central helper `generation_access_policy::can_mutate()` / `require_can_mutate()` on approve,
  source edit, abort/retry, delete, and `retrytypes.php`.
- UI hides mutate controls when the user lacks permission.
- PHPUnit: `generation_access_policy_test`; updated delete/source tests.

## 2026-08-24 — `2026081306` (1.0.0)

**Behat regression suite (internal, no live LLM)**

- Cherry-picked acceptance features from `test/behat-acceptance`: access, admin settings,
  approve/review, delete, new generation, status.
- Added `approve_page_actions.feature`: unmoved row (Edit + Preview, no Open) and moved row
  (Open, no Edit/Preview) using DB fixtures only.
- Test data generator extended for moved questions (`movecourse` + `movedout`).
- CI: `behat` job on Moodle 4.5.12 and 5.2.2 with Selenium (tags `@local_artqtml`).

## 2026-08-13 — `2026081305` (1.0.0)

**Approve page: after move, Open goes to the destination question bank (no Preview)**

- A moved (`movedout`) question row **Open** link opens the **destination question bank**
  listing (`/question/edit.php?courseid=…&cat=category,context` on Moodle 4.5; `cmid` for
  `mod_qbank` on 5.1+), not the question editor (`question.php`).
- Moved rows have **no Preview** and **no Edit**.
- Before move, Edit + Preview behaviour is unchanged (plugin-aware editor).
- Other post-move locks are unchanged: no checkbox, delete, approve/unapprove, or second move.

## 2026-08-13 — `2026081304` (1.0.0)

**Approve page: Open after move, not Edit**

- A moved (`movedout`) question row shows **Open** instead of **Edit**.
  The link is Moodle's native question editor (`/question/bank/editquestion/question.php`);
  the plugin validation panel and other plugin extras are not shown.
- Before move, Edit behaviour is unchanged (plugin-aware editor, AI validation).
- Other post-move locks are unchanged: no checkbox, delete, approve/unapprove, or second move.
  Preview remains.

## 2026-08-13 — `2026081303` (1.0.0)

**Admin API-key Save: empty password field no longer wipes the stored key**

- Saving plugin settings with a blank Claude/Gemini key field (password inputs POST empty when
  the administrator does not retype the value, and after decrypt failure the UI already shows
  empty) left the stored ciphertext unchanged. Previously `write_setting('')` wrote an empty
  config row and deleted a still-valid or leftover key.
- Entering a new key still encrypts and replaces the stored value. There is no separate
  'clear key' control.

## 2026-08-13 — `2026081302` (1.0.0)

**Admin API-key notice + status Retry/Back layout**

- Missing or unreadable Claude/Gemini keys now show a persistent red banner to
  `local/artqtml:configure` / site admins on plugin pages, not only as a debugging() line or a
  one-shot session flash on settings.
- Generation start and status Retry refuse immediately when a key is empty or cannot be decrypted,
  so the teacher is not left waiting on cron.
- Status failed-actions: Retry and Back sit in `.artqtml-buttonrow` (flex + gap) instead of
  overlapping.

## 2026-08-13 — `2026081301` (1.0.0)

**Upgrade: migrate leftover plaintext API keys; stop dropping unreadable ciphertext silently**

- Stored Claude/Gemini keys with no Moodle encryption prefix (`sodium:` / `openssl-aes-256-ctr:`)
  are re-encrypted in place on upgrade and on read, so introducing encryption-at-rest does not
  empty the admin field.
- Ciphertext that fails integrity (site sodium key changed) cannot be recovered by anyone,
  including Moodle. The UI stays empty, `debugging()` runs once per setting (not on every
  `/admin/index.php` load), and a persistent admin notice asks for the keys to be re-entered
  from the Anthropic / Google dashboards.

## 2026-08-13 — `2026081300` (1.0.0)

**Model-check schema: add `pluginversion` on upgrade**

- `local_artqtml_modelcheck.pluginversion` is in `install.xml`, but upgrades from older installs
  (and an aiquizgen table rename) could leave the column missing. Visiting plugin settings then
  failed with `Unknown column 'pluginversion'`.
- Upgrade 2026081300 adds the column when missing. Install after rename does the same. Until the
  column exists, the settings page skips the version filter instead of querying it.

## 2026-08-12 — `2026081207` (1.0.0)

**Initial Marketplace release**

ArtQTML generates Moodle quiz questions from teacher-provided source text.

- **Input:** paste or plain `.txt` upload
- **Question types:** Immediate Feedback (IH), Free Response (FE), Sequencing / Ordering (SR)
- **Difficulty:** easy / medium / hard
- **AI providers:** Claude and Gemini (bring your own API keys)
- **Workflow:** generate → review / approve → move a single question into the question bank
- **Admin:** API keys, models, draft course, and basic type feedback settings
- **Compatibility:** Moodle 4.5 / 5.1 / 5.2 (`$plugin->supported = [405, 501, 502]`), maturity STABLE
- **Dependency:** `qtype_ordering` (required for SR)
- **License:** GPL v3 or later
