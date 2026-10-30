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
 * The dev-stubs exist only as a vestigial belt for exotic classpath states
 * (phlix-shared genuinely absent) and mirror the canonical SIGNATURES.
 */

require __DIR__.'/../vendor/autoload.php';

if (!interface_exists(\Phlix\Shared\Plugin\LifecycleInterface::class)) {
    require __DIR__.'/../dev-stubs/LifecycleInterface.php';
}

if (!interface_exists(\Phlix\Shared\Metadata\MetadataSourceInterface::class)) {
    include __DIR__.'/../dev-stubs/MetadataSourceInterface.php';
}
