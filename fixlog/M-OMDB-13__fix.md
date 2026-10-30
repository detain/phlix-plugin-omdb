# M-OMDB-13 — status: LANDED

## Verdict — LANDED 93175ce

Dev/host library-generation skew: tested against the lock generation
(workerman/workerman ^4.0 → v4.2.1, http-client ^1.0 → v1.0.11) but runs on
the host's workerman 5.x / http-client ^3.x. The seam gated Timer::sleep on
`class_exists(Timer::class)` — TRUE on v4, where the METHOD does not exist:
any un-neutralized seam entry was a latent fatal, and phpstan.neon carried an
"provided by host environment" ignore papering over exactly that static
finding. Cure (review's sanctioned document-and-gate branch; require-dev
realignment = composer.lock churn, forbidden in-lane): select on
`method_exists(Timer::class, 'sleep')` so the v4 arm falls through to usleep;
generation-contract docblock on `getHttpClient()` (only both-generation
surface — request($url,$options) + success/error callbacks + stream body);
two tripwires: `test_default_timer_seam_survives_generations_without_timer_sleep`
(red at fbe57ea: Error on undefined Timer::sleep) and
`test_sleep_vectors_stay_behind_the_generation_guards` (source-scan: exactly
one usleep = the seam fallback, one guarded Timer::sleep arm). phpstan.neon
ignore PRUNED — zero ignores now, stricter than found (removal, not addition).
