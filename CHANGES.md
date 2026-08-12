# Changelog — ArtQTML (`local_artqtml`)

Newest release first. Version numbers match `version.php` `$plugin->version`;
the number in parentheses is `$plugin->release`.

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
