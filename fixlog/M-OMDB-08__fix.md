# M-OMDB-08 — status: LANDED

## Verdict — LANDED 93175ce

`waitForResponse()` busy-polled with a raw `usleep(10_000)` — bypassing the
shared `$timerSleep` choke the host controls, blocking the worker instead of
yielding, and previously the whole loop was unexercised. Cure: every tick
routes through `($this->timerSleep)(0.01)`. Red-then-green:
`test_wait_for_response_ticks_route_through_the_timer_sleep_seam` (neutralized
seam + `OmdbFakeHttpClient::neverCompletes()` — seam never called at the old
tip: ticks=0 red; null result asserted; also proves the timeout bound with a
transport that never calls back). The fake's never-completes mode is the
"response never arrives" venue. The false 'Uses cooperative yielding when
inside a Swoole coroutine' docblock premise was corrected in the same cure —
there was no coroutine detection anywhere in the file.
