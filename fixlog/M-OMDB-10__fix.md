# M-OMDB-10 — status: STALE-AT 91b69be

## Verdict — STALE-AT 91b69be

Claim: cache/cooldown state does not survive workers/restart (per-process).
91b69be is the cure with an honest, deliberate design statement — class
docblock: "The cooldown is process-local by design — the plugin owns no
cross-worker store (persisting across restarts is a known follow-up); the
host-side SourceRateLimiter in phlix-server paces dispatch (~90s spacing for
omdb) as the belt, this cooldown is the braces." Making it cross-worker
needs shared-state infrastructure the plugin must not own (would be an
owner-gated architectural change, not a fix). Behavior pins green in the
baseline; no new work.
