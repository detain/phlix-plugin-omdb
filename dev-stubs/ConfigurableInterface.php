<?php

/**
 * Dev-only stub of the host server's configurable-plugin contract.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 *
 * @internal Tests only — never autoloaded into production.
 */

declare(strict_types=1);

namespace Phlix\Shared\Plugin;

/**
 * Dev-only stub of the host server's configurable-plugin contract.
 *
 * Loaded by `tests/bootstrap.php` only when `detain/phlix-shared` is
 * genuinely absent from the classpath. This stub is SIGNATURE-compatible
 * with the canonical definition in `detain/phlix-shared` at
 * `src/Plugin/ConfigurableInterface.php` — same single method, parameter
 * and return type — but NOT byte-identical: the canonical's extensive
 * docblocks are trimmed away here. Keep the METHOD SIGNATURE in lockstep
 * with the canonical; nothing behavioral may drift.
 *
 * @internal Tests only — never autoloaded into production.
 */
interface ConfigurableInterface
{
    /**
     * @param array<string, mixed> $settings Raw persisted settings map
     * @return void
     */
    public function configure(array $settings): void;
}
