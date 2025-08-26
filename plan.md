# plan.md — Sprint Plan (2025-08-25)

## Goal
Slipless PromptPay flow (no bank/PSP APIs, no fees):
- Live EMV QR per order via n8n with unique cents (0–99 satang) and 10‑min TTL
- Android Tasker notification ingest → AI mapper → exact match to session
- WordPress REST proxy signs requests (HMAC) and polls status; slip upload kept only as fallback

## Tasks
- [x] Update docs to slipless architecture and task breakdown
- [ ] WP REST proxy: `POST /wp-json/san8n/v1/qr/generate` (server‑side HMAC) → forward to n8n
- [ ] WP REST proxy: `GET /wp-json/san8n/v1/order/status` → poll n8n session store
- [ ] WP REST callback: `POST /wp-json/san8n/v1/order/paid` from n8n to mark paid/cancelled
- [ ] n8n: persist `payment_sessions` (session_token, amount_variant, TTL, used)
- [ ] n8n: Tasker Ingest match engine (exact amount to unique cents within TTL; anti‑reuse)
- [x] n8n: Tasker ingest idempotency + early ACK
- [ ] WP gateway UI: render QR from EMV response and start polling until paid/expired
- [ ] Security hardening: HMAC, HTTPS, payload guards, secret rotation, idempotency
- [ ] Observability: logs, dead‑letter, metrics; tester checklist

## Process

1) Working Rhythm
- Re-read core docs before any change: `plan.md`, `readme.txt`, `instructions.md`, `context.md`, `evaluation.md`, `feedback.md`, `ai respone.txt`, `AGENTS.md`.
- Open a branch: `feature/<slug>` or `fix/<slug>`; small, scoped PRs only.
- Use the PR template; include a concise change document (what/why/files/tests/next steps).

2) Standards & Security
- PHPCS clean; WP coding standards, escaping, and i18n for new strings.
- Enforce HTTPS, HMAC; no secrets in repo. Trim secrets; add payload size guards.
- For slipless flows, use `X-San8n-*` headers and keep n8n session fields: `session_token`, `amount_variant`, `expires_epoch`, `used`.

3) Testing Matrix (per `evaluation.md`)
- Admin settings: Media Picker, backend toggle, Test Backend UX.
- Checkout: Classic + Blocks parity, static QR placeholder, slip upload path OK.
- REST: `/wc-scanandpay/v1/verify-slip` happy/error paths; timeouts/retries filters.
- Slipless (when touched): `/san8n/v1/qr/generate`, `/san8n/v1/order/status`, optional `/san8n/v1/order/paid`.
- Backend: Tasker ingest dedup + exact-match; Postgres `payment_sessions` where applicable.

4) Versioning & Docs
- If code changes ship: bump `SAN8N_VERSION` and append `readme.txt` changelog (dated).
- Always update docs affected by the change: `plan.md`, `AGENTS.md`, `instructions.md`, `context.md`, `evaluation.md`, `feedback.md`.
- Attach the change document in the PR. Keep `json/` in sync with exported n8n workflows.

5) Release
- Merge via squash; tag release if version bumped. Close tasks in `plan.md` and track open items.

## Risks/Mitigations
- n8n/WP downtime → timeouts, retries, clear status to user, callback fallback
- Tasker reliability → device setup guide, retry/queue, dedup
- Signature mismatch → trim/normalize secret, fixed formula, header variants
- Memory pressure on HMAC → payload size guard, lightweight pure‑JS SHA/HMAC

## Acceptance Criteria
- WP can call `san8n/v1/qr/generate` and receive `{ emv, amount_to_pay, currency, expires_epoch, session_token }`
- Checkout shows EMV QR and polls `san8n/v1/order/status` → transitions to `paid|expired`
- Tasker Ingest matches a real notification to an open session (exact amount_variant) within TTL, marks `used=true`
- n8n posts callback to `san8n/v1/order/paid`; Woo order status updates to processing/completed
- HMAC verified end‑to‑end; secrets trimmed/rotated; payload guard enforced
- Docs reflect slipless default; slip upload documented as fallback only

## Verification Contracts

### 1) QR Generate (WP → n8n via proxy)
- Body (JSON): `{ order_id, amount, currency: 'THB', session_token }`
- Headers (either casing): `X-San8n-Timestamp`, `X-San8n-Signature` where
  - `signature = HMAC_SHA256(secret, `${timestamp}\n${sha256(rawBody)}`)`
- Response: `{ emv, amount_to_pay, amount_variant?, currency, expires_epoch, session_token }`

### 2) Order Status (WP → n8n via proxy)
- Query: `order_id, session_token`
- Response: `{ status: pending|paid|expired, paid_at_epoch?, reference_id? }`

### 3) Tasker Ingest (Device → n8n)
- Body (JSON): `{ source, app, title, text, posted_at, nid }`
- Headers: `X-Device-Id`, `X-San8n-Secret` or `X-San8n-Signature` (same HMAC scheme)
- Match: exact `amount_variant` within TTL; anti‑reuse by `nid+posted_at` or content hash

## Next
- Short: implement WP proxy + n8n endpoints + Tasker match; ship slipless MVP
- Mid: add IMAP email path; observability & rotation; polish UX (progress)
- Long: optional Laravel backend; manual review queue; expanded bank parsers

## Open question (Copy/Branding)
- Keep “Scan & Pay (n8n)” or generalize to backend‑agnostic wording?

## Step-by-step Execution Plan
Sprint 1 — Slipless MVP
- [ ] WP proxy: `/qr/generate`, `/order/status`, `/order/paid` + HMAC
- [ ] n8n: session store + QR build + status + callback
- [ ] Woo checkout: show EMV QR + poll + finalize on paid

Sprint 2 — Tasker Reliability & Observability
- [ ] Tasker match engine hardening; dedup; retries; device guide
- [ ] Logs/alerts/metrics; dead‑letter for unmatched notifications
- [ ] Secret rotation and dual‑secret window

Sprint 3 — Fallbacks & Options
- [ ] IMAP email ingest path
- [ ] Maintain slip upload as optional fallback only
- [ ] Optional Laravel backend adapter

## Open Tasks
- [ ] Implement WP proxy endpoints and settings wiring
- [ ] Implement n8n session table and nodes; exact match logic
- [ ] Connect checkout UI to new proxy endpoints and polling
- [ ] Add callback handler to mark orders paid and stop polling
- [ ] Update docs and tester checklist; field test with real Tasker notifications

## Deliverables per Sprint
- Sprint 1: Slipless MVP working E2E (QR shown, auto-match, order paid), docs updated
- Sprint 2: Reliability + observability + rotation, device guide complete
- Sprint 3: Fallbacks (IMAP/slip), optional Laravel adapter, docs

