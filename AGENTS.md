# AGENTS.md — Operational Guide

## Purpose
Rules and map for agents (Codex/Windsurf/Cursor) to work safely on this WooCommerce plugin.

## Key Files
- scanandpay-n8n.php (bootstrap, SAN8N_VERSION)
- includes/class-san8n-gateway.php (settings, checkout UI)
- includes/class-san8n-rest-api.php (POST /verify-slip)
- assets/js/checkout-inline.js (classic checkout)
- assets/js/settings.js (admin UX)
- assets/css/frontend.css (responsive QR)
- readme.txt (Changelog)
- context.md / instructions.md / evaluation.md / plan.md

## Current Mission
- Static QR via Media Library with simplified slip verification
- Trust n8n decision for approved/rejected (mock OK)
- Maintain security: nonce, file type/size, strip EXIF, caps
- Next: integrate real bank API

## API (internal → n8n)
POST /wp-json/wc-scanandpay/v1/verify-slip  (multipart)
- slip_image (file), session_token, order_id, order_total, customer_email?
n8n → { status: approved|rejected, reference_id?, approved_amount?, reason? }

## Policies
- Bump plugin header + SAN8N_VERSION every change
- Append readme.txt changelog with date + bullets
- Update plan.md in each PR/iteration
- PHPCS clean; WP/WC compatibility intact
- Render QR via `wp_get_attachment_image()` with `srcset`/`sizes`
- Load frontend CSS only on checkout or order-pay pages

## Do / Don’t
- ✅ Use `wp_get_attachment_image()` with responsive attributes
- ✅ Localize data via `wp_localize_script`
- ❌ No raw `<img src>` tags for the QR
- ❌ Don’t load CSS globally or add new deps without reason

## Definition of Done
- Settings: media picker `qr_image_id` working
- Checkout shows QR image; slip upload integrates mock
- REST accepts new params, handles n8n response
- Removed generate_qr_payload & related JS resets
- Version bumped + readme.txt changelog updated
- plan.md updated; evaluation.md checks pass
- QR fits ≤360px without overflow or horizontal scroll
- Blocks checkout path gated until ready
