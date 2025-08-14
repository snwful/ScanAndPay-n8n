# AGENTS.md — Operational Guide

## Purpose
Rules and map for agents (Codex/Windsurf/Cursor) to work safely on this WooCommerce plugin.

## Key Files
- scanandpay-n8n.php (bootstrap, SAN8N_VERSION)
- includes/class-san8n-gateway.php (settings, checkout UI)
- includes/class-san8n-rest-api.php (POST /verify-slip)
- assets/js/checkout-inline.js (classic checkout)
- assets/js/settings.js (admin UX)
- readme.txt (Changelog)
- context.md / instructions.md / evaluation.md / plan.md

## Current Mission
- Replace dynamic QR with static Media Library image
- Slip verify via n8n (mock ok); trust n8n decision
- Remove dynamic-price logic & promptpay_payload
- Keep security: nonce, file type/size, strip EXIF, caps

## API (internal → n8n)
POST /wp-json/wc-scanandpay/v1/verify-slip  (multipart)
- slip_image (file), session_token, order_id, order_total, customer_email?
n8n → { status: approved|rejected, reference_id?, approved_amount?, reason? }

## Policies
- Bump plugin header + SAN8N_VERSION every change
- Append readme.txt changelog with date + bullets
- Update plan.md in each PR/iteration
- PHPCS clean; WP/WC compatibility intact

## Do / Don’t
- ✅ Use wp_get_attachment_url(qr_image_id)
- ✅ Localize data via wp_localize_script
- ❌ No dynamic QR payload / cart_hash gating
- ❌ Don’t add new deps without reason

## Definition of Done
- Settings: media picker `qr_image_id` working
- Checkout shows QR image; slip upload integrates mock
- REST accepts new params, handles n8n response
- Removed generate_qr_payload & related JS resets
- Version bumped + readme.txt changelog updated
- plan.md updated; evaluation.md checks pass
