-- Scan & Pay (n8n) minimal Postgres schema
-- Tables: payments, payment_sessions, approvals
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

-- Approvals dedupe table for idempotent webhook/callback processing
CREATE TABLE IF NOT EXISTS approvals (
  session_token    text PRIMARY KEY,
  approved_amount  numeric(12,2) NOT NULL,
  matched_at       timestamptz    NOT NULL,
  ref_code         text,
  message_id       text,
  created_at       timestamptz    NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS approvals_message_id_uq ON approvals (message_id) WHERE message_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS approvals_created_at_idx ON approvals (created_at);

-- Payment sessions generated for Woo orders (QR generate)
CREATE TABLE IF NOT EXISTS payment_sessions (
  session_token       text PRIMARY KEY,
  order_id            text,
  amount              numeric(12,2) NOT NULL,
  amount_variant      numeric(12,2),
  currency            text NOT NULL DEFAULT 'THB',
  emv                 text,                  -- EMV payload issued for this session
  ref_code            text,                  -- optional reference code for display/tracking
  expires_at          timestamptz NOT NULL,  -- absolute expiry time
  created_at          timestamptz NOT NULL DEFAULT now(),
  status              text NOT NULL DEFAULT 'pending', -- pending | approved | expired
  matched_message_id  text,                  -- from payments.message_id
  approved_amount     numeric(12,2)
);

-- Legacy safety: ensure status column exists before creating indexes/views that reference it
DO $$
BEGIN
  -- Add any missing columns for legacy databases before indexes/views
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name='payment_sessions' AND column_name='status'
  ) THEN
    ALTER TABLE payment_sessions ADD COLUMN status text NOT NULL DEFAULT 'pending';
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name='payment_sessions' AND column_name='emv'
  ) THEN
    ALTER TABLE payment_sessions ADD COLUMN emv text;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name='payment_sessions' AND column_name='amount_variant'
  ) THEN
    ALTER TABLE payment_sessions ADD COLUMN amount_variant numeric(12,2);
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name='payment_sessions' AND column_name='approved_amount'
  ) THEN
    ALTER TABLE payment_sessions ADD COLUMN approved_amount numeric(12,2);
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name='payment_sessions' AND column_name='matched_message_id'
  ) THEN
    ALTER TABLE payment_sessions ADD COLUMN matched_message_id text;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name='payment_sessions' AND column_name='ref_code'
  ) THEN
    ALTER TABLE payment_sessions ADD COLUMN ref_code text;
  END IF;
END$$;

CREATE INDEX IF NOT EXISTS idx_payment_sessions_order_created ON payment_sessions (order_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_payment_sessions_created ON payment_sessions (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_payment_sessions_status ON payment_sessions (status);
CREATE INDEX IF NOT EXISTS idx_payment_sessions_ref_code ON payment_sessions (ref_code);

-- Optional FK if you expect matching always to a known payment (not enforced to allow decoupled ingestion)
-- ALTER TABLE payment_sessions
--   ADD CONSTRAINT fk_payment_sessions_payment
--   FOREIGN KEY (matched_message_id) REFERENCES payments(message_id);

-- Helper view: latest session per order
CREATE OR REPLACE VIEW v_latest_session_per_order AS
SELECT DISTINCT ON (order_id)
  order_id, session_token, status, amount, amount_variant, currency, emv,
  expires_at, created_at, matched_message_id, approved_amount, ref_code
FROM payment_sessions
WHERE order_id IS NOT NULL
ORDER BY order_id, created_at DESC;

--
-- ============================================================
-- Full Bootstrap (appended): Safe to run multiple times
-- ============================================================
-- Scan&Pay-n8n Postgres bootstrap
-- Safe to run multiple times.

-- Optional: create role/database (customize as needed)
-- DO NOT run if you already have roles/db set up.
-- CREATE ROLE san8n LOGIN PASSWORD 'REPLACE_STRONG_PASSWORD';
-- CREATE DATABASE san8n OWNER san8n;
-- \c san8n

-- Ensure sane timezone behavior
SET TIME ZONE 'UTC';

------------------------------------------------------------
-- 1) Core tables
------------------------------------------------------------

-- Payments ingested from Tasker/IMAP/others
CREATE TABLE IF NOT EXISTS payments (
  message_id        text PRIMARY KEY,
  amount            numeric(12,2) NOT NULL,
  currency          text NOT NULL DEFAULT 'THB',
  bank              text,               -- e.g., KBANK, SCB (from ingest)
  ref               text,               -- bank-provided reference if any
  sender_name       text,               -- may be filled by OCR or ingestion
  receiver_name     text,               -- may be filled by OCR or ingestion
  txn_time          timestamptz,        -- transaction time (from slip/notification)
  raw               jsonb,              -- raw parsed payload for audits/debug
  created_at        timestamptz NOT NULL DEFAULT now(),  -- ingest time
  used              boolean NOT NULL DEFAULT false
);

-- Helpful indexes for matching windows and exact amounts
CREATE INDEX IF NOT EXISTS idx_payments_created_at ON payments (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_payments_used_created ON payments (used, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_payments_amount_created ON payments (amount, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_payments_txn_time ON payments (txn_time DESC);
-- Partial index for typical match query (only unconsumed)
CREATE INDEX IF NOT EXISTS idx_payments_unconsumed_recent
  ON payments (created_at DESC, amount)
  WHERE used = false;

-- Payment sessions generated for Woo orders (QR generate)
CREATE TABLE IF NOT EXISTS payment_sessions (
  session_token       text PRIMARY KEY,
  order_id            text,
  amount              numeric(12,2) NOT NULL,  -- base order amount
  amount_variant      numeric(12,2),           -- unique cents adjusted amount
  currency            text NOT NULL DEFAULT 'THB',
  emv                 text,                    -- EMV payload issued for this session
  ref_code            text,                    -- optional reference code for display/tracking
  expires_at          timestamptz NOT NULL,    -- absolute expiry time
  created_at          timestamptz NOT NULL DEFAULT now(),
  status              text NOT NULL DEFAULT 'pending', -- pending | approved | expired
  matched_message_id  text,                    -- from payments.message_id
  approved_amount     numeric(12,2),
  source              text,                    -- e.g. wp, api, etc.
  source_ip           inet                     -- client IP if captured
);

  -- Constrain status values
  DO $$
  BEGIN
    -- Ensure status column exists for legacy tables
    IF NOT EXISTS (
      SELECT 1 FROM information_schema.columns
      WHERE table_name='payment_sessions' AND column_name='status'
    ) THEN
      ALTER TABLE payment_sessions ADD COLUMN status text NOT NULL DEFAULT 'pending';
    END IF;

    -- Add constraint if not already present
    BEGIN
      ALTER TABLE payment_sessions
        ADD CONSTRAINT payment_sessions_status_chk
        CHECK (status IN ('pending','approved','expired'));
    EXCEPTION WHEN duplicate_object THEN
      -- already exists
      NULL;
    END;
  END$$;

CREATE INDEX IF NOT EXISTS idx_payment_sessions_order_created
  ON payment_sessions (order_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_payment_sessions_created
  ON payment_sessions (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_payment_sessions_status
  ON payment_sessions (status);
CREATE INDEX IF NOT EXISTS idx_payment_sessions_expires
  ON payment_sessions (expires_at);
-- For exact-amount + recency match
CREATE INDEX IF NOT EXISTS idx_payment_sessions_amount_variant_created
  ON payment_sessions (amount_variant, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_payment_sessions_ref_code
  ON payment_sessions (ref_code);

-- Optional FK (disabled by default; enable if you want coupling)
-- DO $$
-- BEGIN
--   ALTER TABLE payment_sessions
--     ADD CONSTRAINT fk_payment_sessions_payment
--     FOREIGN KEY (matched_message_id) REFERENCES payments(message_id);
-- EXCEPTION WHEN duplicate_object THEN NULL;
-- END$$;

------------------------------------------------------------
-- 2) Views
------------------------------------------------------------

-- Latest session per order for quick lookups
CREATE OR REPLACE VIEW v_latest_session_per_order AS
SELECT DISTINCT ON (order_id)
  order_id, session_token, status, amount, amount_variant, currency, emv,
  expires_at, created_at, matched_message_id, approved_amount, ref_code
FROM payment_sessions
WHERE order_id IS NOT NULL
ORDER BY order_id, created_at DESC;

------------------------------------------------------------
-- 3) Helper functions
------------------------------------------------------------

-- Upsert a payment row (used by Tasker/IMAP ingestion)
CREATE OR REPLACE FUNCTION fn_upsert_payment(
  p_message_id text,
  p_amount numeric,
  p_currency text DEFAULT 'THB',
  p_bank text DEFAULT NULL,
  p_ref text DEFAULT NULL,
  p_txn_time timestamptz DEFAULT NULL,
  p_created_at timestamptz DEFAULT now(),
  p_used boolean DEFAULT false,
  p_sender_name text DEFAULT NULL,
  p_receiver_name text DEFAULT NULL,
  p_raw jsonb DEFAULT NULL
) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO payments (message_id, amount, currency, bank, ref, txn_time, created_at, used, sender_name, receiver_name, raw)
  VALUES (p_message_id, p_amount, COALESCE(p_currency,'THB'), p_bank, p_ref, p_txn_time, COALESCE(p_created_at, now()), COALESCE(p_used,false), p_sender_name, p_receiver_name, p_raw)
  ON CONFLICT (message_id) DO UPDATE SET
    amount = EXCLUDED.amount,
    currency = EXCLUDED.currency,
    bank = EXCLUDED.bank,
    ref = EXCLUDED.ref,
    txn_time = EXCLUDED.txn_time,
    created_at = EXCLUDED.created_at,
    used = EXCLUDED.used,
    sender_name = COALESCE(EXCLUDED.sender_name, payments.sender_name),
    receiver_name = COALESCE(EXCLUDED.receiver_name, payments.receiver_name),
    raw = COALESCE(EXCLUDED.raw, payments.raw);
END$$;

-- Upsert a session (mirrors n8n ON CONFLICT logic)
CREATE OR REPLACE FUNCTION fn_upsert_session(
  p_session_token text,
  p_order_id text,
  p_amount numeric,
  p_amount_variant numeric,
  p_currency text,
  p_emv text,
  p_expires_at timestamptz,
  p_source text DEFAULT NULL,
  p_source_ip inet DEFAULT NULL
) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
  INSERT INTO payment_sessions(session_token, order_id, amount, amount_variant, currency, emv, expires_at, status, source, source_ip)
  VALUES (p_session_token, p_order_id, p_amount, p_amount_variant, COALESCE(p_currency,'THB'), p_emv, p_expires_at, 'pending', p_source, p_source_ip)
  ON CONFLICT (session_token) DO UPDATE SET
    order_id = EXCLUDED.order_id,
    amount = EXCLUDED.amount,
    amount_variant = EXCLUDED.amount_variant,
    currency = EXCLUDED.currency,
    emv = EXCLUDED.emv,
    expires_at = EXCLUDED.expires_at,
    source = COALESCE(EXCLUDED.source, payment_sessions.source),
    source_ip = COALESCE(EXCLUDED.source_ip, payment_sessions.source_ip),
    status = CASE
      WHEN payment_sessions.status = 'approved' THEN payment_sessions.status
      ELSE 'pending'
    END;
END$$;

-- Mark a payment as used (idempotent)
CREATE OR REPLACE FUNCTION fn_mark_payment_used(p_message_id text)
RETURNS void LANGUAGE sql AS $$
  UPDATE payments SET used = true WHERE message_id = p_message_id;
$$;

-- Expire overdue sessions (returns rows affected)
CREATE OR REPLACE FUNCTION fn_gc_expire_sessions()
RETURNS integer LANGUAGE plpgsql AS $$
DECLARE
  v_count int;
BEGIN
  UPDATE payment_sessions SET status='expired'
  WHERE status='pending' AND expires_at < now();
  GET DIAGNOSTICS v_count = ROW_COUNT;
  RETURN v_count;
END$$;

-- Match and approve a session by exact amount within time window (mirrors WP Order Status)
-- p_time_window_sec: e.g. 900 (15 minutes)
-- p_amount_tol: use 0 for exact match policy
CREATE OR REPLACE FUNCTION fn_match_and_approve_session(
  p_session_token text,
  p_time_window_sec int,
  p_amount_tol numeric
) RETURNS TABLE(message_id text, amount numeric) LANGUAGE plpgsql AS $$
BEGIN
  RETURN QUERY
  WITH params AS (
    SELECT s.created_at AS center,
           s.amount_variant::numeric AS want_amt,
           p_amount_tol::numeric AS tol,
           p_time_window_sec::int AS win
    FROM payment_sessions s
    WHERE s.session_token = p_session_token
  ),
  cte AS (
    SELECT p.message_id, p.amount
    FROM payments p, params
    WHERE p.used = false
      AND p.created_at BETWEEN (params.center - (params.win || ' seconds')::interval) AND now()
      AND p.amount BETWEEN (params.want_amt - params.tol) AND (params.want_amt + params.tol)
    ORDER BY p.created_at DESC
    LIMIT 1
  ),
  mark_pay AS (
    UPDATE payments p SET used=true FROM cte WHERE p.message_id = cte.message_id
  ),
  mark_sess AS (
    UPDATE payment_sessions s SET status='approved', matched_message_id=cte.message_id, approved_amount=cte.amount
    FROM cte
    WHERE s.session_token = p_session_token
  )
  SELECT message_id, amount FROM cte;
END$$;

------------------------------------------------------------
-- 4) (Optional) Migration helpers if you already ran older schema
------------------------------------------------------------
-- These will NO-OP if columns already exist.

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='payments' AND column_name='bank') THEN
    ALTER TABLE payments ADD COLUMN bank text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='payments' AND column_name='ref') THEN
    ALTER TABLE payments ADD COLUMN ref text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='payments' AND column_name='txn_time') THEN
    ALTER TABLE payments ADD COLUMN txn_time timestamptz;
  END IF;
END$$;

-- Validate status check constraint if created NOT VALID in older PG
-- ALTER TABLE payment_sessions VALIDATE CONSTRAINT payment_sessions_status_chk;

-- Backfill/migrate older payment_sessions tables to include columns used by workflows
DO $$
BEGIN
  -- Add missing optional columns (safe operations)
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='payment_sessions' AND column_name='emv') THEN
    ALTER TABLE payment_sessions ADD COLUMN emv text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='payment_sessions' AND column_name='amount_variant') THEN
    ALTER TABLE payment_sessions ADD COLUMN amount_variant numeric(12,2);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='payment_sessions' AND column_name='approved_amount') THEN
    ALTER TABLE payment_sessions ADD COLUMN approved_amount numeric(12,2);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='payment_sessions' AND column_name='matched_message_id') THEN
    ALTER TABLE payment_sessions ADD COLUMN matched_message_id text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='payment_sessions' AND column_name='source') THEN
    ALTER TABLE payment_sessions ADD COLUMN source text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='payment_sessions' AND column_name='source_ip') THEN
    ALTER TABLE payment_sessions ADD COLUMN source_ip inet;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='payment_sessions' AND column_name='ref_code') THEN
    ALTER TABLE payment_sessions ADD COLUMN ref_code text;
  END IF;
END$$;
