# Scan & Pay (n8n) — WooCommerce Payment Gateway

Static QR checkout for WooCommerce with inline slip verification.

## Overview
- Displays a static QR placeholder image configured via the WordPress Media Library (with a bundled SVG fallback).
- Customers upload a payment slip; the plugin verifies it via a selectable backend service (n8n default; Laravel optional) using a unified contract, then updates the order on approval.
- Supports Android Tasker-based notification/SMS forwarding to your backend (n8n/Laravel) for verification; the WooCommerce plugin interface stays unchanged and still calls `/verify-slip`.

## Requirements
- WordPress 6.0+ and PHP 8.0+
- WooCommerce 7.0+

## Installation
1) Upload the plugin to `wp-content/plugins/` and activate it.
2) In WooCommerce → Settings → Payments → Scan & Pay (n8n), configure the QR image and verification backend.

## Configuration
- Select a QR placeholder image via the Media Library (or use the default SVG).
- Set your verification backend webhook URL and shared secret (selectable: n8n or Laravel).

## Usage
- Classic and Blocks checkout both render the configured static QR placeholder image.
- Customers upload a payment slip and verify inline; on approval, the order is updated automatically.

## Troubleshooting
- If the QR doesn’t appear: ensure a QR image is selected in settings or that the fallback exists at `assets/images/qr-placeholder.svg`.
- Clear browser and site caches; verify no console errors from checkout scripts.
- Confirm REST endpoint is reachable: `POST /wp-json/wc-scanandpay/v1/verify-slip` should return validation errors without a file.

## WordPress Directory Readme
See `readme.txt` for changelog and detailed instructions.

## Roadmap

- Short term: Use n8n IMAP/email alert parsing or Android (Tasker) notification/SMS forwarding to verify incoming funds; document flow, security (HTTPS/HMAC), and reliability (battery, retries, de-dup).
- Medium term: Maintain optional external API adapter (Laravel) selectable in settings; standardize the response contract and maintain both backends.
- Long term: Implement slipless "unique-amount + email/SMS alert + webhook auto-matching" via Laravel with idempotency, manual review queue, and expanded bank parsers.

## Verification Backends (Adapter)

- Supported: n8n (default) and Laravel (optional) with the same contract
- Unified response contract expected from any backend:

```json
{
  "status": "approved|rejected",
  "message": "optional",
  "approved_amount": 1499.00,
  "reference_id": "abc123"
}
```

### Verification Contract (Request & Headers)

- Request (multipart/form-data): `slip_image` (file), `order` (JSON with `id`, `total`, `currency`), `session_token`.
- Headers: `X-PromptPay-Timestamp` (unix), `X-PromptPay-Signature` (HMAC-SHA256 of `${timestamp}\n${sha256(body)}`), `X-PromptPay-Version: 1.0`, `X-Correlation-ID`.
- Adapter implementation: see `includes/class-san8n-verifier.php` (`SAN8N_Verifier_Factory`, `SAN8N_Verifier_N8n`, `SAN8N_Verifier_Laravel`).

### Matching Rules (Summary)

- Time window: match incoming email alerts within a configurable window (e.g., 10–15 min) around checkout.
- Amount: Pro (Laravel) requires exact match using unique-amount suffix; Standard (n8n) may allow small tolerance if enabled.
- Idempotency: dedupe via email Message-ID/reference; pass through `X-Correlation-ID` for tracing.
- Outcome: backend returns only `approved` or `rejected` with optional `reference_id`, `approved_amount`, `message`.

## Android Forwarder (Tasker)

Android Tasker can forward bank/Stripe notifications or SMS to your backend (n8n/Laravel) over HTTPS. The plugin remains unchanged and still calls `/verify-slip`; the backend uses recent forwarded alerts to decide `approved|rejected` per the unified contract.

- Headers from Tasker → backend: `X-Device-Id`, `X-Secret` or `X-Signature` (HMAC of `${timestamp}\n${sha256(body)}`), optional `X-Timestamp`.
- Payload example (JSON):
  `{"source":"android-tasker","app":"%an","title":"%ntitle","text":"%ntext","posted_at":"%DATE %TIME","nid":"%nid"}`
- Backend responsibilities: verify secret/HMAC, parse amount/reference via regex, de-duplicate (nid+timestamp or content hash), cache recent transactions (10–15 minutes), and respond via the unified contract when called by `/verify-slip`.
- Reliability: disable battery optimizations for Tasker, allow background activity, implement retries/backoff and offline queue, mask PII in logs, enforce HTTPS with SSL verification.

## Optional Enhancements (Out of Scope)

- Progress UI and retry hints on verification.
- Optional anti-reuse via slip hash; optional support for `webp/jfif` with strict validation.
- Laravel adapter as an alternative backend using the same contract.

## Developer Filters

- `san8n_verifier_timeout` — Adjust verifier HTTP timeout (seconds). Args: (int $timeout, string $backend)
- `san8n_verifier_retries` — Adjust verifier retry attempts. Args: (int $retries, string $backend)

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

## Open Tasks

- [ ] Admin: Select QR via media picker, preview, save, reload persists (in progress)
- [ ] Classic checkout: static QR displays; no PromptPay assets/shortcodes; no 404s
- [ ] WooCommerce Blocks checkout: same image; no console errors
- [ ] Optional cleanup: remove PromptPay wording from gateway title/description defaults in `includes/class-san8n-gateway.php`
- [ ] REST flow: `/verify-slip` returns approved|rejected only; set/clear session accordingly; Classic auto-submit on approval (if enabled)
- [ ] Adapter wrapper: unify n8n (Laravel optional later) with contract `{ status, message?, approved_amount?, reference_id? }`
- [ ] Security hardening: enforce HTTPS/SSL verify, HMAC, timeouts/retries; strict file validation; PII masking in logs
- [ ] Tests: unit/integration for REST adapter; manual regression on Classic/Blocks

## Laravel Adapter Quickstart (Planned)

- Endpoint: `POST /api/verify` with HMAC-signed JSON body; see contract above
- Poll: `GET /api/status/{reference_id}` for async status
- Security: HTTPS only, SSL verify ON, HMAC via shared secret configured in WordPress settings
- Timeout/retries tunable via WordPress filters (to be documented)