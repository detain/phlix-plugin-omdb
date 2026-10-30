# M-OMDB-01 — status: LANDED

## Verdict — LANDED 7bedc1f

README.md:31 install URL pointed at `/main/plugin.json`; origin default branch
is `master` (ls-remote --symref verified) and a live probe of the raw URL
returned 404 vs 200 for `/master/`. Cure: single-line re-point to `/master/`.
