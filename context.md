Plugin Context and Rationale

This document provides background on the existing Scan & Pay (n8n) payment gateway plugin and the rationale for the upcoming refactor. It is intended to give Codex full context before modifying the code.

Existing Functionality

The plugin registers a WooCommerce payment gateway that lets customers pay by scanning a PromptPay QR code and uploading their payment slip. Key behaviours include:

Dynamic QR generation – In class‑san8n‑gateway.php, the gateway stores a promptpay_payload and uses it with the current cart total via generate_qr_payload($amount) to build a QR payload string
GitHub
. The QR code shown to customers is therefore tied to the exact order amount.

Slip upload & verification – In the checkout JavaScript (checkout‑inline.js), customers upload an image of their payment slip. The script packages slip_image, a session_token, the cart total and a cart hash into a FormData object and posts it to the REST endpoint /verify‑slip
GitHub
. On the server, verify_slip() receives these parameters, uploads the file and forwards it (plus the promptpay_payload) to an n8n webhook
GitHub
.

Admin settings – Administrators can configure the gateway in WooCommerce settings. Fields include enabling/disabling the gateway, setting the title and description, entering the PromptPay ID, n8n webhook URL and shared secret, and adjusting the amount tolerance and time window
GitHub
.

Desired Changes

The merchant now wants to accept payments via a static QR image rather than generating a dynamic QR code for every order. Customers will scan the static QR and enter the amount themselves. A separate API (called by n8n) will validate the slip image against the bank to ensure it is authentic. The plugin should therefore:

Allow administrators to choose a QR image from the Media Library instead of entering a PromptPay payload/ID.

Remove all dynamic price calculation. The amount on the slip will not be derived from the cart; the customer will enter the correct amount manually, and n8n will check it.

Simplify slip verification data – send only the slip image plus identifiers such as order_id and order_total to n8n. The plugin no longer needs to include cart_total or a cart hash in the verification call.

Continue to display upload and verification UI to customers and update order status based on n8n’s response.

These changes require coordinated modifications across PHP (gateway settings, checkout rendering and REST API) and JavaScript (checkout behaviour). The sections below explain how to perform these changes.