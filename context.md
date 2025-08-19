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

- Short term: Use n8n IMAP/email alert parsing to verify incoming funds before relying on slips; document the flow and security controls.
- Medium term: Add an optional external API adapter (Laravel) selectable in settings; standardize the response contract and maintain both backends.
- Long term: Implement slipless "unique-amount + email/SMS alert + webhook auto-matching" via Laravel with idempotency, manual review queue, and expanded bank parsers.

## Out of Scope (by design)
- Admin re-verify actions and metabox UI.
- Order list status column.
- Pending state and scheduled re-checks.
- Background status polling endpoints.

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