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
