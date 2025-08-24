Plugin Context and Rationale

This document provides background on the existing Scan & Pay (n8n) payment gateway plugin and the rationale for the upcoming refactor. It is intended to give Codex full context before modifying the code.

## Status (v1.1.1) — Current Architecture
**QR Rendering (Static Placeholder)**: Both Classic and Blocks checkout render a static QR placeholder image. The image source is configured via a WordPress Media Library picker (`qr_image_url` setting) in `includes/class-san8n-gateway.php`. If unset, we fall back to `assets/images/qr-placeholder.svg`.

**No PromptPay Shortcode**: The legacy PromptPay shortcode and dynamic QR generation are removed. There is no dependency on any external PromptPay plugin or its assets.

**Blocks vs Classic**: Classic renders the placeholder inside `payment_fields()`; Blocks receives the placeholder URL via `includes/class-san8n-blocks-integration.php` (exposed as `qr_placeholder`) and renders the same image. Responsive display issues have been fixed.

**Slip Verification**: Frontend JS posts `order_id`, `order_total`, `session_token`, and `slip_image` to `/wp-json/wc-scanandpay/v1/verify-slip`. The REST handler forwards to a verification backend. The backend choice (n8n or Laravel) is pending; n8n is currently supported.

Important: Verification is a checkout-only flow. Orders can be placed only when verification returns approved. There is no post-checkout pending state, scheduler, or admin re-verify in this plugin.

Existing Functionality

The plugin registers a WooCommerce payment gateway that presents a QR placeholder and lets customers upload a payment slip for verification. Key behaviours include:

- Static QR placeholder configured via Media Library (`qr_image_url`) with default fallback SVG.
- Slip upload & verification via REST to `wc-scanandpay/v1` with minimal payload.
- Admin settings for gateway basics and verification backend (webhook URL, secret, limits).

Desired Changes

- Backend selectable in settings (n8n default; Laravel optional) and standardize response schema: `{ status, reference_id?, approved_amount?, reason? }`.
- Optional UX: progress indicators, retry, and async status polling via `/status/{token}`.
- Continue maintenance of responsive layout and Blocks parity.

Roadmap

- Short term: Use n8n IMAP/email alert parsing or Android (Tasker) notification/SMS forwarding to verify incoming funds before relying on slips; document the flow, security (HTTPS/HMAC), and reliability (battery, retries, de-dup).
- Medium term: Add an optional external API adapter (Laravel) selectable in settings; standardize the response contract and maintain both backends.
- Long term: Implement slipless "unique-amount + email/SMS alert + webhook auto-matching" via Laravel with idempotency, manual review queue, and expanded bank parsers.

## Slipless Flow (Planned default)

We are moving to a slipless PromptPay flow as the default experience (no bank/PSP APIs or fees). WordPress will act as a secure proxy to n8n:

- WP REST proxy endpoints to add:
  - `POST /wp-json/san8n/v1/qr/generate` → signs (HMAC) and forwards to n8n to generate EMV PromptPay with unique cents and 10‑min TTL
  - `GET /wp-json/san8n/v1/order/status` → polls n8n session store for `pending|paid|expired`
  - Optional: `POST /wp-json/san8n/v1/order/paid` ← n8n callback to mark Woo orders paid/cancelled
- n8n maintains a `payment_sessions` store with fields: `session_token`, `amount_variant`, `expires_epoch`, `used`
- Matching: Android Tasker forwards bank notifications; n8n’s AI/regex mapper extracts amount/time and matches exact `amount_variant` within TTL; sets `used=true`
- Frontend: checkout renders EMV QR returned by `/qr/generate` and polls `/order/status` until paid/expired; slip upload remains a fallback only

Headers for slipless flow use `X-San8n-*`:
- `X-San8n-Timestamp`, `X-San8n-Signature = HMAC_SHA256(secret, `${timestamp}\n${sha256(rawBody)}`)`
- Tasker can send `X-San8n-Secret` or `X-San8n-Signature` with the same formula

## Android Forwarder (Tasker) Architecture

Android(Tasker) captures bank/Stripe notifications or SMS and forwards them to the backend (n8n/Laravel) via HTTPS. The plugin remains unchanged and still calls `/verify-slip`; the backend uses recent forwarded alerts to decide `approved|rejected` according to the unified contract.

- Headers: `X-Device-Id`, `X-Secret` or `X-Signature` (HMAC of `${timestamp}\n${sha256(body)}`)
- Body (JSON example): `{"source":"android-tasker","app":"%an","title":"%ntitle","text":"%ntext","posted_at":"%DATE %TIME","nid":"%nid"}`
- Backend responsibilities: verify secret/HMAC, parse amount/reference via regex, de-duplicate (nid+timestamp or content hash), cache recent transactions (e.g., 10–15 minutes), and answer the plugin with the unified contract.
- Reliability: disable battery optimizations on the device, allow background activity, implement retries/backoff and offline queue where possible, and minimize/mask PII in logs.

## Out of Scope (by design)
- Admin re-verify actions and metabox UI.
- Order list status column.
- Pending state and scheduled re-checks.

## Verification Backend Adapter Contract
Any backend (n8n/Laravel) must return a uniform JSON object:
```
{
  "status": "approved|rejected",
  "message": "optional human readable",
  "approved_amount": 1499.00,
  "reference_id": "abc123"
}
```
- All requests are HMAC-signed; HTTPS required; SSL verification ON.
- Filters standardized: `san8n_verifier_timeout` (int $timeout, string $backend), `san8n_verifier_retries` (int $retries, string $backend)

Headers (outbound to verifier):
- `X-PromptPay-Timestamp` (unix)
- `X-PromptPay-Signature` = HMAC-SHA256 of `${timestamp}\n${sha256(body)}` with the shared secret
- `X-PromptPay-Version: 1.0`
- `X-Correlation-ID` (trace)

Adapter reference:
- See `includes/class-san8n-verifier.php` (`SAN8N_Verifier_Factory`, `SAN8N_Verifier_N8n`, `SAN8N_Verifier_Laravel`).

## Phased Plan
- Short term (Now): Use n8n IMAP/email alert parsing to confirm incoming funds; document flow/security; finalize checkout verification UX.
- Mid term: Add Laravel adapter as selectable backend in settings; keep the same checkout-only response contract (approved|rejected).
- Long term: Slipless unique-amount + alert + webhook matching; idempotency; manual review queue; richer bank parsers.

## Short-term Tasks (Phases 1–3)

### Phase 1 — Settings QA
- [ ] Toggle backend shows correct fields; “Test Backend” relabels and moves next to active URL field
- [ ] Test AJAX returns clear success/error without exposing URL/secret; client validates HTTPS/required URL

### Phase 2 — Checkout Regression
- [ ] Classic and Blocks render identical static QR; no console errors
- [ ] Upload slip → call `verify-slip` → receives approved/rejected; order updated accordingly
- [ ] Test both backends (n8n default; Laravel optional/mock)

### Phase 3 — Tests
- [ ] Unit: factory selects adapter; response mapping conforms `{ status, message?, approved_amount?, reference_id? }`
- [ ] Integration: REST happy-path and error/timeout/retry; filters `san8n_verifier_timeout`/`san8n_verifier_retries` honored