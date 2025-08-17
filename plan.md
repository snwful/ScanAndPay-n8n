# plan.md — Sprint Plan (2025-08-25)

## Goal
Fix PromptPay QR rendering on checkout; ensure shortcode and assets load automatically and maintain fallback. Prepare optional live QR path for Blocks checkout.

## Tasks
- [x] Auto-bootstrap bundled PromptPay plugin when shortcode missing (guard against double load)
- [x] Conditionally enqueue PromptPay CSS/JS when shortcode present
- [x] Ensure Classic checkout renders live QR via shortcode with SVG fallback
- [x] Add filter to optionally enable live QR on Blocks checkout
- [x] Bump version and update changelog

## Risks/Mitigations
- External PromptPay plugin active → use shortcode_exists/class check before bootstrapping
- Blocks live QR disabled by default to avoid regressions
- Shortcode missing → fallback placeholder displayed

## Acceptance Criteria
- Classic checkout shows live QR when PromptPay available
- PromptPay assets only load when shortcode exists
- Blocks integration can opt into live QR via `san8n_blocks_live_qr` filter
- PHPCS passes

## Next
- AJAX re-render of shortcode on `update_checkout` for dynamic totals
- Full Blocks React integration without feature flag
- Real n8n/bank integration
- Add end-to-end tests for slip verification
