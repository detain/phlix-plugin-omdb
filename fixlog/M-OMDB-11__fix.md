# M-OMDB-11 — status: STALE-AT 91b69be

## Verdict — STALE-AT 91b69be

Claim (srphp era): a transient rate-limit response is cached like any 200 and
becomes a silent 24h per-URL hole. Cured by 91b69be: `admitResponse()` is the
single choke and "an over-limit envelope is never cached (a day-stale
'not-found' lie is the failure mode this prevents)" — pinned by
test_over_limit_envelope_is_never_cached (line 1338 at tip, green in the
pre-lane 166 baseline). The plain not-found-cached complement stays
deliberate (docblock: caching "amortizes quota"; pinned by
test_not_found_envelope_stays_silent_and_does_not_arm_cooldown). Disclosure:
the cache-write arm of admitResponse still returns the array with the
injected '_cached_at' key visible to callers (m-class cosmetic; not this
queue item).
