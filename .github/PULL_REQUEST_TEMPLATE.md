# PR Title

## Summary
- What changed and why (1–3 lines)
- Link to context in `plan.md` and any related docs/tasks

## Changes
- [ ] Feature/Refactor/Bugfix: describe scope
- [ ] Backward compatible
- [ ] No secrets committed

## Files Touched
- List key files (paths):
  - e.g., `includes/class-san8n-gateway.php`
  - e.g., `assets/js/checkout-inline.js`
  - e.g., `readme.txt`, `plan.md`, `AGENTS.md`

## Implementation Notes
- Architecture/decisions
- HMAC/headers used: `X-PromptPay-*` (checkout verify) or `X-San8n-*` (slipless proxy)
- Any migrations/workflow exports (update `json/` as needed)

## Testing
- Local environment: WP/WC versions, PHP version
- PHPCS clean
- Manual checks:
  - Admin Settings
    - [ ] Media Library picker for QR (`qr_image_url`) works and persists
    - [ ] Backend toggle + Test Backend UX
  - Checkout (Classic & Blocks)
    - [ ] Static QR placeholder renders
    - [ ] Slip upload -> `/wc-scanandpay/v1/verify-slip` happy/error paths
  - REST Adapter
    - [ ] Uses `SAN8N_Verifier_Factory`; headers `X-PromptPay-*`; HTTPS/SSL verify
  - Slipless (only if touched)
    - [ ] `POST /wp-json/san8n/v1/qr/generate` (server-side HMAC) returns `{ emv, amount_to_pay, amount_variant?, currency, expires_epoch, session_token }`
    - [ ] `GET /wp-json/san8n/v1/order/status?order_id&session_token` returns `{ status: pending|paid|expired, ... }`
    - [ ] Optional `POST /wp-json/san8n/v1/order/paid` callback updates order
  - Backend/N8N (if applicable)
    - [ ] Tasker ingest dedup + exact-match to `amount_variant` within TTL
    - [ ] `payment_sessions` schema/queries OK

## Screenshots / Videos (optional)
- Before/After admin + checkout

## Versioning & Docs
- [ ] If shipping code: bump `SAN8N_VERSION`
- [ ] Update `readme.txt` Changelog (dated)
- [ ] Update docs touched: `plan.md`, `AGENTS.md`, `instructions.md`, `context.md`, `evaluation.md`, `feedback.md`
- [ ] Export/commit n8n workflows in `json/` (if updated)

## Acceptance Criteria (from evaluation.md)
- [ ] Settings: picker works, backend toggle + Test Backend
- [ ] Checkout: Classic/Blocks parity, no console errors
- [ ] REST: minimal payload (`order_id`, `order_total`, `session_token`, `slip_image`)
- [ ] Adapter: unified contract `{ status, message?, approved_amount?, reference_id? }`
- [ ] Security: HTTPS + HMAC, payload size guard, secrets trimmed
- [ ] Code quality: compiles, no unused legacy (e.g., `promptpay_payload`) and conforms to WP standards

## Risks / Rollback
- Risks and mitigations
- Rollback plan (revert commit/tag); no DB migration unless noted

## Next Steps
- Follow-ups to close out tasks in `plan.md`

---
Maintainers Checklist
- [ ] PHPCS pass
- [ ] i18n for new strings
- [ ] No secrets/tokens in code or history
- [ ] Works on both Classic and Blocks checkout
- [ ] Docs updated and consistent with the change
