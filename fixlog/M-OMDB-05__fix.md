# M-OMDB-05 — status: LANDED

## Verdict — LANDED ce261d4

`OmdbPlugin::getDetails()` mapped its 15 text fields with bare
`$details['X'] ?? ''`, so OMDb's literal `'N/A'` "no value" sentinel passed
through as data (genre/director/awards/etc.). Cure: `private static
textField(array, key): string` — only genuine non-sentinel strings pass, else
`''` — applied to all 15 mapped fields (same idiom getImages() already used
for Poster). Red-then-green: `test_getDetails_maps_na_sentinels_to_empty_strings`.
Plugin-internal shaping only; zero wire change.
