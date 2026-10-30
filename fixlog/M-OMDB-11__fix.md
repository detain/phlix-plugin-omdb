# M-OMDB-11 — Error envelopes are cached for the full TTL (r2 rework)

## History of this item
Prior disposition "STALE-AT 91b69be" was OVERTURNED by the independent r1
review (.reviews/fixlog/M-OMDB-11__review.md — verdict REWORK). The stale
claim held the item cured by 91b69be's `admitResponse()` choke; the reviewer
proved the choke skips the cache ONLY for the two over-limit markers
('daily limit' / 'rate limit', src/OmdbApi.php OVER_LIMIT_ERROR_MARKERS) —
the claim's second named harm, a BAD-KEY response, was still cached for the
full 86400s success TTL at tip 866431b and replayed as a cache hit, exactly
the "silent 24-hour per-URL lookup hole" the queue words describe.

## What was actually missing
A non-over-limit `Response:'False'` envelope (e.g. `{"Response":"False",
"Error":"Invalid API key."}`) fell through `admitResponse()` to the
unconditional cache write. Unlike a per-title not-found, an auth/request-
state failure is key-state truth wearing a URL-shaped payload: after the
operator fixes the key, every URL touched during the outage keeps replaying
the 401-shaped error for the rest of the TTL.

## Cure (fix commit named in the verdict below)
`admitResponse()` cache write now passes through `isCacheableEnvelope()`:
success payloads keep the long-TTL design; among negatives (over-limit ones
still never reach the write), ONLY the per-title not-found family
(NOT_FOUND_ERROR_MARKERS = ['not found'] — 'Movie/Series/Episode not found!')
stays cached. That exception is deliberate quota design explicitly allowed by
the r1 review ("the cure may keep that exception if pinned") and pinned. The
taxonomy is cache-ALLOWLISTED, so unknown, missing, non-string, and future
error strings land on the safe side (never cached) instead of inheriting a
day of immunity. No wire-taxonomy change: negative envelopes still flow to
callers verbatim (getByImdbId/search map them to null/[] as before); only
the cache admission is new law.

## Red-then-green raws (host PHP 8.3.6, serial, hermetic jsonFetcher seam)
- Baseline (pre-lane): `OK (174 tests, 433 assertions)`.
- New pins on OLD law: `Tests: 177, Assertions: 441, Failures: 3` —
  test_bad_key_envelope_is_never_cached (assertSame [] vs cached envelope),
  test_repaired_key_is_not_shadowed_by_a_cached_error_envelope ("Failed
  asserting that null is not null" — call 2 replayed the cached bad-key),
  test_unknown_negative_envelope_shapes_are_never_cached. The extended
  not-found complement pin (call-count == 1 on second lookup) is green on
  both laws by design: it guards the exception against over-broad cures.
- After cure: `OK (177 tests, 447 assertions)` (+3 tests; +14 assertions
  attributed: 2 bad-key + 4 shadow-recovery + 6 unknown-shapes + 2 pin
  extension).
- Mutations, both killed: M1 strip the `isCacheableEnvelope` guard (old law
  restored) → exactly the 3 new tests red (177/441/3F); M2 empty
  NOT_FOUND_ERROR_MARKERS (exception silently dropped) → exactly
  test_not_found_envelope_stays_silent_and_does_not_arm_cooldown red
  (177/445/1F).
- Gates: composer phpstan (level 9, zero ignores) `[OK] No errors`;
  phpcs PSR12 src clean; composer validate --strict valid; M-OMDB-13
  sleep-vectors source tripwire green (change touches no sleep vector).
- Bright-lines: scripts/security-audit-check.php md5 40aec284… and
  tests/Unit/SecurityAuditCheckTest.php md5 1baee43b… byte-ident before and
  after (git diff = 0 lines).
- No CHANGELOG.md exists in this repo (plugin-family convention — verified
  at tip, not created); no tags; no new wire codes.

## Verdict — LANDED baac541321cf014a15f22d9aea8cf5e211bbf355
