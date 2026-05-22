<?php

declare(strict_types=1);

use ProcessWire\ProcessWireConsole;

require_once __DIR__ . '/../bootstrap.php';
$wire = \Site\modules\WireConsole\tests\bootProcessWire();

/**
 * Assert that $haystack contains at least one of the $needles.
 *
 * @param string $haystack
 * @param string[] $needles
 */
function assertContainsAny(string $haystack, array $needles): void
{
    foreach ($needles as $needle) {
        if (str_contains($haystack, $needle)) {
            expect(true)->toBeTrue();
            return;
        }
    }

    expect($haystack)->toContain($needles[0]); // show a useful diff
}

test('admin feature cards render without throwing (guest user)', function (): void {
    $process = new ProcessWireConsole();
    $process->setWire($wire);

    $ref = new ReflectionClass($process);

    $cards = [
        'renderMigrationsCard' => [
            'title' => 'Migrations',
            'containsAny' => [
                'Permission required to view this section',
                'Migrations table is not installed',
                'Migrations UI is not available via PHP API',
            ],
        ],
        'renderQueuesCard' => [
            'title' => 'Queues',
            'containsAny' => [
                'Permission required to view this section',
                'Queue tables are not installed',
                'Queues UI is not available via PHP API',
            ],
        ],
        'renderSeedingCard' => [
            'title' => 'Database Seeding',
            'containsAny' => [
                'Permission required to view this section',
                'Database seeding UI is not available via PHP API',
                'Discovered seeders',
            ],
        ],
        'renderSchedulingCard' => [
            'title' => 'Task Scheduling',
            'containsAny' => [
                'Permission required to view this section',
                'Scheduling UI is not available via PHP API',
                'Run schedule:run',
            ],
        ],
        'renderBackupCard' => [
            'title' => 'Database & Backup',
            'containsAny' => [
                'Permission required to view this section',
                'Create database backup',
                'Database backup tool not available',
            ],
        ],
        'renderTestingCard' => [
            'title' => 'Testing',
            'containsAny' => [
                'Permission required to view this section',
                'php vendor/bin/wire test',
                'Process execution is disabled',
            ],
        ],
    ];

    foreach ($cards as $method => $expectations) {
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        $html = (string) $m->invoke($process);

        expect($html)->toContain((string) $expectations['title']);
        assertContainsAny($html, (array) $expectations['containsAny']);
    }
});

test('backup card includes restore safety confirmation UX', function (): void {
    $process = new ProcessWireConsole();
    $process->setWire($wire);

    $ref = new ReflectionClass($process);
    $m = $ref->getMethod('renderBackupCard');
    $m->setAccessible(true);

    $html = (string) $m->invoke($process);

    // If user doesn't have backup permission, the card will render a permission-required notice.
    // Otherwise it must contain the restore safety UX.
    if (str_contains($html, 'Permission required to view this section')) {
        expect($html)->toContain('Required permission: wire-console-backup');
        return;
    }

    expect($html)->toContain('backup_restore')
        ->and($html)->toContain('placeholder="RESTORE"')
        ->and($html)->toContain('Restore will overwrite your database');
});
