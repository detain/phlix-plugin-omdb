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
 * Phlix server checkout. Keep this file byte-compatible with the
 * canonical definition in `detain/phlix-shared` at
 * `src/Plugin/LifecycleInterface.php`.
 *
 * @internal Tests only — never autoloaded into production.
 */
interface LifecycleInterface
{
    /**
     * @param ContainerInterface $container Host PSR-11 container
     */
    public function onEnable(ContainerInterface $container): void;

    public function onDisable(): void;

    /**
     * @return array<class-string, string|callable>
     */
    public function subscribedEvents(): array;
}
