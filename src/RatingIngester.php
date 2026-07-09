<?php

/**
 * Rating ingester for OMDb ratings.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\Omdb;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Workerman\MySQL\Connection;

/**
 * Writes OMDb ratings to the metadata_ratings table.
 *
 * Handles upsert semantics for the same source+type combination and
 * triggers aggregation after each batch of ratings.
 */
final class RatingIngester
{
    /**
     * @param Connection $db Database connection
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     */
    public function __construct(
        private readonly Connection $db,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Ingest ratings from OMDb for a media item.
     *
     * @param string $mediaItemId The media item UUID
     * @param float|null $imdbRating IMDb rating (0-10 scale)
     * @param float|null $rtRating Rotten Tomatoes rating (0-10 scale)
     * @param float|null $metascore Metascore rating (0-10 scale)
     * @return void
     */
    public function ingest(
        string $mediaItemId,
        ?float $imdbRating = null,
        ?float $rtRating = null,
        ?float $metascore = null,
    ): void {
        // IMDb rating (stored as TMDB source since TMDB also uses IMDb IDs)
        if ($imdbRating !== null) {
            $this->upsertRating($mediaItemId, 'imdb', 'critic', $imdbRating, null);
        }

        // Rotten Tomatoes rating
        if ($rtRating !== null) {
            $this->upsertRating($mediaItemId, 'rt', 'critic', $rtRating, null);
        }

        // Metascore rating
        if ($metascore !== null) {
            $this->upsertRating($mediaItemId, 'metacritic', 'meta', $metascore, null);
        }

        // Trigger aggregation
        $this->aggregate($mediaItemId);
    }

    /**
     * Upsert a single rating record.
     *
     * @param string $mediaItemId The media item UUID
     * @param string $source Rating source (e.g., 'imdb', 'rt', 'metacritic')
     * @param string $ratingType Rating type (e.g., 'critic', 'meta', 'average')
     * @param float $score Score (0.0-10.0)
     * @param int|null $votes Optional vote count
     * @return void
     */
    private function upsertRating(
        string $mediaItemId,
        string $source,
        string $ratingType,
        float $score,
        ?int $votes = null,
    ): void {
        $score = match (true) {
            $score < 0.0 => 0.0,
            $score > 10.0 => 10.0,
            default => round($score, 1),
        };

        try {
            $this->db->query(
                'INSERT INTO metadata_ratings (media_item_id, source, rating_type, score, votes)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE score = VALUES(score), votes = VALUES(votes)',
                [
                    $mediaItemId,
                    $source,
                    $ratingType,
                    (string) $score,
                    $votes,
                ]
            );

            $this->logger?->debug('OMDb: rating upserted', [
                'media_item_id' => $mediaItemId,
                'source' => $source,
                'type' => $ratingType,
                'score' => $score,
            ]);
        } catch (\Throwable $e) {
            $this->logger?->warning('OMDb: failed to upsert rating', [
                'media_item_id' => $mediaItemId,
                'source' => $source,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Compute and persist a weighted-average rating across all sources.
     *
     * @param string $mediaItemId The media item UUID
     * @return void
     */
    private function aggregate(string $mediaItemId): void
    {
        try {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $this->db->query(
                'SELECT score, votes FROM metadata_ratings WHERE media_item_id = ?',
                [$mediaItemId]
            );

            if ($rows === []) {
                return;
            }

            $totalScore = 0.0;
            $totalWeight = 0.0;

            foreach ($rows as $row) {
                /** @var array<string, mixed> $row */
                $score = is_numeric($row['score'] ?? null) ? (float) $row['score'] : 0.0;
                $votes = isset($row['votes']) && is_int($row['votes']) ? $row['votes'] : 1;

                $totalScore += $score * $votes;
                $totalWeight += $votes;
            }

            if ($totalWeight <= 0.0) {
                return;
            }

            $weightedAverage = round($totalScore / $totalWeight, 1);

            /** @var int $totalVotes */
            $totalVotes = array_reduce(
                $rows,
                static function (int $sum, array $row): int {
                    $votes = $row['votes'] ?? 1;
                    return $sum + (is_numeric($votes) ? (int) $votes : 1);
                },
                0
            );

            $this->upsertRating(
                $mediaItemId,
                'omdb', // aggregate source identifier
                'average',
                $weightedAverage,
                $totalVotes,
            );
        } catch (\Throwable $e) {
            $this->logger?->warning('OMDb: failed to aggregate ratings', [
                'media_item_id' => $mediaItemId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
