<?php

/**
 * Dev-only stub of the host server's lifecycle contract.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 *
 * @internal Tests only — never autoloaded into production.
 */

declare(strict_types=1);

namespace Phlix\Shared\Plugin;

use Psr\Container\ContainerInterface;

/**
 * Dev-only stub of the host server's lifecycle contract.
 *
 * Loaded by `tests/bootstrap.php` when the plugin is tested outside a
 * Phlix server checkout. This stub is SIGNATURE-compatible with the
 * canonical definition in `detain/phlix-shared` at
 * `src/Plugin/LifecycleInterface.php` — same three methods, parameters
 * and return types — but NOT byte-identical: the canonical's extensive
 * docblocks are trimmed away here and this file adds local formatting.
 * Keep the METHOD SIGNATURES in lockstep with the canonical; nothing
 * behavioral may drift.
 *
 * @internal Tests only — never autoloaded into production.
 */
interface LifecycleInterface
{

    /**
     * @param ContainerInterface $container Host PSR-11 container
     * @return void
     */
    public function onEnable(ContainerInterface $container): void;


    public function onDisable(): void;


    /**
     * @return array<class-string, string|callable>
     */
    public function subscribedEvents(): array;
}
