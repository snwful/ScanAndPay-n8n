# plan.md — Sprint Plan (2025-08-25)

## Goal
Finalize documentation and plan the verification backend decision and integration while maintaining the static QR placeholder architecture across Classic and Blocks checkout.

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
- Choose and integrate backend (n8n or Laravel) and finalize adapter.
- Add end-to-end tests for slip verification flows.
- Consider async status polling and richer UX feedback.
- Evaluate feasibility of dynamic QR only if product requirements change.
