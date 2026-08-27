# ArtQTML (local_artqtml)

Moodle local plugin that generates quiz questions with **Claude**, validates them with **Gemini**, then lets teachers review and approve into the question bank.

Scope (GPLv3+):

- Source: paste/type or **`.txt`** upload
- Types: true/false (IH), single-choice (FE), ordering (SR — requires `qtype_ordering`)
- Difficulty: easy / medium / hard
- Review: edit, approve, delete, **single-question** move to the bank
- Admin: API keys (BYOK — Claude + Gemini), models, connection test, draft course, thin type defaults

## Requirements

- Moodle 4.5.0+
- `qtype_ordering`
- Anthropic (Claude) and Google (Gemini) API keys configured by the site admin (bring your own keys)

## Install

Install the ZIP so the folder is `moodle/local/artqtml/`. Then configure keys and a draft course under Site administration → Plugins → Local plugins → ArtQTML.

## Configure API keys (BYOK)

1. Site administration → Plugins → Local plugins → **ArtQTML**.
2. **Generator** tab: paste your **Anthropic (Claude)** API key, run **Connection test**, then pick a model.
3. **Validator** tab: paste your **Google AI (Gemini)** API key, run **Connection test**, then pick a model.
4. **General** tab: set a dedicated **draft course ID** (hidden course, no students).

Do not commit API keys. Reviewers and sites use their own Anthropic + Google AI keys in these settings.

## Reviewer smoke path

Minimal path a Moodle.org reviewer (or site admin) can use after install:

1. Configure Claude + Gemini keys and a draft course (above).
2. Open the ArtQTML entry point → **New generation**.
3. Paste a short paragraph of source text (or upload a `.txt` file) → Continue.
4. Request a few **IH / FE / SR** questions (easy/medium/hard counts) → start generation.
5. Wait for Claude generation + Gemini validation to finish on the status page.
6. Open **Approve**, review a question, **Approve**, then move it into a question-bank category.

## License

GPLv3 or later. See `COPYING.txt` and `COPYRIGHT.txt`.

## Support / bug tracker

Public issue tracker (bugs, feature requests, other code issues):

**https://github.com/akoharek/moodle-local_artqtml/issues**

Please use the issue templates (Bug report, Feature request, or Other / code issue). Do not post API keys or other secrets.

> Magyarul: hibákat, funkciókéréseket és egyéb kódproblémákat a fenti nyilvános GitHub Issues oldalon várjuk (sablonok angolul).

See [CONTRIBUTING.md](CONTRIBUTING.md) for how to report issues and propose changes.
