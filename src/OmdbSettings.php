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
     * Documented default cache TTL (24h), shared by the constructor and the
     * fromArray() fallback so the two can never drift apart.
     */
    private const DEFAULT_CACHE_TTL_SECONDS = 86_400;

    /**
     * Upper bound for cache_ttl_seconds: 30 days (2,592,000s). An
     * unbounded TTL lets a fat-fingered value outlive every reasonable
     * metadata staleness expectation AND defeats the write-time eviction
     * sweep in practice (the deadline is never crossed), pinning entries
     * until process death. Clamped down, not rejected [PG-198].
     */
    private const MAX_CACHE_TTL_SECONDS = 2_592_000;

    /**
     * @param bool         $enabled             Whether the plugin is enabled
     * @param string|null  $apiKey              OMDb API key (null when not configured)
     * @param bool         $useSslVerification  Whether to verify TLS certificates
     * @param int          $cacheTtlSeconds     Response cache TTL in seconds (0 disables caching)
     * @param list<string> $rejectedKeys        Setting NAMES whose stored value was the
     *                                          wrong type and got discarded to its
     *                                          fail-safe default ([PG-196] drift
     *                                          disclosure; empty when nothing was mistyped)
     */
    public function __construct(
        public bool $enabled = false,
        public ?string $apiKey = null,
        public bool $useSslVerification = true,
        public int $cacheTtlSeconds = self::DEFAULT_CACHE_TTL_SECONDS,
        public array $rejectedKeys = [],
    ) {
    }

    /**
     * Create settings from a flat key-value array (as stored in plugin JSON).
     *
     * Boundary law [PG-196/PG-198]: values are translated TYPE-STRICTLY and
     * drift is SURFACED, never swallowed. A wrong-typed member falls back to
     * its fail-safe default AND names itself in {@see $rejectedKeys} (which
     * OmdbPlugin::configure() logs). A canonical JSON gotcha is honored:
     * integer knobs legitimately arrive as numeric strings ("86400"), so
     * those are coerced rather than rejected. cache_ttl_seconds is clamped
     * to 0..MAX_CACHE_TTL_SECONDS on every path — negative now maps to 0
     * (the explicit "cache disabled" setting) instead of a silently
     * inverted never-expiring comparison; the clamp lands quietly because
     * it is a bound, not a discard.
     *
     * @param array<string, mixed> $data Flat settings array from plugin.json
     * @return self
     */
    public static function fromArray(array $data): self
    {
        /** @var list<string> $rejected */
        $rejected = [];

        $enabled = false;
        if (array_key_exists('enabled', $data)) {
            if (is_bool($data['enabled']) === true) {
                $enabled = $data['enabled'];
            } else {
                $rejected[] = 'enabled';
            }
        }

        $apiKey = null;
        if (array_key_exists('api_key', $data)) {
            if (is_string($data['api_key']) === true || $data['api_key'] === null) {
                /** @var string|null $apiKey */
                $apiKey = $data['api_key'];
            } else {
                $rejected[] = 'api_key';
            }
        }

        $useSslVerification = true;
        if (array_key_exists('use_ssl_verification', $data)) {
            if (is_bool($data['use_ssl_verification']) === true) {
                $useSslVerification = $data['use_ssl_verification'];
            } else {
                $rejected[] = 'use_ssl_verification';
            }
        }

        $cacheTtlSeconds = self::DEFAULT_CACHE_TTL_SECONDS;
        if (array_key_exists('cache_ttl_seconds', $data)) {
            $ttl = $data['cache_ttl_seconds'];
            if (is_int($ttl) === true) {
                $cacheTtlSeconds = $ttl;
            } elseif (is_string($ttl) === true && is_numeric($ttl) === true) {
                $cacheTtlSeconds = (int) $ttl;
            } else {
                $rejected[] = 'cache_ttl_seconds';
            }
        }
        $cacheTtlSeconds = max(0, min($cacheTtlSeconds, self::MAX_CACHE_TTL_SECONDS));

        return new self(
            enabled: $enabled,
            apiKey: $apiKey,
            useSslVerification: $useSslVerification,
            cacheTtlSeconds: $cacheTtlSeconds,
            rejectedKeys: $rejected,
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
