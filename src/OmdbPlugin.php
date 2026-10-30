<?php

/**
 * OMDb metadata provider plugin for Phlix.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\Omdb;

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
 * - Configurable rate limiting, response caching, and daily-limit cooldown
 * - TLS verification toggle (advanced escape hatch — see use_ssl_verification
 *   below: off means the query-string API key is capturable in transit)
 * - onEnable is a cheap wire-only step: no network, no DB, never throws
 *
 * ## Configuration (plugin.json settings)
 *
 * - enabled: Enable the OMDb provider (boolean, default false)
 * - api_key: OMDb API key (string, secret) — get at http://www.omdbapi.com/apikey.aspx
 * - use_ssl_verification: Verify TLS certificates (boolean, default true) —
 *   turning it off sends the key in cleartext-query to any intercepting hop
 * - cache_ttl_seconds: Cache TTL in seconds (integer, default 86400; 0 disables caching)
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
     * Whether {@see $api} was constructed by {@see ensureApi()} (true) or
     * injected by the caller through the constructor (false). configure()
     * discards only self-built transports — injected clients are the
     * caller's property.
     */
    private bool $apiSelfBuilt = false;

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

        // [M-OMDB-06 minor] A rotated api_key must reach the wire: drop the
        // self-built transport so the next read rebuilds it from the fresh
        // settings (ensureApi() used to keep serving the client built with
        // the old key). Constructor-injected clients belong to the caller
        // and survive untouched.
        if ($this->apiSelfBuilt === true) {
            $this->api = null;
            $this->apiSelfBuilt = false;
        }
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
     * It only resolves a logger and runs the (network-free) transport wire-up
     * {@see ensureApi()} when a key is already present. ensureApi() is not a
     * connect step: constructing OmdbApi performs no I/O, and the first
     * request happens only when a method on the client is invoked. Reads run
     * through the same idempotent ensureApi() call.
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
     * Idempotent transport wire-up: returns the existing client unchanged.
     * Called from {@see onEnable()} (when a key is already configured) and
     * again before every read (search/getDetails/getImages). Constructing
     * {@see OmdbApi} performs NO network I/O; the first request only happens
     * when a method on the returned client is invoked.
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
        $this->apiSelfBuilt = true;

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
        // Every OMDb text field goes through textField(): the API encodes
        // "no value" as the literal string 'N/A', and a proxied/foreign body
        // can carry any JSON shape — both collapse to ''. The Poster member
        // goes through posterUrl(), which additionally requires https.
        return [
            'source'    => self::SOURCE_NAME,
            'imdb_id'   => $externalId,
            'title'     => self::textField($details, 'Title'),
            'year'      => self::textField($details, 'Year'),
            'rated'     => self::textField($details, 'Rated'),
            'released'  => self::textField($details, 'Released'),
            'runtime'   => self::textField($details, 'Runtime'),
            'genre'     => self::textField($details, 'Genre'),
            'director'  => self::textField($details, 'Director'),
            'writer'    => self::textField($details, 'Writer'),
            'actors'    => self::textField($details, 'Actors'),
            'plot'      => self::textField($details, 'Plot'),
            'language'  => self::textField($details, 'Language'),
            'country'   => self::textField($details, 'Country'),
            'awards'    => self::textField($details, 'Awards'),
            'poster_url' => self::posterUrl($details),
            'type'      => self::textField($details, 'Type'),
            'ratings'   => $ratingList,
        ];
    }

    /**
     * Sanitize one OMDb text field for emission.
     *
     * OMDb signals "no value" with the literal string 'N/A'; shape-junk
     * (array/int/bool/null from a proxied or hostile body) is equally
     * unusable. Only genuine, non-sentinel strings pass; everything else
     * maps to '' so the host resolver never persists 'N/A' as data.
     * The getImages() Poster gate uses the same sentinel idiom.
     *
     * @param array<string, mixed> $details
     */
    private static function textField(array $details, string $key): string
    {
        $value = $details[$key] ?? '';

        return (is_string($value) && $value !== 'N/A') ? $value : '';
    }

    /**
     * Sanitize a Poster URL for emission.
     *
     * Real OMDb serves art only over HTTPS (https://m.media-amazon.com/...),
     * and the value flows onward to host persistence and eventually a
     * browser/TV image loader. The textField() sentinel gate plus a strict
     * lowercase `https://` prefix are required: a proxied or hostile body
     * can carry 'http://' (key-adjacent traffic downgrade), 'javascript:'
     * (script URL sinks), 'file:' or any other shape, and those must map to
     * '' exactly like 'N/A' does. Uppercase scheme variants are rejected
     * too — canonical OMDb values are lowercase, and fail-closed keeps a
     * casing trick out of the emitter.
     *
     * @param array<string, mixed> $details
     */
    private static function posterUrl(array $details): string
    {
        $value = self::textField($details, 'Poster');

        return (preg_match('/^https:\/\/\S+$/', $value) === 1) ? $value : '';
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

        // [M-OMDB-07 minor] Same gate as getDetails(): only a strict https
        // Poster yields an image; sentinel/junk/scheme-shapeshifters -> [].
        $poster = self::posterUrl($details);
        if ($poster === '') {
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
