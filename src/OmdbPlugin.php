<?php

/**
 * OMDb metadata provider plugin for Phlix.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\Omdb;

use Phlix\Plugins\Metadata\Omdb\OmdbApi;
use Phlix\Plugins\Metadata\Omdb\OmdbSettings;
use Phlix\Shared\Metadata\MetadataSourceInterface;
use Phlix\Shared\Plugin\ConfigurableInterface;
use Phlix\Shared\Plugin\LifecycleInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OMDb metadata provider plugin for Phlix.
 *
 * Fetches metadata and ratings from the OMDb API for movies and TV series.
 * This is a PURE READ source: {@see getDetails()} fetches, shapes, and returns
 * data only — it performs no persistence. Ratings are emitted under the host
 * `metadata_ratings.source` ENUM values (`imdb`, `rt`); the host resolver
 * (F2) owns writing them and computing any aggregate.
 *
 * ## Features
 *
 * - IMDb ID resolution via title + year search
 * - Ratings emitted enum-safe: IMDb -> `imdb`, Rotten Tomatoes -> `rt`
 *   (Metascore has no valid ENUM member and is dropped)
 * - Non-blocking async HTTP via Workerman/http-client (HTTPS only)
 * - Configurable rate limiting and response caching
 * - TLS verification toggle for self-hosted proxies
 * - onEnable is a cheap wire-only step: no network, no DB, never throws
 *
 * ## Configuration (plugin.json settings)
 *
 * - enabled: Enable the OMDb provider (boolean, default false)
 * - api_key: OMDb API key (string, secret) — get at http://www.omdbapi.com/apikey.aspx
 * - use_ssl_verification: Verify TLS certificates (boolean, default true)
 * - cache_ttl_seconds: Cache TTL in seconds (integer, default 86400)
 *
 * @package Phlix\Plugins\Metadata\Omdb
 * @since 0.1.0
 */
final class OmdbPlugin implements ConfigurableInterface, LifecycleInterface, MetadataSourceInterface
{
    /**
     * Canonical source name used in the host priority map.
     */
    public const SOURCE_NAME = 'omdb';

    /**
     * @param OmdbSettings|null $settings             Initial settings (loaded from DB on enable)
     * @param LoggerInterface|null $logger            Optional PSR-3 logger
     * @param OmdbApi|null $api                       Pre-built API client (test seam)
     */
    public function __construct(
        private ?OmdbSettings $settings = null,
        private ?LoggerInterface $logger = null,
        private ?OmdbApi $api = null,
    ) {
        $this->settings ??= new OmdbSettings();
        $this->logger ??= new NullLogger();
    }

    /**
     * Configure the plugin from a settings array.
     *
     * @param array<string, mixed> $settings Key-value settings from plugins.settings_json
     * @return void
     */
    public function configure(array $settings): void
    {
        $this->settings = OmdbSettings::fromArray($settings);
    }

    /**
     * Called by the loader once when the plugin is enabled — the cheap
     * "wire" step ONLY.
     *
     * A future boot activation (F1) calls onEnable across ~14 resident
     * workers, so this MUST be non-blocking and total-failure-free:
     *
     * - NO network I/O (no search(), no connectivity probe).
     * - NO database connection, NO migrations.
     * - NEVER throws on a missing API key.
     *
     * It only resolves a logger and constructs the (network-free) transport
     * object. The actual "connect" is deferred to {@see ensureApi()}, which
     * runs lazily on the first search/getDetails/getImages call.
     *
     * @param ContainerInterface $container Host PSR-11 container
     * @return void
     */
    public function onEnable(ContainerInterface $container): void
    {
        if ($this->logger instanceof NullLogger) {
            try {
                $logger = $container->get(LoggerInterface::class);
                $this->logger = $logger instanceof LoggerInterface ? $logger : new NullLogger();
            } catch (\Throwable) {
                $this->logger = new NullLogger();
            }
        }

        // Wire-only: construct the transport if a key is already present.
        // This performs NO network I/O — the OmdbApi constructor is inert.
        $this->ensureApi();
    }

    /**
     * Deferred "connect" step: lazily construct the OMDb transport.
     *
     * Called on the first read (search/getDetails/getImages), never at boot.
     * Constructing {@see OmdbApi} performs NO network I/O; the first request
     * only happens when a method on the returned client is invoked.
     *
     * @return OmdbApi|null The client, or null when no API key is configured
     */
    private function ensureApi(): ?OmdbApi
    {
        if ($this->api !== null) {
            return $this->api;
        }

        $settings = $this->settings ?? new OmdbSettings();
        $apiKey = $settings->apiKey;
        if (is_string($apiKey) === false || $apiKey === '') {
            return null;
        }

        $this->api = new OmdbApi(
            apiKey: $apiKey,
            useSslVerification: $settings->useSslVerification,
            cacheTtlSeconds: $settings->cacheTtlSeconds,
            logger: $this->logger ?? new NullLogger(),
        );

        return $this->api;
    }

    /**
     * Called by the loader once when the plugin is disabled.
     *
     * Clears the response cache.
     *
     * @return void
     */
    public function onDisable(): void
    {
        $this->api?->clearCache();
    }

    /**
     * Return the PSR-14 listener subscriptions this plugin wants.
     *
     * This plugin is invoked directly by MetadataManager via the
     * MetadataSourceInterface triad rather than through the event
     * dispatcher, so no subscriptions.
     *
     * @return array<class-string, string|callable> Always empty
     */
    public function subscribedEvents(): array
    {
        return [];
    }

    // -------------------------------------------------------------------------
    // MetadataSourceInterface — the first-class typed contract for metadata
    // providers. The host SourceRegistry registers this on plugin-enable.
    // -------------------------------------------------------------------------

    /**
     * Canonical source name for the host priority map.
     *
     * @return non-empty-string Always 'omdb'
     */
    public function sourceName(): string
    {
        return self::SOURCE_NAME;
    }

    /**
     * Media types this source supports.
     *
     * @return list<non-empty-string> ['movie', 'series']
     */
    public function supportedMediaTypes(): array
    {
        return ['movie', 'series'];
    }

    /**
     * Search OMDb for items matching a free-text query.
     *
     * @param string $query Free-text query (e.g., a title parsed from a filename)
     * @param array<string, mixed> $options Optional hints such as year/language
     * @return list<array{id: non-empty-string, title: string, overview?: string, poster_path?: string}>
     */
    public function search(string $query, array $options = []): array
    {
        $settings = $this->settings ?? new OmdbSettings();
        if ($settings->isConfigured() === false) {
            return [];
        }
        $api = $this->ensureApi();
        if ($api === null) {
            return [];
        }

        $year = null;
        if (isset($options['year']) && is_int($options['year'])) {
            $year = $options['year'];
        }

        $results = $api->search($query, $year);

        $items = [];
        foreach ($results as $result) {
            if (is_array($result) === false) {
                continue;
            }
            $imdbId = (is_string($result['imdb_id'] ?? null) ? $result['imdb_id'] : '');
            if ($imdbId === '') {
                continue;
            }

            $title = (is_string($result['title'] ?? null) ? $result['title'] : '');

            /** @var array{id: non-empty-string, title: string} $entry */
            $entry = [
                'id' => $imdbId,
                'title' => $title,
            ];

            $items[] = $entry;
        }

        return $items;
    }

    /**
     * Fetch the full metadata record for an IMDb ID.
     *
     * This is a PURE READ: fetch, shape, return. It performs NO persistence
     * and ignores any `media_item_id` hint — the host resolver (F2) owns the
     * media_item_id and drives writing ratings/fields into the DB. Results are
     * intended to merge UNDER TMDB (gap-fill only).
     *
     * Ratings are emitted enum-safe for `metadata_ratings.source`
     * (allowed: imdb, tmdb, rt, aggregate): IMDb -> `imdb`,
     * Rotten Tomatoes -> `rt`. Metascore is DROPPED (no valid ENUM member),
     * and no plugin-local aggregate is computed.
     *
     * @param string $externalId IMDb ID from search() (e.g., "tt0120737")
     * @param array<string, mixed> $options Optional hints such as language
     * @return array<string, mixed> Detailed metadata, or [] when not found
     */
    public function getDetails(string $externalId, array $options = []): array
    {
        $settings = $this->settings ?? new OmdbSettings();
        if ($settings->isConfigured() === false) {
            return [];
        }
        $api = $this->ensureApi();
        if ($api === null) {
            return [];
        }

        $details = $api->getByImdbId($externalId);
        if ($details === null) {
            return [];
        }

        $ratings = OmdbApi::extractRatings($details);

        // Emit ratings ONLY under valid metadata_ratings.source ENUM values.
        // Metascore is intentionally omitted (no valid ENUM member) and no
        // aggregate is computed here — the host resolver owns aggregation.
        $ratingList = [];
        if ($ratings['imdb'] !== null) {
            $ratingList[] = ['source' => 'imdb', 'score' => $ratings['imdb']];
        }
        if ($ratings['rotten_tomatoes'] !== null) {
            $ratingList[] = ['source' => 'rt', 'score' => $ratings['rotten_tomatoes']];
        }

        // Build the return array with standard metadata fields (pure read).
        return [
            'source'    => self::SOURCE_NAME,
            'imdb_id'   => $externalId,
            'title'     => $details['Title'] ?? '',
            'year'      => $details['Year'] ?? '',
            'rated'     => $details['Rated'] ?? '',
            'released'  => $details['Released'] ?? '',
            'runtime'   => $details['Runtime'] ?? '',
            'genre'     => $details['Genre'] ?? '',
            'director'  => $details['Director'] ?? '',
            'writer'    => $details['Writer'] ?? '',
            'actors'    => $details['Actors'] ?? '',
            'plot'      => $details['Plot'] ?? '',
            'language'  => $details['Language'] ?? '',
            'country'   => $details['Country'] ?? '',
            'awards'    => $details['Awards'] ?? '',
            'poster_url'=> $details['Poster'] ?? '',
            'type'      => $details['Type'] ?? '',
            'ratings'   => $ratingList,
        ];
    }

    /**
     * Fetch image URLs for an IMDb ID.
     *
     * OMDb only provides a single poster image, no backdrops or fanart.
     *
     * @param string $externalId The external id from search()
     * @return array<string, list<array{url: non-empty-string, width?: int, height?: int}>> Images keyed by type
     */
    public function getImages(string $externalId): array
    {
        $settings = $this->settings ?? new OmdbSettings();
        if ($settings->isConfigured() === false) {
            return [];
        }
        $api = $this->ensureApi();
        if ($api === null) {
            return [];
        }

        $details = $api->getByImdbId($externalId);
        if ($details === null) {
            return [];
        }

        $poster = $details['Poster'] ?? '';
        if (is_string($poster) === false || $poster === '' || $poster === 'N/A') {
            return [];
        }

        return [
            'poster' => [
                ['url' => $poster],
            ],
        ];
    }

    /**
     * Get the current settings.
     *
     * @return OmdbSettings
     */
    public function getSettings(): OmdbSettings
    {
        return $this->settings ?? new OmdbSettings();
    }
}
