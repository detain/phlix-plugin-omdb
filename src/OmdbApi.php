<?php

/**
 * OMDb API HTTP client.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\Omdb;

use Psr\Log\LoggerInterface;
use Workerman\Http\Client;
use Workerman\Http\Response;
use Workerman\Timer;
use Workerman\Worker;

/**
 * HTTP client for the OMDb API.
 *
 * Provides search, title lookup, and handles rate limiting, daily-limit
 * cooldown, caching, SSL verification toggling, and async/non-blocking
 * patterns.
 *
 * ## Daily-limit posture (free tier: 1,000 requests/day)
 *
 * OMDb signals quota exhaustion with a `{"Response":"False","Error":"API key
 * is over its daily limit."}` envelope (as HTTP 200 or 403 — the vendored
 * transport surfaces both identically). {@see httpGetJson()} classifies that
 * envelope apart from a plain "title not found", logs a distinguishable
 * warning, and cools the key down until the next UTC midnight (the reset
 * boundary): further cache-miss lookups short-circuit without touching the
 * network, so one dead key cannot turn into a retry-storm against a wall.
 * The cooldown is process-local by design — the plugin owns no cross-worker
 * store (persisting across restarts is a known follow-up); the host-side
 * `SourceRateLimiter` in phlix-server is the belt (it paces dispatch at
 * ~90s spacing for omdb), this cooldown is the braces.
 */
final class OmdbApi
{
    /**
     * OMDb API host — the single origin this client ever dials. Pinned as
     * the TLS `peer_name` so certificate-name verification is explicit on
     * every http-client generation ([PG-193], see {@see tlsContext()}).
     */
    private const API_HOST = 'www.omdbapi.com';

    /**
     * OMDb API base URL. HTTPS only — never plaintext http.
     */
    private const API_BASE = 'https://' . self::API_HOST;

    /**
     * HTTP request timeout in seconds.
     */
    private const HTTP_TIMEOUT_SEC = 10;

    /**
     * Class name the Swoole event adapter carries in
     * {@see \Workerman\Worker}::$eventLoopClass when the host runs
     * `Worker::$eventLoopClass = Workerman\Events\Swoole` (set in production
     * at phlix-server start.php). Compared as a STRING so this file never
     * loads — or requires — ext-swoole [PG-194].
     */
    private const SWOOLE_EVENT_LOOP_CLASS = 'Workerman\\Events\\Swoole';

    /**
     * Minimum interval between API requests in seconds (rate protection).
     */
    private const RATE_LIMIT_INTERVAL_SEC = 0.25;

    /**
     * Mask written in place of the apikey value on every log/exception surface.
     */
    public const MASKED_APIKEY = '***';

    /**
     * Lower-cased substrings of the envelope Error field that mean "this key
     * has spent its daily request allowance" (OMDb has shipped both the
     * "daily limit" and the "rate limit" wording).
     *
     * @var list<string>
     */
    private const OVER_LIMIT_ERROR_MARKERS = ['daily limit', 'rate limit'];

    /**
     * Lower-cased substrings of the envelope Error field that mean "this
     * URL's title simply is not in the database" (OMDb has shipped the
     * 'Movie not found!' / 'Series not found.' / 'Episode not found.'
     * family). The only negative whose truth belongs to the queried URL
     * rather than the key or the day, so it may be replayed from cache.
     *
     * @var list<string>
     */
    private const NOT_FOUND_ERROR_MARKERS = ['not found'];

    /** Seconds in a UTC day; OMDb daily limits roll over at UTC midnight. */
    private const UTC_DAY_SECONDS = 86400;

    /**
     * In-memory response cache: md5(url) => decoded JSON array.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    /**
     * Monotonic seconds reading ({@see clock()} — hrtime based, not a Unix
     * wall-clock timestamp) at the last API request dispatch, for rate
     * limiting. Only ever compared against clock() values.
     */
    private float $lastRequestTimestamp = 0.0;

    /**
     * Unix (UTC) timestamp until which HTTP dispatch is suppressed after an
     * over-limit response; 0 when never armed. Process-local by design — see
     * the class docblock's daily-limit posture.
     */
    private int $overLimitCooldownUntil = 0;

    /**
     * Sleep function for rate-limit delays.
     *
     * @var \Closure(float): void
     */
    private \Closure $timerSleep;

    /**
     * Shared HTTP client instance.
     */
    private ?Client $httpClient = null;

    /**
     * Test seam: when set, replaces the network call with a caller-supplied
     * decoder. Signature: `function(string $url): ?array`. Left null in
     * production so the real non-blocking Workerman client is used.
     *
     * @var (\Closure(string): (array<string, mixed>|null))|null
     * @internal Tests only.
     */
    private ?\Closure $jsonFetcher = null;

    /**
     * Blocking-cURL transport seam for the [PG-194] event-loop TLS fallback.
     * Returns the raw response body plus a transport error string (empty on
     * success): `function(string $url): array{body: string, error: string}`.
     * Defaults to the real cURL round trip; tests override it so the reroute
     * law is assertable without ever touching the network.
     *
     * @var \Closure(string): array{body: string, error: string}
     */
    private \Closure $curlTransport;

    /**
     * @param string $apiKey OMDb API key
     * @param bool $useSslVerification Whether to verify TLS certificates
     * @param int $cacheTtlSeconds Cache TTL in seconds (0 = disabled)
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     * @param (\Closure(string): (array<string, mixed>|null))|null $jsonFetcher Test seam replacing the network call
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly bool $useSslVerification = true,
        private readonly int $cacheTtlSeconds = 86400,
        private readonly ?LoggerInterface $logger = null,
        ?\Closure $jsonFetcher = null,
    ) {
        $this->jsonFetcher = $jsonFetcher;
        $this->curlTransport = function (string $url): array {
            return $this->performCurlRequest($url);
        };
        $this->timerSleep = static function (float $seconds): void {
            // [M-OMDB-13] Select on the METHOD, not the class: workerman v4
            // (this repo's lock generation) ships Timer without sleep(), so
            // class_exists used to green-light a call to a nonexistent
            // method — fatal the moment the seam ran un-neutralized. v5
            // hosts get the cooperative sleep; anything older falls back.
            if (method_exists(Timer::class, 'sleep')) {
                Timer::sleep($seconds);
            } else {
                usleep((int) round($seconds * 1_000_000));
            }
        };
    }

    /**
     * Get the current monotonic time in seconds.
     *
     * @return float
     */
    private function clock(): float
    {
        return (hrtime(true) / 1_000_000_000.0);
    }

    /**
     * Search OMDb by title, with an optional year hint.
     *
     * [PG-083] Year-hint truth: the `y=` parameter is sent alongside `s=`,
     * but OMDb documents `y` as a disambiguator for the `t=` single-title
     * lookup — whether the search arm honors it at all is not contractual
     * (and this plugin's quota-bound tests cannot probe the live API).
     * Treat the year as a hint: results are NOT guaranteed to be
     * year-filtered, so callers needing a strict filter must match the
     * returned `year` field themselves.
     *
     * @param string $title Title to search for
     * @param int|null $year Optional release year, sent as a hint (see above)
     * @return list<array{imdb_id: non-empty-string, title: string, year: string, type: string}>
     */
    public function search(string $title, ?int $year = null): array
    {
        $params = ['s' => $title, 'apikey' => $this->apiKey];
        if ($year !== null) {
            $params['y'] = (string) $year;
        }

        $url = self::API_BASE . '/?' . http_build_query($params);
        $data = $this->httpGetJson($url);

        if ($data === null) {
            return [];
        }

        if (($data['Response'] ?? '') === 'False') {
            return [];
        }

        $results = $data['Search'] ?? null;
        if (is_array($results) === false) {
            return [];
        }

        $items = [];
        foreach ($results as $result) {
            if (is_array($result) === false) {
                continue;
            }

            $imdbId = (is_string($result['imdbID'] ?? null) ? $result['imdbID'] : '');
            if ($imdbId === '') {
                continue;
            }

            $items[] = [
                'imdb_id' => $imdbId,
                'title'   => (is_string($result['Title'] ?? null) ? $result['Title'] : ''),
                'year'    => (is_string($result['Year'] ?? null) ? $result['Year'] : ''),
                'type'    => (is_string($result['Type'] ?? null) ? $result['Type'] : ''),
            ];
        }

        return $items;
    }

    /**
     * Fetch full details for an IMDb ID.
     *
     * @param string $imdbId IMDb ID — must match `^tt\d{7,10}$` (e.g.,
     *                       "tt0120737"); non-conforming strings fail to
     *                       null without dispatching
     * @return array<string, mixed>|null Details array or null on failure
     */
    public function getByImdbId(string $imdbId): ?array
    {
        // [M-OMDB-08 minor] Format gate ahead of dispatch: an OMDb id is
        // 'tt' + 7-10 digits. Junk (a raw title, a short stub, an
        // over-long random run) can never return a record — it only burns
        // daily quota on an error envelope. Fail taxonomy: null, matching
        // every other rejection in this method.
        if (preg_match('/^tt\d{7,10}$/', $imdbId) !== 1) {
            return null;
        }

        $params = ['i' => $imdbId, 'apikey' => $this->apiKey, 'plot' => 'full'];
        $url = self::API_BASE . '/?' . http_build_query($params);

        $data = $this->httpGetJson($url);
        if ($data === null) {
            return null;
        }

        // [M-OMDB-07] Only a validated success envelope is a record: the
        // Response member must literally be 'True' and Title must be a real
        // non-empty string. Bodies missing Response entirely (or carrying
        // junk) are rejected to null instead of leaking to the host resolver
        // as metadata; the old gate only rejected the 'False' string.
        if (($data['Response'] ?? '') !== 'True') {
            return null;
        }

        $title = $data['Title'] ?? null;
        if (is_string($title) === false || $title === '') {
            return null;
        }

        return $data;
    }

    /**
     * Extract ratings from an OMDb details response.
     *
     * @param array<string, mixed> $details OMDb details response
     * @return array{imdb: float|null, rotten_tomatoes: float|null, metascore: float|null}
     */
    public static function extractRatings(array $details): array
    {
        $imdb = null;
        $rottenTomatoes = null;
        $metascore = null;

        // Extract IMDb rating.
        $imdbRating = $details['imdbRating'] ?? null;
        if (is_string($imdbRating) && $imdbRating !== 'N/A' && is_numeric($imdbRating)) {
            $imdb = (float) $imdbRating;
        }

        // Extract ratings from the Ratings array
        $ratings = ($details['Ratings'] ?? null);
        if (is_array($ratings)) {
            foreach ($ratings as $rating) {
                if (is_array($rating) === false) {
                    continue;
                }

                $source = (is_string($rating['Source'] ?? null) ? $rating['Source'] : '');
                $value = (is_string($rating['Value'] ?? null) ? $rating['Value'] : '');

                if ($source === 'Rotten Tomatoes' && $value !== 'N/A') {
                    // RT returns "95%" format
                    $rottenTomatoes = self::parsePercentage($value);
                } elseif ($source === 'Metacritic' && $value !== 'N/A') {
                    // Metascore returns "72/100" format
                    $metascore = self::parseFraction($value);
                }
            }
        }

        return [
            'imdb' => $imdb,
            'rotten_tomatoes' => $rottenTomatoes,
            'metascore' => $metascore,
        ];
    }

    /**
     * Parse a percentage string like "95%" to a 0-10 float.
     */
    private static function parsePercentage(string $value): ?float
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*%$/', $value, $matches) === 1) {
            $num = (float) $matches[1];
            // OMDb returns 0-100, normalize to 0-10
            return round($num / 10.0, 1);
        }

        return null;
    }

    /**
     * Parse a fraction string like "72/100" to a 0-10 float.
     */
    private static function parseFraction(string $value): ?float
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*\/\s*100$/', $value, $matches) === 1) {
            $num = (float) $matches[1];
            // OMDb returns 0-100, normalize to 0-10
            return round($num / 10.0, 1);
        }

        return null;
    }

    /**
     * Mask the apikey wherever it appears in a string — instance-aware.
     *
     * OMDb authenticates with a query-string key, so any URL (or exception
     * message embedding one) must pass through here before it can reach a
     * log or exception surface. Two passes, two shapes [PG-197]:
     *
     * 1. The named-parameter pattern: the masked run stops at the first
     *    delimiter a percent-encoded value can never contain ('&',
     *    whitespace, a quote, or the '#' fragment start) so surrounding
     *    diagnostics stay readable; '=' deliberately stays inside the run
     *    because a copied URL could carry it. This pass scrubs ANY key in
     *    that parameter shape — including a rotated-away key still embedded
     *    in an old URL surfaced by the transport.
     * 2. The literal value of this instance's key, wherever it appears bare
     *    (a future transport diagnostic echoing the key without its
     *    parameter name would otherwise slip past pass 1). Skipped for an
     *    empty key, which as a search term would shred every message.
     */
    private function redactSecrets(string $text): string
    {
        $masked = (string) preg_replace('/apikey=[^&\s"\'#]+/i', 'apikey=' . self::MASKED_APIKEY, $text);

        if ($this->apiKey !== '') {
            $masked = str_replace($this->apiKey, self::MASKED_APIKEY, $masked);
        }

        return $masked;
    }

    /**
     * Perform a GET request and decode the JSON body.
     *
     * Applies the daily-limit cooldown gate, rate limiting, in-memory cache,
     * and optional SSL verification. Over-limit envelopes are surfaced as a
     * distinguishable warning + null (never a stale cached not-found); other
     * negative envelopes flow through to the callers unchanged, and only the
     * per-title not-found family is cached ([M-OMDB-11 r2]: key-state and
     * request-state failures bypass the replay cache — see
     * {@see isCacheableEnvelope()}).
     *
     * @param string $url Absolute OMDb API URL
     * @return array<string, mixed>|null Decoded JSON or null on failure.
     *         Cache bookkeeping ('_cached_at') is stamped on the stored copy
     *         only — miss-path and hit-path returns have identical shape.
     */
    private function httpGetJson(string $url): ?array
    {
        $cacheKey = md5($url);
        if ($this->cacheTtlSeconds > 0 && isset($this->cache[$cacheKey])) {
            $cached = $this->cache[$cacheKey];
            if (($cached['_cached_at'] ?? 0) > ($this->clock() - $this->cacheTtlSeconds)) {
                unset($cached['_cached_at']);
                /** @var array<string, mixed> $cached */
                return $cached;
            }
        }

        // Cache hits above cost zero quota; misses during a cooldown must not
        // burn the dead key again until its UTC-day reset.
        if ($this->overLimitCooldownUntil > time()) {
            $this->logger?->info('OMDb request suppressed: API key over its daily limit (cooldown active)', [
                'url' => $this->redactSecrets($url),
            ]);

            return null;
        }

        $this->enforceRateLimit();

        if ($this->jsonFetcher !== null) {
            /** @var array<string, mixed>|null $response */
            $response = ($this->jsonFetcher)($url);

            return $this->admitResponse($response, $url, $cacheKey);
        }

        // [PG-194] Event-loop TLS law: under the Swoole adapter, the async
        // client's TLS reads stall after the handshake, so every https cache
        // miss would burn the full timeout and die. Reroute to the bounded
        // blocking cURL transport instead of letting it false-timeout.
        if ($this->requiresBlockingCurl($url) === true) {
            $response = $this->httpGetJsonCurl($url);

            return $this->admitResponse($response, $url, $cacheKey);
        }

        $state = ['response' => null, 'error' => null, 'done' => false];
        $client = $this->getHttpClient();

        $client->request($url, [
            // Redirect kill-out: the key-bearing request must never be
            // re-dispatched to a Location target (an http:// downgrade or a
            // foreign host would leak the apikey). max=0 makes the vendored
            // client's guardMax() refuse the first hop through the error
            // arm; the 3xx guard below backstops any generation that does
            // not honor the key (same posture as the cURL seam's
            // FOLLOWLOCATION=false).
            'allow_redirects' => ['max' => 0],
            'success' => function (Response $response) use (&$state): void {
                $status = $response->getStatusCode();
                if ($status >= 300 && $status < 400) {
                    $state['error'] = new \RuntimeException('OMDb redirect refused (HTTP ' . $status . ')');
                    $state['done'] = true;

                    return;
                }

                $body = $response->getBody();
                $contents = $body->getContents();
                if ($contents !== '') {
                    try {
                        /** @var mixed $decoded */
                        $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $e) {
                        // [M-OMDB-12] A 200 with a non-JSON body (e.g. a
                        // Cloudflare challenge page from a transparent proxy)
                        // must never escape into client internals — route it
                        // through the error sink (message carries no URL).
                        $state['error'] = $e;
                        $state['done'] = true;

                        return;
                    }

                    if (is_array($decoded) === false) {
                        // Scalar JSON documents decode fine but are not an
                        // envelope; same sink. Body text is not echoed.
                        $state['error'] = new \RuntimeException('OMDb response body is not a JSON object');
                        $state['done'] = true;

                        return;
                    }

                    /** @var array<string, mixed> $decoded */
                    $state['response'] = $decoded;
                }
                $state['done'] = true;
            },
            'error' => function (\Throwable $e) use (&$state): void {
                $state['error'] = $e;
                $state['done'] = true;
            },
        ]);

        $this->waitForResponse($state);

        // [PG-195] The backstop was the only silent failure class: a wedged
        // transport (no callback ever fires) resolved to null with zero log
        // output. A stalled transport must announce itself like every other
        // one does — URL redacted at the sink, same as the error arm below.
        if ($state['done'] === false) {
            $this->logger?->warning('OMDb request timed out: no transport callback before the poll backstop', [
                'url' => $this->redactSecrets($url),
                'backstop_sec' => self::HTTP_TIMEOUT_SEC + 1,
            ]);

            return null;
        }

        if ($state['error'] !== null) {
            $error = $state['error'];
            $errorMessage = $error instanceof \Throwable ? $error->getMessage() : 'Unknown error';
            // Both values below can carry the key-bearing request URL (the URL
            // itself, or an exception message that embeds it) — redact at the
            // sink so the secret never reaches a log stream. Review omdb__srphp C1.
            $this->logger?->warning('OMDb HTTP error', [
                'url' => $this->redactSecrets($url),
                'error' => $this->redactSecrets($errorMessage),
            ]);

            return null;
        }

        /** @var array<string, mixed>|null $response */
        $response = $state['response'];

        return $this->admitResponse($response, $url, $cacheKey);
    }

    /**
     * Admit a decoded response to the caller (and the cache) — or reject it.
     *
     * The single choke point both transports (test seam and Workerman client)
     * funnel through: an over-limit envelope is never cached (a day-stale
     * "not found" lie is the failure mode this prevents) and never returned
     * verbatim; it arms the cooldown and presents as null, matching the
     * client's existing silent-null failure taxonomy while the warning log
     * keeps it distinguishable from a real not-found. What may reach the
     * cache at all is the envelope-eligibility law in
     * {@see isCacheableEnvelope()}.
     *
     * @param array<string, mixed>|null $response Decoded JSON body or null
     * @param string $url Request URL, for the log surface
     * @param string $cacheKey Cache slot for this URL
     * @return array<string, mixed>|null The response, or null when rejected
     */
    private function admitResponse(?array $response, string $url, string $cacheKey): ?array
    {
        if ($response === null) {
            return null;
        }

        if (self::isOverLimitEnvelope($response)) {
            $this->armOverLimitCooldown($url);

            return null;
        }

        if ($this->cacheTtlSeconds > 0 && self::isCacheableEnvelope($response)) {
            // [M-OMDB-09/15/11 minors] Cache-bookkeeping law: the '_cached_at'
            // stamp lives ONLY on the stored copy — the caller receives the
            // envelope exactly as decoded, so fresh-miss and replay-hit
            // returns have identical shape (the miss path used to leak the
            // stamp). Expired entries are evicted at write time (entries
            // merely bypassed on read used to leak until process death).
            $now = $this->clock();
            $deadline = $now - $this->cacheTtlSeconds;
            foreach ($this->cache as $key => $entry) {
                if (($entry['_cached_at'] ?? 0) <= $deadline) {
                    unset($this->cache[$key]);
                }
            }

            $stamped = $response;
            $stamped['_cached_at'] = $now;
            $this->cache[$cacheKey] = $stamped;
        }

        return $response;
    }

    /**
     * Whether a decoded envelope may enter the replay cache.
     *
     * Only URL-stable truth survives a replay. Success payloads keep the
     * long-TTL design; among negatives (over-limit ones never reach here)
     * only the per-title "not found" family is cacheable — its answer
     * describes the queried title, so replay amortizes quota (deliberate
     * design, pinned by
     * test_not_found_envelope_stays_silent_and_does_not_arm_cooldown).
     *
     * [M-OMDB-11 r2] Every other `Response:'False'` shape is key-state or
     * request-state failure wearing a URL-shaped payload: an 'Invalid API
     * key.' envelope admitted at the success TTL replays the 401 lie for a
     * full day and silently shadows the repaired key. The taxonomy is
     * cache-allowlisted, so unknown and future error strings land on the
     * safe side (never cached) instead of inheriting a day of immunity.
     *
     * @param array<string, mixed> $data Decoded JSON envelope
     */
    private static function isCacheableEnvelope(array $data): bool
    {
        if (($data['Response'] ?? '') !== 'False') {
            return true;
        }

        $error = $data['Error'] ?? null;

        if (is_string($error) === false) {
            return false;
        }

        $needle = strtolower($error);

        foreach (self::NOT_FOUND_ERROR_MARKERS as $marker) {
            if (str_contains($needle, $marker) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a decoded envelope is OMDb's "key spent its daily quota" signal
     * rather than a plain not-found.
     *
     * @param array<string, mixed> $data Decoded JSON envelope
     */
    private static function isOverLimitEnvelope(array $data): bool
    {
        if (($data['Response'] ?? '') !== 'False') {
            return false;
        }

        $error = $data['Error'] ?? null;

        if (is_string($error) === false) {
            return false;
        }

        $needle = strtolower($error);

        foreach (self::OVER_LIMIT_ERROR_MARKERS as $marker) {
            if (str_contains($needle, $marker) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Arm the key cooldown until the next UTC midnight and log the breach.
     */
    private function armOverLimitCooldown(string $url): void
    {
        $resetsAt = intdiv($this->utcNow() + self::UTC_DAY_SECONDS, self::UTC_DAY_SECONDS) * self::UTC_DAY_SECONDS;
        $this->overLimitCooldownUntil = $resetsAt;

        $this->logger?->warning('OMDb API key is over its daily limit; cooldown armed until the UTC day rolls over', [
            'url' => $this->redactSecrets($url),
            'cooldown_until_utc' => gmdate('Y-m-d H:i:s', $resetsAt),
            'cooldown_seconds' => $resetsAt - $this->utcNow(),
        ]);
    }

    /**
     * Wall-clock seconds since the epoch, UTC-epoch based (time()).
     *
     * The cooldown boundary is a calendar instant (UTC midnight), so it must
     * use the wall clock; the response cache deliberately keeps using the
     * monotonic {@see clock()} because it never crosses process meaning.
     */
    private function utcNow(): int
    {
        return time();
    }

    /**
     * Whether the daily-limit cooldown is currently suppressing dispatch.
     */
    public function isOverLimitCoolingDown(): bool
    {
        return $this->overLimitCooldownUntil > $this->utcNow();
    }

    /**
     * Get or create the shared HTTP client.
     *
     * [M-OMDB-13] Library-generation contract: this plugin is TESTED against
     * the lock generation (workerman/workerman ^4.0 + http-client ^1.0) but
     * RUNS inside the host worker (workerman 5.x + http-client ^3.x). Only
     * the surface common to both generations is used: `Client::request($url,
     * $options)` with 'success'/'error' callbacks and a stream body on the
     * Response — anything generation-specific (Timer::sleep above, protocol
     * classes, option keys beyond timeout/context) is forbidden in src/.
     * Aligning require-dev with the host generation would re-resolve the
     * whole pinned lock (S440 audit-gate churn) — owner-gated, deliberately
     * not done in a fix lane; the seam tests pin this file's neutrality.
     *
     * [PG-194] The contract has a LOOP half, not just a class surface: even
     * where the API matches, async https only completes while the running
     * event loop actually delivers TLS reads. Under the Swoole adapter the
     * host runs in production it never does (see
     * {@see requiresBlockingCurl()}), so correctness there rides the bounded
     * blocking cURL seam — ext-curl is declared in composer.json because
     * this arm is no longer optional on the live host.
     *
     * [PG-193] TLS posture rides the constructor-level `context` key, which
     * both generations read for every https connection: lock v1
     * ConnectionPool::create() REPLACES its default (verify-off) context
     * wholesale when `context` is non-empty; host v3 array_merge()s at the
     * top level, so a provided `ssl` block likewise replaces its defaults
     * (including the peer_name v3 pre-sets). Either way the context must be
     * a COMPLETE ssl block — which is exactly what {@see tlsContext()}
     * carries. The historical `verify_ssl` constructor key is read by
     * neither generation and is retired here as the dead knob it always was.
     */
    private function getHttpClient(): Client
    {
        if ($this->httpClient !== null) {
            return $this->httpClient;
        }

        $this->httpClient = new Client($this->httpClientOptions());

        return $this->httpClient;
    }

    /**
     * Constructor options for the shared HTTP client.
     *
     * Generation-neutral per [M-OMDB-13] above: `timeout` and `context` are
     * the two keys both vendored and host http-client generations honor.
     * Pinned in full by tests — a key or shape drift here reddens before it
     * can silently turn TLS verification back off.
     *
     * @return array<string, mixed>
     */
    private function httpClientOptions(): array
    {
        return [
            'timeout' => self::HTTP_TIMEOUT_SEC,
            'context' => $this->tlsContext(),
        ];
    }

    /**
     * Explicit TLS stream context — verification is never library-defaulted.
     *
     * Both http-client generations construct https connections with
     * verify_peer=false + verify_peer_name=false (+ allow_self_signed on
     * the host generation). Leaving that default in place would send the
     * apikey query parameter to any intercepting hop presenting any
     * certificate — so this seam ALWAYS passes a complete `ssl` block,
     * mirroring the host's Hub HttpClient::tlsContext() posture [PG-193].
     *
     * Verify-on (default): full chain + name validation against the system
     * trust store, with SNI/peer_name stated explicitly for the single
     * constant host this client ever dials (not relying on either
     * generation's merge/replace timing to set it).
     * Verify-off: the operator's explicit plugin-setting opt-out — the
     * deliberately insecure arm, documented at the settings surface as
     * leaking the key in cleartext-query to any intercepting hop.
     *
     * @return array{ssl: array<string, bool|string>}
     */
    private function tlsContext(): array
    {
        if ($this->useSslVerification === false) {
            return ['ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]];
        }

        return ['ssl' => [
            'peer_name' => self::API_HOST,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ]];
    }

    /**
     * Whether the current event loop forces the blocking cURL transport.
     *
     * [PG-194] The estate event-loop TLS law (phlix-server
     * src/Common/Http/EventLoopTls.php): under
     * `Worker::$eventLoopClass = Workerman\Events\Swoole`, client-side TLS
     * streams stall after the handshake — Swoole's epoll watches the raw fd
     * while OpenSSL buffers the response bytes internally, so the read
     * callback never fires. Production pins that adapter (server start.php),
     * which made this plugin's async-only https dispatch structurally dead on
     * the live host: every cache-miss lookup burned HTTP_TIMEOUT_SEC and died
     * with a transport error. Detection mirrors the host helper's own
     * comparison (a plain string check of $eventLoopClass — ext-swoole is
     * never loaded here), and every server-side HTTP client that crosses this
     * boundary answers it with a bounded blocking cURL fetch; this one now
     * does too. Blocking the worker is the registered, measured posture: a
     * bounded ≤HTTP_TIMEOUT_SEC stall on a low-frequency, quota-paced
     * enrichment call (host spacing ~90s) beats a request that can never
     * complete.
     */
    private function requiresBlockingCurl(string $url): bool
    {
        if (stripos($url, 'https://') !== 0) {
            return false;
        }

        return Worker::$eventLoopClass === self::SWOOLE_EVENT_LOOP_CLASS;
    }

    /**
     * Blocking cURL GET, decoded through the same envelope law as the async
     * arm — the [PG-194] fallback transport. Failures reach the identical
     * redacted 'OMDb HTTP error' sink.
     *
     * @return array<string, mixed>|null Decoded envelope or null on failure
     */
    private function httpGetJsonCurl(string $url): ?array
    {
        /** @var array{body: string, error: string} $result */
        $result = ($this->curlTransport)($url);

        if ($result['error'] !== '') {
            // cURL error text can embed the request URL — redact at the sink.
            $this->logger?->warning('OMDb HTTP error', [
                'url' => $this->redactSecrets($url),
                'error' => $this->redactSecrets($result['error']),
            ]);

            return null;
        }

        if ($result['body'] === '') {
            // Same shape the async arm produces for an empty body: null.
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($result['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger?->warning('OMDb HTTP error', [
                'url' => $this->redactSecrets($url),
                'error' => $this->redactSecrets($e->getMessage()),
            ]);

            return null;
        }

        if (is_array($decoded) === false) {
            $this->logger?->warning('OMDb HTTP error', [
                'url' => $this->redactSecrets($url),
                'error' => 'OMDb response body is not a JSON object',
            ]);

            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The real blocking round trip behind {@see $curlTransport}.
     *
     * Bounded and HTTPS-only: CURLOPT_TIMEOUT/CONNECTTIMEOUT carry the same
     * bound the async client was configured with, PROTOCOLS/REDIR_PROTOCOLS
     * keep a redirect from walking the key-bearing request down to plain
     * http, and the TLS pins answer the same operator toggle the stream
     * context does ([PG-193]) — the opt-out arm stays explicit, never a
     * library default.
     *
     * @return array{body: string, error: string}
     */
    private function performCurlRequest(string $url): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return ['body' => '', 'error' => 'cURL init failed'];
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT_SEC,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => $this->useSslVerification,
            CURLOPT_SSL_VERIFYHOST => $this->useSslVerification ? 2 : 0,
        ]);

        $raw = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        curl_close($handle);

        if ($errno !== 0 || is_string($raw) === false) {
            return [
                'body' => '',
                'error' => $error !== '' ? $error : 'cURL transport error (errno ' . $errno . ')',
            ];
        }

        return ['body' => $raw, 'error' => ''];
    }

    /**
     * Enforce rate limiting by sleeping if needed.
     */
    private function enforceRateLimit(): void
    {
        $elapsed = $this->clock() - $this->lastRequestTimestamp;
        if ($elapsed < self::RATE_LIMIT_INTERVAL_SEC) {
            $sleep = self::RATE_LIMIT_INTERVAL_SEC - $elapsed;
            ($this->timerSleep)($sleep);
        }

        $this->lastRequestTimestamp = $this->clock();
    }

    /**
     * Wait for an async HTTP response.
     *
     * Every tick goes through the shared {@see $timerSleep} seam — the one
     * sleep choke the host controls (cooperative Timer::sleep on v5) and
     * tests neutralize. A raw usleep here bypassed that choke and blocked
     * the worker instead of yielding. The $waited counter is what enforces
     * the timeout bound; with the seam neutralized (tests) the loop drains
     * instantly, and with it honoring time (host) the bound tracks the
     * deadline the transport itself was configured with.
     *
     * @param array<string, mixed> $state Shared state array
     */
    private function waitForResponse(array &$state): void
    {
        $maxWait = self::HTTP_TIMEOUT_SEC + 1.0;
        $waited = 0.0;

        while ($state['done'] === false && $waited < $maxWait) {
            ($this->timerSleep)(0.01); // 10ms tick through the seam
            $waited += 0.01;
        }
    }

    /**
     * Clear the response cache.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}
