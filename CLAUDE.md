> **ArtQTML Light (`local_artqtml`)** — Marketplace/GPL stripped edition.
> Feature set: IH+FE+SR, paste+TXT, thin admin. No license/.lic, PDF/DOCX, Bloom, FT/EH/RV, bulk move, institutional prompts, token admin.
> Docs: iCloud `moodle_dev_projektek/artqtmlight-munkadokumentumok/`.
> Sibling Full product: `local_artqtm` / `moodle-local_artqtm`.

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

`local_artqtml` is a Moodle **local plugin** (frankenstyle component: `local_artqtml`) that generates quiz questions using AI:

- **Claude API (Structured Outputs)** — generates question content in a defined schema.
- **Gemini API** — validates/reviews generated questions before they're accepted.

Supported question types (ArtQTML Light):
- True/False — IH (`qtype_truefalse`)
- Multiple choice — FE (`qtype_multichoice`)
- Ordering — SR (`qtype_ordering` — contrib dependency)

## Reference documents

Product documents live outside this repository (iCloud
`moodle_dev_projektek/artqtmlight-munkadokumentumok/` and shared Full-era archives under
`artqtml-munkadokumentumok/`). Nothing in the plugin, the package, or CI reads them.

Plugin-agnostic Moodle checklists live in
`~/Library/Mobile Documents/com~apple~CloudDocs/moodle_dev_projektek/general/`.

## AI integration architecture

Two distinct external API roles — keep them separate in code:

1. **Generation (Claude API)**: takes teacher input (source text, difficulty scale, type counts)
   and produces structured question data via Structured Outputs for IH/FE/SR.
2. **Validation (Gemini API)**: reviews Claude's output for correctness/quality before the
   teacher sees or saves a Moodle question.

Both API keys are secrets and must be stored via Moodle's admin settings (encrypted password
fields in `settings.php`), never hardcoded or committed. Follow the `moodle-security-audit`
skill for credentials and outbound HTTP (SSRF, timeouts, failure handling).

Validate AI output against Moodle's expected question data shape before insert, not only against
the AI schema.

## Relevant skills

This project has Moodle-specific skills preloaded (`.agents/skills/`) — invoke them proactively:
- `moodle-plugin-development` — plugin scaffolding, `version.php`, capabilities, upgrade steps.
- `moodle-web-services` — AJAX/external functions for the generation UI.
- `moodle-security-audit` — API keys, capabilities, input validation on AI content.
- `moodle-phpunit-testing` — local Docker PHPUnit suite.
- `moodle-amd-javascript` — generation-flow UI (AMD, `core/ajax`, templates).
- `moodle-privacy-gdpr` — privacy provider for generation/AI data.

## Deployment

### Marketplace ZIP (preferred)

```bash
./tools/package_marketplace_zip.sh
# → dist/local_artqtml-<version>.zip and dist/artqtml.zip
```

The script builds an `artqtml/` top-level tree with English lang only and excludes
`.git`, tests, tools, CLAUDE.md, BACKLOG.md, and other non-runtime files. `CHANGES.md`
**is** included (installer-facing changelog). Light has no `.lic` / license-integrity
manifest — do not regenerate or ship license fixtures.

Copy the versioned ZIP to iCloud `riportok/` when cutting a Marketplace build.

### Hosted / manual install

- Install or update via Moodle admin → Plugins → Install plugins (upload ZIP).
- Hosted shared hosting may be browser-only (no SSH); use local Docker for PHPUnit/CLI.

## Local Docker Deployment

- Plugin path: `~/projektek/moodle/local/artqtml` (source mount; no zip step for local).
- From `~/projektek/moodle-docker`:
  - Upgrade: `docker compose -f base.yml -f db.mariadb.yml -f webserver.port.yml exec webserver php admin/cli/upgrade.php --non-interactive`
  - Purge caches: `... purge_caches.php`
  - Run pending generation: `... scheduled_task.php --execute='\local_artqtml\task\process_pending_generations'`
- Local Moodle URL: http://localhost:8080 — admin / `Admin1234!`

## Architecture

- AI API calls run in the background only. `generate.php` sets status to `generating`;
  `process_pending_generations` drives `generate_questions_task` → `validate_questions_task` →
  `save_questions_task`. Pending Claude/Gemini JSON lives in
  `local_artqtml_generations.pendingdata` until save commits real questions.
- AJAX status polling via `classes/external/`.
- DB access via `$DB` only; forms use `require_sesskey()` + `require_capability()`.
- User-facing strings via `get_string()` from `lang/*/local_artqtml.php`.

## Forbidden patterns

- No hardcoded UI strings
- No SQL bypassing `$DB`
- No parallel subagent sessions unless requested
- Never use `/feedback` (sends session data to Anthropic)
- No `require()` / `include()` outside Moodle bootstrap

## Technical debt

See `BACKLOG.md` for historical notes (many predate the Light fork and refer to Full-only
paths). Do not start large refactors unless explicitly asked.
