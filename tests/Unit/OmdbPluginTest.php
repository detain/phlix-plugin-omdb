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
