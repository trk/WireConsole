<?php

declare(strict_types=1);

use ProcessWire\ProcessWireConsole;
use ProcessWire\WireConsole;

/**
 * These tests are intentionally lightweight and non-destructive.
 * They validate module metadata and guard logic without requiring any DB mutations.
 */

require_once __DIR__ . '/../bootstrap.php';
\Site\modules\WireConsole\tests\bootProcessWire();

test('ProcessWireConsole module info contains permission and admin page definition', function (): void {
    $info = ProcessWireConsole::getModuleInfo();

    expect($info)->toHaveKey('permission')
        ->and($info['permission'])->toBe('wire-console');

    expect($info)->toHaveKey('permissions')
        ->and($info['permissions'])->toHaveKey('wire-console')
        ->and($info['permissions'])->toHaveKey('wire-console-migrations')
        ->and($info['permissions'])->toHaveKey('wire-console-queues')
        ->and($info['permissions'])->toHaveKey('wire-console-seeding')
        ->and($info['permissions'])->toHaveKey('wire-console-scheduling')
        ->and($info['permissions'])->toHaveKey('wire-console-backup')
        ->and($info['permissions'])->toHaveKey('wire-console-testing');

    expect($info)->toHaveKey('page')
        ->and($info['page'])->toHaveKeys(['name', 'parent', 'title'])
        ->and($info['page']['name'])->toBe('wire-console')
        ->and($info['page']['parent'])->toBe('setup');
});

test('WireConsole module is configurable and has basic metadata', function (): void {
    $info = WireConsole::getModuleInfo();

    expect($info)->toHaveKey('title')
        ->and($info['title'])->toBeString();

    expect($info)->toHaveKey('singular')
        ->and($info['singular'])->toBeTrue();

    expect($info)->toHaveKey('autoload')
        ->and($info['autoload'])->toBeFalse();
});
