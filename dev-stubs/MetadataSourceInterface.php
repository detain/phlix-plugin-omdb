<?php

/**
 * Dev-only stub of the metadata source interface.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 *
 * @internal Tests only — never autoloaded into production.
 */

declare(strict_types=1);

namespace Phlix\Shared\Metadata;

/**
 * Dev-only stub of the host server's MetadataSourceInterface contract.
 *
 * @internal Tests only — never autoloaded into production.
 */
interface MetadataSourceInterface
{
    /**
     * @return non-empty-string
     */
    public function sourceName(): string;

    /**
     * @return list<non-empty-string>
     */
    public function supportedMediaTypes(): array;

    /**
     * @param string              $query   Free-text query
     * @param array<string,mixed> $options Optional hints such as language
     * @return list<array{id: non-empty-string, title: string, overview?: string, poster_path?: string}>
     */
    public function search(string $query, array $options = []): array;


    /**
     * @param string              $externalId External id from search()
     * @param array<string,mixed> $options    Optional hints such as language
     * @return array<string, mixed> Detailed metadata, or [] when not found
     */
    public function getDetails(string $externalId, array $options = []): array;


    /**
     * @param string $externalId The external id from search()
     * @return array<string, list<array{url: non-empty-string, width?: int, height?: int}>> Images keyed by type
     */
    public function getImages(string $externalId): array;
}
