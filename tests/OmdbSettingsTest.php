<?php

/**
 * Unit tests for OmdbSettings.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\Omdb\Tests;

use Phlix\Plugins\Metadata\Omdb\OmdbSettings;
use PHPUnit\Framework\TestCase;

final class OmdbSettingsTest extends TestCase
{
    public function test_defaults(): void
    {
        $settings = new OmdbSettings();

        $this->assertFalse($settings->enabled);
        $this->assertNull($settings->apiKey);
        $this->assertTrue($settings->useSslVerification);
        $this->assertSame(86400, $settings->cacheTtlSeconds);
    }

    public function test_from_array_with_all_fields(): void
    {
        $data = [
            'enabled' => true,
            'api_key' => 'my_api_key',
            'use_ssl_verification' => false,
            'cache_ttl_seconds' => 3600,
        ];

        $settings = OmdbSettings::fromArray($data);

        $this->assertTrue($settings->enabled);
        $this->assertSame('my_api_key', $settings->apiKey);
        $this->assertFalse($settings->useSslVerification);
        $this->assertSame(3600, $settings->cacheTtlSeconds);
    }

    public function test_from_array_with_missing_fields(): void
    {
        $settings = OmdbSettings::fromArray([]);

        $this->assertFalse($settings->enabled);
        $this->assertNull($settings->apiKey);
        $this->assertTrue($settings->useSslVerification);
        $this->assertSame(86400, $settings->cacheTtlSeconds);
    }

    public function test_has_api_key_returns_true_when_set(): void
    {
        $settings = new OmdbSettings(apiKey: 'valid_key');

        $this->assertTrue($settings->hasApiKey());
    }

    public function test_has_api_key_returns_false_when_null(): void
    {
        $settings = new OmdbSettings(apiKey: null);

        $this->assertFalse($settings->hasApiKey());
    }

    public function test_has_api_key_returns_false_when_empty(): void
    {
        $settings = new OmdbSettings(apiKey: '');

        $this->assertFalse($settings->hasApiKey());
    }

    public function test_is_configured_returns_true_when_enabled_with_api_key(): void
    {
        $settings = new OmdbSettings(enabled: true, apiKey: 'valid_key');

        $this->assertTrue($settings->isConfigured());
    }

    public function test_is_configured_returns_false_when_disabled(): void
    {
        $settings = new OmdbSettings(enabled: false, apiKey: 'valid_key');

        $this->assertFalse($settings->isConfigured());
    }

    public function test_is_configured_returns_false_when_no_api_key(): void
    {
        $settings = new OmdbSettings(enabled: true, apiKey: null);

        $this->assertFalse($settings->isConfigured());
    }
}
