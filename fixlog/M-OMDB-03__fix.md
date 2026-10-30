# M-OMDB-03 — status: LANDED

## Verdict — LANDED 5cbda26

README "Rate limiting — configurable request throttling" was false:
`RATE_LIMIT_INTERVAL_SEC = 0.25` is a private const with no setting seam, and
there is no daily request counter (the plugin reacts to OMDb's over-limit
envelope by cooling down to next UTC midnight — 91b69be era). Cure: bullet
re-worded to the implemented truth (fixed 0.25s spacing + over-limit cooldown;
interval explicitly "not a setting"). Promoting the interval to a setting would
be a feature/contract change — owner-gated, out of fix-lane scope.
