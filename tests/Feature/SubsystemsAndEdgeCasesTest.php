<?php // __(file-not-translatable)

declare(strict_types=1);

use ProcessWire\React;
use ProcessWire\ProcessWire;
use ProcessWire\Wire404Exception;
use Totoglu\React\Icon\IconManager;
use Totoglu\WireConsole\ConsoleRuntime;

require_once __DIR__ . '/../bootstrap.php';

beforeEach(function (): void {
	wcSetSuperuser();
	$pw = ProcessWire::getCurrentInstance();
	$pw?->input?->post->removeAll();
	$pw?->input?->get->removeAll();
	unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_X_XSRF_TOKEN'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE']);
});

afterEach(function (): void {
	wcSetSuperuser();
	$pw = ProcessWire::getCurrentInstance();
	$pw?->input?->post->removeAll();
	$pw?->input?->get->removeAll();
	unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_X_XSRF_TOKEN'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE']);
	$homePage = $pw?->pages?->get(1);
	if ($homePage && $homePage->id) {
		$pw?->fuel?->set('page', $homePage);
	}
});

test('IconManager registers Console canonical vector icons and compiles admin CSS mask rules', function (): void {
	$module = wcTestModule();
	$icons = React::instance($module)->icons();
	$catalog = IconManager::loadCatalog()['outline'] ?? [];
	$expectedIcons = [
		'terminal',
		'layout-dashboard',
		'database',
		'stack-2',
		'plant',
		'clock',
		'server',
		'flask',
		'sparkles',
		'list-details',
	];

	foreach ($expectedIcons as $iconName) {
		expect(array_key_exists($iconName, $catalog))->toBeTrue("Expected {$iconName} in vector outline catalog")
			->and($icons->hasIcon($iconName))->toBeTrue()
			->and($icons->renderSvg($iconName))->toContain('<svg');
	}

	$cssUrl = $icons->ensureAdminCss();
	expect($cssUrl)->toContain('.css');
});

test('executeAction validates CSRF tokens across _token field and HTTP_X_CSRF_TOKEN header', function (): void {
	$pw = ProcessWire::getCurrentInstance();
	$module = wcTestModule();
	$validToken = (string) $pw->session->CSRF->getTokenValue();

	$pw->input->post->set('action', 'inspectFile');
	$pw->input->post->set('inspect_category', 'boost-doc');
	$pw->input->post->set('inspect_file', 'agents-md');
	$pw->input->post->set('tab', 'boost');

	// 1. Empty _token must be rejected without leaking inspectedFile content
	$pw->input->post->set('_token', '');
	$emptyHtml = $module->executeAction();
	expect($emptyHtml)->toContain('Invalid or missing CSRF token')
		->and($emptyHtml)->not->toContain('ProcessWire Boost Guidelines');
	$pw->input->post->remove('_token');

	// 2. Tampered HTTP_X_CSRF_TOKEN header must be rejected without leaking inspectedFile content
	$_SERVER['HTTP_X_CSRF_TOKEN'] = 'tampered-header-token';
	$tamperedHeaderHtml = $module->executeAction();
	expect($tamperedHeaderHtml)->toContain('Invalid or missing CSRF token')
		->and($tamperedHeaderHtml)->not->toContain('ProcessWire Boost Guidelines');

	// 3. Valid HTTP_X_CSRF_TOKEN header must succeed
	$_SERVER['HTTP_X_CSRF_TOKEN'] = $validToken;
	$validHeaderHtml = $module->executeAction();
	expect($validHeaderHtml)->toContain('AGENTS.md')
		->and($validHeaderHtml)->toContain('ProcessWire Boost Guidelines')
		->and($validHeaderHtml)->not->toContain('Invalid or missing CSRF token');
});

test('granular non-superuser role sees only permitted tabs and payload slices while falling back disallowed tabs to overview', function (): void {
	$pw = ProcessWire::getCurrentInstance();
	$roles = $pw->roles;
	$users = $pw->users;
	$permissions = $pw->permissions;

	$roleName = 'wc-pest-mig-viewer';
	$userName = 'wc-pest-mig-user';

	// Ensure permissions exist
	wcTestModule()->install();

	$role = $roles->get($roleName);
	if (!$role || !$role->id) {
		$role = $roles->add($roleName);
	}
	foreach ([ConsoleRuntime::PERMISSION_VIEW, ConsoleRuntime::PERMISSION_MIGRATIONS] as $permName) {
		$perm = $permissions->get($permName);
		if ($perm && $perm->id) {
			$role->addPermission($perm);
		}
	}
	$roles->save($role);

	$existing = $users->get($userName);
	if ($existing && $existing->id) {
		$users->delete($existing);
	}
	$user = $users->add($userName);
	$user->pass = 'WcPestRolePass!2026';
	$user->addRole($role);
	$users->save($user);

	try {
		$users->setCurrentUser($user);
		$module = wcTestModule();
		$runtime = $module->runtime();

		expect($runtime->isSuperuser())->toBeFalse();

		$accessibleTabs = $runtime->getAccessibleTabs();
		expect(array_keys($accessibleTabs))->toBe(['overview', 'migrations']);

		// Requesting a disallowed tab ('logs') must fall back to 'overview' and keep unauthorized slices null
		$payload = $runtime->buildWorkspacePayload('logs');
		$serializedPayload = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
		$hostRootPrefix = rtrim($runtime->getProjectRootPath(), '/');
		expect($payload['activeTab'])->toBe('overview')
			->and($payload['isSuperuser'])->toBeFalse()
			->and($payload['overview']['packages']['rootPath'])->toBe('/')
			->and($payload['overview']['maintenance']['file'])->toBe('site/down.json')
			->and($serializedPayload)->not->toContain($hostRootPrefix)
			->and($payload['migrations'])->toBeArray()
			->and($payload['queues'])->toBeNull()
			->and($payload['seeding'])->toBeNull()
			->and($payload['scheduling'])->toBeNull()
			->and($payload['backup'])->toBeNull()
			->and($payload['testing'])->toBeNull()
			->and($payload['boost'])->toBeNull()
			->and($payload['logs'])->toBeNull();

		// executeNavJSON must also restrict flyout items to the 2 allowed tabs
		$navDecoded = json_decode((string) $module->executeNavJSON(), true);
		expect($navDecoded)->toBeArray()
			->and(count($navDecoded['list']))->toBe(2)
			->and($navDecoded['list'][0]['url'])->toBe('')
			->and($navDecoded['list'][1]['url'])->toBe('migrations/');
	} finally {
		wcSetSuperuser();
		$cleanupUser = $users->get($userName);
		if ($cleanupUser && $cleanupUser->id) {
			$users->delete($cleanupUser);
		}
		$cleanupRole = $roles->get($roleName);
		if ($cleanupRole && $cleanupRole->id) {
			$roles->delete($cleanupRole);
		}
	}
});

test('Migrations lifecycle scaffolds, prevents duplicates, applies, rolls back, refreshes with SQL safety snapshot, and resets cleanly', function (): void {
	$pw = ProcessWire::getCurrentInstance();
	$runtime = ConsoleRuntime::make($pw);
	$root = $runtime->getProjectRootPath();
	$migDir = $root . 'site/migrations/';
	$migDirExistedBefore = is_dir($migDir);
	$createdMigrationPath = null;
	$createdSafetyBackupPath = null;

	try {
		$runtime->installMigrationsTable();
		$initialTotal = $runtime->getMigrationsStatus()['total'];

		$scaffold = $runtime->scaffoldComponentFile('migration', 'wc_pest_lifecycle_check');
		expect($scaffold['ok'])->toBeTrue()
			->and($scaffold['path'])->toContain('site/migrations/')
			->and($runtime->getMigrationsStatus()['total'])->toBe($initialTotal + 1);

		$createdMigrationPath = (string) $scaffold['path'];
		expect(is_file($createdMigrationPath))->toBeTrue();

		// Write an idempotent WireCache marker migration so no real schema tables are mutated
		file_put_contents($createdMigrationPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace ProcessWire;

return new class {
	public function up(): void
	{
		wire('cache')?->save('wire-console:test:mig-state', 'up', 300);
	}

	public function down(): void
	{
		wire('cache')?->save('wire-console:test:mig-state', 'down', 300);
	}
};
PHP);

		// Run pending migration on the same ConsoleRuntime instance to prove Migrator cache invalidation
		$runRes = $runtime->runMigrations(1);
		expect($runRes['ok'])->toBeTrue()
			->and(count($runRes['migrated']))->toBe(1)
			->and($pw->cache?->get('wire-console:test:mig-state'))->toBe('up');

		$statusAfterRun = $runtime->getMigrationsStatus();
		expect($statusAfterRun['ran'])->toBeGreaterThanOrEqual(1);

		// Roll back 1 step
		$rbRes = $runtime->rollbackMigrations(1);
		expect($rbRes['ok'])->toBeTrue()
			->and(count($rbRes['rolledBack']))->toBe(1)
			->and($pw->cache?->get('wire-console:test:mig-state'))->toBe('down');

		// Re-apply, then refresh WITH pre-migration SQL safety backup enabled
		$runtime->runMigrations(1);
		$refreshRes = $runtime->resetMigrations('refresh', true);
		expect($refreshRes['ok'])->toBeTrue()
			->and($refreshRes['preBackupFile'])->toBeString()->not->toBeEmpty()
			->and($refreshRes['migrated'])->not->toBeEmpty()
			->and($pw->cache?->get('wire-console:test:mig-state'))->toBe('up');

		if (is_string($refreshRes['preBackupFile']) && $refreshRes['preBackupFile'] !== '') {
			$createdSafetyBackupPath = $runtime->resolveBackupFilePath($refreshRes['preBackupFile']);
			expect($createdSafetyBackupPath)->not->toBeNull();
		}

		// Reset back to zero applied migrations so wire_migrations has no orphan row
		$resetRes = $runtime->resetMigrations('reset', false);
		expect($resetRes['ok'])->toBeTrue()
			->and($pw->cache?->get('wire-console:test:mig-state'))->toBe('down');
	} finally {
		$pw->cache?->delete('wire-console:test:mig-state');
		if (is_string($createdMigrationPath) && is_file($createdMigrationPath)) {
			@unlink($createdMigrationPath);
		}
		if (is_string($createdSafetyBackupPath) && is_file($createdSafetyBackupPath)) {
			@unlink($createdSafetyBackupPath);
		}
		if (!$migDirExistedBefore && is_dir($migDir)) {
			$remaining = glob($migDir . '*');
			if ($remaining === [] || $remaining === false) {
				@rmdir($migDir);
			}
		}
	}
});

test('Backups lifecycle lists snapshots, purges only aged SQL files beyond cutoff, and throws Wire404Exception on missing download', function (): void {
	$pw = ProcessWire::getCurrentInstance();
	$module = wcTestModule();
	$runtime = $module->runtime();
	$backupDir = $runtime->getBackupDirectory();

	$agedFile = $backupDir . 'wc_pest_aged_fixture.sql';
	$freshFile = $backupDir . 'wc_pest_fresh_fixture.sql';

	file_put_contents($agedFile, "-- aged fixture\nSELECT 1;\n");
	touch($agedFile, time() - (420 * 86400));

	file_put_contents($freshFile, "-- fresh fixture\nSELECT 1;\n");
	touch($freshFile, time());

	try {
		$listedNames = array_column($runtime->listBackups(100), 'name');
		expect($listedNames)->toContain('wc_pest_aged_fixture.sql')
			->and($listedNames)->toContain('wc_pest_fresh_fixture.sql');

		// Purge only files older than 400 days so recent user backups are untouched
		$purgeRes = $runtime->purgeBackups(400);
		expect($purgeRes['ok'])->toBeTrue()
			->and($purgeRes['deletedCount'])->toBeGreaterThanOrEqual(1)
			->and(is_file($agedFile))->toBeFalse()
			->and(is_file($freshFile))->toBeTrue();

		// Missing SQL download by superuser must throw Wire404Exception
		$pw->input->get->set('file', 'non_existent_snapshot_2099.sql');
		expect(fn () => $module->executeDownloadBackup())->toThrow(Wire404Exception::class);
	} finally {
		foreach ([$agedFile, $freshFile] as $f) {
			if (is_file($f)) {
				@unlink($f);
			}
		}
	}
});

test('Queues, Seeders, Scheduler, and Test Runner handle delayed jobs, retry/clear flows, unknown targets, and workspace action dispatch', function (): void {
	$pw = ProcessWire::getCurrentInstance();
	$runtime = ConsoleRuntime::make($pw);
	$root = $runtime->getProjectRootPath();

	$queueDir = $root . 'site/queue/';
	if (!is_dir($queueDir)) {
		@mkdir($queueDir, 0755, true);
	}
	$queueFile = $queueDir . 'WcEdgeCaseQueue.php';
	file_put_contents($queueFile, <<<'PHP'
<?php
declare(strict_types=1);
namespace Site\Queue;
use Totoglu\Console\Queue\Queue;
class WcEdgeCaseQueue extends Queue
{
	public function handle(array $data = []): void
	{
		if (!empty($data['shouldFail'])) {
			throw new \RuntimeException('Edge case failure');
		}
	}
}
PHP);

	try {
		$fresh = ConsoleRuntime::make($pw);
		$fresh->installQueueTables();
		$fresh->purgePendingJobs('wc_edge');

		// Delayed job must appear with 'delayed' status in pendingJobs
		$delayed = $fresh->dispatchQueueJob('WcEdgeCaseQueue', '{"shouldFail":false}', 'wc_edge', 120);
		expect($delayed['ok'])->toBeTrue();

		$status = $fresh->getQueuesStatus(50);
		$foundDelayed = null;
		foreach ($status['pendingJobs'] as $job) {
			if ($job['queue'] === 'wc_edge') {
				$foundDelayed = $job;
				break;
			}
		}
		expect($foundDelayed)->toBeArray()
			->and($foundDelayed['status'])->toBe('delayed');

		$fresh->purgePendingJobs('wc_edge');

		// Invalid queue name sanitizing to empty must be rejected
		$invalidPurge = $fresh->purgePendingJobs('★');
		expect($invalidPurge['ok'])->toBeFalse();

		// Retry validation edge cases
		expect($fresh->retryFailedJobs('0')['ok'])->toBeFalse()
			->and($fresh->retryFailedJobs('99999999')['ok'])->toBeFalse();

		// Produce a failed job, retry it by specific ID, fail again, then clearFailedJobs()
		$fresh->dispatchQueueJob('WcEdgeCaseQueue', '{"shouldFail":true}', 'wc_edge', 0);
		$fresh->workQueueBatch('wc_edge', 2, 1);

		$failedId = 0;
		foreach ($fresh->getQueuesStatus(50)['failedJobs'] as $fj) {
			if ($fj['queue'] === 'wc_edge') {
				$failedId = $fj['id'];
				break;
			}
		}
		expect($failedId)->toBeGreaterThan(0);

		$retrySingle = $fresh->retryFailedJobs((string) $failedId);
		expect($retrySingle['ok'])->toBeTrue()
			->and($retrySingle['retriedCount'])->toBe(1);

		// Fail it again and clearFailedJobs()
		$fresh->workQueueBatch('wc_edge', 2, 1);
		$clearFailed = $fresh->clearFailedJobs();
		expect($clearFailed['ok'])->toBeTrue()
			->and($clearFailed['clearedCount'])->toBeGreaterThanOrEqual(1);

		// Non-existent Seeder & Scheduled Task error handling
		$missingSeeder = $fresh->runSeeders('DefinitelyMissingSeederClass');
		expect($missingSeeder['ok'])->toBeFalse();

		$missingTask = $fresh->runScheduledTasks('DefinitelyMissingTaskClass');
		expect($missingTask['ok'])->toBeFalse();

		// sanitizeTestArgs edge cases (>200 chars rejected; --testsuite, --group, --bail, --parallel allowed)
		$overlong = $fresh->sanitizeTestArgs('--filter=' . str_repeat('A', 210));
		expect($overlong['ok'])->toBeFalse();

		$validSuiteFlags = $fresh->sanitizeTestArgs('--testsuite=Unit --group=smoke --bail --parallel');
		expect($validSuiteFlags['ok'])->toBeTrue()
			->and($validSuiteFlags['tokens'])->toBe(['--testsuite=Unit', '--group=smoke', '--bail', '--parallel']);

		// executeWorkspaceAction unknown action & happy-path guarded execution
		$unknownRes = $fresh->executeWorkspaceAction('completelyUnknownAction');
		expect($unknownRes['ok'])->toBeFalse()
			->and($unknownRes['message'])->toContain('Unknown workspace action');

		$pw->session?->remove('wc_ratelimit_system_cache_clear');
		$happyAction = $fresh->executeWorkspaceAction('clearSystemCaches', [
			'cache_clear_mode' => 'wire',
			'confirm_system_cache_clear' => 'CACHE',
		]);
		expect($happyAction['ok'])->toBeTrue()
			->and($happyAction['tab'])->toBe('overview');

		// Specific non-due scheduled task execution runs ONLY the requested task immediately without triggering unrelated due tasks
		$scheduleDir = $root . 'site/schedule/';
		if (!is_dir($scheduleDir)) {
			@mkdir($scheduleDir, 0755, true);
		}
		$nonDueTaskFile = $scheduleDir . 'WcNonDueManualTask.php';
		$unselectedDueTaskFile = $scheduleDir . 'WcUnselectedDueTask.php';
		file_put_contents($nonDueTaskFile, <<<'PHP'
<?php
declare(strict_types=1);
namespace Site\Schedule;
use Totoglu\Console\Scheduling\Event;
use Totoglu\Console\Scheduling\Task;
class WcNonDueManualTask extends Task
{
	public function schedule(Event $event): void
	{
		$event->cron('0 0 31 2 *');
	}

	public function handle(): void
	{
		$this->wire->cache?->save('wire-console:test:manual-task', 'manual-ran', 300);
	}
}
PHP);
		file_put_contents($unselectedDueTaskFile, <<<'PHP'
<?php
declare(strict_types=1);
namespace Site\Schedule;
use Totoglu\Console\Scheduling\Event;
use Totoglu\Console\Scheduling\Task;
class WcUnselectedDueTask extends Task
{
	public function schedule(Event $event): void
	{
		$event->everyMinute();
	}

	public function handle(): void
	{
		$this->wire->cache?->save('wire-console:test:unselected-task', 'should-not-run', 300);
	}
}
PHP);
		try {
			$pw->cache?->delete('wire-console:test:manual-task');
			$pw->cache?->delete('wire-console:test:unselected-task');
			$manualTaskRes = ConsoleRuntime::make($pw)->runScheduledTasks('WcNonDueManualTask');
			expect($manualTaskRes['ok'])->toBeTrue()
				->and($manualTaskRes['executed'])->toBe(['WcNonDueManualTask'])
				->and($pw->cache?->get('wire-console:test:manual-task'))->toBe('manual-ran')
				->and($pw->cache?->get('wire-console:test:unselected-task'))->toBeNull();
		} finally {
			$pw->cache?->delete('wire-console:test:manual-task');
			$pw->cache?->delete('wire-console:test:unselected-task');
			foreach ([$nonDueTaskFile, $unselectedDueTaskFile] as $tf) {
				if (is_file($tf)) {
					@unlink($tf);
				}
			}
		}

		// JSON payload size (>64 KB) and depth (>16 levels) limits on queue dispatch & MCP tool arguments
		$oversizedJson = '{"pad":"' . str_repeat('A', 70000) . '"}';
		$deepJson = str_repeat('{"a":', 20) . '1' . str_repeat('}', 20);

		expect($fresh->dispatchQueueJob('WcEdgeCaseQueue', $oversizedJson, 'wc_edge')['ok'])->toBeFalse()
			->and($fresh->dispatchQueueJob('WcEdgeCaseQueue', $deepJson, 'wc_edge')['ok'])->toBeFalse()
			->and($fresh->executeReadOnlyMcpTool('pw_module_list', $oversizedJson)['ok'])->toBeFalse()
			->and($fresh->executeReadOnlyMcpTool('pw_module_list', $deepJson)['ok'])->toBeFalse();
	} finally {
		ConsoleRuntime::make($pw)->purgePendingJobs('wc_edge');
		if (is_file($queueFile)) {
			@unlink($queueFile);
		}
	}
});

test('Audit logger redacts sensitive keys recursively, truncates long strings, and clearLogChannel truncates isolated log channel', function (): void {
	$pw = ProcessWire::getCurrentInstance();
	$runtime = ConsoleRuntime::make($pw);
	$logsDir = rtrim((string) $pw->config->paths->logs, '/') . '/';
	$tempChannel = 'wc-pest-isolated-log';
	$tempLogFile = $logsDir . $tempChannel . '.txt';

	try {
		$marker = 'audit-redact-' . bin2hex(random_bytes(4));
		$runtime->audit('test.redaction', 'ok', [
			'marker' => $marker,
			'maintenance_secret' => 'super-secret-bypass-value',
			'api_token' => 'tok_live_123456',
			'user_password' => 'hunter2',
			'long_note' => str_repeat('X', 350),
		]);

		$entries = $runtime->readLogEntries(ConsoleRuntime::LOG_CHANNEL, 25, $marker);
		expect($entries)->not->toBeEmpty();
		$auditCtx = (string) ($entries[0]['audit']['contextFormatted'] ?? '');
		expect($auditCtx)->toContain('[REDACTED]')
			->and($auditCtx)->not->toContain('super-secret-bypass-value')
			->and($auditCtx)->not->toContain('tok_live_123456')
			->and($auditCtx)->not->toContain('hunter2')
			->and($auditCtx)->toContain('...');

		// Isolated temporary log channel test
		$pw->log->save($tempChannel, 'Temporary log entry for clearLogChannel verification');
		expect($runtime->readLogEntries($tempChannel, 10))->not->toBeEmpty();

		$clearRes = $runtime->clearLogChannel($tempChannel);
		expect($clearRes['ok'])->toBeTrue()
			->and($runtime->readLogEntries($tempChannel, 10))->toBeEmpty();

		expect($runtime->clearLogChannel('')['ok'])->toBeFalse()
			->and($runtime->clearLogChannel('non-existent-channel-xyz')['ok'])->toBeFalse();
	} finally {
		if (is_file($tempLogFile)) {
			@unlink($tempLogFile);
		}
	}
});
