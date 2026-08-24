# Changelog — ArtQTML (`local_artqtml`)

Newest release first. Version numbers match `version.php` `$plugin->version`;
the number in parentheses is `$plugin->release`.

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
- **Compatibility:** Moodle 4.5–5.2 (`$plugin->supported = [405, 502]`), maturity STABLE
- **Dependency:** `qtype_ordering` (required for SR)
- **License:** GPL v3 or later
