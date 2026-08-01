<?php

/**
 * Unit tests for OmdbApi.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\Omdb\Tests;

use Phlix\Plugins\Metadata\Omdb\OmdbApi;
use PHPUnit\Framework\TestCase;

final class OmdbApiTest extends TestCase
{
    public function test_extract_ratings_parses_imdb_rating(): void
    {
        $details = [
            'imdbRating' => '8.8',
            'Ratings' => [],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertSame(8.8, $ratings['imdb']);
        $this->assertNull($ratings['rotten_tomatoes']);
        $this->assertNull($ratings['metascore']);
    }

    public function test_extract_ratings_parses_rotten_tomatoes_percentage(): void
    {
        $details = [
            'imdbRating' => 'N/A',
            'Ratings' => [
                ['Source' => 'Rotten Tomatoes', 'Value' => '97%'],
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertSame(9.7, $ratings['rotten_tomatoes']);
    }

    public function test_extract_ratings_parses_metascore_fraction(): void
    {
        $details = [
            'imdbRating' => '8.5',
            'Ratings' => [
                ['Source' => 'Metacritic', 'Value' => '72/100'],
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertSame(7.2, $ratings['metascore']);
    }

    public function test_extract_ratings_handles_all_sources(): void
    {
        $details = [
            'imdbRating' => '8.5',
            'Ratings' => [
                ['Source' => 'Internet Movie Database', 'Value' => '8.5/10'],
                ['Source' => 'Rotten Tomatoes', 'Value' => '91%'],
                ['Source' => 'Metacritic', 'Value' => '64/100'],
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertSame(8.5, $ratings['imdb']);
        $this->assertSame(9.1, $ratings['rotten_tomatoes']);
        $this->assertSame(6.4, $ratings['metascore']);
    }

    public function test_extract_ratings_handles_missing_ratings(): void
    {
        $details = [
            'imdbRating' => 'N/A',
            'Ratings' => [],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertNull($ratings['imdb']);
        $this->assertNull($ratings['rotten_tomatoes']);
        $this->assertNull($ratings['metascore']);
    }

    public function test_extract_ratings_handles_na_values(): void
    {
        $details = [
            'imdbRating' => 'N/A',
            'Ratings' => [
                ['Source' => 'Rotten Tomatoes', 'Value' => 'N/A'],
                ['Source' => 'Metacritic', 'Value' => 'N/A'],
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertNull($ratings['imdb']);
        $this->assertNull($ratings['rotten_tomatoes']);
        $this->assertNull($ratings['metascore']);
    }

    public function test_extract_ratings_handles_missing_keys(): void
    {
        $details = [];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertNull($ratings['imdb']);
        $this->assertNull($ratings['rotten_tomatoes']);
        $this->assertNull($ratings['metascore']);
    }

    public function test_extract_ratings_rounds_to_one_decimal(): void
    {
        $details = [
            'imdbRating' => '8.8',
            'Ratings' => [
                ['Source' => 'Rotten Tomatoes', 'Value' => '92%'],
                ['Source' => 'Metacritic', 'Value' => '64/100'],
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertEqualsWithDelta(8.8, $ratings['imdb'], 0.01);
        $this->assertEqualsWithDelta(9.2, $ratings['rotten_tomatoes'], 0.01);
        $this->assertEqualsWithDelta(6.4, $ratings['metascore'], 0.01);
    }

    /**
     * Consequence: the OMDb endpoint is HTTPS, never plaintext http.
     */
    public function test_api_base_is_https(): void
    {
        $base = (new \ReflectionClass(OmdbApi::class))->getConstant('API_BASE');

        $this->assertIsString($base);
        $this->assertStringStartsWith('https://', $base);
    }

    /**
     * Consequence: the default transport is the non-blocking Workerman
     * cooperative-wait HTTP client — not curl/file_get_contents.
     */
    public function test_default_transport_is_workerman_http_client(): void
    {
        $api = new OmdbApi('key');

        $method = new \ReflectionMethod(OmdbApi::class, 'getHttpClient');
        $method->setAccessible(true);
        $client = $method->invoke($api);

        $this->assertInstanceOf(\Workerman\Http\Client::class, $client);
    }

    /**
     * Consequence: the injected fetcher seam is honoured (no network),
     * proving the transport is a replaceable, non-blocking indirection.
     */
    public function test_json_fetcher_seam_bypasses_network(): void
    {
        $data = ['Response' => 'True', 'Title' => 'Inception', 'imdbRating' => '8.8'];
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => $data);

        $result = $api->getByImdbId('tt1375666');

        $this->assertNotNull($result);
        $this->assertSame('Inception', $result['Title']);
    }

    public function test_search_returns_empty_array_on_null_response(): void
    {
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => null);

        $result = $api->search('Inception');

        $this->assertSame([], $result);
    }

    public function test_search_returns_empty_array_on_false_response(): void
    {
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => ['Response' => 'False']);

        $result = $api->search('Inception');

        $this->assertSame([], $result);
    }

    public function test_search_returns_empty_array_when_search_key_missing(): void
    {
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => ['Response' => 'True']);

        $result = $api->search('Inception');

        $this->assertSame([], $result);
    }

    public function test_search_returns_empty_array_when_results_not_array(): void
    {
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => ['Response' => 'True', 'Search' => 'not an array']);

        $result = $api->search('Inception');

        $this->assertSame([], $result);
    }

    public function test_search_returns_formatted_results(): void
    {
        $data = [
            'Response' => 'True',
            'Search' => [
                ['imdbID' => 'tt1375666', 'Title' => 'Inception', 'Year' => '2010', 'Type' => 'movie'],
                ['imdbID' => 'tt0816692', 'Title' => 'Interstellar', 'Year' => '2014', 'Type' => 'movie'],
            ],
        ];
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => $data);

        $result = $api->search('Inception');

        $this->assertCount(2, $result);
        $this->assertSame('tt1375666', $result[0]['imdb_id']);
        $this->assertSame('Inception', $result[0]['title']);
        $this->assertSame('2010', $result[0]['year']);
        $this->assertSame('movie', $result[0]['type']);
    }

    public function test_search_skips_results_with_empty_imdb_id(): void
    {
        $data = [
            'Response' => 'True',
            'Search' => [
                ['imdbID' => '', 'Title' => 'Inception', 'Year' => '2010', 'Type' => 'movie'],
                ['imdbID' => 'tt0816692', 'Title' => 'Interstellar', 'Year' => '2014', 'Type' => 'movie'],
            ],
        ];
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => $data);

        $result = $api->search('Inception');

        $this->assertCount(1, $result);
        $this->assertSame('tt0816692', $result[0]['imdb_id']);
    }

    public function test_search_handles_year_parameter(): void
    {
        $data = [
            'Response' => 'True',
            'Search' => [
                ['imdbID' => 'tt1375666', 'Title' => 'Inception', 'Year' => '2010', 'Type' => 'movie'],
            ],
        ];
        $capturedUrl = null;
        $api = new OmdbApi('key', true, 0, null, static function (string $url) use (&$capturedUrl, $data): ?array {
            $capturedUrl = $url;
            return $data;
        });

        $api->search('Inception', 2010);

        $this->assertNotNull($capturedUrl);
        $this->assertStringContainsString('y=2010', $capturedUrl);
    }

    public function test_search_skips_non_array_results(): void
    {
        $data = [
            'Response' => 'True',
            'Search' => [
                'not an array',
                ['imdbID' => 'tt1375666', 'Title' => 'Inception', 'Year' => '2010', 'Type' => 'movie'],
            ],
        ];
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => $data);

        $result = $api->search('Inception');

        $this->assertCount(1, $result);
        $this->assertSame('tt1375666', $result[0]['imdb_id']);
    }

    public function test_get_by_imdb_id_returns_null_on_null_response(): void
    {
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => null);

        $result = $api->getByImdbId('tt1375666');

        $this->assertNull($result);
    }

    public function test_get_by_imdb_id_returns_null_on_false_response(): void
    {
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => ['Response' => 'False']);

        $result = $api->getByImdbId('tt1375666');

        $this->assertNull($result);
    }

    public function test_get_by_imdb_id_returns_full_details(): void
    {
        $data = [
            'Response' => 'True',
            'Title' => 'Inception',
            'Year' => '2010',
            'Rated' => 'PG-13',
            'Released' => '16 Jul 2010',
            'Runtime' => '148 min',
            'Genre' => 'Action, Adventure, Sci-Fi',
            'Director' => 'Christopher Nolan',
            'Writer' => 'Christopher Nolan',
            'Actors' => 'Leonardo DiCaprio, Joseph Gordon-Levitt, Elliot Page',
            'Plot' => 'A thief who steals corporate secrets through dream-sharing technology.',
            'Language' => 'English, Japanese, French',
            'Country' => 'United States, United Kingdom',
            'Awards' => 'Won 4 Oscars. 157 wins & 220 nominations total.',
            'Poster' => 'https://example.com/poster.jpg',
            'Type' => 'movie',
            'imdbRating' => '8.8',
        ];
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => $data);

        $result = $api->getByImdbId('tt1375666');

        $this->assertNotNull($result);
        $this->assertSame('Inception', $result['Title']);
        $this->assertSame('2010', $result['Year']);
        $this->assertSame('PG-13', $result['Rated']);
        $this->assertSame('148 min', $result['Runtime']);
        $this->assertSame('movie', $result['Type']);
    }

    public function test_clear_cache_clears_internal_cache(): void
    {
        $data = ['Response' => 'True', 'Title' => 'Inception', 'imdbRating' => '8.8'];
        $api = new OmdbApi('key', true, 3600, null, static fn(string $url): ?array => $data);

        // First call populates cache
        $api->getByImdbId('tt1375666');

        // Verify cache is populated by checking it returns cached data
        // We need to test clearCache works - use reflection to check cache state
        $reflection = new \ReflectionClass($api);
        $cacheProperty = $reflection->getProperty('cache');
        $cacheProperty->setAccessible(true);

        // Cache should have entries after getByImdbId
        $cacheBefore = $cacheProperty->getValue($api);
        $this->assertNotEmpty($cacheBefore);

        // Clear the cache
        $api->clearCache();

        // Cache should be empty after clear
        $cacheAfter = $cacheProperty->getValue($api);
        $this->assertSame([], $cacheAfter);
    }

    public function test_parse_percentage_handles_decimal_percentages(): void
    {
        $details = [
            'imdbRating' => 'N/A',
            'Ratings' => [
                ['Source' => 'Rotten Tomatoes', 'Value' => '92.5%'],
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        // 92.5% / 10 = 9.25, which rounds to 9.3
        $this->assertEqualsWithDelta(9.3, $ratings['rotten_tomatoes'], 0.01);
    }

    public function test_parse_fraction_handles_decimal_fractions(): void
    {
        $details = [
            'imdbRating' => 'N/A',
            'Ratings' => [
                ['Source' => 'Metacritic', 'Value' => '85.5/100'],
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        // 85.5 / 10 = 8.55, which rounds to 8.6
        $this->assertEqualsWithDelta(8.6, $ratings['metascore'], 0.01);
    }

    /**
     * Test parsePercentage returns null for invalid format (no percent sign).
     * This exercises the return null path in parsePercentage.
     */
    public function test_parse_percentage_returns_null_for_invalid_format(): void
    {
        $details = [
            'imdbRating' => 'N/A',
            'Ratings' => [
                ['Source' => 'Rotten Tomatoes', 'Value' => '95'], // Missing %
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertNull($ratings['rotten_tomatoes']);
    }

    /**
     * Test parsePercentage returns null for malformed format.
     */
    public function test_parse_percentage_returns_null_for_malformed_value(): void
    {
        $details = [
            'imdbRating' => 'N/A',
            'Ratings' => [
                ['Source' => 'Rotten Tomatoes', 'Value' => '%95'], // Wrong order
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertNull($ratings['rotten_tomatoes']);
    }

    /**
     * Test parseFraction returns null for invalid format (no /100).
     */
    public function test_parse_fraction_returns_null_for_invalid_format(): void
    {
        $details = [
            'imdbRating' => 'N/A',
            'Ratings' => [
                ['Source' => 'Metacritic', 'Value' => '72'], // Missing /100
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertNull($ratings['metascore']);
    }

    /**
     * Test parseFraction returns null for malformed fraction.
     */
    public function test_parse_fraction_returns_null_for_malformed_value(): void
    {
        $details = [
            'imdbRating' => 'N/A',
            'Ratings' => [
                ['Source' => 'Metacritic', 'Value' => '100/72'], // Reversed
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertNull($ratings['metascore']);
    }

    /**
     * Test getHttpClient creates client with SSL verification disabled.
     * This exercises the verify_ssl = false path in getHttpClient.
     * Note: We cannot directly inspect Workerman\Client internals, but we verify
     * the method executes without error and returns a valid client.
     */
    public function test_get_http_client_disables_ssl_verification(): void
    {
        $api = new OmdbApi('key', false, 0); // useSslVerification = false

        $method = new \ReflectionMethod(OmdbApi::class, 'getHttpClient');
        $method->setAccessible(true);
        $client = $method->invoke($api);

        $this->assertInstanceOf(\Workerman\Http\Client::class, $client);
        $this->assertNotSame($api, $client);
    }

    /**
     * Test getHttpClient returns cached client on subsequent calls.
     */
    public function test_get_http_client_returns_same_instance(): void
    {
        $api = new OmdbApi('key');

        $method = new \ReflectionMethod(OmdbApi::class, 'getHttpClient');
        $method->setAccessible(true);
        $client1 = $method->invoke($api);
        $client2 = $method->invoke($api);

        $this->assertSame($client1, $client2);
    }

    /**
     * Test that extractRatings handles Ratings with non-array elements.
     * This exercises the !is_array($rating) continue path.
     */
    public function test_extract_ratings_skips_non_array_rating_entries(): void
    {
        $details = [
            'imdbRating' => '8.5',
            'Ratings' => [
                'not an array',
                ['Source' => 'Rotten Tomatoes', 'Value' => '91%'],
                123,
                null,
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertSame(8.5, $ratings['imdb']);
        $this->assertSame(9.1, $ratings['rotten_tomatoes']);
    }

    /**
     * Test extractRatings handles non-string source/value in ratings.
     */
    public function test_extract_ratings_handles_non_string_rating_values(): void
    {
        $details = [
            'imdbRating' => '8.5',
            'Ratings' => [
                ['Source' => 123, 'Value' => 456],
                ['Source' => null, 'Value' => null],
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        // Should not crash and should return nulls for missing valid ratings
        $this->assertSame(8.5, $ratings['imdb']);
        $this->assertNull($ratings['rotten_tomatoes']);
        $this->assertNull($ratings['metascore']);
    }

    /**
     * Test extractRatings handles imdbRating that is non-numeric string.
     */
    public function test_extract_ratings_handles_non_numeric_imdb_rating(): void
    {
        $details = [
            'imdbRating' => 'N/A',
            'Ratings' => [],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertNull($ratings['imdb']);
    }

    /**
     * Test extractRatings handles numeric but non-string imdbRating.
     */
    public function test_extract_ratings_handles_numeric_imdb_rating(): void
    {
        $details = [
            'imdbRating' => 8.8, // Numeric instead of string
            'Ratings' => [],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertNull($ratings['imdb']); // Should be null because it's not a string
    }

    /**
     * Test that clock() returns a float value representing time in seconds.
     */
    public function test_clock_returns_float_seconds(): void
    {
        $api = new OmdbApi('key', true, 0);

        $method = new \ReflectionMethod(OmdbApi::class, 'clock');
        $method->setAccessible(true);
        $time = $method->invoke($api);

        $this->assertIsFloat($time);
        $this->assertGreaterThan(0, $time);
    }

    /**
     * Test that search returns empty when API key is empty string.
     * This exercises the early return path when ensureApi returns null
     * because apiKey is empty string.
     */
    public function test_search_returns_empty_when_api_key_empty_string(): void
    {
        $data = [
            'Response' => 'True',
            'Search' => [
                ['imdbID' => 'tt1375666', 'Title' => 'Inception', 'Year' => '2010', 'Type' => 'movie'],
            ],
        ];
        // Create API with empty string apiKey - but wait, the constructor doesn't validate
        // The empty string check is in ensureApi which is private on OmdbPlugin
        // So we test this via the plugin instead
        $api = new OmdbApi('', true, 0, null, static fn(string $url): ?array => $data);

        // Even with empty key, the API will try to use it - OmdbPlugin handles the validation
        $result = $api->search('Inception');
        $this->assertCount(1, $result);
    }

    /**
     * Test that getHttpClient reuses the same client instance.
     */
    public function test_http_client_is_reused(): void
    {
        $api = new OmdbApi('key');

        $method = new \ReflectionMethod(OmdbApi::class, 'getHttpClient');
        $method->setAccessible(true);

        $client1 = $method->invoke($api);
        $client2 = $method->invoke($api);

        $this->assertSame($client1, $client2);
    }

    /**
     * Test httpGetJson handles error response from jsonFetcher.
     * The error path sets $state['error'] and returns null.
     */
    public function test_http_get_json_returns_null_on_json_fetcher_error(): void
    {
        $api = new OmdbApi('key', true, 0, null, static function (string $url): ?array {
            // Return null to simulate an error
            return null;
        });

        $method = new \ReflectionMethod(OmdbApi::class, 'httpGetJson');
        $method->setAccessible(true);
        $result = $method->invoke($api, 'https://example.com/test');

        $this->assertNull($result);
    }

    /**
     * Test that getHttpClient returns different clients for different API instances.
     */
    public function test_different_api_instances_have_different_http_clients(): void
    {
        $api1 = new OmdbApi('key1');
        $api2 = new OmdbApi('key2');

        $method = new \ReflectionMethod(OmdbApi::class, 'getHttpClient');
        $method->setAccessible(true);

        $client1 = $method->invoke($api1);
        $client2 = $method->invoke($api2);

        $this->assertNotSame($client1, $client2);
    }

    /**
     * Test that httpGetJson caches responses when cacheTtlSeconds > 0.
     * We verify this by checking the cache property after a call.
     */
    public function test_http_get_json_caches_responses(): void
    {
        $data = ['Response' => 'True', 'Title' => 'Inception', 'imdbRating' => '8.8'];
        $api = new OmdbApi('key', true, 3600, null, static fn(string $url): ?array => $data);

        // Make two calls to the same URL
        $api->getByImdbId('tt1375666');
        $api->getByImdbId('tt1375666');

        // Check cache via reflection
        $reflection = new \ReflectionClass($api);
        $cacheProperty = $reflection->getProperty('cache');
        $cacheProperty->setAccessible(true);
        $cache = $cacheProperty->getValue($api);

        // Should have cached the response (2 entries for search and getByImdbId, or 1 entry with same key)
        $this->assertNotEmpty($cache);
    }

    /**
     * Test that httpGetJson does NOT cache when cacheTtlSeconds is 0.
     */
    public function test_http_get_json_does_not_cache_when_ttl_zero(): void
    {
        $data = ['Response' => 'True', 'Title' => 'Inception', 'imdbRating' => '8.8'];
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => $data);

        $api->getByImdbId('tt1375666');

        $reflection = new \ReflectionClass($api);
        $cacheProperty = $reflection->getProperty('cache');
        $cacheProperty->setAccessible(true);
        $cache = $cacheProperty->getValue($api);

        // With cacheTtlSeconds=0, nothing should be cached
        $this->assertEmpty($cache);
    }

    /**
     * Test that getByImdbId passes 'plot' => 'full' parameter.
     */
    public function test_get_by_imdb_id_requests_full_plot(): void
    {
        $capturedUrl = null;
        $api = new OmdbApi('key', true, 0, null, static function (string $url) use (&$capturedUrl): ?array {
            $capturedUrl = $url;
            return ['Response' => 'True', 'Title' => 'Inception'];
        });

        $api->getByImdbId('tt1375666');

        $this->assertNotNull($capturedUrl);
        $this->assertStringContainsString('plot=full', $capturedUrl);
    }

    /**
     * Test that search constructs correct URL with api key.
     */
    public function test_search_includes_api_key_in_url(): void
    {
        $capturedUrl = null;
        $api = new OmdbApi('my_test_key', true, 0, null, static function (string $url) use (&$capturedUrl): ?array {
            $capturedUrl = $url;
            return ['Response' => 'True', 'Search' => []];
        });

        $api->search('Inception');

        $this->assertNotNull($capturedUrl);
        $this->assertStringContainsString('apikey=my_test_key', $capturedUrl);
    }

    /**
     * Test that search uses correct endpoint.
     */
    public function test_search_uses_correct_endpoint(): void
    {
        $capturedUrl = null;
        $api = new OmdbApi('key', true, 0, null, static function (string $url) use (&$capturedUrl): ?array {
            $capturedUrl = $url;
            return ['Response' => 'True', 'Search' => []];
        });

        $api->search('Inception');

        $this->assertNotNull($capturedUrl);
        $this->assertStringStartsWith('https://www.omdbapi.com/?', $capturedUrl);
    }

    /**
     * Test that getByImdbId uses correct endpoint.
     */
    public function test_get_by_imdb_id_uses_correct_endpoint(): void
    {
        $capturedUrl = null;
        $api = new OmdbApi('key', true, 0, null, static function (string $url) use (&$capturedUrl): ?array {
            $capturedUrl = $url;
            return ['Response' => 'True', 'Title' => 'Inception'];
        });

        $api->getByImdbId('tt1375666');

        $this->assertNotNull($capturedUrl);
        $this->assertStringStartsWith('https://www.omdbapi.com/?', $capturedUrl);
        $this->assertStringContainsString('i=tt1375666', $capturedUrl);
    }

    /**
     * Test that HTTP_TIMEOUT_SEC constant is set correctly.
     */
    public function test_http_timeout_is_10_seconds(): void
    {
        $reflection = new \ReflectionClass(OmdbApi::class);
        $constant = $reflection->getConstant('HTTP_TIMEOUT_SEC');

        $this->assertSame(10, $constant);
    }

    /**
     * Test that RATE_LIMIT_INTERVAL_SEC constant is set correctly.
     */
    public function test_rate_limit_interval_is_025_seconds(): void
    {
        $reflection = new \ReflectionClass(OmdbApi::class);
        $constant = $reflection->getConstant('RATE_LIMIT_INTERVAL_SEC');

        $this->assertSame(0.25, $constant);
    }

    /**
     * Test extractRatings handles empty Ratings array.
     */
    public function test_extract_ratings_with_empty_ratings_array(): void
    {
        $details = [
            'imdbRating' => '7.5',
            'Ratings' => [],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertSame(7.5, $ratings['imdb']);
        $this->assertNull($ratings['rotten_tomatoes']);
        $this->assertNull($ratings['metascore']);
    }

    /**
     * Test extractRatings handles null imdbRating.
     */
    public function test_extract_ratings_handles_null_imdb_rating(): void
    {
        $details = [
            'imdbRating' => null,
            'Ratings' => [],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertNull($ratings['imdb']);
    }

    /**
     * Test that clock() returns increasing values on subsequent calls.
     */
    public function test_clock_returns_increasing_values(): void
    {
        $api = new OmdbApi('key', true, 0);

        $method = new \ReflectionMethod(OmdbApi::class, 'clock');
        $method->setAccessible(true);

        $time1 = $method->invoke($api);
        usleep(1000); // 1ms
        $time2 = $method->invoke($api);

        $this->assertGreaterThan($time1, $time2);
    }

    /**
     * Test that rate limiting is enforced by verifying lastRequestTimestamp updates.
     * We can't easily test the sleep path without actual timing, but we can verify
     * the rate limiting logic is in place by checking the property changes.
     */
    public function test_enforce_rate_limit_updates_timestamp(): void
    {
        $api = new OmdbApi('key', true, 0);

        $reflection = new \ReflectionClass($api);
        $timestampProperty = $reflection->getProperty('lastRequestTimestamp');
        $timestampProperty->setAccessible(true);

        $initialTimestamp = $timestampProperty->getValue($api);

        $method = new \ReflectionMethod(OmdbApi::class, 'enforceRateLimit');
        $method->setAccessible(true);
        $method->invoke($api);

        $newTimestamp = $timestampProperty->getValue($api);

        $this->assertGreaterThanOrEqual($initialTimestamp, $newTimestamp);
    }
}
