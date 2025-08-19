# plan.md — Sprint Plan (2025-08-25)

## Goal
Finalize docs and execute a phased roadmap:
- Short: use n8n IMAP/email alerts to verify incoming funds before relying on slips.
- Mid: add optional Laravel API adapter selectable in settings; standardize response contract.
- Long: implement slipless unique-amount + email/SMS alert + webhook matching via Laravel.

## Tasks
- [x] Update docs to reflect static QR via Media Library, Classic/Blocks parity, responsive fixes, and REST namespace `wc-scanandpay/v1`.
- [ ] Decide verification backend: n8n vs Laravel.
- [ ] Define verification response contract: `{ status: approved|rejected, reference_id?, approved_amount?, reason? }`.
- [ ] Implement backend adapter in `includes/class-san8n-rest-api.php` to call chosen service.
- [ ] Confirm security: nonce, rate limiting, file validation, EXIF stripping.
- [ ] Optional: add `/status/{token}` polling usage and UI progress indicators.
- [ ] Update README/readme.txt sections as needed after backend decision.

## Risks/Mitigations
- Backend availability/latency → timeouts and retries; clear user messaging.
- File size/type differences across hosts → configurable limits and server-side validation.
- Security of webhook → HMAC signing, HTTPS, nonce validation, minimal payload.

## Acceptance Criteria
- Static QR image displays correctly in Classic and Blocks, responsive on mobile.
- `POST /wp-json/wc-scanandpay/v1/verify-slip` forwards to chosen backend and updates order based on response.
- Admin can set QR image via Media Library and configure webhook URL/secret.
- Documentation updated (README.md, readme.txt, context.md, instructions.md, evaluation.md, feedback.md, AGENTS.md).
- PHPCS passes (where applicable) and no console errors on checkout.

## Next
- Short: document and wire n8n IMAP/email parsing → backend verification; add UI copy and countdown guidance.
- Mid: implement Laravel adapter option and shared response schema; expose a settings toggle.
- Long: design slipless unique-amount flow, idempotent webhook, manual review queue; plan migration steps.

## Step-by-step Execution Plan

Sprint 1 — Checkout Verification Finalize
- [x] Finalize checkout-only verification: `/verify-slip` returns approved|rejected only; set/clear session flags; `validate_fields()` gates Place order accordingly; Classic auto-submit on approval (if enabled).
- [x] Adapter wrapper: unify n8n calls in `class-san8n-rest-api.php` with contract `{ status, message?, approved_amount?, reference_id? }`.
- [x] Classic/Blocks parity: both render static QR and share verify flow without console errors.
- [x] Security baseline: HMAC signing, HTTPS with SSL verification, sensible timeout (no retries at checkout); strict file validation.

Sprint 2 — Laravel Adapter + Tests
- [ ] Settings toggle to choose backend (n8n|Laravel) and configure endpoint/secret.
- [ ] Implement Laravel adapter using same contract; add filters for timeout/retry.
- [ ] Tests: unit/integration for REST adapter; manual regression on Classic/Blocks.

Sprint 3 — Optional Enhancements
- [ ] Anti-reuse: compute/store slip hash to block reuse across orders.
- [ ] Optional file types: add `webp/jfif` with strict validation.
- [ ] Logging: structured logs with PII masking.

## Open Tasks
- [ ] Test admin: Select QR image via media picker, preview shows, Save changes, reload confirms persistence (in progress)
- [ ] Test classic checkout: static QR image displays from saved URL; no PromptPay assets/shortcodes used; no 404s
- [ ] Test WooCommerce Blocks checkout: static QR image displays; no PromptPay assets; no console errors
- [ ] Optional cleanup: remove PromptPay wording from gateway title/description defaults in `includes/class-san8n-gateway.php`
- [ ] REST flow: `/verify-slip` returns approved|rejected only; set/clear session accordingly; Classic auto-submit on approval (if enabled)
- [ ] Adapter wrapper: unify n8n (Laravel optional later) with contract `{ status, message?, approved_amount?, reference_id? }`
- [ ] Security hardening: enforce HTTPS/SSL verify, HMAC, timeouts/retries; strict file validation; PII masking in logs
- [ ] Tests: unit/integration for REST adapter; manual regression on Classic/Blocks

## Deliverables per Sprint
- Sprint 1: Checkout-only verification finalized, adapter wrapper for n8n, Classic/Blocks parity, security baseline, docs updated.
- Sprint 2: Laravel backend option with settings, tests for adapter, docs.
- Sprint 3: Optional enhancements (anti-reuse, file types, logging), docs.
