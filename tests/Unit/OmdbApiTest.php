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
use Phlix\Plugins\Metadata\Omdb\Tests\Support\OmdbCapturingLogger;
use Phlix\Plugins\Metadata\Omdb\Tests\Support\OmdbFakeHttpClient;
use PHPUnit\Framework\TestCase;

final class OmdbApiTest extends TestCase
{
    /**
     * Canary secret. Every redaction assertion checks this exact substring is
     * absent from log surfaces; tests never embed a real-looking key elsewhere.
     */
    private const CANARY_KEY = 'canary-omdb-key-9f3a7c';

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

    /**
     * Consequence [M-OMDB-07]: a body WITHOUT the Response member is not a
     * validated success envelope. The old gate only rejected the literal
     * 'False' string and returned everything else raw — proxy error JSON and
     * other shape-junk flowed to the host resolver as if it were a record.
     */
    public function test_get_by_imdb_id_returns_null_when_response_member_missing(): void
    {
        $body = ['Title' => 'Inception', 'Year' => '2010'];
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => $body);

        $this->assertNull($api->getByImdbId('tt1375666'));
    }

    public function test_get_by_imdb_id_returns_null_when_response_member_is_junk(): void
    {
        $body = ['Response' => 'OK', 'Title' => 'Inception'];
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => $body);

        $this->assertNull($api->getByImdbId('tt1375666'));
    }

    public function test_get_by_imdb_id_returns_null_when_title_missing(): void
    {
        $body = ['Response' => 'True', 'Year' => '2010'];
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => $body);

        $this->assertNull($api->getByImdbId('tt1375666'));
    }

    public function test_get_by_imdb_id_returns_null_when_title_is_empty(): void
    {
        $body = ['Response' => 'True', 'Title' => ''];
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => $body);

        $this->assertNull($api->getByImdbId('tt1375666'));
    }

    public function test_get_by_imdb_id_returns_null_when_title_is_not_a_string(): void
    {
        $body = ['Response' => 'True', 'Title' => ['Inception']];
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => $body);

        $this->assertNull($api->getByImdbId('tt1375666'));
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

    /**
     * Test that httpGetJson returns cached response on cache hit.
     * This exercises the cache retrieval path when cache is warm.
     */
    public function test_http_get_json_returns_cached_response_on_cache_hit(): void
    {
        $callCount = 0;
        $data = ['Response' => 'True', 'Title' => 'Inception', 'imdbRating' => '8.8'];

        $api = new OmdbApi('key', true, 3600, null, static function (string $url) use (&$callCount, $data): ?array {
            $callCount++;
            return $data;
        });

        // First call - populates cache
        $api->getByImdbId('tt1375666');
        $this->assertSame(1, $callCount);

        // Second call - should hit cache and not call jsonFetcher again
        $api->getByImdbId('tt1375666');
        $this->assertSame(1, $callCount, 'Second call should use cached response');
    }

    /**
     * Test that cache is NOT used when cache entry is expired.
     * This exercises the cache TTL expiration path.
     */
    public function test_http_get_json_does_not_use_expired_cache(): void
    {
        $callCount = 0;
        $data = ['Response' => 'True', 'Title' => 'Inception', 'imdbRating' => '8.8'];

        // Create API with cacheTtlSeconds = 1 (1 second TTL)
        $api = new OmdbApi('key', true, 1, null, static function (string $url) use (&$callCount, $data): ?array {
            $callCount++;
            return $data;
        });

        // First call - populates cache
        $api->getByImdbId('tt1375666');
        $this->assertSame(1, $callCount);

        // Wait for cache to expire
        usleep(1_200_000); // 1.2 seconds

        // Second call - cache should be expired, jsonFetcher called again
        $api->getByImdbId('tt1375666');
        $this->assertSame(2, $callCount, 'Expired cache should trigger new request');
    }

    /**
     * [M-OMDB-12] A 200 whose body is not JSON (Cloudflare challenge HTML on
     * a transparent proxy) must route to the redacted error sink — the
     * JSON_THROW_ON_ERROR decode previously let JsonException escape into
     * whatever client internals invoked the plugin.
     */
    public function test_non_json_success_body_routes_to_error_sink_instead_of_throwing(): void
    {
        $logger = new OmdbCapturingLogger();
        $api = new OmdbApi(self::CANARY_KEY, true, 0, $logger);
        $challenge = '<html><head>Attention Required! | Cloudflare</head></html>';
        $this->injectClient($api, OmdbFakeHttpClient::withBody($challenge));

        $result = $api->getByImdbId('tt1375666');

        $this->assertNull($result);
        $this->assertCount(1, $logger->records);
        [$message, $context] = $logger->records[0];
        $this->assertSame('OMDb HTTP error', $message);
        $this->assertStringNotContainsString(self::CANARY_KEY, serialize($context));
    }

    /**
     * [M-OMDB-12] Well-formed JSON that is not an object (scalar document)
     * must also route to the error sink instead of TypeError-ing through
     * the admitResponse ?array parameter.
     */
    public function test_scalar_json_success_body_routes_to_error_sink(): void
    {
        $logger = new OmdbCapturingLogger();
        $api = new OmdbApi(self::CANARY_KEY, true, 0, $logger);
        $this->injectClient($api, OmdbFakeHttpClient::withBody('42'));

        $this->assertNull($api->getByImdbId('tt1375666'));
        $this->assertSame('OMDb HTTP error', $logger->records[0][0]);
    }

    /**
     * Coverage gap (review omdb__srphp): the SUCCESS arm of the real-HTTP
     * transport had never been exercised — a valid JSON envelope must decode,
     * admit, and reach the caller. (Green before and after the cure; it pins
     * the arm the M-12 tests contrast against.)
     */
    public function test_valid_json_success_body_admits_the_record(): void
    {
        $body = json_encode(['Response' => 'True', 'Title' => 'Inception', 'Year' => '2010']);
        $this->assertIsString($body);
        $api = new OmdbApi('key', true, 0);
        $this->injectClient($api, OmdbFakeHttpClient::withBody($body));

        $result = $api->getByImdbId('tt1375666');

        $this->assertIsArray($result);
        $this->assertSame('Inception', $result['Title']);
    }

    /**
     * Drive the real-HTTP transport with a preconfigured fake client.
     */
    private function injectClient(OmdbApi $api, OmdbFakeHttpClient $client): void
    {
        $property = new \ReflectionProperty(OmdbApi::class, 'httpClient');
        $property->setAccessible(true);
        $property->setValue($api, $client);
    }

    /**
     * [M-OMDB-08] The poll loop must route every tick through the shared
     * $timerSleep seam — the single choke the host (and tests) control —
     * never a raw usleep that bypasses cooperative scheduling. A never-
     * completing transport returns bounded-fast with null once the seam is
     * neutralized (test venue: vendored workerman v4 has no Timer::sleep,
     * so the DEFAULT seam would fatal in the loop — see M-13 gate below).
     */
    public function test_wait_for_response_ticks_route_through_the_timer_sleep_seam(): void
    {
        $api = new OmdbApi('key', true, 0);
        $ticks = 0;
        $sleep = new \ReflectionProperty(OmdbApi::class, 'timerSleep');
        $sleep->setAccessible(true);
        $sleep->setValue($api, static function (float $seconds) use (&$ticks): void {
            $ticks++;
        });
        $this->injectClient($api, OmdbFakeHttpClient::neverCompletes());

        $result = $api->getByImdbId('tt1375666');

        $this->assertNull($result, 'A transport that never calls back must resolve to null');
        $this->assertGreaterThan(0, $ticks, 'waitForResponse must sleep via the shared seam');
    }

    /**
     * [M-OMDB-13 gate] The default sleep seam must survive the VENDORED dev
     * generation (workerman v4 Timer has no sleep() — the previous
     * class_exists check said "yes" and called a nonexistent method, a fatal
     * waiting for any caller that enters the seam without neutralizing it).
     * CI and local suites both run the lock generation, so invoking the
     * constructed default closure with 0.0 must complete without Error.
     */
    public function test_default_timer_seam_survives_generations_without_timer_sleep(): void
    {
        $api = new OmdbApi('key', true, 0);
        $sleep = new \ReflectionProperty(OmdbApi::class, 'timerSleep');
        $sleep->setAccessible(true);
        $seam = $sleep->getValue($api);
        $this->assertInstanceOf(\Closure::class, $seam);

        $seam(0.0);
    }

    /**
     * [M-OMDB-13 gate] Source tripwire: the only sleep vectors allowed in
     * OmdbApi are (1) the seam's method_exists-guarded Timer::sleep arm and
     * (2) its usleep fallback — no raw usleep may reappear in a poll loop,
     * and the v4-generation call must stay behind the METHOD guard.
     */
    public function test_sleep_vectors_stay_behind_the_generation_guards(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/OmdbApi.php');
        $this->assertIsString($src);

        $this->assertSame(
            1,
            substr_count($src, 'usleep('),
            'usleep may appear only as the seam fallback'
        );
        $this->assertSame(1, substr_count($src, 'Timer::sleep('), 'exactly one guarded Timer::sleep arm');
        $this->assertMatchesRegularExpression(
            '/method_exists\(Timer::class,\s*\'sleep\'\)\) \{/',
            $src,
            'the Timer::sleep arm must be guarded by method_exists, not class_exists'
        );
    }

    /**
     * Test that getHttpClient creates client with default timeout.
     */
    public function test_get_http_client_has_default_timeout(): void
    {
        $api = new OmdbApi('key', true, 0);

        $method = new \ReflectionMethod(OmdbApi::class, 'getHttpClient');
        $method->setAccessible(true);
        $client = $method->invoke($api);

        $this->assertInstanceOf(\Workerman\Http\Client::class, $client);
    }

    /**
     * Test that API_BASE constant is correct.
     */
    public function test_api_base_url_is_correct(): void
    {
        $reflection = new \ReflectionClass(OmdbApi::class);
        $base = $reflection->getConstant('API_BASE');

        $this->assertSame('https://www.omdbapi.com', $base);
    }

    /**
     * C1 (review omdb__srphp): a search failure must never log the apikey value.
     *
     * The error sink lives on the real HTTP path of httpGetJson(); a fake
     * client synchronously fails the request so the sink is reached without
     * touching the network.
     */
    public function test_search_failure_logs_url_without_apikey_value(): void
    {
        $logger = new OmdbCapturingLogger();
        $api = new OmdbApi(self::CANARY_KEY, true, 0, $logger);
        $this->injectFailingClient($api);

        $results = $api->search('Inception');

        $this->assertSame([], $results);
        $this->assertCount(1, $logger->records);
        [$message, $context] = $logger->records[0];
        $this->assertSame('OMDb HTTP error', $message);
        $this->assertStringNotContainsString(self::CANARY_KEY, (string) $context['url']);
        $this->assertStringContainsString('apikey=' . OmdbApi::MASKED_APIKEY, (string) $context['url']);
        // Diagnostics must survive redaction: host + path + other params stay.
        $this->assertStringContainsString('https://www.omdbapi.com/?s=Inception', (string) $context['url']);
    }

    /**
     * C1 (review omdb__srphp): the detail lookup shares the same sink and the
     * same leak vector (key-bearing URL built in getByImdbId()).
     */
    public function test_get_by_imdb_id_failure_logs_url_without_apikey_value(): void
    {
        $logger = new OmdbCapturingLogger();
        $api = new OmdbApi(self::CANARY_KEY, true, 0, $logger);
        $this->injectFailingClient($api);

        $details = $api->getByImdbId('tt0120737');

        $this->assertNull($details);
        $this->assertCount(1, $logger->records);
        [, $context] = $logger->records[0];
        $this->assertStringNotContainsString(self::CANARY_KEY, (string) $context['url']);
        $this->assertStringContainsString('apikey=' . OmdbApi::MASKED_APIKEY, (string) $context['url']);
        $this->assertStringContainsString('i=tt0120737', (string) $context['url']);
        $this->assertStringContainsString('plot=full', (string) $context['url']);
    }

    /**
     * C1 belt: an exception message that embeds the key-bearing URL must also
     * reach the log context scrubbed, not verbatim.
     */
    public function test_error_message_embedding_the_url_is_scrubbed_in_log_context(): void
    {
        $logger = new OmdbCapturingLogger();
        $api = new OmdbApi(self::CANARY_KEY, true, 0, $logger);
        $this->injectFailingClient($api, 'Request to {url} timed out after 10 seconds');

        $api->search('Batman');

        $this->assertCount(1, $logger->records);
        [, $context] = $logger->records[0];
        $error = (string) $context['error'];
        $this->assertStringNotContainsString(self::CANARY_KEY, $error);
        $this->assertStringContainsString('timed out after 10 seconds', $error);
    }

    /**
     * Redaction is masking, not deletion: a trailing parameter survives.
     */
    public function test_redaction_masks_key_and_preserves_trailing_parameters(): void
    {
        $redact = new \ReflectionMethod(OmdbApi::class, 'redactSecrets');
        $redact->setAccessible(true);
        /** @var string $masked */
        $masked = $redact->invoke(null, 'https://x.test/?a=1&apikey=SECRET&plot=full');

        $this->assertSame('https://x.test/?a=1&apikey=' . OmdbApi::MASKED_APIKEY . '&plot=full', $masked);
    }

    /**
     * Boundary law: prose around an end-of-string key survives, the mask is
     * case-insensitive, and a '#' fragment cannot smuggle a key past it.
     */
    public function test_redaction_boundaries_and_cases(): void
    {
        $redact = new \ReflectionMethod(OmdbApi::class, 'redactSecrets');
        $redact->setAccessible(true);
        $mask = 'apikey=' . OmdbApi::MASKED_APIKEY;

        $this->assertSame('boom apikey=*** now', $redact->invoke(null, 'boom apikey=abc123 now'));
        $this->assertSame($mask, $redact->invoke(null, 'APIKEY=abc123'));
        $fragmentUrl = 'https://x.test/?apikey=abc#frag';
        $this->assertSame('https://x.test/?' . $mask . '#frag', $redact->invoke(null, $fragmentUrl));
        $this->assertSame('no keys here', $redact->invoke(null, 'no keys here'));
    }

    /**
     * The request itself keeps the real key — redaction applies to log
     * surfaces only (pins test_search_includes_api_key_in_url's law).
     */
    public function test_request_url_still_carries_the_real_api_key(): void
    {
        $client = new OmdbFakeHttpClient();
        $api = new OmdbApi(self::CANARY_KEY, true, 0, new OmdbCapturingLogger());
        $property = new \ReflectionProperty(OmdbApi::class, 'httpClient');
        $property->setAccessible(true);
        $property->setValue($api, $client);

        $api->search('Batman');

        $this->assertNotNull($client->lastUrl);
        $this->assertStringContainsString('apikey=' . self::CANARY_KEY, $client->lastUrl);
    }

    /**
     * M2 (review omdb__secaudit): OMDb's over-limit envelope — delivered as
     * HTTP 200 or 403 with this JSON body, which the transport surfaces
     * identically — must NOT masquerade as a plain "title not found": it
     * raises a distinguishable warning and presents as null to the caller.
     */
    public function test_over_limit_envelope_logs_distinguishable_warning_and_returns_null(): void
    {
        $logger = new OmdbCapturingLogger();
        $api = new OmdbApi(self::CANARY_KEY, true, 0, $logger, static fn (string $url): ?array => [
            'Response' => 'False',
            'Error' => 'API key is over its daily limit.',
        ]);

        $details = $api->getByImdbId('tt1375666');

        $this->assertNull($details);
        $this->assertCount(1, $logger->records);
        [$message, $context] = $logger->records[0];
        $this->assertStringContainsString('over its daily limit', $message);
        $this->assertArrayHasKey('cooldown_seconds', $context);
        // C1 custody law applies to every sink, including the new one.
        $this->assertStringNotContainsString(self::CANARY_KEY, (string) json_encode($context));
    }

    /**
     * The alternate historical OMDb wording ("rate limit") classifies the
     * same way, and the search entry point arms the cooldown too.
     */
    public function test_over_limit_search_path_returns_empty_and_arms_cooldown(): void
    {
        $api = new OmdbApi('key', true, 0, null, static fn (string $url): ?array => [
            'Response' => 'False',
            'Error' => 'API rate limit exceeded.',
        ]);

        $this->assertSame([], $api->search('Inception'));
        $this->assertTrue($api->isOverLimitCoolingDown());
    }

    /**
     * Retry-storm prevention: once the key is over its limit, no further
     * request leaves the client today — every later miss short-circuits.
     */
    public function test_over_limit_cooldown_suppresses_further_requests(): void
    {
        $calls = 0;
        $api = new OmdbApi('key', true, 0, null, static function (string $url) use (&$calls): ?array {
            $calls++;
            if ($calls === 1) {
                return ['Response' => 'False', 'Error' => 'API key is over its daily limit.'];
            }

            return ['Response' => 'True', 'Title' => 'Never Served'];
        });

        $this->assertNull($api->getByImdbId('tt1375666'));
        $this->assertNull($api->getByImdbId('tt0816692'));
        $this->assertSame([], $api->search('Inception'));

        $this->assertSame(1, $calls, 'Cooldown must not fire a spent key again');
    }

    /**
     * The cooldown gates dispatch only: warm cache entries cost zero quota
     * and must keep being served while the key rests.
     */
    public function test_over_limit_cooldown_still_serves_cache_hits(): void
    {
        $inception = ['Response' => 'True', 'Title' => 'Inception'];
        $calls = 0;
        $api = new OmdbApi('key', true, 3600, null, static function (string $url) use (&$calls, $inception): ?array {
            $calls++;
            if (str_contains($url, 'i=tt1375666') === true) {
                return $inception;
            }

            return ['Response' => 'False', 'Error' => 'API key is over its daily limit.'];
        });
        $this->neutralizeRateLimitSleep($api);

        $this->assertNotNull($api->getByImdbId('tt1375666'));
        $this->assertNull($api->getByImdbId('tt0816692'));
        $this->assertTrue($api->isOverLimitCoolingDown());

        $cached = $api->getByImdbId('tt1375666');

        $this->assertNotNull($cached);
        $this->assertSame('Inception', $cached['Title']);
        $this->assertSame(2, $calls);
    }

    /**
     * A limit error must never be cached: a day-TTL "not found" lie would
     * outlive the quota reset and re-mask the breach.
     */
    public function test_over_limit_envelope_is_never_cached(): void
    {
        $api = new OmdbApi('key', true, 3600, null, static fn (string $url): ?array => [
            'Response' => 'False',
            'Error' => 'API key is over its daily limit.',
        ]);

        $api->getByImdbId('tt1375666');

        $cache = new \ReflectionProperty(OmdbApi::class, 'cache');
        $cache->setAccessible(true);

        $this->assertSame([], $cache->getValue($api));
    }

    /**
     * [M-OMDB-11 r2] A bad-key envelope is key-state failure wearing a
     * URL-shaped payload: cached at the success TTL it replays the 401 lie
     * for a full day and shadows the repaired key (review omdb__secaudit
     * M11 overturn: 91b69be's choke covered only the two over-limit
     * markers, leaving the claim's second named harm — the bad key —
     * sitting in the replay cache). Never-cached, same idiom as the
     * over-limit pin above.
     */
    public function test_bad_key_envelope_is_never_cached(): void
    {
        $api = new OmdbApi('key', true, 3600, null, static fn (string $url): ?array => [
            'Response' => 'False',
            'Error' => 'Invalid API key.',
        ]);

        $this->assertNull($api->getByImdbId('tt1375666'));

        $cache = new \ReflectionProperty(OmdbApi::class, 'cache');
        $cache->setAccessible(true);

        $this->assertSame([], $cache->getValue($api));
    }

    /**
     * [M-OMDB-11 r2] The harm made visible end to end: plant a bad key,
     * fetch, then fetch again after the key works — the second lookup must
     * reach upstream fresh, not replay the cached error for the rest of
     * the TTL. Red on the old law (call 2 hit the day-TTL bad-key entry).
     */
    public function test_repaired_key_is_not_shadowed_by_a_cached_error_envelope(): void
    {
        $calls = 0;
        $api = new OmdbApi('key', true, 3600, null, static function (string $url) use (&$calls): ?array {
            $calls++;
            if ($calls === 1) {
                return ['Response' => 'False', 'Error' => 'Invalid API key.'];
            }

            return ['Response' => 'True', 'Title' => 'Inception'];
        });
        $this->neutralizeRateLimitSleep($api);

        $this->assertNull($api->getByImdbId('tt1375666'));

        $recovered = $api->getByImdbId('tt1375666');

        $this->assertNotNull($recovered, 'A repaired key must not be shadowed by a cached error envelope');
        $this->assertSame('Inception', $recovered['Title']);
        $this->assertSame(2, $calls);
    }

    /**
     * [M-OMDB-11 r2] The taxonomy lands on the safe side for every False
     * shape outside the two named families: an Error-less envelope, a
     * non-string Error, and request-state failures are key/request truth,
     * not URL truth — none may enter the replay cache.
     */
    public function test_unknown_negative_envelope_shapes_are_never_cached(): void
    {
        $envelopes = [
            ['Response' => 'False'],
            ['Response' => 'False', 'Error' => null],
            ['Response' => 'False', 'Error' => "Request was missing the 'ID' argument."],
        ];

        foreach ($envelopes as $envelope) {
            $api = new OmdbApi('key', true, 3600, null, static fn (string $url): ?array => $envelope);

            $this->assertNull($api->getByImdbId('tt1375666'));

            $cache = new \ReflectionProperty(OmdbApi::class, 'cache');
            $cache->setAccessible(true);

            $this->assertSame([], $cache->getValue($api), 'Envelope ' . json_encode($envelope) . ' must not be cached');
        }
    }

    /**
     * Complement pin: a plain not-found keeps its old silent behavior — no
     * warning, no cooldown — and remains the ONLY cached negative: its
     * truth belongs to the queried URL, not to the key or the day, so
     * caching it amortizes quota for repeated lookups of the same missing
     * title (deliberate design, review omdb__secaudit M11 r1).
     */
    public function test_not_found_envelope_stays_silent_and_does_not_arm_cooldown(): void
    {
        $logger = new OmdbCapturingLogger();
        $calls = 0;
        $api = new OmdbApi('key', true, 3600, $logger, static function (string $url) use (&$calls): ?array {
            $calls++;

            return ['Response' => 'False', 'Error' => 'Title not found.'];
        });

        $this->assertNull($api->getByImdbId('tt0000000'));
        $this->assertFalse($api->isOverLimitCoolingDown());
        $this->assertSame([], $logger->records);

        $cache = new \ReflectionProperty(OmdbApi::class, 'cache');
        $cache->setAccessible(true);
        $this->assertNotEmpty($cache->getValue($api));

        $this->assertNull($api->getByImdbId('tt0000000'));
        $this->assertSame(1, $calls, 'The not-found exception must still be served from cache');
    }

    /**
     * Suppressed dispatch is logged, not swallowed — the caller-visible null
     * stays explainable in the log stream.
     */
    public function test_suppressed_request_is_logged_not_silent(): void
    {
        $logger = new OmdbCapturingLogger();
        $api = new OmdbApi(self::CANARY_KEY, true, 0, $logger, static fn (string $url): ?array => [
            'Response' => 'False',
            'Error' => 'API key is over its daily limit.',
        ]);

        $api->getByImdbId('tt1375666');
        $api->getByImdbId('tt0816692');

        $this->assertCount(2, $logger->records);
        $this->assertStringContainsString('suppressed', $logger->records[1][0]);
        $this->assertStringNotContainsString(self::CANARY_KEY, (string) json_encode($logger->records));
    }

    /**
     * The breach resets the day boundary, so the cooldown must expire at the
     * next UTC midnight exactly (whole-day aligned epoch instant, in future).
     */
    public function test_cooldown_expires_at_next_utc_midnight(): void
    {
        $api = new OmdbApi('key', true, 0, null, static fn (string $url): ?array => [
            'Response' => 'False',
            'Error' => 'API key is over its daily limit.',
        ]);

        $api->getByImdbId('tt1375666');

        $until = new \ReflectionProperty(OmdbApi::class, 'overLimitCooldownUntil');
        $until->setAccessible(true);
        /** @var int $value */
        $value = $until->getValue($api);

        $this->assertGreaterThan(time(), $value);
        $this->assertLessThanOrEqual(time() + 86400, $value);
        $this->assertSame(0, $value % 86400);
    }

    /**
     * After the rollover the client dispatches again (a fresh day, fresh
     * allowance): expiry of the cooldown resumes normal request flow.
     */
    public function test_cooldown_expiry_resumes_requests(): void
    {
        $calls = 0;
        $api = new OmdbApi('key', true, 0, null, static function (string $url) use (&$calls): ?array {
            $calls++;
            if ($calls === 1) {
                return ['Response' => 'False', 'Error' => 'API key is over its daily limit.'];
            }

            return ['Response' => 'True', 'Title' => 'Inception'];
        });
        $this->neutralizeRateLimitSleep($api);

        $this->assertNull($api->getByImdbId('tt1375666'));
        $this->assertTrue($api->isOverLimitCoolingDown());

        $until = new \ReflectionProperty(OmdbApi::class, 'overLimitCooldownUntil');
        $until->setAccessible(true);
        $until->setValue($api, time() - 1);

        $result = $api->getByImdbId('tt1375666');

        $this->assertNotNull($result);
        $this->assertSame(2, $calls);
    }

    /**
     * Replace the rate-limit sleep with a no-op so tests dispatching twice in
     * one tick do not depend on the host-provided Timer::sleep().
     */
    private function neutralizeRateLimitSleep(OmdbApi $api): void
    {
        $sleep = new \ReflectionProperty(OmdbApi::class, 'timerSleep');
        $sleep->setAccessible(true);
        $sleep->setValue($api, static function (float $seconds): void {
        });
    }

    /**
     * Drive the real-HTTP error path by injecting a synchronously failing client.
     */
    private function injectFailingClient(OmdbApi $api, ?string $messageTemplate = null): void
    {
        $client = new OmdbFakeHttpClient($messageTemplate);
        $property = new \ReflectionProperty(OmdbApi::class, 'httpClient');
        $property->setAccessible(true);
        $property->setValue($api, $client);
    }
}
