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

    /**
     * Test fromArray handles explicit boolean enabled value.
     */
    public function test_from_array_handles_explicit_boolean_enabled(): void
    {
        $settings = OmdbSettings::fromArray(['enabled' => true]);

        $this->assertTrue($settings->enabled);
    }

    /**
     * Test fromArray handles non-boolean enabled value (non-true values are treated as false).
     */
    public function test_from_array_treats_non_true_enabled_as_false(): void
    {
        $settings = OmdbSettings::fromArray(['enabled' => 'yes']);

        // Non-boolean 'yes' is not === true, so it becomes false
        $this->assertFalse($settings->enabled);
    }

    /**
     * Test fromArray handles null api_key.
     */
    public function test_from_array_handles_null_api_key(): void
    {
        $settings = OmdbSettings::fromArray(['api_key' => null]);

        $this->assertNull($settings->apiKey);
    }

    /**
     * Test fromArray uses default cache TTL when not integer.
     */
    public function test_from_array_uses_default_cache_ttl_when_not_integer(): void
    {
        $settings = OmdbSettings::fromArray(['cache_ttl_seconds' => 'invalid']);

        $this->assertSame(86400, $settings->cacheTtlSeconds);
    }

    /**
     * Test fromArray uses default useSslVerification when false string.
     */
    public function test_from_array_uses_default_use_ssl_when_false_string(): void
    {
        $settings = OmdbSettings::fromArray(['use_ssl_verification' => 'false']);

        $this->assertTrue($settings->useSslVerification);
    }

    /**
     * Test fromArray correctly interprets use_ssl_verification as true when explicitly set.
     */
    public function test_from_array_interprets_use_ssl_verification_true(): void
    {
        $settings = OmdbSettings::fromArray(['use_ssl_verification' => true]);

        $this->assertTrue($settings->useSslVerification);
    }

    /**
     * Test hasApiKey returns true for non-empty string apiKey.
     */
    public function test_has_api_key_returns_true_for_valid_string(): void
    {
        $settings = new OmdbSettings(apiKey: 'abc123');

        $this->assertTrue($settings->hasApiKey());
    }

    /**
     * Test isConfigured combines enabled and hasApiKey checks.
     */
    public function test_is_configured_requires_both_enabled_and_api_key(): void
    {
        // Disabled with key
        $s1 = new OmdbSettings(enabled: false, apiKey: 'key');
        $this->assertFalse($s1->isConfigured());

        // Enabled without key
        $s2 = new OmdbSettings(enabled: true, apiKey: null);
        $this->assertFalse($s2->isConfigured());

        // Both
        $s3 = new OmdbSettings(enabled: true, apiKey: 'key');
        $this->assertTrue($s3->isConfigured());
    }
}
