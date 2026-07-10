<?php

/**
 * Unit tests for OmdbApi.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\Omdb\Tests;

use Phlix\Plugins\Metadata\Omdb\OmdbApi;
use PHPUnit\Framework\TestCase;

final class OmdbApiTest extends TestCase
{
    public function test_extract_ratings_parses_imdb_rating(): void
    {
        $details = [
            'imdbRating' => '8.8',
            'Ratings' => [],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertSame(8.8, $ratings['imdb']);
        $this->assertNull($ratings['rotten_tomatoes']);
        $this->assertNull($ratings['metascore']);
    }

    public function test_extract_ratings_parses_rotten_tomatoes_percentage(): void
    {
        $details = [
            'imdbRating' => 'N/A',
            'Ratings' => [
                ['Source' => 'Rotten Tomatoes', 'Value' => '97%'],
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertSame(9.7, $ratings['rotten_tomatoes']);
    }

    public function test_extract_ratings_parses_metascore_fraction(): void
    {
        $details = [
            'imdbRating' => '8.5',
            'Ratings' => [
                ['Source' => 'Metacritic', 'Value' => '72/100'],
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertSame(7.2, $ratings['metascore']);
    }

    public function test_extract_ratings_handles_all_sources(): void
    {
        $details = [
            'imdbRating' => '8.5',
            'Ratings' => [
                ['Source' => 'Internet Movie Database', 'Value' => '8.5/10'],
                ['Source' => 'Rotten Tomatoes', 'Value' => '91%'],
                ['Source' => 'Metacritic', 'Value' => '64/100'],
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertSame(8.5, $ratings['imdb']);
        $this->assertSame(9.1, $ratings['rotten_tomatoes']);
        $this->assertSame(6.4, $ratings['metascore']);
    }

    public function test_extract_ratings_handles_missing_ratings(): void
    {
        $details = [
            'imdbRating' => 'N/A',
            'Ratings' => [],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertNull($ratings['imdb']);
        $this->assertNull($ratings['rotten_tomatoes']);
        $this->assertNull($ratings['metascore']);
    }

    public function test_extract_ratings_handles_na_values(): void
    {
        $details = [
            'imdbRating' => 'N/A',
            'Ratings' => [
                ['Source' => 'Rotten Tomatoes', 'Value' => 'N/A'],
                ['Source' => 'Metacritic', 'Value' => 'N/A'],
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertNull($ratings['imdb']);
        $this->assertNull($ratings['rotten_tomatoes']);
        $this->assertNull($ratings['metascore']);
    }

    public function test_extract_ratings_handles_missing_keys(): void
    {
        $details = [];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertNull($ratings['imdb']);
        $this->assertNull($ratings['rotten_tomatoes']);
        $this->assertNull($ratings['metascore']);
    }

    public function test_extract_ratings_rounds_to_one_decimal(): void
    {
        $details = [
            'imdbRating' => '8.8',
            'Ratings' => [
                ['Source' => 'Rotten Tomatoes', 'Value' => '92%'],
                ['Source' => 'Metacritic', 'Value' => '64/100'],
            ],
        ];

        $ratings = OmdbApi::extractRatings($details);

        $this->assertEqualsWithDelta(8.8, $ratings['imdb'], 0.01);
        $this->assertEqualsWithDelta(9.2, $ratings['rotten_tomatoes'], 0.01);
        $this->assertEqualsWithDelta(6.4, $ratings['metascore'], 0.01);
    }
}
