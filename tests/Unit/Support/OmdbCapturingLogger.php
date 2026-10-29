<?php

/**
 * PSR-3 collector for asserting on the API's log surfaces.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Metadata\Omdb\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Records every message and context array passed to it, so tests can assert
 * that no secret substring reaches a log sink.
 */
final class OmdbCapturingLogger extends AbstractLogger
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [(string) $message, $context];
    }
}
