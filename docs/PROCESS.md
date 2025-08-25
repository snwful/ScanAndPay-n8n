# PROCESS.md — Working Agreement and Docs Workflow

Purpose: Ensure continuity and portability by always re-reading core docs before any work and by recording every change in repo.

## Always Read Before Action
Re-read these files at the start of each session and before any code edit:
- `plan.md`
- `readme.txt`
- `instructions.md`
- `context.md`
- `evaluation.md`
- `feedback.md`
- `ai respone.txt`
- `AGENTS.md`

## Change Documentation After Every Edit
For every code/content change:
1) Create a session log under `docs/sessions/` using `templates/SESSION_TEMPLATE.md`.
2) Append a short entry to `docs/CHANGELOG.md`.
3) If a release-worthy change: update `readme.txt` (Changelog) and bump `SAN8N_VERSION` in `scanandpay-n8n.php`.
4) Update checklists or plans when relevant (`plan.md`, `evaluation.md`).

## Session Log Naming
- File: `docs/sessions/session-YYYY-MM-DDThhmm+TZ.md` (avoid `:` for Windows). Example: `session-2025-08-25T0951+07.md`.

## Minimal Session Log Content
- Summary, Context/Goal, Files touched, Why, Tests/Validation, Next steps, Related docs updated.
- Optional: Correlation-ID for tracing.

## Architecture Decisions (ADR)
- When making a notable decision, add an ADR using `templates/ADR_TEMPLATE.md` under `docs/adr/ADR-XXX-title.md`.

## Roles & Tools
- Developer changelog: `docs/CHANGELOG.md` (engineering history).
- Release notes: `readme.txt` Changelog section.
- Templates: `docs/templates/`.

## Security & Compliance in Docs
- Do not include secrets in logs/docs. Mask PII and tokens. Keep URLs without secrets.

## Workflow Checklist (each change)
- [ ] Re-read core docs
- [ ] Implement change
- [ ] Create session log in `docs/sessions/`
- [ ] Update `docs/CHANGELOG.md`
- [ ] If releasing: bump version + `readme.txt`
- [ ] Update `plan.md`/`evaluation.md` if scope/criteria changed
