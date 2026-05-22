<?php

declare(strict_types=1);

use ProcessWire\ProcessWireConsole;

require_once __DIR__ . '/../bootstrap.php';
$wire = \Site\modules\WireConsole\tests\bootProcessWire();

/**
 * Ensure "handler" methods do not react to unrelated wc_action values.
 * This prevents one tab POST from accidentally executing logic from another tab.
 */
test('handlers ignore unrelated wc_action prefixes', function (): void {
    $process = new ProcessWireConsole();
    $process->setWire($wire);

    $ref = new ReflectionClass($process);

    $handlers = [
        // method => action that should be ignored
        'handleMigrationsPost' => 'queue_install',
        'handleQueuesPost' => 'migrate_install',
        'handleBackupPost' => 'seed_run',
        'handleSeedingPost' => 'backup_create',
        'handleSchedulingPost' => 'test_run',
        'handleTestingPost' => 'schedule_run',
    ];

    foreach ($handlers as $method => $action) {
        $wire->input->post->set('wc_action', $action);

        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        // Must not throw, and must not attempt CSRF validation for unrelated prefixes.
        $m->invoke($process);
    }

    expect(true)->toBeTrue();
});
