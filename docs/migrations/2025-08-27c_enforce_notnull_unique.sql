-- 2025-08-27 Enforce NOT NULL and UNIQUE constraint on approvals idempotency
-- Safe, idempotent migration. Apply after backfill.

DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns WHERE table_name='approvals' AND column_name='source'
  ) AND EXISTS (
    SELECT 1 FROM information_schema.columns WHERE table_name='approvals' AND column_name='idempotency_key'
  ) THEN
    IF EXISTS (SELECT 1 FROM approvals WHERE source IS NULL OR idempotency_key IS NULL) THEN
      RAISE EXCEPTION 'approvals contains NULL source or idempotency_key; run backfill first';
    END IF;
    ALTER TABLE approvals
      ALTER COLUMN source SET NOT NULL,
      ALTER COLUMN idempotency_key SET NOT NULL;
    CREATE UNIQUE INDEX IF NOT EXISTS approvals_source_idempotency_key_uq
      ON approvals (source, idempotency_key);
  END IF;
END$$;
