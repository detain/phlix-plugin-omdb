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
 * Provides search, title lookup, and handles rate limiting, caching,
 * SSL verification toggling, and async/non-blocking patterns.
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

        if (($data['Response'] ?? '') === 'False') {
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
     * Perform a GET request and decode the JSON body.
     *
     * Applies rate limiting, in-memory cache, and optional SSL verification.
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

        $this->enforceRateLimit();

        if ($this->jsonFetcher !== null) {
            /** @var array<string, mixed>|null $response */
            $response = ($this->jsonFetcher)($url);
            if ($response !== null && $this->cacheTtlSeconds > 0) {
                $response['_cached_at'] = $this->clock();
                $this->cache[$cacheKey] = $response;
            }

            return $response;
        }

        $state = ['response' => null, 'error' => null, 'done' => false];
        $client = $this->getHttpClient();

        $client->request($url, [
            'success' => function (Response $response) use (&$state): void {
                $body = $response->getBody();
                $contents = $body->getContents();
                if ($contents !== '') {
                    /** @var array<string, mixed>|null $decoded */
                    $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
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
            $this->logger?->warning('OMDb HTTP error', [
                'url' => $url,
                'error' => $errorMessage,
            ]);

            return null;
        }

        /** @var array<string, mixed>|null $response */
        $response = $state['response'];

        if ($response !== null && $this->cacheTtlSeconds > 0) {
            $response['_cached_at'] = $this->clock();
            $this->cache[$cacheKey] = $response;
        }

        return $response;
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
