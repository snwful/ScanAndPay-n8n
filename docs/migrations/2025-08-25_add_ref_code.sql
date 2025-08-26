-- 2025-08-25 Add ref_code to payment_sessions
-- Safe, idempotent migration. Apply on existing databases.

-- 1) Add nullable ref_code column if missing
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'payment_sessions' AND column_name = 'ref_code'
  ) THEN
    ALTER TABLE payment_sessions ADD COLUMN ref_code text;
  END IF;
END$$;

-- 2) Create index on ref_code for quick lookups (idempotent)
CREATE INDEX IF NOT EXISTS idx_payment_sessions_ref_code
  ON payment_sessions (ref_code);

-- 3) Update helper view to include ref_code
CREATE OR REPLACE VIEW v_latest_session_per_order AS
SELECT DISTINCT ON (order_id)
  order_id, session_token, status, amount, amount_variant, currency, emv,
  expires_at, created_at, matched_message_id, approved_amount, ref_code
FROM payment_sessions
WHERE order_id IS NOT NULL
ORDER BY order_id, created_at DESC;
