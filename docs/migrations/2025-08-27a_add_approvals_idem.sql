-- 2025-08-27 Add source/idempotency columns to approvals
-- Safe, idempotent migration. Apply on existing databases.

DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_name = 'approvals'
  ) THEN
    ALTER TABLE approvals
      ADD COLUMN IF NOT EXISTS source text,
      ADD COLUMN IF NOT EXISTS idempotency_key text,
      ADD COLUMN IF NOT EXISTS last_seen_at timestamptz NOT NULL DEFAULT now();
  END IF;
END$$;
