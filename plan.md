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
- [ ] WP gateway UI: render QR from EMV response and start polling until paid/expired
- [ ] Security hardening: HMAC, HTTPS, payload guards, secret rotation, idempotency
- [ ] Observability: logs, dead‑letter, metrics; tester checklist

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

