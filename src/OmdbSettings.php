<?php

/**
 * OMDb plugin settings.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\Omdb;

/**
 * Settings holder for the OMDb plugin.
 *
 * Encapsulates all plugin configuration with sensible defaults.
 */
final readonly class OmdbSettings
{
    /**
     * @param bool $enabled Whether the plugin is enabled
     * @param string|null $apiKey OMDb API key (null when not configured)
     * @param bool $useSslVerification Whether to verify TLS certificates
     * @param int $cacheTtlSeconds Cache TTL in seconds
     */
    public function __construct(
        public bool $enabled = false,
        public ?string $apiKey = null,
        public bool $useSslVerification = true,
        public int $cacheTtlSeconds = 86400,
    ) {
    }

    /**
     * Create settings from a flat key-value array (as stored in plugin JSON).
     *
     * @param array<string, mixed> $data Flat settings array from plugin.json
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: ($data['enabled'] ?? false) === true,
            apiKey: is_string($data['api_key'] ?? null) ? $data['api_key'] : null,
            useSslVerification: ($data['use_ssl_verification'] ?? true) !== false,
            cacheTtlSeconds: is_int($data['cache_ttl_seconds'] ?? null) ? (int) $data['cache_ttl_seconds'] : 86400,
        );
    }

    /**
     * Whether the plugin has a valid API key configured.
     *
     * @return bool
     */
    public function hasApiKey(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    /**
     * Whether the plugin is fully configured and ready to use.
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->enabled && $this->hasApiKey();
    }
}
