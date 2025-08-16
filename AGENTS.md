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
 - promptpay/promptpay.php (shortcode provider `[promptpayqr]`)

## Current Mission
- Render PromptPay QR via shortcode: `[promptpayqr amount="{cart_total_float}"]`
- Use PromptPay ID from PromptPay plugin settings (do not pass `id` in shortcode)
- Slip verify via n8n (mock ok); trust n8n decision
- Remove custom QR payload logic (`generate_qr_payload`) and any `promptpay_payload` usage
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
- ✅ Use `echo do_shortcode('[promptpayqr amount="…"]')` in `payment_fields()`
- ✅ Localize needed data via `wp_localize_script` (e.g., numeric `order_total`)
- ✅ Pass a plain float for amount (no currency symbol/locale formatting)
- ❌ Don’t build custom QR payloads or store `promptpay_payload`
- ❌ Don’t gate logic on cart_hash or re-approve resets for price changes (see roadmap)
- ❌ Don’t add new deps without reason

## Definition of Done
- Checkout renders PromptPay QR via shortcode with amount locked to cart total
- No references to `generate_qr_payload` or `promptpay_payload`
- REST accepts expected params and handles n8n mock response
- Version bumped + readme.txt changelog updated
- plan.md updated; evaluation.md checks pass

## Roadmap
- Medium term: Re-render QR on `update_checkout` via AJAX to fetch refreshed shortcode HTML so the locked amount always matches the latest total
- Long term: Add WooCommerce Blocks support (separate integration path; React-based, not `payment_fields()`)
