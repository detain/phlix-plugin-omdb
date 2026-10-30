# M-OMDB-02 — status: LANDED

## Verdict — LANDED 5cbda26

Headline claims (composer.json description + `metascore` keyword, README.md:5
headline, README body "enrich ... with ... Metascore ratings") promised Metascore
delivery while `OmdbPlugin::getDetails()` deliberately DROPS it — no valid
`metadata_ratings.source` ENUM member — a behavior pinned by
`test_getDetails_drops_metascore`. Cure: prose re-worded to the delivered truth
(IMDb ID + imdb/rt ratings; Metascore read-but-dropped); keyword removed.
composer validate --strict green; no code/wire change, no consumer break.
