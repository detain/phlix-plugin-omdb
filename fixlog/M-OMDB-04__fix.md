# M-OMDB-04 — status: LANDED

## Verdict — LANDED fbe57ea

`src/OmdbApiException.php` was a phantom: zero throw or catch sites anywhere
in src/ (re-grepped at tip — only self-reference + its own test), and zero
references estate-wide (grep across all /home/sites/phlix repos). The real
failure taxonomy is documented silent-null/`[]` (OmdbApi class docblock).
Cure: DELETE class + `tests/Unit/OmdbApiExceptionTest.php`. Suite
disclosure: −5 tests / −7 assertions (168/417 after). Not consumer-visible:
deleting a type nothing catches removes no catch-ability.
