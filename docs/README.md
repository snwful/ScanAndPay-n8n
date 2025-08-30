# Scan & Pay (n8n) — Docs Quick Notes

## Tasker Ingest v5.4 — Approvals UPSERT Gating (Quick Note)

Purpose: ensure idempotent approval writes using `(source, idempotency_key)` while refreshing `last_seen_at` for duplicates — without changing business behavior.

- Idempotency:
  - `source`: `tasker`
  - `idempotency_key`: prefer `message_id`; else `md5(session_token || '|' || posted_at)`
- Gating behavior:
  - First event inserts and returns a row → proceed with session update + callback
  - Duplicate events update `last_seen_at` only and return no rows → treat as duplicate; do not callback again
- n8n (v5.4) Postgres node SQL (CTE pattern):

```
WITH ins AS (
  INSERT INTO approvals (
    source, idempotency_key, session_token,
    approved_amount, matched_at, ref_code, message_id, last_seen_at
  ) VALUES (
    'tasker',
    COALESCE({{$json.message_id || null}}, md5('{{$json.session_token}}' || '|' || {{$json.posted_at}}::text)),
    '{{$json.session_token}}',
    {{$json.amount}}::numeric,
    to_timestamp({{$json.posted_at}}),
    {{$json.ref_code || null}},
    {{$json.message_id || null}},
    now()
  )
  ON CONFLICT (source, idempotency_key) DO NOTHING
  RETURNING session_token
), upd AS (
  UPDATE approvals SET last_seen_at = now()
  WHERE source = 'tasker'
    AND idempotency_key = COALESCE({{$json.message_id || null}}, md5('{{$json.session_token}}' || '|' || {{$json.posted_at}}::text))
    AND NOT EXISTS (SELECT 1 FROM ins)
)
SELECT session_token FROM ins;
```

- Migration/Docs alignment:
  - Approvals table has `source`, `idempotency_key`, `last_seen_at`, and UNIQUE `(source,idempotency_key)`
  - Docs UPSERT example includes `last_seen_at` in `INSERT` and updates it on conflict
- v5.5+: superseded by stored function `fn_match_and_approve_session(...)` which performs the same idempotent logic transactionally.

ไทยย่อ: v5.4 ปรับมาใช้คีย์คู่ `(source,idempotency_key)` สำหรับ dedupe และอัปเดต `last_seen_at` เมื่อเจอซ้ำ โดยใช้ CTE เดียวกันใน Postgres node — insert ครั้งแรกจะ return แถว (ไปต่อ), เคสซ้ำจะไม่ return (ถือเป็น duplicate ไม่ส่ง callback ซ้ำ)

