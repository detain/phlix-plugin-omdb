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
use Phlix\Plugins\Metadata\Omdb\RatingIngester;
use Phlix\Shared\Metadata\MetadataSourceInterface;
use Phlix\Shared\Plugin\LifecycleInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

/**
 * OMDb metadata provider plugin for Phlix.
 *
 * Fetches metadata and ratings from the OMDb API for movies and TV series.
 * Ratings are stored in the metadata_ratings table and feed into Phlix's
 * rating aggregation pipeline.
 *
 * ## Features
 *
 * - IMDb ID resolution via title + year search
 * - Multi-source ratings: IMDb, Rotten Tomatoes, Metascore
 * - Ratings stored in metadata_ratings for aggregation
 * - Non-blocking async HTTP via Workerman/http-client
 * - Configurable rate limiting and response caching
 * - TLS verification toggle for self-hosted proxies
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
final class OmdbPlugin implements LifecycleInterface, MetadataSourceInterface
{
    /**
     * Canonical source name used in the host priority map.
     */
    public const SOURCE_NAME = 'omdb';

    /**
     * @param OmdbSettings|null $settings Initial settings (loaded from DB on enable)
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     * @param OmdbApi|null $api Pre-built API client (test seam)
     * @param RatingIngester|null $ingester Pre-built rating ingester (test seam)
     */
    public function __construct(
        private ?OmdbSettings $settings = null,
        private ?LoggerInterface $logger = null,
        private ?OmdbApi $api = null,
        private ?RatingIngester $ingester = null,
    ) {
        $this->settings = $this->settings ?? new OmdbSettings();
        $this->logger = $this->logger ?? new NullLogger();
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
     * Called by the loader once when the plugin is enabled.
     *
     * Validates connectivity and credentials, and registers with the host
     * SourceRegistry so the metadata pipeline can consume OMDb results.
     *
     * @param ContainerInterface $container Host PSR-11 container
     * @return void
     * @throws \RuntimeException If API key is missing or OMDb is unreachable
     */
    public function onEnable(ContainerInterface $container): void
    {
        if ($this->logger instanceof NullLogger) {
            $logger = $container->get(LoggerInterface::class);
            $this->logger = $logger instanceof LoggerInterface ? $logger : new NullLogger();
        }

        $settings = $this->settings ?? new OmdbSettings();

        if (!$settings->hasApiKey()) {
            throw new \RuntimeException(
                'OMDb API key not configured. Add your API key in the plugin settings.'
            );
        }

        // Initialize the API client if not injected
        $api = $this->api;
        if ($api === null) {
            $apiKey = $settings->apiKey;
            if (is_string($apiKey)) {
                $api = new OmdbApi(
                    apiKey: $apiKey,
                    useSslVerification: $settings->useSslVerification,
                    cacheTtlSeconds: $settings->cacheTtlSeconds,
                    logger: $this->logger,
                );
                $this->api = $api;
            }
        }

        // Initialize the rating ingester if not injected
        if ($this->ingester === null) {
            try {
                $db = $container->get(Connection::class);
                if ($db instanceof Connection) {
                    $this->ingester = new RatingIngester($db, $this->logger);
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('OMDb: database connection unavailable; ratings will not be stored', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Validate connectivity with a lightweight test request
        if ($api !== null) {
            $test = $api->search('test', 2020);
            if ($test === []) {
                $this->logger?->warning('OMDb: search returned no results for test query — API may be unreachable');
            }
        }
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
        if (!$settings->isConfigured() || $this->api === null) {
            return [];
        }

        $year = null;
        if (isset($options['year']) && is_int($options['year'])) {
            $year = $options['year'];
        }

        $results = $this->api->search($query, $year);

        $items = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $imdbId = is_string($result['imdb_id'] ?? null) ? $result['imdb_id'] : '';
            if ($imdbId === '') {
                continue;
            }

            $title = is_string($result['title'] ?? null) ? $result['title'] : '';

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
     * @param string $externalId IMDb ID from search() (e.g., "tt0120737")
     * @param array<string, mixed> $options Optional hints such as language
     * @return array<string, mixed> Detailed metadata, or [] when not found
     */
    public function getDetails(string $externalId, array $options = []): array
    {
        $settings = $this->settings ?? new OmdbSettings();
        if (!$settings->isConfigured() || $this->api === null) {
            return [];
        }

        $details = $this->api->getByImdbId($externalId);
        if ($details === null) {
            return [];
        }

        $ratings = OmdbApi::extractRatings($details);

        // Build the return array with standard metadata fields
        $result = [
            'source' => self::SOURCE_NAME,
            'imdb_id' => $externalId,
            'title' => $details['Title'] ?? '',
            'year' => $details['Year'] ?? '',
            'rated' => $details['Rated'] ?? '',
            'released' => $details['Released'] ?? '',
            'runtime' => $details['Runtime'] ?? '',
            'genre' => $details['Genre'] ?? '',
            'director' => $details['Director'] ?? '',
            'writer' => $details['Writer'] ?? '',
            'actors' => $details['Actors'] ?? '',
            'plot' => $details['Plot'] ?? '',
            'language' => $details['Language'] ?? '',
            'country' => $details['Country'] ?? '',
            'awards' => $details['Awards'] ?? '',
            'poster_url' => $details['Poster'] ?? '',
            'type' => $details['Type'] ?? '',
            'imdb_rating' => $ratings['imdb'],
            'rotten_tomatoes_rating' => $ratings['rotten_tomatoes'],
            'metascore' => $ratings['metascore'],
        ];

        // Ingest ratings into metadata_ratings if ingester is available
        $mediaItemId = $options['media_item_id'] ?? null;
        if (
            $this->ingester !== null
            && is_string($mediaItemId)
            && $mediaItemId !== ''
        ) {
            $this->ingester->ingest(
                $mediaItemId,
                $ratings['imdb'],
                $ratings['rotten_tomatoes'],
                $ratings['metascore'],
            );
        }

        return $result;
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
        if (!$settings->isConfigured() || $this->api === null) {
            return [];
        }

        $details = $this->api->getByImdbId($externalId);
        if ($details === null) {
            return [];
        }

        $poster = $details['Poster'] ?? '';
        if (!is_string($poster) || $poster === '' || $poster === 'N/A') {
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
