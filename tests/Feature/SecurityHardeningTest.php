<?php

declare(strict_types=1);

use ProcessWire\ProcessWireConsole;

require_once __DIR__ . '/../bootstrap.php';
$wire = \Site\modules\WireConsole\tests\bootProcessWire();

/**
 * Extract CSRF token name/value from the SessionCSRF::renderInput() HTML.
 *
 * @return array{name: string, value: string}
 */
function getCsrfTokenPair(\ProcessWire\Session $session): array
{
    $html = (string) $session->CSRF->renderInput();
    preg_match("/name='([^']+)'/", $html, $m1);
    preg_match("/value='([^']+)'/", $html, $m2);
    return [
        'name' => $m1[1] ?? '',
        'value' => $m2[1] ?? '',
    ];
}

/**
 * Get a superuser for integration-style tests.
 */
function getSuperuser(\ProcessWire\ProcessWire $wire): ?\ProcessWire\User
{
    $users = $wire->users->find('roles=superuser, limit=1');
    $u = $users->first();
    return $u && $u->id ? $u : null;
}

test('migrations run requires typed confirmation', function () use ($wire): void {
    $admin = getSuperuser($wire);
    if (!$admin) {
        $this->markTestSkipped('No superuser found in this installation.');
    }
    $wire->users->setCurrentUser($admin);

    $process = new ProcessWireConsole();
    $process->setWire($wire);

    $csrf = getCsrfTokenPair($wire->session);
    $wire->input->post->set('wc_action', 'migrate_run');
    $wire->input->post->set('confirm', 1);
    $wire->input->post->set('confirm_text', 'NOPE');
    $wire->input->post->set($csrf['name'], $csrf['value']);

    $ref = new ReflectionClass($process);
    $m = $ref->getMethod('handleMigrationsPost');
    $m->setAccessible(true);
    $m->invoke($process);

    $messages = (array) $wire->session->get('wireconsole_panel_messages');
    $texts = array_column($messages['migrations'] ?? [], 'text');
    expect(implode("\n", $texts))->toContain('Type RUN to confirm.');
});

test('queues clear requires typed confirmation', function () use ($wire): void {
    $admin = getSuperuser($wire);
    if (!$admin) {
        $this->markTestSkipped('No superuser found in this installation.');
    }
    $wire->users->setCurrentUser($admin);

    $process = new ProcessWireConsole();
    $process->setWire($wire);

    $csrf = getCsrfTokenPair($wire->session);
    $wire->input->post->set('wc_action', 'queue_clear');
    $wire->input->post->set('confirm', 1);
    $wire->input->post->set('confirm_text', 'NOPE');
    $wire->input->post->set($csrf['name'], $csrf['value']);

    $ref = new ReflectionClass($process);
    $m = $ref->getMethod('handleQueuesPost');
    $m->setAccessible(true);
    $m->invoke($process);

    $messages = (array) $wire->session->get('wireconsole_panel_messages');
    $texts = array_column($messages['queues'] ?? [], 'text');
    expect(implode("\n", $texts))->toContain('Type CLEAR to confirm.');
});

test('backup purge requires typed confirmation', function () use ($wire): void {
    $admin = getSuperuser($wire);
    if (!$admin) {
        $this->markTestSkipped('No superuser found in this installation.');
    }
    $wire->users->setCurrentUser($admin);

    $process = new ProcessWireConsole();
    $process->setWire($wire);

    $csrf = getCsrfTokenPair($wire->session);
    $wire->input->post->set('wc_action', 'backup_purge');
    $wire->input->post->set('confirm', 1);
    $wire->input->post->set('confirm_text', 'NOPE');
    $wire->input->post->set('days', 30);
    $wire->input->post->set($csrf['name'], $csrf['value']);

    $ref = new ReflectionClass($process);
    $m = $ref->getMethod('handleBackupPost');
    $m->setAccessible(true);
    $m->invoke($process);

    $messages = (array) $wire->session->get('wireconsole_panel_messages');
    $texts = array_column($messages['backup'] ?? [], 'text');
    expect(implode("\n", $texts))->toContain('Type PURGE to confirm.');
});

test('backup create requires typed confirmation', function () use ($wire): void {
    $admin = getSuperuser($wire);
    if (!$admin) {
        $this->markTestSkipped('No superuser found in this installation.');
    }
    $wire->users->setCurrentUser($admin);

    $process = new ProcessWireConsole();
    $process->setWire($wire);

    $csrf = getCsrfTokenPair($wire->session);
    $wire->input->post->set('wc_action', 'backup_create');
    $wire->input->post->set('confirm', 1);
    $wire->input->post->set('confirm_text', 'NOPE');
    $wire->input->post->set($csrf['name'], $csrf['value']);

    $ref = new ReflectionClass($process);
    $m = $ref->getMethod('handleBackupPost');
    $m->setAccessible(true);
    $m->invoke($process);

    $messages = (array) $wire->session->get('wireconsole_panel_messages');
    $texts = array_column($messages['backup'] ?? [], 'text');
    expect(implode("\n", $texts))->toContain('Type BACKUP to confirm.');
});

test('testing run requires typed confirmation', function () use ($wire): void {
    $admin = getSuperuser($wire);
    if (!$admin) {
        $this->markTestSkipped('No superuser found in this installation.');
    }
    $wire->users->setCurrentUser($admin);

    $process = new ProcessWireConsole();
    $process->setWire($wire);

    $csrf = getCsrfTokenPair($wire->session);
    $wire->input->post->set('wc_action', 'test_run');
    $wire->input->post->set('confirm', 1);
    $wire->input->post->set('confirm_text', 'NOPE');
    $wire->input->post->set($csrf['name'], $csrf['value']);

    $ref = new ReflectionClass($process);
    $m = $ref->getMethod('handleTestingPost');
    $m->setAccessible(true);
    $m->invoke($process);

    $messages = (array) $wire->session->get('wireconsole_panel_messages');
    $texts = array_column($messages['testing'] ?? [], 'text');
    expect(implode("\n", $texts))->toContain('Type TEST to confirm.');
});

test('seeding run requires typed confirmation', function () use ($wire): void {
    $admin = getSuperuser($wire);
    if (!$admin) {
        $this->markTestSkipped('No superuser found in this installation.');
    }
    $wire->users->setCurrentUser($admin);

    $process = new ProcessWireConsole();
    $process->setWire($wire);

    $csrf = getCsrfTokenPair($wire->session);
    $wire->input->post->set('wc_action', 'seed_run');
    $wire->input->post->set('confirm', 1);
    $wire->input->post->set('confirm_text', 'NOPE');
    $wire->input->post->set($csrf['name'], $csrf['value']);

    $ref = new ReflectionClass($process);
    $m = $ref->getMethod('handleSeedingPost');
    $m->setAccessible(true);
    $m->invoke($process);

    $messages = (array) $wire->session->get('wireconsole_panel_messages');
    $texts = array_column($messages['seeding'] ?? [], 'text');
    expect(implode("\n", $texts))->toContain('Type SEED to confirm.');
});

test('schedule run requires typed confirmation', function () use ($wire): void {
    $admin = getSuperuser($wire);
    if (!$admin) {
        $this->markTestSkipped('No superuser found in this installation.');
    }
    $wire->users->setCurrentUser($admin);

    $process = new ProcessWireConsole();
    $process->setWire($wire);

    $csrf = getCsrfTokenPair($wire->session);
    $wire->input->post->set('wc_action', 'schedule_run');
    $wire->input->post->set('confirm', 1);
    $wire->input->post->set('confirm_text', 'NOPE');
    $wire->input->post->set($csrf['name'], $csrf['value']);

    $ref = new ReflectionClass($process);
    $m = $ref->getMethod('handleSchedulingPost');
    $m->setAccessible(true);
    $m->invoke($process);

    $messages = (array) $wire->session->get('wireconsole_panel_messages');
    $texts = array_column($messages['scheduling'] ?? [], 'text');
    expect(implode("\n", $texts))->toContain('Type RUN to confirm.');
});

test('rate limiting blocks repeated actions', function () use ($wire): void {
    $process = new ProcessWireConsole();
    $process->setWire($wire);

    $ref = new ReflectionClass($process);
    $m = $ref->getMethod('checkRateLimit');
    $m->setAccessible(true);

    $key = 'wireconsole_test_rate_limit';
    $first = (bool) $m->invoke($process, $key, 60);
    $second = (bool) $m->invoke($process, $key, 60);

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse();
});

test('backup file resolver rejects traversal and unknown files', function () use ($wire): void {
    $process = new ProcessWireConsole();
    $process->setWire($wire);

    $ref = new ReflectionClass($process);
    $m = $ref->getMethod('resolveDatabaseBackupFilePath');
    $m->setAccessible(true);

    $bad1 = $m->invoke($process, '../secrets.sql');
    $bad2 = $m->invoke($process, 'does-not-exist.sql');

    expect($bad1)->toBeNull()
        ->and($bad2)->toBeNull();
});

test('wire test args are allowlisted', function () use ($wire): void {
    $process = new ProcessWireConsole();
    $process->setWire($wire);

    $ref = new ReflectionClass($process);
    $m = $ref->getMethod('sanitizeWireTestArgs');
    $m->setAccessible(true);

    $args = "--filter=WireConsole --parallel --configuration=/etc/passwd ; rm -rf / --colors=never --unknown=1";
    $clean = (string) $m->invoke($process, $args);

    expect($clean)->toContain('--filter=WireConsole')
        ->and($clean)->toContain('--parallel')
        ->and($clean)->toContain('--colors=never')
        ->and($clean)->not->toContain('--configuration')
        ->and($clean)->not->toContain('rm')
        ->and($clean)->not->toContain('--unknown');
});
