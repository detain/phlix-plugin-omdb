# M-OMDB-07 — status: LANDED

## Verdict — LANDED ce261d4

`OmdbApi::getByImdbId()` only rejected the literal `'Response' => 'False'`
string and returned everything else raw — a body with NO Response member
(proxy error JSON, foreign 200s) or with junk Title shapes leaked into the
host resolver as if validated. Cure: require `($data['Response'] ?? '') ===
'True'` AND a non-empty string `Title`, else null. Red-then-green: 5 new
gate tests in OmdbApiTest (missing/junk Response; missing/empty/non-string
Title). All existing fixtures already carried Response:True + Title —
166-test baseline held green UNMODIFIED; search() untouched (already
shape-safe). Field-type hardening folded into M-05's textField.
