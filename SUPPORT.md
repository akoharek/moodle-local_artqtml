# Support & maintainer alerts (ArtQTML Light)

Public tracker: https://github.com/akoharek/moodle-local_artqtml/issues

When someone opens a new issue, GitHub Actions can notify maintainers via **Microsoft Teams** (preferred) and optionally **email**. Alerts run only on this public Light repo. Missing secrets skip with a warning and do not fail other CI.

Workflow: [`.github/workflows/notify-new-issue.yml`](.github/workflows/notify-new-issue.yml)

## Microsoft Teams (Incoming Webhook)

1. Open the Teams **channel** where alerts should appear.
2. Click **⋯** (More options) on the channel → **Connectors** / **Manage channel** → **Connectors**,  
   **or** (newer tenants) **Workflows** → search **Incoming Webhook** / “Post to a channel when a webhook request is received”.
3. Configure **Incoming Webhook**: name it e.g. `ArtQTML issues`, optional icon → **Create**.
4. **Copy the webhook URL** (treat it like a password).
5. In GitHub: repo **Settings → Secrets and variables → Actions → New repository secret**.
6. Name: `TEAMS_WEBHOOK_URL` (alternate accepted name: `MS_TEAMS_WEBHOOK_URL`).
7. Value: paste the webhook URL → **Add secret**.

After that, opening a test issue should post a MessageCard (title, URL, author, labels, body excerpt).

## Email (optional)

Recipient is fixed in the workflow: `koharek.andras@artudasmenedzsment.hu`.

Email needs your organisation SMTP (likely Microsoft 365 for `artudasmenedzsment.hu`). Until these secrets exist, **Teams-only** still works; the email job logs a warning and skips.

Add repository secrets:

| Secret | Example / notes |
|--------|-----------------|
| `SMTP_HOST` | `smtp.office365.com` (M365) |
| `SMTP_USER` | mailbox UPN used to authenticate |
| `SMTP_PASSWORD` | app password or mailbox password (do not commit) |
| `SMTP_FROM` | From address, e.g. `koharek.andras@artudasmenedzsment.hu` |
| `SMTP_PORT` | Optional; default **587** (STARTTLS) |

Do not commit real webhook URLs or SMTP passwords.

## Soft-fail behaviour

- No Teams secret → Teams job warns and skips (`continue-on-error`).
- Incomplete SMTP secrets → email job warns and skips.
- Other workflows (phpunit, static analysis) are unaffected.
