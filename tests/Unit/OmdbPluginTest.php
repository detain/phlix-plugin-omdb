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
use Phlix\Plugins\Metadata\Omdb\Tests\Support\OmdbCapturingLogger;
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

        // [OMB-15] Reaching this line proves no throw; the observable
        // contract is that no transport was wired without a key.
        $this->assertNull($this->apiOf($plugin), 'onEnable must not wire a transport without an API key');
    }

    /**
     * Consequence: with a key configured, onEnable runs the wire step and
     * the plugin holds a self-built transport afterwards.
     */
    public function test_onEnable_wires_the_transport_when_api_key_present(): void
    {
        $plugin = new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'key'));
        $this->assertNull($this->apiOf($plugin));

        $plugin->onEnable($this->makeContainer());

        $this->assertInstanceOf(OmdbApi::class, $this->apiOf($plugin));
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
            'Poster' => 'https://m.media-amazon.com/images/M/poster.jpg',
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
            'Poster' => 'https://m.media-amazon.com/images/M/inception.jpg',
        ];
        $plugin = $this->makeConfiguredPluginWithPoster($details);

        $result = $plugin->getImages('tt1375666');

        $this->assertArrayHasKey('poster', $result);
        $this->assertCount(1, $result['poster']);
        $this->assertSame('https://m.media-amazon.com/images/M/inception.jpg', $result['poster'][0]['url']);
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
     * Consequence: onDisable() empties the API client's response cache.
     *
     * [OMB-15] Populated-then-empty: a cached read is proven first (second
     * read served without a fetch, one cache entry held), then onDisable
     * must leave the cache empty and the next read must refetch.
     */
    public function test_onDisable_clears_api_cache(): void
    {
        $fetches = 0;
        $api = new OmdbApi('key', true, 3600, null, static function (string $url) use (&$fetches): ?array {
            $fetches++;

            return ['Response' => 'True', 'Title' => 'Inception', 'imdbRating' => '8.8'];
        });
        (new \ReflectionProperty(OmdbApi::class, 'timerSleep'))
            ->setValue($api, static function (float $seconds): void {
            });
        $cache = new \ReflectionProperty(OmdbApi::class, 'cache');
        $plugin = new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'key'), null, $api);

        $this->assertSame('Inception', $plugin->getDetails('tt1375666')['title']);
        $this->assertSame('Inception', $plugin->getDetails('tt1375666')['title']);
        $this->assertSame(1, $fetches, 'second read must be a cache hit');
        $this->assertCount(1, $cache->getValue($api), 'cache must be populated before onDisable');

        $plugin->onDisable();

        $this->assertSame([], $cache->getValue($api), 'onDisable must empty the response cache');
        $this->assertSame('Inception', $plugin->getDetails('tt1375666')['title']);
        $this->assertSame(2, $fetches, 'first read after onDisable must refetch');
    }

    // -------------------------------------------------------------------------
    // onEnable logger resolution
    // -------------------------------------------------------------------------

    /**
     * Consequence: onEnable resolves logger from container when available.
     */
    public function test_onEnable_resolves_logger_from_container(): void
    {
        $logger = new OmdbCapturingLogger();
        $plugin = new OmdbPlugin(
            new OmdbSettings(enabled: true, apiKey: 'key', useSslVerification: false),
        );

        $plugin->onEnable($this->makeContainer($logger));

        // [OMB-15] The container's logger is the one the plugin now holds,
        // and onEnable's own wire step already logs through it.
        $this->assertSame($logger, (new \ReflectionProperty(OmdbPlugin::class, 'logger'))->getValue($plugin));
        $this->assertSame(
            [[OmdbPlugin::TLS_VERIFICATION_DISABLED_WARNING, []]],
            $logger->records,
            'the TLS opt-out warning must reach the container-resolved logger',
        );
    }

    /**
     * Consequence: onEnable handles missing logger gracefully.
     */
    public function test_onEnable_handles_missing_logger(): void
    {
        $container = new class implements ContainerInterface {
            /** @var list<string> */
            public array $requested = [];

            public function get(string $id): mixed
            {
                $this->requested[] = $id;

                throw new class ('not found') extends \RuntimeException implements NotFoundExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $plugin = new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'key'));

        $plugin->onEnable($container);

        // [OMB-15] The lookup was attempted, its failure was swallowed into
        // a NullLogger, and the wire step still ran.
        $this->assertSame([LoggerInterface::class], $container->requested);
        $this->assertInstanceOf(
            NullLogger::class,
            (new \ReflectionProperty(OmdbPlugin::class, 'logger'))->getValue($plugin),
        );
        $this->assertInstanceOf(OmdbApi::class, $this->apiOf($plugin));
    }

    /**
     * Consequence: a container entry that is not a PSR-3 logger is not
     * adopted; the plugin falls back to a NullLogger.
     */
    public function test_onEnable_rejects_a_non_logger_container_entry(): void
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return new \stdClass();
            }

            public function has(string $id): bool
            {
                return true;
            }
        };
        $plugin = new OmdbPlugin();

        $plugin->onEnable($container);

        $this->assertInstanceOf(
            NullLogger::class,
            (new \ReflectionProperty(OmdbPlugin::class, 'logger'))->getValue($plugin),
        );
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

    /**
     * The plugin's current transport (null until wired).
     */
    private function apiOf(OmdbPlugin $plugin): ?OmdbApi
    {
        $api = (new \ReflectionProperty(OmdbPlugin::class, 'api'))->getValue($plugin);
        $this->assertTrue($api === null || $api instanceof OmdbApi);

        return $api;
    }

    /**
     * Fake host container serving LoggerInterface (a NullLogger unless the
     * caller supplies one, e.g. an OmdbCapturingLogger).
     */
    private function makeContainer(?LoggerInterface $logger = null): ContainerInterface
    {
        return new class ($logger ?? new NullLogger()) implements ContainerInterface {
            public function __construct(private readonly LoggerInterface $logger)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === LoggerInterface::class) {
                    return $this->logger;
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

    // -------------------------------------------------------------------------
    // ensureApi() returns null when API key is empty string
    // -------------------------------------------------------------------------

    /**
     * Consequence: ensureApi() returns null when apiKey is an empty string.
     * This exercises the !is_string($apiKey) || $apiKey === '' branch.
     */
    public function test_ensure_api_returns_null_when_api_key_is_empty_string(): void
    {
        $settings = new OmdbSettings(enabled: true, apiKey: '');
        $plugin = new OmdbPlugin($settings);

        // Use reflection to call the private ensureApi method
        $method = new \ReflectionMethod(OmdbPlugin::class, 'ensureApi');
        $method->setAccessible(true);
        $api = $method->invoke($plugin);

        $this->assertNull($api);
    }

    /**
     * Consequence: ensureApi() returns pre-built API when available.
     * This exercises the $this->api !== null early return path.
     */
    public function test_ensure_api_returns_prebuilt_api(): void
    {
        $prebuiltApi = new OmdbApi('test_key');
        $settings = new OmdbSettings(enabled: true, apiKey: 'different_key');
        $plugin = new OmdbPlugin($settings, null, $prebuiltApi);

        $method = new \ReflectionMethod(OmdbPlugin::class, 'ensureApi');
        $method->setAccessible(true);
        $api = $method->invoke($plugin);

        $this->assertSame($prebuiltApi, $api);
    }

    /**
     * Consequence: ensureApi() returns null when apiKey is null.
     * This exercises the null apiKey path.
     */
    public function test_ensure_api_returns_null_when_api_key_is_null(): void
    {
        $settings = new OmdbSettings(enabled: true, apiKey: null);
        $plugin = new OmdbPlugin($settings);

        $method = new \ReflectionMethod(OmdbPlugin::class, 'ensureApi');
        $method->setAccessible(true);
        $api = $method->invoke($plugin);

        $this->assertNull($api);
    }

    /**
     * Consequence: ensureApi() creates a new OmdbApi when no prebuilt API exists
     * and settings have a valid apiKey.
     * This exercises lines 139-146 where new OmdbApi() is called.
     */
    public function test_ensure_api_creates_new_api_when_no_prebuilt(): void
    {
        $settings = new OmdbSettings(enabled: true, apiKey: 'test_key');
        $plugin = new OmdbPlugin($settings);

        // Verify no prebuilt API
        $reflection = new \ReflectionClass($plugin);
        $apiProperty = $reflection->getProperty('api');
        $apiProperty->setAccessible(true);
        $this->assertNull($apiProperty->getValue($plugin));

        // Call ensureApi via reflection
        $method = new \ReflectionMethod(OmdbPlugin::class, 'ensureApi');
        $method->setAccessible(true);
        $api = $method->invoke($plugin);

        // Verify a new API was created and stored
        $this->assertNotNull($api);
        $this->assertInstanceOf(OmdbApi::class, $api);
        $this->assertSame($api, $apiProperty->getValue($plugin));
    }

    /**
     * Consequence: search() returns empty when api is prebuilt but settings not configured.
     * This tests the full path when ensureApi returns null due to unconfigured settings.
     */
    public function test_search_returns_empty_when_settings_not_configured(): void
    {
        $settings = new OmdbSettings(enabled: false, apiKey: 'key');
        $plugin = new OmdbPlugin($settings);

        $results = $plugin->search('Inception');

        $this->assertSame([], $results);
    }

    /**
     * Consequence: getDetails() returns empty when settings not configured.
     */
    public function test_getDetails_returns_empty_when_settings_disabled(): void
    {
        $settings = new OmdbSettings(enabled: false, apiKey: 'key');
        $plugin = new OmdbPlugin($settings);

        $result = $plugin->getDetails('tt1375666');

        $this->assertSame([], $result);
    }

    /**
     * Consequence: getImages() returns empty when settings not configured.
     */
    public function test_getImages_returns_empty_when_settings_disabled(): void
    {
        $settings = new OmdbSettings(enabled: false, apiKey: 'key');
        $plugin = new OmdbPlugin($settings);

        $result = $plugin->getImages('tt1375666');

        $this->assertSame([], $result);
    }

    /**
     * Test getSettings returns the same settings object that was passed to constructor.
     */
    public function test_getSettings_returns_constructor_settings(): void
    {
        $settings = new OmdbSettings(enabled: true, apiKey: 'test_key', useSslVerification: false, cacheTtlSeconds: 7200);
        $plugin = new OmdbPlugin($settings);

        $retrievedSettings = $plugin->getSettings();

        $this->assertSame($settings, $retrievedSettings);
        $this->assertSame('test_key', $retrievedSettings->apiKey);
        $this->assertFalse($retrievedSettings->useSslVerification);
        $this->assertSame(7200, $retrievedSettings->cacheTtlSeconds);
    }

    /**
     * Test getSettings returns new OmdbSettings when none was passed and settings was never configured.
     */
    public function test_getSettings_returns_default_when_no_settings(): void
    {
        $plugin = new OmdbPlugin();

        $settings = $plugin->getSettings();

        $this->assertInstanceOf(OmdbSettings::class, $settings);
        $this->assertFalse($settings->enabled);
        $this->assertNull($settings->apiKey);
    }

    /**
     * Test that onDisable does not throw when api is null.
     */
    public function test_onDisable_does_not_throw_when_api_is_null(): void
    {
        $plugin = new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'key'));

        // onDisable must not throw even though api was never created, and
        // must not build a transport just to clear it.
        $plugin->onDisable();

        $this->assertNull($this->apiOf($plugin));
    }

    /**
     * Test that onEnable does not overwrite an existing logger.
     */
    public function test_onEnable_does_not_overwrite_existing_logger(): void
    {
        $customLogger = new class extends \Psr\Log\AbstractLogger {
            public function log($level, $message, array $context = []): void {}
        };

        $plugin = new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'key'), $customLogger);

        $loggerProperty = new \ReflectionProperty(OmdbPlugin::class, 'logger');
        $this->assertSame($customLogger, $loggerProperty->getValue($plugin));

        $plugin->onEnable($this->makeContainer(new OmdbCapturingLogger()));

        $loggerAfter = $loggerProperty->getValue($plugin);

        // The existing custom logger should NOT be overwritten by NullLogger from container
        $this->assertSame($customLogger, $loggerAfter, 'Existing logger should not be overwritten');
    }

    /**
     * Test that getDetails returns empty array when api returns null for getByImdbId.
     */
    public function test_getDetails_returns_empty_when_api_returns_null(): void
    {
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => null);
        $plugin = new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'key'), null, $api);

        $result = $plugin->getDetails('tt1375666');

        $this->assertSame([], $result);
    }

    /**
     * Test that getImages returns empty when api returns null for getByImdbId.
     */
    public function test_getImages_returns_empty_when_api_returns_null(): void
    {
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => null);
        $plugin = new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'key'), null, $api);

        $result = $plugin->getImages('tt1375666');

        $this->assertSame([], $result);
    }

    /**
     * Test that search handles the case where api returns null.
     */
    public function test_search_returns_empty_when_api_returns_null(): void
    {
        $api = new OmdbApi('key', true, 0, null, static fn(string $url): ?array => null);
        $plugin = new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'key'), null, $api);

        $result = $plugin->search('Inception');

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // Field hygiene [M-OMDB-05/M-OMDB-09]: OMDb's 'N/A' sentinel and foreign
    // JSON shapes must never be emitted as metadata values.
    // -------------------------------------------------------------------------

    /**
     * Consequence: every mapped text field carrying OMDb's literal 'N/A'
     * ("no value") sentinel maps to '' — the host resolver must never
     * persist the string 'N/A' as a genre, director, awards line, etc.
     */
    public function test_getDetails_maps_na_sentinels_to_empty_strings(): void
    {
        $sentinelFields = [
            'Title', 'Year', 'Rated', 'Released', 'Runtime', 'Genre', 'Director',
            'Writer', 'Actors', 'Plot', 'Language', 'Country', 'Awards', 'Poster', 'Type',
        ];
        $details = ['Response' => 'True', 'imdbRating' => 'N/A', 'Ratings' => []];
        foreach ($sentinelFields as $field) {
            $details[$field] = 'N/A';
        }

        $result = $this->makeConfiguredPluginWithDetails($details)->getDetails('tt1375666');

        $outKeys = [
            'title', 'year', 'rated', 'released', 'runtime', 'genre', 'director',
            'writer', 'actors', 'plot', 'language', 'country', 'awards', 'poster_url', 'type',
        ];
        $this->assertSame(array_fill_keys($outKeys, ''), array_intersect_key($result, array_fill_keys($outKeys, true)));
    }

    /**
     * Consequence: a field whose JSON shape is not a string (array/int/bool/
     * null — hostile or proxied payload shapes) maps to ''; genuine strings
     * pass through byte-identical.
     */
    public function test_getDetails_maps_non_string_field_shapes_to_empty_strings(): void
    {
        $details = [
            'Response' => 'True',
            'Title' => 'Inception',
            'Year' => 2010,
            'Rated' => true,
            'Released' => null,
            'Runtime' => ['148 min'],
            'Genre' => 42,
            'Director' => ['Christopher Nolan'],
            'Writer' => 1.5,
            'Actors' => false,
            'Plot' => ['p'],
            'Language' => [],
            'Country' => [],
            'Awards' => 'Won 4 Oscars.',
            'Poster' => ['https://m.media-amazon.com/images/M/poster.jpg'],
            'Type' => 'series',
            'imdbRating' => 'N/A',
            'Ratings' => [],
        ];

        $result = $this->makeConfiguredPluginWithDetails($details)->getDetails('tt1375666');

        $this->assertSame('Inception', $result['title']);
        $junkKeys = [
            'year', 'rated', 'released', 'runtime', 'genre', 'director', 'writer',
            'actors', 'plot', 'language', 'country',
        ];
        foreach ($junkKeys as $key) {
            $this->assertSame('', $result[$key], "non-string OMDb field must map to empty string ($key)");
        }
        $this->assertSame('Won 4 Oscars.', $result['awards']);
        $this->assertSame('', $result['poster_url']);
        $this->assertSame('series', $result['type']);
    }

    /**
     * [OMB-13] Consequence: the 'N/A' sentinel is recognised after trim and
     * case-fold — padded or re-cased variants from a proxy/cache layer must
     * not leak into host persistence as literal data.
     */
    public function test_getDetails_maps_trimmed_and_case_folded_na_variants_to_empty_strings(): void
    {
        $variants = [
            'Title' => ' N/A',
            'Year' => 'N/A ',
            'Rated' => 'n/a',
            'Released' => 'N/a',
            'Runtime' => "\tn/A\n",
            'Genre' => " N/A\r\n",
            'Director' => "\x00n/a\x0B",
            'Writer' => 'n/A',
            'Actors' => '  N/A  ',
            'Plot' => "N/A\n",
            'Language' => ' n/a ',
            'Country' => 'N/A',
            'Awards' => "\nN/A",
            'Poster' => ' n/a ',
            'Type' => 'N/A ',
        ];
        $details = ['Response' => 'True', 'imdbRating' => 'N/A', 'Ratings' => []] + $variants;

        $result = $this->makeConfiguredPluginWithDetails($details)->getDetails('tt1375666');

        $outKeys = [
            'title', 'year', 'rated', 'released', 'runtime', 'genre', 'director',
            'writer', 'actors', 'plot', 'language', 'country', 'awards', 'poster_url', 'type',
        ];
        $this->assertSame(array_fill_keys($outKeys, ''), array_intersect_key($result, array_fill_keys($outKeys, true)));
    }

    /**
     * [OMB-13] Consequence: the sentinel gate is whole-value folded
     * equality only — a genuine string that merely CONTAINS 'n/a' (or is
     * padded around a longer value) passes through byte-identical,
     * untrimmed, never dropped.
     */
    public function test_getDetails_keeps_genuine_strings_containing_na_substring(): void
    {
        $details = [
            'Response' => 'True',
            'Title' => 'N/A.',
            'Year' => 'n/a 2010',
            'Rated' => 'NA',
            'Released' => 'N /A',
            'Runtime' => ' 148 min ',
            'Genre' => 'Drama, N/A',
            'Director' => 'Christopher Nolan (n/a)',
            'Writer' => 'N/AN/A',
            'Actors' => 'Leonardo DiCaprio, n/a',
            'Plot' => 'A thief whose n/a status is in question.',
            'Language' => 'English',
            'Country' => '/N/A',
            'Awards' => 'N/A N/A',
            'Type' => 'movie',
            'imdbRating' => 'N/A',
            'Ratings' => [],
        ];

        $result = $this->makeConfiguredPluginWithDetails($details)->getDetails('tt1375666');

        $this->assertSame('N/A.', $result['title']);
        $this->assertSame('n/a 2010', $result['year']);
        $this->assertSame('NA', $result['rated']);
        $this->assertSame('N /A', $result['released']);
        $this->assertSame(' 148 min ', $result['runtime']);
        $this->assertSame('Drama, N/A', $result['genre']);
        $this->assertSame('Christopher Nolan (n/a)', $result['director']);
        $this->assertSame('N/AN/A', $result['writer']);
        $this->assertSame('Leonardo DiCaprio, n/a', $result['actors']);
        $this->assertSame('A thief whose n/a status is in question.', $result['plot']);
        $this->assertSame('English', $result['language']);
        $this->assertSame('/N/A', $result['country']);
        $this->assertSame('N/A N/A', $result['awards']);
        $this->assertSame('movie', $result['type']);
    }

    /**
     * [OMB-13] Consequence: getImages() shares the folded sentinel gate —
     * a re-cased or padded 'N/A' Poster yields no image.
     */
    public function test_getImages_returns_empty_for_case_folded_na_poster(): void
    {
        foreach (['n/a', ' N/A ', "N/a\n"] as $poster) {
            $details = ['Response' => 'True', 'Title' => 'Inception', 'Poster' => $poster];

            $this->assertSame([], $this->makeConfiguredPluginWithDetails($details)->getImages('tt1375666'));
        }
    }

    // -------------------------------------------------------------------------
    // W-min75-omdb minors: configure() transport law, poster scheme gate
    // -------------------------------------------------------------------------

    /**
     * [M-OMDB-06 minor] A rotated api_key must reach the wire: configure()
     * discards the self-built transport so the next read rebuilds it from
     * the fresh settings (previously ensureApi() kept serving the client
     * built with the old key forever).
     */
    public function test_configure_rebuilds_the_self_built_transport_for_a_rotated_key(): void
    {
        $plugin = new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'first_key'));
        $ensure = new \ReflectionMethod(OmdbPlugin::class, 'ensureApi');
        $ensure->setAccessible(true);
        $apiKey = new \ReflectionProperty(OmdbApi::class, 'apiKey');
        $apiKey->setAccessible(true);

        $first = $ensure->invoke($plugin);
        $this->assertInstanceOf(OmdbApi::class, $first);

        $plugin->configure(['enabled' => true, 'api_key' => 'second_key']);

        $second = $ensure->invoke($plugin);
        $this->assertInstanceOf(OmdbApi::class, $second);
        $this->assertNotSame($first, $second, 'configure() must discard the self-built client');
        $this->assertSame('first_key', $apiKey->getValue($first));
        $this->assertSame('second_key', $apiKey->getValue($second));
    }

    /**
     * [M-OMDB-06 minor] Counterpart law: a constructor-injected client is
     * the caller's property — configure() must never null it out (the test
     * seam and any host-injected transport rely on this).
     */
    public function test_configure_keeps_a_constructor_injected_transport(): void
    {
        $prebuilt = new OmdbApi('injected_key');
        $plugin = new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'key'), null, $prebuilt);

        $plugin->configure(['enabled' => true, 'api_key' => 'other_key']);

        $ensure = new \ReflectionMethod(OmdbPlugin::class, 'ensureApi');
        $ensure->setAccessible(true);
        $this->assertSame($prebuilt, $ensure->invoke($plugin));
    }

    /**
     * [M-OMDB-07 minor] Poster values that are not strict-lowercase https
     * URLs (plaintext http:, other schemes, uppercase scheme variants,
     * malformed slashes) are dropped: getDetails emits '' and getImages
     * returns []. A tampered or transparently-proxied body must never ship
     * a fetchable non-TLS art URL into host persistence.
     */
    public function test_poster_values_outside_strict_https_are_dropped(): void
    {
        $bad = [
            'http://example.com/poster.jpg',
            'HTTP://EXAMPLE.COM/POSTER.JPG',
            'HTTPS://EXAMPLE.COM/POSTER.JPG',
            'javascript:alert(1)',
            'file:///etc/passwd',
            ' //example.com/poster.jpg',
            'https:/example.com/poster.jpg',
            // allowlisted host, so each row is rejected by the scheme law alone
            'http://m.media-amazon.com/images/M/x.jpg',
            'HTTPS://m.media-amazon.com/images/M/x.jpg',
            'Https://m.media-amazon.com/images/M/x.jpg',
        ];
        foreach ($bad as $poster) {
            $details = ['Response' => 'True', 'Title' => 'Inception', 'Poster' => $poster];

            $result = $this->makeConfiguredPluginWithDetails($details)->getDetails('tt1375666');
            $this->assertSame('', $result['poster_url'], 'non-https Poster must map to empty string: ' . $poster);

            $images = $this->makeConfiguredPluginWithPoster($details)->getImages('tt1375666');
            $this->assertSame([], $images, 'non-https Poster must yield no images: ' . $poster);
        }
    }

    /**
     * [OMB-11] Poster host tightening: https alone is not enough. A body
     * that is proxied, tampered or simply foreign can carry an https URL
     * whose authority smuggles credentials (userinfo), an explicit port,
     * or a host OMDb never serves art from — every such value flows into
     * host persistence and a client image loader. Each row must map to ''
     * in getDetails() and [] in getImages().
     */
    public function test_poster_userinfo_port_and_foreign_host_rows_are_dropped(): void
    {
        $bad = [
            // userinfo smuggling (credential leak / host-confusion shapes)
            'https://user:pass@m.media-amazon.com/images/M/x.jpg',
            'https://m.media-amazon.com@evil.example/images/M/x.jpg',
            'https://m.media-amazon.com:443@evil.example/x.jpg',
            'https://m.media-amazon.com\\@evil.example/x.jpg',
            'https://@m.media-amazon.com/images/M/x.jpg',
            // explicit port (canonical OMDb art never carries one)
            'https://m.media-amazon.com:8443/images/M/x.jpg',
            'https://m.media-amazon.com:443/images/M/x.jpg',
            // foreign hosts, including lookalike suffix/prefix tricks
            'https://example.com/poster.jpg',
            'https://evil.example/images/M/x.jpg',
            'https://evilmedia-amazon.com/images/M/x.jpg',
            'https://media-amazon.com.evil.example/images/M/x.jpg',
            'https://m.media-amazon.com.evil.example/x.jpg',
            'https://127.0.0.1/x.jpg',
            'https://[::1]/x.jpg',
            // authority/path delimiter shapes a lenient parser reads differently
            'https://m.media-amazon.com\\images\\M\\x.jpg',
            'https://m.media-amazon.com?u=https://evil.example/x.jpg',
            'https://m.media-amazon.com#@evil.example/x.jpg',
            'https://M.MEDIA-AMAZON.COM/images/M/x.jpg',
            'https://m.media-amazon.com./images/M/x.jpg',
            // malformed DNS labels (empty label, hyphen-edged label)
            'https://m..media-amazon.com/x.jpg',
            'https://a-.media-amazon.com/x.jpg',
        ];
        foreach ($bad as $poster) {
            $details = ['Response' => 'True', 'Title' => 'Inception', 'Poster' => $poster];

            $result = $this->makeConfiguredPluginWithDetails($details)->getDetails('tt1375666');
            $this->assertSame('', $result['poster_url'], 'untrusted Poster must map to empty string: ' . $poster);

            $images = $this->makeConfiguredPluginWithPoster($details)->getImages('tt1375666');
            $this->assertSame([], $images, 'untrusted Poster must yield no images: ' . $poster);
        }
    }

    /**
     * [OMB-11] Counterpart law: the allowlist must not reject legitimate
     * art. Current OMDb posters live on m.media-amazon.com and carry '@@'
     * inside the PATH (not the authority); historical bodies used the
     * ssl-images-amazon.com and media-imdb.com CDNs. All pass verbatim.
     */
    public function test_poster_on_omdb_art_cdn_hosts_passes_verbatim(): void
    {
        $good = [
            'https://m.media-amazon.com/images/M/MV5BMjAxMzY3NjcxNF5BMl5BanBnXkFtZTcwNTI5OTM0Mw@@._V1_SX300.jpg',
            'https://images-na.ssl-images-amazon.com/images/M/MV5BMTM0MjUzNjkwMl5BMl5BanBnXkFtZTcw@@._V1_SX300.jpg',
            'https://ia.media-imdb.com/images/M/MV5BMTM0MjUzNjkwMl5BMl5BanBnXkFtZTcwNjY0OTk1Mw@@._V1_SX300.jpg',
            'https://media-amazon.com/images/M/x.jpg',
        ];
        foreach ($good as $poster) {
            $details = ['Response' => 'True', 'Title' => 'Inception', 'Poster' => $poster];

            $result = $this->makeConfiguredPluginWithDetails($details)->getDetails('tt1375666');
            $this->assertSame($poster, $result['poster_url'], 'OMDb CDN Poster must pass verbatim: ' . $poster);

            $images = $this->makeConfiguredPluginWithPoster($details)->getImages('tt1375666');
            $this->assertSame(
                ['poster' => [['url' => $poster]]],
                $images,
                'OMDb CDN Poster must yield the image: ' . $poster
            );
        }
    }

    /**
     * [M-OMDB-17 minor] OmdbPlugin lives in namespace
     * Phlix\Plugins\Metadata\Omdb — `use` lines importing its own
     * namespace's classes (OmdbApi, OmdbSettings) are redundant noise and
     * must not return.
     */
    public function test_omdb_plugin_carries_no_same_namespace_imports(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/OmdbPlugin.php');
        $this->assertIsString($src);
        $this->assertStringNotContainsString('use Phlix\\Plugins\\Metadata\\Omdb\\', $src);
    }

    // -------------------------------------------------------------------------
    // W-min76-omdb minors: action pinning, parse-don't-revalidate law
    // -------------------------------------------------------------------------

    /**
     * [M-OMDB-13 minor] Estate CI hygiene: every `uses:` reference in
     * .github/workflows must be pinned to a full 40-hex commit SHA — tags
     * and major floats (@v6, @v1) are rewritten upstream and silently
     * change what runs on our runners. The sibling phlix-plugin-example
     * pins in exactly this shape.
     */
    public function test_github_workflows_pin_every_action_to_a_full_sha(): void
    {
        $files = glob(__DIR__ . '/../../.github/workflows/*.yml');
        $this->assertIsArray($files);
        $this->assertNotEmpty($files, 'the workflows directory must exist');

        $checked = 0;
        foreach ($files as $file) {
            foreach (file($file) as $lineNo => $line) {
                if (preg_match('/^\s*-?\s*uses:\s*(\S+)/', $line, $m) !== 1) {
                    continue;
                }
                $checked++;
                $this->assertMatchesRegularExpression(
                    '/@[0-9a-f]{40}$/',
                    $m[1],
                    sprintf('%s:%d floats an action ref — pin it to a full SHA', basename($file), $lineNo + 1)
                );
            }
        }
        $this->assertGreaterThan(0, $checked, 'the tripwire must actually observe uses: lines');
    }

    /**
     * [M-OMDB-19 minor] OmdbApi::search() is the parse boundary: its return
     * is typed list<array{imdb_id: non-empty string, title: string, ...}>.
     * The plugin must map rows directly — re-validating is_array()/
     * is_string() over already-parsed rows is double validation and must
     * not creep back. is_array( in the plugin source is the canary.
     */
    public function test_plugin_does_not_revalidate_rows_the_api_layer_already_parsed(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/OmdbPlugin.php');
        $this->assertIsString($src);
        $this->assertStringNotContainsString('is_array(', $src);
    }

    /**
     * [PG-196] configure() surfaces boundary drift: mistyped settings_json
     * keys land on their fail-safe default AND name themselves in one
     * warning. Key NAMES only — a mistyped api_key value is still a secret.
     */
    public function test_configure_logs_rejected_setting_keys_without_their_values(): void
    {
        $logger = new OmdbCapturingLogger();
        $plugin = new OmdbPlugin(logger: $logger);

        $plugin->configure(['enabled' => true, 'api_key' => 'k', 'cache_ttl_seconds' => 'not-a-number']);

        $this->assertCount(1, $logger->records);
        [$message, $context] = $logger->records[0];
        $this->assertSame('OMDb ignored settings with the wrong value type and used the defaults instead; correct these settings in the OMDb plugin configuration', $message);
        $this->assertSame(['cache_ttl_seconds'], $context['keys']);
        $this->assertStringNotContainsString('not-a-number', serialize($context));
    }

    public function test_configure_with_clean_settings_logs_no_drift_warning(): void
    {
        $logger = new OmdbCapturingLogger();
        $plugin = new OmdbPlugin(logger: $logger);

        $plugin->configure(['enabled' => true, 'api_key' => 'k', 'cache_ttl_seconds' => '3600']);

        $this->assertSame([], $logger->records);
        $this->assertSame(3600, $plugin->getSettings()->cacheTtlSeconds);
    }

    // -------------------------------------------------------------------------
    // [OMB-4] TLS-verification opt-out is surfaced as a warning.
    // -------------------------------------------------------------------------

    /**
     * Consequence: building the transport with TLS verification off logs
     * exactly one warning through the injected logger — fixed text, no
     * context, no setting key name and no credential material.
     */
    public function test_ensure_api_warns_once_when_tls_verification_disabled(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{mixed, string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        $plugin = new OmdbPlugin(
            new OmdbSettings(enabled: true, apiKey: 'tls_secret_key', useSslVerification: false),
            $logger,
        );

        $method = new \ReflectionMethod(OmdbPlugin::class, 'ensureApi');
        $first = $method->invoke($plugin);
        $second = $method->invoke($plugin);

        $this->assertInstanceOf(OmdbApi::class, $first);
        $this->assertSame($first, $second);
        $this->assertSame([[
            \Psr\Log\LogLevel::WARNING,
            'OMDb TLS certificate verification is disabled: '
                . 'outbound requests and their credentials can be intercepted in transit',
            [],
        ]], $logger->records);
        $this->assertSame(OmdbPlugin::TLS_VERIFICATION_DISABLED_WARNING, $logger->records[0][1]);
        $this->assertStringNotContainsString('tls_secret_key', serialize($logger->records));
        $this->assertStringNotContainsString('api_key', serialize($logger->records));
        $this->assertStringNotContainsString('use_ssl_verification', serialize($logger->records));
    }

    /**
     * Consequence: the default (verification on) posture logs nothing.
     */
    public function test_ensure_api_does_not_warn_when_tls_verification_enabled(): void
    {
        $logger = new OmdbCapturingLogger();
        $plugin = new OmdbPlugin(new OmdbSettings(enabled: true, apiKey: 'k'), $logger);

        $method = new \ReflectionMethod(OmdbPlugin::class, 'ensureApi');
        $this->assertInstanceOf(OmdbApi::class, $method->invoke($plugin));
        $this->assertSame([], $logger->records);
    }
}
