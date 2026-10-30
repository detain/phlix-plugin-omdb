# M-OMDB-06 — status: STALE-AT 91b69be

## Verdict — STALE-AT 91b69be

Claim (review era 5558183): an over-limit response is indistinguishable from
not-found and invites a retry-storm. Cured BEFORE this lane by 91b69be
('fix(client): separate over-limit from not-found + daily cooldown
[review omdb__secaudit]', ancestor of tip — merge-base verified). Content
proof at that commit: `isOverLimitEnvelope()` (markers 'daily limit'/'rate
limit'), `armOverLimitCooldown()` to next UTC midnight, dispatch gate at
httpGetJson logging 'OMDb request suppressed… (cooldown active)', plus pins
test_over_limit_envelope_logs_distinguishable_warning_and_returns_null,
_cooldown_suppresses_further_requests, _still_serves_cache_hits,
test_suppressed_request_is_logged_not_silent (all green in the 166 baseline).
No work required.
