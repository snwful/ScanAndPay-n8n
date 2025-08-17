# plan.md — Sprint Plan (2025-08-17)

## Goal
Use PromptPay shortcode to render QR with locked amount (ID from PromptPay plugin settings). Simplify slip verification payload and remove custom QR payload logic. Classic checkout only in this iteration.

## Tasks
- [x] Remove `promptpay_payload` option and any code usage
- [x] In `payment_fields()`, output `[promptpayqr amount="{float_cart_total}"]`; do not pass `id`
- [x] Add fallback notice + `assets/images/qr-placeholder.svg` when shortcode unavailable
- [x] JS: send slip with {session_token, order_id, order_total} only (no cart_total/cart_hash)
- [x] REST: accept new params; forward to n8n (mock); trust decision
- [x] Remove legacy dynamic-price resets
- [x] Bump version + add readme.txt changelog
- [x] Update docs: context.md, AGENTS.md, instructions.md, evaluation.md, feedback.md, plan.md
- [x] Improve small-screen responsive layout for payment UI

## Risks/Mitigations
- PromptPay plugin inactive → show fallback SVG + admin notice; document dependency
- Amount formatting → always cast to float; avoid localized strings
- Blocks vs Classic → ship Classic first; blocks tracked in roadmap
- If shortcode is missing, auto-bootstrap bundled `promptpay/promptpay.php` to register shortcode and enqueue assets; avoid double-enqueue when external plugin is active

## Acceptance Criteria
- Checkout renders PromptPay QR via shortcode with amount locked to cart total
- REST + JS flow works E2E with mock; payload excludes cart_total/cart_hash
- No references to `generate_qr_payload` or `promptpay_payload`
- PHPCS passes

## Next
- Medium: AJAX re-render of shortcode on `update_checkout` to keep amount in sync
- Long: WooCommerce Blocks support (dedicated Blocks payment method)
- Integrate real bank-API via n8n
- Add e2e smoke tests (upload → approved)