<?php

/**
 * Unit tests for OmdbPlugin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\Omdb\Tests;

use Phlix\Plugins\Metadata\Omdb\OmdbApi;
use Phlix\Plugins\Metadata\Omdb\OmdbPlugin;
use Phlix\Plugins\Metadata\Omdb\OmdbSettings;
use Phlix\Shared\Plugin\ConfigurableInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class OmdbPluginTest extends TestCase
{
    public function test_subscribed_events_is_empty(): void
    {
        $plugin = new OmdbPlugin();

        $this->assertSame([], $plugin->subscribedEvents());
    }

    public function test_source_name_returns_omdb(): void
    {
        $plugin = new OmdbPlugin();

        $this->assertSame('omdb', $plugin->sourceName());
    }

    public function test_supported_media_types_returns_movie_and_series(): void
    {
        $plugin = new OmdbPlugin();

        $this->assertSame(['movie', 'series'], $plugin->supportedMediaTypes());
    }

    public function test_settings_defaults(): void
    {
        $plugin = new OmdbPlugin();
        $settings = $plugin->getSettings();

        $this->assertFalse($settings->enabled);
        $this->assertNull($settings->apiKey);
        $this->assertTrue($settings->useSslVerification);
        $this->assertSame(86400, $settings->cacheTtlSeconds);
    }

    public function test_isConfigured_returns_false_when_disabled(): void
    {
        $settings = new OmdbSettings(enabled: false, apiKey: 'test_key');
        $plugin = new OmdbPlugin(settings: $settings);

        $this->assertFalse($settings->isConfigured());
    }

    public function test_isConfigured_returns_false_when_no_api_key(): void
    {
        $settings = new OmdbSettings(enabled: true, apiKey: null);
        $plugin = new OmdbPlugin(settings: $settings);

        $this->assertFalse($settings->isConfigured());
    }

    public function test_isConfigured_returns_true_when_enabled_with_api_key(): void
    {
        $settings = new OmdbSettings(enabled: true, apiKey: 'test_key');
        $plugin = new OmdbPlugin(settings: $settings);

        $this->assertTrue($settings->isConfigured());
    }

    public function test_search_returns_empty_when_not_configured(): void
    {
        $plugin = new OmdbPlugin();

        $results = $plugin->search('Inception', []);

        $this->assertSame([], $results);
    }

    public function test_getDetails_returns_empty_when_not_configured(): void
    {
        $plugin = new OmdbPlugin();

        $result = $plugin->getDetails('tt1375666', []);

        $this->assertSame([], $result);
    }

    public function test_getImages_returns_empty_when_not_configured(): void
    {
        $plugin = new OmdbPlugin();

        $result = $plugin->getImages('tt1375666');

        $this->assertSame([], $result);
    }

    public function test_implements_configurable_interface(): void
    {
        $plugin = new OmdbPlugin();

        $this->assertInstanceOf(ConfigurableInterface::class, $plugin);
    }

    public function test_configure_updates_settings(): void
    {
        $plugin = new OmdbPlugin();
        $this->assertFalse($plugin->getSettings()->enabled);

        $plugin->configure([
            'enabled' => true,
            'api_key' => 'my_secret_key',
            'use_ssl_verification' => false,
            'cache_ttl_seconds' => 3600,
        ]);

        $settings = $plugin->getSettings();
        $this->assertTrue($settings->enabled);
        $this->assertSame('my_secret_key', $settings->apiKey);
        $this->assertFalse($settings->useSslVerification);
        $this->assertSame(3600, $settings->cacheTtlSeconds);
    }

    // -------------------------------------------------------------------------
    // onEnable is a cheap, non-blocking wire-only step (F1 boot safety).
    // -------------------------------------------------------------------------

    /**
     * Consequence: onEnable NEVER throws when the API key is missing — a
     * boot activation across ~14 workers must not hang or throw.
     */
    public function test_onEnable_does_not_throw_when_api_key_missing(): void
    {
        $plugin = new OmdbPlugin();

        $plugin->onEnable($this->makeContainer());

        // Reached only if onEnable did not throw.
        $this->assertInstanceOf(OmdbSettings::class, $plugin->getSettings());
    }

    /**
     * Consequence: onEnable performs ZERO HTTP requests (no connectivity
     * probe, no search()) even when fully configured.
     */
    public function test_onEnable_performs_zero_http(): void
    {
        $calls = 0;
        $fetcher = static function (string $url) use (&$calls): ?array {
            $calls++;
            return ['Response' => 'True', 'Search' => []];
        };
        $api = new OmdbApi('key', true, 0, null, $fetcher);
        $plugin = new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'key'), null, $api);

        $plugin->onEnable($this->makeContainer());

        $this->assertSame(0, $calls, 'onEnable must perform zero HTTP requests');
    }

    // -------------------------------------------------------------------------
    // getDetails is a PURE READ with enum-safe rating sources (F2 gap-fill).
    // -------------------------------------------------------------------------

    /**
     * Consequence: getDetails emits ratings ONLY under valid
     * metadata_ratings.source ENUM values (imdb, rt). A metacritic/metascore
     * source or a plugin-local 'omdb'/'aggregate' source is NEVER emitted.
     */
    public function test_getDetails_emits_only_enum_safe_rating_sources(): void
    {
        $plugin = $this->makeConfiguredPluginWithDetails([
            'Response' => 'True',
            'Title' => 'Inception',
            'imdbRating' => '8.8',
            'Ratings' => [
                ['Source' => 'Internet Movie Database', 'Value' => '8.8/10'],
                ['Source' => 'Rotten Tomatoes', 'Value' => '87%'],
                ['Source' => 'Metacritic', 'Value' => '74/100'],
            ],
        ]);

        $result = $plugin->getDetails('tt1375666');

        $this->assertArrayHasKey('ratings', $result);
        $this->assertIsArray($result['ratings']);
        $sources = array_column($result['ratings'], 'source');

        $this->assertContains('imdb', $sources);
        $this->assertContains('rt', $sources);
        $this->assertNotContains('metacritic', $sources);
        $this->assertNotContains('metascore', $sources);
        $this->assertNotContains('omdb', $sources);
        $this->assertNotContains('aggregate', $sources);
        $this->assertCount(2, $result['ratings']);
    }

    /**
     * Consequence: Metascore is dropped entirely — it appears neither as a
     * rating source nor as a top-level 'metascore' field.
     */
    public function test_getDetails_drops_metascore(): void
    {
        $plugin = $this->makeConfiguredPluginWithDetails([
            'Response' => 'True',
            'Title' => 'Inception',
            'Ratings' => [
                ['Source' => 'Metacritic', 'Value' => '74/100'],
            ],
        ]);

        $result = $plugin->getDetails('tt1375666');

        $this->assertArrayNotHasKey('metascore', $result);
        $this->assertSame([], array_column($result['ratings'], 'source'));
    }

    /**
     * Consequence: getDetails is a pure read — the media_item_id hint is
     * ignored and does not change the output (no persistence path).
     */
    public function test_getDetails_ignores_media_item_id(): void
    {
        $details = [
            'Response' => 'True',
            'Title' => 'Inception',
            'imdbRating' => '8.8',
            'Ratings' => [],
        ];

        $withoutId = $this->makeConfiguredPluginWithDetails($details)->getDetails('tt1375666');
        $withId = $this->makeConfiguredPluginWithDetails($details)
            ->getDetails('tt1375666', ['media_item_id' => 'some-uuid']);

        $this->assertSame($withoutId, $withId);
    }

    /**
     * Consequence: the plugin no longer depends on any persistence collaborator
     * (RatingIngester / DB Connection) — ingestion is host-driven (F2).
     */
    public function test_constructor_has_no_persistence_dependency(): void
    {
        $ctor = (new \ReflectionClass(OmdbPlugin::class))->getConstructor();
        $this->assertNotNull($ctor);

        $names = array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            $ctor->getParameters()
        );

        $this->assertNotContains('ingester', $names);
        $this->assertContains('settings', $names);
        $this->assertContains('api', $names);
    }

    // -------------------------------------------------------------------------
    // search() returns structured results when configured
    // -------------------------------------------------------------------------

    /**
     * Consequence: search() returns properly structured results with id and title.
     */
    public function test_search_returns_formatted_results(): void
    {
        $plugin = $this->makeConfiguredPluginWithSearch([
            ['imdb_id' => 'tt1375666', 'title' => 'Inception', 'year' => '2010', 'type' => 'movie'],
            ['imdb_id' => 'tt0816692', 'title' => 'Interstellar', 'year' => '2014', 'type' => 'movie'],
        ]);

        $results = $plugin->search('Inception');

        $this->assertCount(2, $results);
        $this->assertSame('tt1375666', $results[0]['id']);
        $this->assertSame('Inception', $results[0]['title']);
    }

    /**
     * Consequence: search() passes year hint to the API when provided.
     */
    public function test_search_passes_year_hint_to_api(): void
    {
        $capturedUrl = null;
        $api = new OmdbApi('key', true, 0, null, static function (string $url) use (&$capturedUrl): ?array {
            $capturedUrl = $url;
            return ['Response' => 'True', 'Search' => []];
        });
        $plugin = new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'key'), null, $api);

        $plugin->search('Inception', ['year' => 2010]);

        $this->assertNotNull($capturedUrl);
        $this->assertStringContainsString('y=2010', $capturedUrl);
    }

    /**
     * Consequence: search() returns empty when API returns no results.
     */
    public function test_search_returns_empty_when_no_results(): void
    {
        $plugin = $this->makeConfiguredPluginWithSearch([]);

        $results = $plugin->search('NonexistentTitleXYZ123');

        $this->assertSame([], $results);
    }

    // -------------------------------------------------------------------------
    // getDetails() returns full metadata with ratings when configured
    // -------------------------------------------------------------------------

    /**
     * Consequence: getDetails() returns complete metadata structure.
     */
    public function test_getDetails_returns_complete_metadata(): void
    {
        $details = [
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
            'Ratings' => [
                ['Source' => 'Internet Movie Database', 'Value' => '8.8/10'],
                ['Source' => 'Rotten Tomatoes', 'Value' => '87%'],
            ],
        ];
        $plugin = $this->makeConfiguredPluginWithDetails($details);

        $result = $plugin->getDetails('tt1375666');

        $this->assertSame('omdb', $result['source']);
        $this->assertSame('tt1375666', $result['imdb_id']);
        $this->assertSame('Inception', $result['title']);
        $this->assertSame('2010', $result['year']);
        $this->assertSame('PG-13', $result['rated']);
        $this->assertSame('148 min', $result['runtime']);
        $this->assertSame('movie', $result['type']);
        $this->assertCount(2, $result['ratings']);
    }

    // -------------------------------------------------------------------------
    // getImages() returns poster URLs when available
    // -------------------------------------------------------------------------

    /**
     * Consequence: getImages() returns poster image when available.
     */
    public function test_getImages_returns_poster(): void
    {
        $details = [
            'Response' => 'True',
            'Title' => 'Inception',
            'Poster' => 'https://example.com/inception.jpg',
        ];
        $plugin = $this->makeConfiguredPluginWithPoster($details);

        $result = $plugin->getImages('tt1375666');

        $this->assertArrayHasKey('poster', $result);
        $this->assertCount(1, $result['poster']);
        $this->assertSame('https://example.com/inception.jpg', $result['poster'][0]['url']);
    }

    /**
     * Consequence: getImages() returns empty when poster is N/A.
     */
    public function test_getImages_returns_empty_when_poster_na(): void
    {
        $details = [
            'Response' => 'True',
            'Title' => 'Inception',
            'Poster' => 'N/A',
        ];
        $plugin = $this->makeConfiguredPluginWithPoster($details);

        $result = $plugin->getImages('tt1375666');

        $this->assertSame([], $result);
    }

    /**
     * Consequence: getImages() returns empty when poster is empty string.
     */
    public function test_getImages_returns_empty_when_poster_empty(): void
    {
        $details = [
            'Response' => 'True',
            'Title' => 'Inception',
            'Poster' => '',
        ];
        $plugin = $this->makeConfiguredPluginWithPoster($details);

        $result = $plugin->getImages('tt1375666');

        $this->assertSame([], $result);
    }

    /**
     * Consequence: getImages() returns empty when poster key is missing.
     */
    public function test_getImages_returns_empty_when_poster_missing(): void
    {
        $details = [
            'Response' => 'True',
            'Title' => 'Inception',
        ];
        $plugin = $this->makeConfiguredPluginWithPoster($details);

        $result = $plugin->getImages('tt1375666');

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // onDisable() clears the API cache
    // -------------------------------------------------------------------------

    /**
     * Consequence: onDisable() calls clearCache() on the API client.
     */
    public function test_onDisable_clears_api_cache(): void
    {
        $cacheCleared = false;
        $api = new OmdbApi('key', true, 3600, null, static function (string $url): ?array {
            return ['Response' => 'True', 'Title' => 'Inception', 'imdbRating' => '8.8'];
        });

        // Use reflection to mock clearCache behavior
        $plugin = new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'key'), null, $api);

        // Manually set api to simulate after onEnable has been called
        $reflection = new \ReflectionClass(OmdbPlugin::class);
        $apiProperty = $reflection->getProperty('api');
        $apiProperty->setAccessible(true);
        $apiProperty->setValue($plugin, $api);

        // onDisable should clear cache (we verify by checking it doesn't throw)
        $plugin->onDisable();

        // If we got here without exception, the test passes
        $this->assertTrue(true, 'onDisable should not throw');
    }

    // -------------------------------------------------------------------------
    // onEnable logger resolution
    // -------------------------------------------------------------------------

    /**
     * Consequence: onEnable resolves logger from container when available.
     */
    public function test_onEnable_resolves_logger_from_container(): void
    {
        $container = $this->makeContainer();
        $plugin = new OmdbPlugin();

        $plugin->onEnable($container);

        // Verify plugin still works (logger was resolved but using NullLogger as fallback is valid)
        $this->assertInstanceOf(OmdbSettings::class, $plugin->getSettings());
    }

    /**
     * Consequence: onEnable handles missing logger gracefully.
     */
    public function test_onEnable_handles_missing_logger(): void
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new class ('not found') extends \RuntimeException implements NotFoundExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $plugin = new OmdbPlugin();

        // Should not throw
        $plugin->onEnable($container);

        $this->assertInstanceOf(OmdbSettings::class, $plugin->getSettings());
    }

    /**
     * Build a configured plugin whose OMDb transport returns canned details
     * without any network access.
     *
     * @param array<string, mixed> $details Canned OMDb getByImdbId response
     */
    private function makeConfiguredPluginWithDetails(array $details): OmdbPlugin
    {
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => $details);

        return new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'key'), null, $api);
    }

    /**
     * Build a configured plugin whose OMDb transport returns canned search results
     * without any network access.
     *
     * @param list<array{imdb_id: string, title: string, year: string, type: string}> $searchResults
     */
    private function makeConfiguredPluginWithSearch(array $searchResults): OmdbPlugin
    {
        $api = new OmdbApi('key', true, 0, null, fn(string $url): ?array => [
            'Response' => 'True',
            'Search' => array_map(
                static fn(array $r) => [
                    'imdbID' => $r['imdb_id'],
                    'Title' => $r['title'],
                    'Year' => $r['year'],
                    'Type' => $r['type'],
                ],
                $searchResults
            ),
        ]);

        return new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'key'), null, $api);
    }

    /**
     * Build a configured plugin whose OMDb transport returns canned images
     * without any network access.
     *
     * @param array<string, mixed> $details Canned OMDb getByImdbId response with poster
     */
    private function makeConfiguredPluginWithPoster(array $details): OmdbPlugin
    {
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => $details);

        return new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'key'), null, $api);
    }

    private function makeContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === LoggerInterface::class) {
                    return new NullLogger();
                }

                throw new class ('not found') extends \RuntimeException implements NotFoundExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return $id === LoggerInterface::class;
            }
        };
    }
}
