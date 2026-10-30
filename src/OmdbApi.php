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
use Psr\Log\NullLogger;
use Workerman\Http\Client;
use Workerman\Http\Response;
use Workerman\Timer;

/**
 * HTTP client for the OMDb API.
 *
 * Provides search, title lookup, and handles rate limiting, daily-limit
 * cooldown, caching, SSL verification toggling, and async/non-blocking
 * patterns.
 *
 * ## Daily-limit posture (free tier: 1000 requests/day)
 *
 * OMDb signals quota exhaustion with a `{"Response":"False","Error":"API key
 * is over its daily limit."}` envelope (as HTTP 200 or 403 — the vendored
 * transport surfaces both identically). {@see httpGetJson()} classifies that
 * envelope apart from a plain "title not found", logs a distinguishable
 * warning, and cools the key down until the next UTC midnight (the reset
 * boundary): further cache-missing lookups short-circuit without touching the
 * network, so one dead key cannot turn into a retry-storm against a wall.
 * The cooldown is process-local by design — the plugin owns no cross-worker
 * store (persisting across restarts is a known follow-up); the host-side
 * `SourceRateLimiter` in phlix-server paces dispatch (~90s spacing for omdb)
 * as the belt, this cooldown is the braces.
 */
final class OmdbApi
{
    /**
     * OMDb API base URL. HTTPS only — never plaintext http.
     */
    private const API_BASE = 'https://www.omdbapi.com';

    /**
     * HTTP request timeout in seconds.
     */
    private const HTTP_TIMEOUT_SEC = 10;

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

    /** Seconds in a UTC day; OMDb daily limits roll over at UTC midnight. */
    private const UTC_DAY_SECONDS = 86400;

    /**
     * In-memory response cache: md5(url) => decoded JSON array.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    /**
     * Unix timestamp (with microseconds) of the last API request, for rate limiting.
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
    private $jsonFetcher = null;

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
        $this->timerSleep = static function (float $seconds): void {
            if (class_exists(Timer::class)) {
                Timer::sleep($seconds);
            } else {
                usleep((int) ($seconds * 1_000_000));
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
     * Search OMDb by title and optional year.
     *
     * @param string $title Title to search for
     * @param int|null $year Optional release year
     * @return list<array{imdb_id: string, title: string, year: string, type: string}>
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
     * @param string $imdbId IMDb ID (e.g., "tt0120737")
     * @return array<string, mixed>|null Details array or null on failure
     */
    public function getByImdbId(string $imdbId): ?array
    {
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
     * Mask the apikey parameter wherever it appears in a string.
     *
     * OMDb authenticates with a query-string key, so any URL (or exception
     * message embedding one) must pass through here before it can reach a
     * log or exception surface. The masked run stops at the first delimiter a
     * percent-encoded value can never contain ('&', whitespace, a quote, or
     * the '#' fragment start) so surrounding diagnostics stay readable; '='
     * deliberately stays inside the run because a copied URL could carry it.
     */
    private static function redactSecrets(string $text): string
    {
        return (string) preg_replace('/apikey=[^&\s"\'#]+/i', 'apikey=' . self::MASKED_APIKEY, $text);
    }

    /**
     * Perform a GET request and decode the JSON body.
     *
     * Applies the daily-limit cooldown gate, rate limiting, in-memory cache,
     * and optional SSL verification. Over-limit envelopes are surfaced as a
     * distinguishable warning + null (never a stale cached not-found); plain
     * negative envelopes flow through to the callers unchanged (and are
     * cached, which amortizes quota).
     *
     * @param string $url Absolute OMDb API URL
     * @return array<string, mixed>|null Decoded JSON or null on failure
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
                'url' => self::redactSecrets($url),
            ]);

            return null;
        }

        $this->enforceRateLimit();

        if ($this->jsonFetcher !== null) {
            /** @var array<string, mixed>|null $response */
            $response = ($this->jsonFetcher)($url);

            return $this->admitResponse($response, $url, $cacheKey);
        }

        $state = ['response' => null, 'error' => null, 'done' => false];
        $client = $this->getHttpClient();

        $client->request($url, [
            'success' => function (Response $response) use (&$state): void {
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

        if ($state['error'] !== null) {
            $error = $state['error'];
            $errorMessage = $error instanceof \Throwable ? $error->getMessage() : 'Unknown error';
            // Both values below can carry the key-bearing request URL (the URL
            // itself, or an exception message that embeds it) — redact at the
            // sink so the secret never reaches a log stream. Review omdb__srphp C1.
            $this->logger?->warning('OMDb HTTP error', [
                'url' => self::redactSecrets($url),
                'error' => self::redactSecrets($errorMessage),
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
     * keeps it distinguishable from a real not-found.
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

        if ($this->cacheTtlSeconds > 0) {
            $response['_cached_at'] = $this->clock();
            $this->cache[$cacheKey] = $response;
        }

        return $response;
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
            'url' => self::redactSecrets($url),
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
     */
    private function getHttpClient(): Client
    {
        if ($this->httpClient !== null) {
            return $this->httpClient;
        }

        $options = [
            'timeout' => self::HTTP_TIMEOUT_SEC,
        ];

        if ($this->useSslVerification === false) {
            $options['verify_ssl'] = false;
        }

        $this->httpClient = new Client($options);

        return $this->httpClient;
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
     * Uses cooperative yielding when inside a Swoole coroutine.
     *
     * @param array<string, mixed> $state Shared state array
     */
    private function waitForResponse(array &$state): void
    {
        $maxWait = self::HTTP_TIMEOUT_SEC + 1.0;
        $waited = 0.0;

        while ($state['done'] === false && $waited < $maxWait) {
            usleep(10_000); // 10ms
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
