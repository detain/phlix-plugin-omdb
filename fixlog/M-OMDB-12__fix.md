# M-OMDB-12 — status: LANDED

## Verdict — LANDED 6114712

The success-callback arm decoded with `json_decode(..., JSON_THROW_ON_ERROR)`
unguarded: a 200 whose body is not JSON (Cloudflare challenge HTML from a
transparent proxy) threw JsonException into client internals; a scalar JSON
document (e.g. `42`) TypeError-ed through `admitResponse`'s `?array`
parameter. Measured red both ways (JsonException + TypeError tracebacks at
fbe57ea). Cure: try/catch routes JsonException to the existing redacted
'OMDb HTTP error' warning sink (message carries no URL/key); decoded
non-array gets a generic same-sink error, body never echoed. Infrastructure:
`OmdbFakeHttpClient::withBody()` models the success arm with a REAL
Workerman\Http\Response — plus `test_valid_json_success_body_admits_the_record`
closes the never-tested success path (green before/after, pinned contrast).
