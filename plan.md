# plan.md — Sprint Plan (2025-08-28)

## Goal
Make checkout QR responsive across devices.

## Tasks
- [x] Replace raw `<img>` with `wp_get_attachment_image()` + `srcset`/`sizes`.
- [x] Create `assets/css/frontend.css` and enqueue only on checkout/order-pay.
- [x] Ensure no overflow ≤360px; gate Blocks path until QA.
- [x] PHPCS run; bump version/changelog; update docs.

## Risks/Mitigations
- Theme overrides may affect QR size → scope selectors carefully.
- Blocks checkout needs separate handling → gate for now.

## Acceptance Criteria
- QR responsive via `wp_get_attachment_image()` with effective `sizes`.
- Frontend CSS loads only on checkout/order-pay.
- No horizontal scroll on mobile ≤360px.
- plan.md updated; evaluation checks pass.

## Done
- Responsive QR implemented and stylesheet enqueued conditionally.

## Next
- Integrate real bank-API via n8n.
- Add e2e smoke tests (upload → approved).
