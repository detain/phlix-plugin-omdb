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
use PHPUnit\Framework\TestCase;

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
}
