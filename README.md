# ArtQTML (local_artqtml) — Light

Moodle local plugin that generates quiz questions with **Claude**, validates them with **Gemini**, then lets teachers review and approve into the question bank.

This is the **Light** Marketplace edition (GPLv3+):

- Source: paste/type or **`.txt`** upload
- Types: true/false (IH), single-choice (FE), ordering (SR — requires `qtype_ordering`)
- Difficulty: easy / medium / hard
- Review: edit, approve, delete, **single-question** move to the bank
- Admin: API keys (BYOK), models, connection test, draft course, thin type defaults

Not included (separate Full product): PDF/DOCX, FT/EH/RV types, Bloom, free-text instruction, own-knowledge mode, bulk move, institutional prompt editing, token admin UI, `.lic` entitlement.

## Requirements

- Moodle 4.5.1+
- `qtype_ordering`
- Anthropic (Claude) and Google (Gemini) API keys configured by the site admin

## Install

Install the ZIP so the folder is `moodle/local/artqtml/`. Then configure keys and a draft course under Site administration → Plugins → Local plugins → ArtQTML.

## License

GPLv3 or later. See `COPYING.txt` and `COPYRIGHT.txt`.

## Tracker

https://github.com/akoharek/moodle-local_artqtml/issues
