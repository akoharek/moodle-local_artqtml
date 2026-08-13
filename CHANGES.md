# Changelog — ArtQTML (`local_artqtml`)

Newest release first. Version numbers match `version.php` `$plugin->version`;
the number in parentheses is `$plugin->release`.

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
