# plan.md — Sprint Plan (2025-08-14)

## Goal
Static QR (Media Library) + simplified slip verification via n8n (mock).

## Tasks
- [ ] Add `qr_image_id` setting (media picker), remove `promptpay_payload`
- [ ] Render QR image in checkout (no dynamic price)
- [ ] JS: send slip with {session_token, order_id, order_total, (email?)}
- [ ] REST: accept new params; forward to n8n (mock); trust decision
- [ ] Remove dynamic-price/cart-hash resets
- [ ] Bump version + add readme.txt changelog
- [ ] Update docs: context.md, AGENTS.md, plan.md

## Risks/Mitigations
- Blocks vs Classic → ship Classic first; gate others
- Admin caps → restrict media picker to manage_woocommerce

## Acceptance Criteria
- Settings/checkout/REST flow works E2E with mock
- No references to generate_qr_payload or promptpay_payload
- PHPCS passes

## Next
- Integrate real bank-API via n8n
- Add e2e smoke tests (upload → approved)