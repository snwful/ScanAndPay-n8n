# AGENTS.md — Operational Guide

## Purpose
Rules and map for agents (Codex/Windsurf/Cursor) to work safely on this WooCommerce plugin.

## Key Files
- scanandpay-n8n.php (bootstrap, constants like `SAN8N_REST_NAMESPACE`)
- includes/class-san8n-gateway.php (settings incl. `qr_image_url`, classic checkout UI)
- includes/class-san8n-rest-api.php (POST /verify-slip)
- includes/class-san8n-verifier.php (adapter factory + implementations: n8n/Laravel)
- includes/class-san8n-blocks-integration.php (Blocks checkout integration; passes `qr_placeholder`)
- assets/js/checkout-inline.js (classic checkout logic)
- assets/js/blocks-integration.js (Blocks UI logic)
- assets/js/admin.js and assets/js/settings.js (admin UX incl. Media Library picker)
- assets/images/qr-placeholder.svg (default QR placeholder)
- readme.txt (Changelog)
- context.md / instructions.md / evaluation.md / plan.md

## Additional Key Files
- includes/class-san8n-admin.php (admin metabox, columns, and AJAX re-verify UI)
- includes/class-san8n-helper.php (shared helpers)

## Current Mission
Use a static QR placeholder image configured via WordPress Media Library (`qr_image_url` setting). If empty, fall back to `assets/images/qr-placeholder.svg`.
Ensure both Classic and Blocks checkout display the placeholder image consistently and responsively.
Slip verification is handled via REST `POST /wp-json/wc-scanandpay/v1/verify-slip`, which forwards to an external service. Backend is selectable in settings (n8n default, Laravel optional) using the same contract.
Maintain security: nonces, file type/size validation, EXIF stripping, capability checks.

## API (internal → verification backend)
POST /wp-json/wc-scanandpay/v1/verify-slip (multipart)
- slip_image (file), session_token, order_id, order_total
Backend (n8n or Laravel TBD) → { status: approved|rejected, reference_id?, approved_amount?, reason? }

Headers (outbound to verifier):
- X-PromptPay-Timestamp (unix)
- X-PromptPay-Signature = HMAC-SHA256 of `${timestamp}\n${sha256(body)}` with shared secret
- X-PromptPay-Version: 1.0
- X-Correlation-ID (trace)

## Policies
- Bump plugin header + SAN8N_VERSION every change
- Append readme.txt changelog with date + bullets
- Update plan.md in each PR/iteration
- PHPCS clean; WP/WC compatibility intact
- Standardize filters: `san8n_verifier_timeout`, `san8n_verifier_retries`

## Do / Don’t
- Do use the `qr_image_url` setting and Media Library picker for the QR placeholder.
- Do localize needed data via `wp_localize_script` (e.g., numeric `order_total`).
- Do enforce HTTPS and a shared secret/HMAC when forwarding from Tasker→n8n/Laravel.
- Do disable battery optimizations and allow background activity for Tasker on the Android device used.
- Do de-duplicate forwarded alerts in backend (e.g., nid+timestamp or content hash) and keep a short-lived cache for matching.
- Do mask PII in logs; only send minimal fields from Tasker (title/text/timestamp/app/nid).
- Don’t render PromptPay shortcode or generate custom QR payloads.
- Don’t depend on cart_hash for verification; keep payload minimal (order_id, order_total, session_token, slip_image).
- Don’t add new deps without reason.

## Definition of Done
- Settings page includes a working Media Library picker that saves `qr_image_url` and shows a preview.
- Classic and Blocks checkout show the selected static QR placeholder (or default SVG) responsively without layout bugs.
- REST endpoint `/wp-json/wc-scanandpay/v1/verify-slip` accepts expected params and handles backend (currently n8n) responses.
- Version and changelog are consistent; plan.md updated; evaluation.md checks pass.

## Roadmap
- Short term (Now): Integrate IMAP/email parsing or Android (Tasker) notification forwarding via n8n as a verification source; document flow, security (HTTPS/HMAC), and reliability (battery, retries, de-dup).
- Mid term: Add an optional external API adapter (Laravel) selectable in settings; standardize the response contract and maintain both n8n and Laravel backends.
- Long term: Implement slipless "unique-amount + email/SMS alert + webhook auto-matching" via Laravel with idempotency, manual review queue, and expanded bank parsers.
- Additionally: Add progress UI and retry logic to improve user feedback.

## Backend Options (Adapter Pattern)
- Default: n8n webhook (email/IMAP-based verification).
- Optional: Laravel service with the same contract.
- Standard response schema expected from any adapter:
  - `{ status: approved|rejected, message?, approved_amount?, reference_id? }`

## Android Forwarder (Tasker)
- Flow: Android Tasker captures bank/Stripe notifications or SMS → HTTP POST to n8n/Laravel (HTTPS).
- Headers: `X-Device-Id`, `X-Secret` or `X-Signature` (HMAC of `${timestamp}\n${sha256(body)}`).
- Body example (JSON): `{"source":"android-tasker","app":"%an","title":"%ntitle","text":"%ntext","posted_at":"%DATE %TIME","nid":"%nid"}`
- Backend responsibilities: verify secret/HMAC, parse amount/reference via regex, de-dup (nid+time), cache recent transactions for matching, return unified contract.
- Reliability: disable battery optimizations; add retries/queue; avoid PII; enforce HTTPS/SSL verify.

## Step-by-step (Implementation Order)
1) Checkout verification: finalize frontend UX and REST integration; approve-or-reject only; optional auto-submit on approval.
2) Adapter wrapper in REST handler to call verifier (n8n now; Laravel optional) uniformly.
3) Security hardening: HMAC, HTTPS with SSL verification, timeouts/retries; minimal payload.
4) Tests and docs: unit/integration for REST path; manual QA on Classic/Blocks; update docs.

## Short-term Tasks (Phases 1–3)

### Phase 1 — Settings QA
- [ ] Toggle backend shows correct fields; button “Test Backend” relabels and moves next to active URL field
- [ ] Test AJAX returns clear success/error without exposing URL/secret; client validates HTTPS/required URL

### Phase 2 — Checkout Regression
- [ ] Classic and Blocks render identical static QR; no console errors
- [ ] Upload slip → call `verify-slip` → receives approved/rejected; order updated accordingly
- [ ] Test both backends (n8n default; Laravel optional/mock)

### Phase 3 — Tests
- [ ] Unit: factory selects adapter; response mapping conforms `{ status, message?, approved_amount?, reference_id? }`
- [ ] Integration: REST happy-path and error/timeout/retry; filters `san8n_verifier_timeout`/`san8n_verifier_retries` honored

## Slipless Flow (Preview)

WordPress acts as a secure proxy to n8n to deliver a slipless experience with unique‑cents EMV PromptPay and a 10‑minute TTL. This is planned as the default mode; slip upload becomes fallback.

- WP REST proxy endpoints to add:
  - `POST /wp-json/san8n/v1/qr/generate` → sign with HMAC and forward to n8n, receive `{ emv, amount_to_pay, amount_variant?, expires_epoch, session_token }`
  - `GET /wp-json/san8n/v1/order/status?order_id&session_token` → returns `{ status: pending|paid|expired, ... }`
  - `POST /wp-json/san8n/v1/order/paid` (callback from n8n) → mark order paid/cancelled
- Headers (slipless): use `X-San8n-*`
  - `X-San8n-Timestamp` and `X-San8n-Signature = HMAC_SHA256(secret, `${timestamp}\n${sha256(rawBody)}`)`
  - Tasker may send `X-San8n-Secret` or `X-San8n-Signature` with the same formula
- n8n responsibilities:
  - Persist `payment_sessions` with `session_token`, `amount_variant`, `expires_epoch`, `used`
  - Ingest Tasker notifications; AI/regex mapper; exact‑match `amount_variant` within TTL; set `used=true`; expose status
  - Optional callback `order/paid` to WP when a match is confirmed
