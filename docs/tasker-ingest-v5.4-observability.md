# Tasker Ingest v5.4 – Error Paths & Metrics

Version 5.4 introduces explicit `onError` branches for HMAC verification, Postgres writes, session lookup, approvals persistence, and the async WordPress callback. Failures route to a dedicated **Log Error** node which emits structured JSON:

```json
{ "correlation_id": "…", "session_token": "…", "reason": "…", "idem_drop": 0, "retry_attempt": 0, "retry_success": 0, "retry_fail": 1 }
```

These counters support downstream dashboards and safe retries. Core matching behavior and the ±600s window remain unchanged.
