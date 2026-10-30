# M-OMDB-09 — status: LANDED

## Verdict — LANDED ce261d4

Same defect family as M-OMDB-05 (queued separately by a different persona):
non-string JSON shapes in the 15 mapped text fields (array/int/bool/null)
were emitted verbatim under `array<string,mixed>`, poisoning downstream
persistence. Cure: shared `textField()` sanitizer (is_string && !== 'N/A'
gate) now covers shape-junk and the sentinel in one choke. Red-then-green:
`test_getDetails_maps_non_string_field_shapes_to_empty_strings` (measured
red at 5cbda26: "2010 is identical to ''").
