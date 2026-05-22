<?php

declare(strict_types=1);

/**
 * Test bootstrap for WireConsole module tests.
 *
 * This boots ProcessWire without rendering frontend output and ensures
 * the module classes are available to the test runtime.
 */

namespace Site\modules\WireConsole\tests;

use ProcessWire\Config;
use ProcessWire\ProcessWire;

/**
 * Boot ProcessWire and return the current instance.
 */
function bootProcessWire(): ProcessWire
{
    $root = findProjectRoot(__DIR__);

    $composerAutoloader = $root . '/vendor/autoload.php';
    if (is_file($composerAutoloader)) {
        require_once $composerAutoloader;
    }

    if (!class_exists(ProcessWire::class, false)) {
        require_once $root . '/wire/core/ProcessWire.php';
    }

    /** @var Config $config */
    $config = ProcessWire::buildConfig($root);

    /**
     * Tests should be isolated from project-specific init/ready code.
     * Disable site status files (init/ready hooks) so test runs do not execute
     * site/init.php, site/ready.php, readySite.php, etc.
     */
    $config->statusFiles = [];

    // Boot without rendering ProcessPageView output.
    $wire = new ProcessWire($config, '/', false);
    ProcessWire::setCurrentInstance($wire);
    $wire->boot($config);
    ProcessWire::setCurrentInstance($wire);

    // Ensure our module classes are defined (tests call static methods).
    require_once $root . '/site/modules/WireConsole/WireConsole.module.php';
    require_once $root . '/site/modules/WireConsole/ProcessWireConsole.module.php';

    return $wire;
}

/**
 * Find project root by walking up until both "wire/" and "index.php" exist.
 */
function findProjectRoot(string $startDir): string
{
    $dir = $startDir;
    for ($i = 0; $i < 10; $i++) {
        if (is_file($dir . '/index.php') && is_dir($dir . '/wire')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    throw new \RuntimeException('Could not locate ProcessWire project root for tests.');
}
