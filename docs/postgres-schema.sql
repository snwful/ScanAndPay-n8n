-- Scan & Pay (n8n) minimal Postgres schema
-- Tables: payments, payment_sessions
-- Ensure timezone is set appropriately; using timestamptz for safety.

-- Payments ingested from Tasker/IMAP/others
CREATE TABLE IF NOT EXISTS payments (
  message_id        text PRIMARY KEY,
  amount            numeric(12,2) NOT NULL,
  currency          text NOT NULL DEFAULT 'THB',
  sender_name       text,
  receiver_name     text,
  raw               jsonb,               -- raw parsed payload for audits/debug
  created_at        timestamptz NOT NULL DEFAULT now(),
  used              boolean NOT NULL DEFAULT false
);

CREATE INDEX IF NOT EXISTS idx_payments_created_at ON payments (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_payments_used_created ON payments (used, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_payments_amount_created ON payments (amount, created_at DESC);

-- Payment sessions generated for Woo orders (QR generate)
CREATE TABLE IF NOT EXISTS payment_sessions (
  session_token       text PRIMARY KEY,
  order_id            text,
  amount              numeric(12,2) NOT NULL,
  amount_variant      numeric(12,2),
  currency            text NOT NULL DEFAULT 'THB',
  emv                 text,                  -- EMV payload issued for this session
  expires_at          timestamptz NOT NULL,  -- absolute expiry time
  created_at          timestamptz NOT NULL DEFAULT now(),
  status              text NOT NULL DEFAULT 'pending', -- pending | approved | expired
  matched_message_id  text,                  -- from payments.message_id
  approved_amount     numeric(12,2)
);

CREATE INDEX IF NOT EXISTS idx_payment_sessions_order_created ON payment_sessions (order_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_payment_sessions_created ON payment_sessions (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_payment_sessions_status ON payment_sessions (status);

-- Optional FK if you expect matching always to a known payment (not enforced to allow decoupled ingestion)
-- ALTER TABLE payment_sessions
--   ADD CONSTRAINT fk_payment_sessions_payment
--   FOREIGN KEY (matched_message_id) REFERENCES payments(message_id);

-- Helper view: latest session per order
CREATE OR REPLACE VIEW v_latest_session_per_order AS
SELECT DISTINCT ON (order_id)
  order_id, session_token, status, amount, amount_variant, currency, emv,
  expires_at, created_at, matched_message_id, approved_amount
FROM payment_sessions
WHERE order_id IS NOT NULL
ORDER BY order_id, created_at DESC;
