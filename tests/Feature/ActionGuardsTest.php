<?php // __(file-not-translatable)

declare(strict_types=1);

use ProcessWire\ProcessWire;

require_once __DIR__ . '/../bootstrap.php';

beforeEach(function (): void {
	wcSetSuperuser();
	$pw = ProcessWire::getCurrentInstance();
	if ($pw !== null) {
		$pw->input->post->removeAll();
	}
});

afterEach(function (): void {
	wcSetSuperuser();
});

test('mutations reject missing or mismatched confirmation words across all 34 workspace actions', function (): void {
	$module = wcTestModule();
	$runtime = $module->runtime();

	$cases = [
		['method' => 'installMigrationsTable', 'tab' => 'migrations', 'expected' => 'RUN'],
		['method' => 'runMigrations', 'tab' => 'migrations', 'expected' => 'RUN'],
		['method' => 'rollbackMigrations', 'tab' => 'migrations', 'expected' => 'ROLLBACK'],
		['method' => 'resetOrRefreshMigrations', 'tab' => 'migrations', 'expected' => 'RESET'],
		['method' => 'makeMigration', 'tab' => 'migrations', 'expected' => 'CREATE'],
		['method' => 'installQueueTables', 'tab' => 'queues', 'expected' => 'RUN'],
		['method' => 'retryFailedJobs', 'tab' => 'queues', 'expected' => 'RETRY'],
		['method' => 'clearFailedJobs', 'tab' => 'queues', 'expected' => 'CLEAR'],
		['method' => 'purgePendingJobs', 'tab' => 'queues', 'expected' => 'PURGE'],
		['method' => 'deleteFailedJob', 'tab' => 'queues', 'expected' => 'DELETE'],
		['method' => 'workQueueBatch', 'tab' => 'queues', 'expected' => 'WORK'],
		['method' => 'dispatchQueueJob', 'tab' => 'queues', 'expected' => 'DISPATCH'],
		['method' => 'makeQueueClass', 'tab' => 'queues', 'expected' => 'CREATE'],
		['method' => 'runSeeders', 'tab' => 'seeding', 'expected' => 'SEED'],
		['method' => 'makeSeederClass', 'tab' => 'seeding', 'expected' => 'CREATE'],
		['method' => 'runScheduledTasks', 'tab' => 'scheduling', 'expected' => 'RUN'],
		['method' => 'makeTaskClass', 'tab' => 'scheduling', 'expected' => 'CREATE'],
		['method' => 'createBackup', 'tab' => 'backup', 'expected' => 'BACKUP'],
		['method' => 'purgeBackups', 'tab' => 'backup', 'expected' => 'PURGE'],
		['method' => 'restoreBackup', 'tab' => 'backup', 'expected' => 'RESTORE'],
		['method' => 'runTests', 'tab' => 'testing', 'expected' => 'TEST'],
		['method' => 'runE2eTests', 'tab' => 'testing', 'expected' => 'E2E'],
		['method' => 'initPlaywrightE2e', 'tab' => 'testing', 'expected' => 'INIT'],
		['method' => 'makeTestFile', 'tab' => 'testing', 'expected' => 'CREATE'],
		['method' => 'syncBoost', 'tab' => 'boost', 'expected' => 'SYNC'],
		['method' => 'configureBoost', 'tab' => 'boost', 'expected' => 'SAVE'],
		['method' => 'installRemoteBoostSkills', 'tab' => 'boost', 'expected' => 'INSTALL'],
		['method' => 'runMcpReadOnlyTool', 'tab' => 'boost', 'expected' => 'QUERY'],
		['method' => 'makeModuleClass', 'tab' => 'boost', 'expected' => 'CREATE'],
		['method' => 'enableMaintenanceMode', 'tab' => 'overview', 'expected' => 'DOWN'],
		['method' => 'disableMaintenanceMode', 'tab' => 'overview', 'expected' => 'UP'],
		['method' => 'clearSystemCaches', 'tab' => 'overview', 'expected' => 'CACHE'],
		['method' => 'clearLogChannel', 'tab' => 'logs', 'expected' => 'CLEAR'],
		['method' => 'clearAllLogChannels', 'tab' => 'logs', 'expected' => 'CLEARALL'],
	];

	foreach ($cases as $case) {
		$result = $runtime->executeWorkspaceAction($case['method'], []);

		expect($result['ok'])->toBeFalse()
			->and($result['tab'])->toBe($case['tab'])
			->and($result['message'])->toContain('Confirmation failed')
			->and($result['message'])->toContain($case['expected']);
	}

	// Generic `confirm` key without action-scoped `confirm_<guardKey>` must also be rejected
	$genericOnly = $runtime->executeWorkspaceAction('runMigrations', [
		'confirm' => 'RUN',
	]);
	expect($genericOnly['ok'])->toBeFalse()
		->and($genericOnly['tab'])->toBe('migrations')
		->and($genericOnly['message'])->toContain('Confirmation failed');
});

test('non-superuser is blocked from executing mutating actions even with valid confirmation word', function (): void {
	$pw = ProcessWire::getCurrentInstance();
	$module = wcTestModule();
	$runtime = $module->runtime();

	$guest = $pw->users->get('name=guest');
	if ($guest && $guest->id) {
		$pw->users->setCurrentUser($guest);
	}

	$result = $runtime->executeWorkspaceAction('runMigrations', [
		'confirm_migrations_run' => 'RUN',
	]);

	expect($result['ok'])->toBeFalse()
		->and($result['tab'])->toBe('migrations')
		->and($result['message'])->toContain('Access denied');
});

test('session rate limiter blocks rapid repeat executions within cooldown window', function (): void {
	$module = wcTestModule();
	$runtime = $module->runtime();

	$key = 'unit_test_cooldown_' . uniqid();
	$first = $runtime->checkRateLimit($key, 10);
	$second = $runtime->checkRateLimit($key, 10);

	expect($first['allowed'])->toBeTrue()
		->and($first['retryAfter'])->toBe(0)
		->and($second['allowed'])->toBeFalse()
		->and($second['retryAfter'])->toBeGreaterThan(0);
});
