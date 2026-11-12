<?php

/**
 * Test bootstrap for the plugin PHPUnit suite.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

/**
 * Test bootstrap for the plugin's own PHPUnit suite.
 *
 * The shared contracts this plugin implements — LifecycleInterface,
 * ConfigurableInterface (Phlix\Shared\Plugin) and MetadataSourceInterface
 * (Phlix\Shared\Metadata) — ship in `detain/phlix-shared`, which
 * composer.json requires at RUNTIME.
 * In a normal `composer install && vendor/bin/phpunit` checkout they are
 * therefore already autoloaded from vendor/ and the stub branches below
 * never fire; inside an installed plugin (`var/plugins/phlix-plugin-omdb/`)
 * they may additionally be resolved by the host application's autoloader.
 * The dev-stubs exist only as a belt for exotic classpath states
 * (phlix-shared genuinely absent): one stub per shared contract the plugin
 * implements — all three, each behind its own interface_exists() guard —
 * mirroring the canonical SIGNATURES, so the suite runs green either way.
 */

require __DIR__ . '/../vendor/autoload.php';

if (!interface_exists(\Phlix\Shared\Plugin\LifecycleInterface::class)) {
    require __DIR__ . '/../dev-stubs/LifecycleInterface.php';
}

if (!interface_exists(\Phlix\Shared\Plugin\ConfigurableInterface::class)) {
    require __DIR__ . '/../dev-stubs/ConfigurableInterface.php';
}

if (!interface_exists(\Phlix\Shared\Metadata\MetadataSourceInterface::class)) {
    require __DIR__ . '/../dev-stubs/MetadataSourceInterface.php';
}
