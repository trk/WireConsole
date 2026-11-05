<?php

declare(strict_types=1);

use ProcessWire\ProcessWire;
use ProcessWire\WirePermissionException;

require_once __DIR__ . '/../bootstrap.php';

beforeEach(function (): void {
	wcSetSuperuser();
});

afterEach(function (): void {
	wcSetSuperuser();
});

test('sanitizeTestArgs allows safe Pest flags and rejects shell injection or path traversal', function (): void {
	$runtime = wcTestModule()->runtime();

	$valid = $runtime->sanitizeTestArgs('--filter=ModuleInfoTest --stop-on-failure site/modules/WireConsole/tests');
	expect($valid['ok'])->toBeTrue()
		->and($valid['tokens'])->toBe([
			'--filter=ModuleInfoTest',
			'--stop-on-failure',
			'site/modules/WireConsole/tests',
		]);

	$maliciousCases = [
		'--filter=Test; rm -rf /',
		'--filter=Test && whoami',
		'--filter=Test | cat /etc/passwd',
		'--filter=`id`',
		'--filter=$(id)',
		'../../etc/passwd',
		'--configuration=/etc/passwd',
		'--bootstrap=evil.php',
	];

	foreach ($maliciousCases as $payload) {
		$res = $runtime->sanitizeTestArgs($payload);
		expect($res['ok'])->toBeFalse("Payload should be rejected: {$payload}");
	}

	// Ensure PHP-FPM or PHP-CGI SAPI binary paths resolve to the CLI php binary, never php-fpm
	$resolvedCli = $runtime->resolvePhpCliBinary('/opt/homebrew/Cellar/php/8.5.12_1/sbin/php-fpm');
	expect($resolvedCli)->not->toContain('php-fpm')
		->and(basename($resolvedCli))->toBe('php');

	$runRes = $runtime->runTests('site/modules/WireConsole/tests/Feature/ModuleInfoTest.php --compact', 30);
	expect($runRes['ok'])->toBeTrue()
		->and($runRes['exitCode'])->toBe(0)
		->and($runRes['output'])->not->toContain('Usage: php-fpm');
});

test('resolveBackupFilePath rejects path traversal, null bytes, and non-sql extensions', function (): void {
	$runtime = wcTestModule()->runtime();

	$invalidFiles = [
		'',
		'../config.php',
		'../../site/config.php',
		'backup.sql/../../config.php',
		"backup.sql\0.php",
		'backup.php',
		'backup.sql.gz',
		'/etc/passwd',
	];

	foreach ($invalidFiles as $candidate) {
		expect($runtime->resolveBackupFilePath($candidate))->toBeNull();
	}
});

test('executeDownloadBackup throws WirePermissionException for non-superusers', function (): void {
	$pw = ProcessWire::getCurrentInstance();
	$module = wcTestModule();

	$guest = $pw->users->get('name=guest');
	if ($guest && $guest->id) {
		$pw->users->setCurrentUser($guest);
	}

	expect(fn () => $module->executeDownloadBackup())->toThrow(WirePermissionException::class);
});

test('ConsoleRuntime integrates directly with upstream Migrator, QueueManager, and FeatureDiscoverer', function (): void {
	$pw = ProcessWire::getCurrentInstance();
	$admin = $pw->users->get('name=admin') ?: $pw->users->get(41);
	if ($admin && $admin->id) {
		$pw->users->setCurrentUser($admin);
	}

	$runtime = wcTestModule()->runtime();
	$packages = $runtime->getPackagesStatus();
	$migrations = $runtime->getMigrationsStatus();
	$queues = $runtime->getQueuesStatus();
	$telemetry = $runtime->getOverviewTelemetry();

	expect($packages['console']['installed'])->toBeTrue()
		->and($packages['boost']['installed'])->toBeTrue()
		->and($migrations['available'])->toBeTrue()
		->and($queues['available'])->toBeTrue()
		->and($queues)->toHaveKey('pendingJobs')
		->and($telemetry)->toHaveKeys([
			'packages',
			'versions',
			'migrations',
			'queues',
			'seeding',
			'scheduling',
			'backup',
			'testing',
			'boost',
			'maintenance',
		]);

	$versions = $runtime->getVersionMatrix();
	expect($versions)->toHaveKeys(['php', 'processwire', 'database', 'htmx', 'wirekit', 'console', 'boost'])
		->and($versions['php'])->toContain(PHP_MAJOR_VERSION . '.')
		->and($versions['htmx'])->toContain('.')
		->and($versions['wirekit'])->toContain('.');

	$installMig = $runtime->installMigrationsTable();
	expect($installMig['ok'])->toBeTrue()
		->and($runtime->migrationsTableExists())->toBeTrue();

	$installQueue = $runtime->installQueueTables();
	expect($installQueue['ok'])->toBeTrue()
		->and($runtime->queueTablesExist())->toBeTrue();

	$purgeRes = $runtime->purgePendingJobs('all');
	expect($purgeRes['ok'])->toBeTrue()
		->and($purgeRes['purgedCount'])->toBeInt();
});

test('ConsoleRuntime integrates with BoostManager and discovers Boost-aware modules', function (): void {
	$runtime = wcTestModule()->runtime();
	$boost = $runtime->getBoostStatus();

	expect($boost['available'])->toBeTrue()
		->and($boost['discoverableModules'])->toBeArray()
		->and($boost['availableAgents'])->toBeArray()
		->and(count($boost['availableAgents']))->toBeGreaterThan(5);

	$moduleNames = array_column($boost['discoverableModules'], 'name');
	expect($moduleNames)->toContain('Htmx')
		->and($moduleNames)->not->toContain('WireConsole');

	$htmxMeta = null;
	foreach ($boost['discoverableModules'] as $mod) {
		if ($mod['name'] === 'Htmx') {
			$htmxMeta = $mod;
			break;
		}
	}
	expect($htmxMeta)->toBeArray()
		->and($htmxMeta['version'])->toContain('.')
		->and($htmxMeta['hasGuidelines'])->toBeTrue()
		->and($htmxMeta['hasSkills'])->toBeTrue();

	$mcpDiag = $runtime->getMcpServerDiagnostics();
	expect($mcpDiag['available'])->toBeTrue()
		->and($mcpDiag['protocolVersion'])->toBe('2024-11-05')
		->and($mcpDiag['toolCount'])->toBe(28)
		->and($mcpDiag['readOnlyCount'])->toBe(8)
		->and($mcpDiag['mutationCount'])->toBe(20);

	$schemaMcp = $runtime->executeReadOnlyMcpTool('pw_schema_read');
	expect($schemaMcp['ok'])->toBeTrue()
		->and($schemaMcp['isError'])->toBeFalse()
		->and($schemaMcp['outputFormatted'])->toContain('templates');

	$queryMcp = $runtime->executeReadOnlyMcpTool('pw_query', '{"selector": "id=1, limit=1"}');
	expect($queryMcp['ok'])->toBeTrue()
		->and($queryMcp['outputFormatted'])->toContain('"id": 1');

	$blockedExec = $runtime->executeReadOnlyMcpTool('pw_execute', '{"code": "return 1;"}');
	expect($blockedExec['ok'])->toBeFalse()
		->and($blockedExec['isError'])->toBeTrue();

	$blockedMut = $runtime->executeReadOnlyMcpTool('pw_module_install', '{"name": "Evil"}');
	expect($blockedMut['ok'])->toBeFalse();

	$badJsonMcp = $runtime->executeReadOnlyMcpTool('pw_query', '{invalid-json');
	expect($badJsonMcp['ok'])->toBeFalse();

	// Invalid remote repo input is safely rejected before any network call
	$badRemote = $runtime->installRemoteBoostSkills('https://evil.example.com/repo');
	expect($badRemote['ok'])->toBeFalse();
});

test('ConsoleRuntime validates scaffold identifiers and blocks traversal or malformed names', function (): void {
	$runtime = wcTestModule()->runtime();

	expect($runtime->isValidIdentifier('create_blog_fields'))->toBeTrue()
		->and($runtime->isValidIdentifier('SendWelcomeEmail'))->toBeTrue()
		->and($runtime->isValidIdentifier(''))->toBeFalse()
		->and($runtime->isValidIdentifier('../evil'))->toBeFalse()
		->and($runtime->isValidIdentifier('123InvalidStart'))->toBeFalse()
		->and($runtime->isValidIdentifier('evil;rm -rf'))->toBeFalse();

	$badMig = $runtime->scaffoldComponentFile('migration', '../evil');
	expect($badMig['ok'])->toBeFalse();

	$badQueue = $runtime->scaffoldComponentFile('queue', '../evil');
	expect($badQueue['ok'])->toBeFalse();

	$badModule = $runtime->scaffoldComponentFile('module', '../evil');
	expect($badModule['ok'])->toBeFalse();

	// Verify scaffolding a valid queue file and discovering it via getQueuesStatus()
	$queueFullPath = $runtime->getProjectRootPath() . 'site/queue/RegressionCheckQueue.php';
	if (is_file($queueFullPath)) {
		@unlink($queueFullPath);
	}
	try {
		$scaffoldedQueue = $runtime->scaffoldComponentFile('queue', 'RegressionCheckQueue');
		expect($scaffoldedQueue['ok'])->toBeTrue()
			->and($scaffoldedQueue['file'])->toBe('RegressionCheckQueue.php');

		$freshRuntime = \Totoglu\WireConsole\ConsoleRuntime::make(\ProcessWire\ProcessWire::getCurrentInstance());
		$queueStatus = $freshRuntime->getQueuesStatus();
		$discoveredQueueNames = array_column($queueStatus['discoveredQueues'], 'name');
		expect($discoveredQueueNames)->toContain('RegressionCheckQueue');

		$queuePreview = $freshRuntime->inspectProjectFile('queue', 'RegressionCheckQueue');
		expect($queuePreview)->toBeArray()
			->and($queuePreview['relativePath'])->toBe('site/queue/RegressionCheckQueue.php');
	} finally {
		if (is_file($queueFullPath)) {
			@unlink($queueFullPath);
		}
	}
});

test('ConsoleRuntime inspectProjectFile reads allowlisted skills and seeders and blocks path traversal', function (): void {
	$runtime = wcTestModule()->runtime();

	expect($runtime->inspectProjectFile('skill', '../config'))->toBeNull()
		->and($runtime->inspectProjectFile('migration', '../../site/config.php'))->toBeNull()
		->and($runtime->inspectProjectFile('backup', '../config.php'))->toBeNull()
		->and($runtime->inspectProjectFile('unknown', 'pw-htmx'))->toBeNull();

	$skillPreview = $runtime->inspectProjectFile('skill', 'pw-htmx');
	expect($skillPreview)->toBeArray()
		->and($skillPreview['relativePath'])->toContain('.agents/skills/pw-htmx/SKILL.md')
		->and($skillPreview['content'])->toContain('pw-htmx');

	$boostDocPreview = $runtime->inspectProjectFile('boost-doc', 'agents-md');
	expect($boostDocPreview)->toBeArray()
		->and($boostDocPreview['relativePath'])->toBe('AGENTS.md');

	$discoveredTests = $runtime->getDiscoveredTestFiles();
	expect($discoveredTests)->not->toBeEmpty();
	$testPreview = $runtime->inspectProjectFile('test', 'ActionGuardsTest');
	expect($testPreview)->toBeArray()
		->and($testPreview['relativePath'])->toContain('ActionGuardsTest.php');

	// Verify queue batch worker and job dispatcher validation
	$badDispatchJson = $runtime->dispatchQueueJob('NonExistentQueue', '{invalid json');
	expect($badDispatchJson['ok'])->toBeFalse();

	$badDispatchClass = $runtime->dispatchQueueJob('NonExistentQueue', '{"ok": true}');
	expect($badDispatchClass['ok'])->toBeFalse();

	$emptyWork = $runtime->workQueueBatch('default', 3, 2);
	expect($emptyWork['ok'])->toBeTrue()
		->and($emptyWork['processed'])->toBe(0);
});

test('ConsoleRuntime manages maintenance mode (down.json) and audit log channels safely', function (): void {
	$runtime = wcTestModule()->runtime();

	try {
		$down = $runtime->enableMaintenanceMode('secret-token-123', 120);
		expect($down['ok'])->toBeTrue()
			->and($runtime->getMaintenanceStatus()['isDown'])->toBeTrue()
			->and($runtime->getMaintenanceStatus()['hasSecret'])->toBeTrue()
			->and($runtime->getMaintenanceStatus()['retry'])->toBe(120);
	} finally {
		$up = $runtime->disableMaintenanceMode();
		expect($up['ok'])->toBeTrue()
			->and($runtime->getMaintenanceStatus()['isDown'])->toBeFalse();
	}

	$runtime->audit('pest_test_event', 'ok', ['detail' => 'verified']);
	$channels = $runtime->getLogChannels();
	$channelNames = array_column($channels, 'name');
	expect($channelNames)->toContain('wire-console-audit');

	$entries = $runtime->readLogEntries('wire-console-audit', 10);
	expect($entries)->toBeArray()
		->and(count($entries))->toBeGreaterThan(0);

	$filteredEntries = $runtime->readLogEntries('wire-console-audit', 10, 'pest_test_event');
	expect($filteredEntries)->not->toBeEmpty()
		->and($filteredEntries[0]['audit'])->toBeArray()
		->and($filteredEntries[0]['audit']['action'])->toBe('pest_test_event')
		->and($filteredEntries[0]['audit']['status'])->toBe('ok')
		->and($filteredEntries[0]['audit']['contextFormatted'])->toContain('verified');

	$unmatchedEntries = $runtime->readLogEntries('wire-console-audit', 10, 'non_existent_token_' . uniqid());
	expect($unmatchedEntries)->toBeEmpty();

	$wireCacheOnly = $runtime->clearSystemCaches('wire');
	expect($wireCacheOnly['ok'])->toBeTrue()
		->and($wireCacheOnly['cleared'])->toBe(['WireCache']);

	$modulesRefreshOnly = $runtime->clearSystemCaches('modules');
	expect($modulesRefreshOnly['ok'])->toBeTrue()
		->and($modulesRefreshOnly['cleared'])->toBe(['ModulesRegistry']);

	$schedRun = $runtime->runScheduledTasks('due');
	expect($schedRun)->toHaveKey('details');
});

test('a role with only wire-console gets a refusal when inspecting a backup, test, skill or boost-doc it lacks the tab permission for', function (): void {
	$pw = ProcessWire::getCurrentInstance();
	$adminRuntime = wcTestModule()->runtime();
	$backupDir = $adminRuntime->getBackupDirectory();
	if (!is_dir($backupDir)) {
		@mkdir($backupDir, 0755, true);
	}
	$backupFile = 'wc_inspect_guard_' . uniqid() . '.sql';
	$backupFullPath = $backupDir . $backupFile;
	file_put_contents($backupFullPath, "-- SECRET SQL BACKUP CONTENT\nSELECT 1;\n");

	$createdPermissions = [];
	foreach ([
		\Totoglu\WireConsole\ConsoleRuntime::PERMISSION_VIEW,
		\Totoglu\WireConsole\ConsoleRuntime::PERMISSION_BACKUP,
	] as $permName) {
		$perm = $pw->permissions->get('name=' . $permName);
		if (!$perm || !$perm->id) {
			$perm = $pw->permissions->add($permName);
			$createdPermissions[] = $perm;
		}
	}

	$roleName = 'wc-only-role-' . uniqid();
	$userName = 'wc-only-user-' . uniqid();
	$role = $pw->roles->add($roleName);
	$role->of(false);
	$viewPerm = $pw->permissions->get('name=page-view');
	if ($viewPerm && $viewPerm->id) {
		$role->addPermission($viewPerm);
	}
	$role->addPermission(\Totoglu\WireConsole\ConsoleRuntime::PERMISSION_VIEW);
	$pw->roles->save($role);

	$user = $pw->users->add($userName);
	$user->of(false);
	$user->pass = 'TempPass123!@#';
	$user->addRole($role);
	$pw->users->save($user);

	try {
		$pw->users->setCurrentUser($user);
		expect($user->isSuperuser())->toBeFalse()
			->and($user->hasPermission(\Totoglu\WireConsole\ConsoleRuntime::PERMISSION_VIEW))->toBeTrue()
			->and($user->hasPermission(\Totoglu\WireConsole\ConsoleRuntime::PERMISSION_TESTING))->toBeFalse()
			->and($user->hasPermission(\Totoglu\WireConsole\ConsoleRuntime::PERMISSION_BOOST))->toBeFalse()
			->and($user->hasPermission(\Totoglu\WireConsole\ConsoleRuntime::PERMISSION_BACKUP))->toBeFalse();

		$restrictedRuntime = \Totoglu\WireConsole\ConsoleRuntime::make($pw);
		$targets = [
			'backup' => $backupFile,
			'test' => 'ActionGuardsTest',
			'skill' => 'pw-htmx',
			'boost-doc' => 'agents-md',
		];

		foreach ($targets as $category => $identifier) {
			expect($restrictedRuntime->userCanInspectCategory($category))->toBeFalse("Expected userCanInspectCategory('{$category}') to be false")
				->and($restrictedRuntime->inspectProjectFile($category, $identifier))->toBeNull("Expected inspectProjectFile('{$category}', '{$identifier}') to return null");

			// Action-time refusal via queryInspectFile
			$workspace = new \Htmx\Component\ConsoleWorkspace();
			$workspace->setWire($pw);
			$workspace->mount('overview');
			$workspace->queryInspectFile($category, $identifier);
			$html = $workspace->render();

			expect($workspace->inspectedCategory)->toBe('')
				->and($workspace->inspectedIdentifier)->toBe('')
				->and($html)->toContain('Permission Denied')
				->and($html)->not->toContain('Read-only source preview')
				->and($html)->not->toContain('SECRET SQL BACKUP CONTENT');

			// Render-time refusal when state carries an unauthorized inspected category
			$hydratedWorkspace = new \Htmx\Component\ConsoleWorkspace();
			$hydratedWorkspace->setWire($pw);
			$hydratedWorkspace->fill([
				'activeTab' => 'overview',
				'inspectedCategory' => $category,
				'inspectedIdentifier' => $identifier,
			]);
			$renderedHtml = $hydratedWorkspace->render();

			expect($hydratedWorkspace->inspectedCategory)->toBe('')
				->and($hydratedWorkspace->inspectedIdentifier)->toBe('')
				->and($renderedHtml)->toContain('Permission Denied')
				->and($renderedHtml)->not->toContain('Read-only source preview')
				->and($renderedHtml)->not->toContain('SECRET SQL BACKUP CONTENT');
		}

		// Even with wire-console-backup permission, a non-superuser cannot inspect backups (matches download-backup)
		wcSetSuperuser();
		$role->of(false);
		$role->addPermission(\Totoglu\WireConsole\ConsoleRuntime::PERMISSION_BACKUP);
		$pw->roles->save($role);
		$pw->users->setCurrentUser($user);

		$backupViewerRuntime = \Totoglu\WireConsole\ConsoleRuntime::make($pw);
		expect($backupViewerRuntime->userCanView(\Totoglu\WireConsole\ConsoleRuntime::PERMISSION_BACKUP))->toBeTrue()
			->and($backupViewerRuntime->userCanInspectCategory('backup'))->toBeFalse()
			->and($backupViewerRuntime->inspectProjectFile('backup', $backupFile))->toBeNull();
	} finally {
		wcSetSuperuser();
		if (is_file($backupFullPath)) {
			@unlink($backupFullPath);
		}
		if ($user && $user->id) {
			$pw->users->delete($user);
		}
		if ($role && $role->id) {
			$pw->roles->delete($role);
		}
		foreach ($createdPermissions as $perm) {
			if ($perm && $perm->id) {
				$pw->permissions->delete($perm);
			}
		}
	}
});

test('superuser with backup permission can still inspect', function (): void {
	wcSetSuperuser();
	$pw = ProcessWire::getCurrentInstance();
	$runtime = wcTestModule()->runtime();

	$backupDir = $runtime->getBackupDirectory();
	if (!is_dir($backupDir)) {
		@mkdir($backupDir, 0755, true);
	}
	$backupFile = 'wc_superuser_inspect_' . uniqid() . '.sql';
	$backupFullPath = $backupDir . $backupFile;
	file_put_contents($backupFullPath, "-- SUPERUSER SQL SNAPSHOT\nSELECT 42;\n");

	try {
		expect($runtime->userCanInspectCategory('backup'))->toBeTrue()
			->and($runtime->userCanInspectCategory('test'))->toBeTrue()
			->and($runtime->userCanInspectCategory('skill'))->toBeTrue()
			->and($runtime->userCanInspectCategory('boost-doc'))->toBeTrue();

		$preview = $runtime->inspectProjectFile('backup', $backupFile);
		expect($preview)->toBeArray()
			->and($preview['content'])->toContain('SUPERUSER SQL SNAPSHOT');

		$workspace = new \Htmx\Component\ConsoleWorkspace();
		$workspace->setWire($pw);
		$workspace->mount('backup');
		$workspace->queryInspectFile('backup', $backupFile);
		$html = $workspace->render();

		expect($workspace->inspectedCategory)->toBe('backup')
			->and($workspace->inspectedIdentifier)->toBe($backupFile)
			->and($html)->toContain('Read-only source preview')
			->and($html)->toContain('SUPERUSER SQL SNAPSHOT')
			->and($html)->not->toContain('Permission Denied');
	} finally {
		if (is_file($backupFullPath)) {
			@unlink($backupFullPath);
		}
	}
});

test('queue work, seeding, scheduling, maintenance mode, and cache clearing delegate to trk/processwire-console and ProcessWire APIs without duplicated loops or raw SQL', function (): void {
	wcSetSuperuser();
	$pw = ProcessWire::getCurrentInstance();
	$runtime = wcTestModule()->runtime();
	$root = $runtime->getProjectRootPath();

	// 1. Source verification: no duplicated worker/seeder/scheduler loops or raw SQL on caches/queue tables
	$queuesSource = (string) file_get_contents($root . 'site/modules/WireConsole/src/Concerns/InteractsWithQueues.php');
	$seedSchedSource = (string) file_get_contents($root . 'site/modules/WireConsole/src/Concerns/InteractsWithSeedingAndScheduling.php');
	$sysLogsSource = (string) file_get_contents($root . 'site/modules/WireConsole/src/Concerns/InteractsWithSystemAndLogs.php');

	expect($queuesSource)->toContain('QueueWorkCommand')
		->and($queuesSource)->not->toContain('->handle(')
		->and($queuesSource)->not->toContain('->markFailed(')
		->and($queuesSource)->not->toContain('->releaseJob(')
		->and($queuesSource)->not->toContain('DELETE FROM')
		->and($queuesSource)->not->toContain('SELECT ')
		->and($queuesSource)->not->toContain('SHOW TABLES');

	expect($seedSchedSource)->toContain('DbSeedCommand')
		->and($seedSchedSource)->toContain('ScheduleRunCommand')
		->and($seedSchedSource)->not->toContain('->run()')
		->and($seedSchedSource)->not->toContain('->handle()');

	expect($sysLogsSource)->toContain('DownCommand')
		->and($sysLogsSource)->toContain('UpCommand')
		->and($sysLogsSource)->not->toContain('DELETE FROM')
		->and($sysLogsSource)->not->toContain('`caches`');

	// 2. Behaviour test: queue job processed via QueueWorkCommand delegation + QueueManager maintenance APIs
	$queueDir = $root . 'site/queue/';
	if (!is_dir($queueDir)) {
		@mkdir($queueDir, 0755, true);
	}
	$queueFile = $queueDir . 'WcDelegationVerifyQueue.php';
	$failQueueFile = $queueDir . 'WcDelegationFailQueue.php';

	file_put_contents($queueFile, <<<'PHP'
<?php
declare(strict_types=1);
namespace Site\Queue;
use Totoglu\Console\Queue\Queue;
class WcDelegationVerifyQueue extends Queue
{
	public function handle(array $data = []): void
	{
		\ProcessWire\wire('cache')?->save('wire-console:test:queue-marker', (string) ($data['marker'] ?? 'missing'), 300);
	}
}
PHP);

	file_put_contents($failQueueFile, <<<'PHP'
<?php
declare(strict_types=1);
namespace Site\Queue;
use Totoglu\Console\Queue\Queue;
class WcDelegationFailQueue extends Queue
{
	public function handle(array $data = []): void
	{
		throw new \RuntimeException('Intentional delegated queue failure');
	}
}
PHP);

	// 3. Behaviour test fixtures: seeder & scheduled task
	$seederDir = $root . 'site/seeders/';
	if (!is_dir($seederDir)) {
		@mkdir($seederDir, 0755, true);
	}
	$seederFile = $seederDir . 'WcDelegationVerifySeeder.php';
	file_put_contents($seederFile, <<<'PHP'
<?php
declare(strict_types=1);
namespace Site\Seeders;
use Totoglu\Console\Database\Seeder;
class WcDelegationVerifySeeder extends Seeder
{
	public function run(): void
	{
		$this->wire->cache?->save('wire-console:test:seeder-marker', 'seeded-via-db-seed-command', 300);
	}
}
PHP);

	$scheduleDir = $root . 'site/schedule/';
	if (!is_dir($scheduleDir)) {
		@mkdir($scheduleDir, 0755, true);
	}
	$taskFile = $scheduleDir . 'WcDelegationVerifyTask.php';
	file_put_contents($taskFile, <<<'PHP'
<?php
declare(strict_types=1);
namespace Site\Schedule;
use Totoglu\Console\Scheduling\Event;
use Totoglu\Console\Scheduling\Task;
class WcDelegationVerifyTask extends Task
{
	public function schedule(Event $event): void
	{
		$event->everyMinute();
	}

	public function handle(): void
	{
		$this->wire->cache?->save('wire-console:test:task-marker', 'ran-via-schedule-run-command', 300);
	}
}
PHP);

	try {
		$freshRuntime = \Totoglu\WireConsole\ConsoleRuntime::make($pw);
		$freshRuntime->installQueueTables();
		$freshRuntime->purgePendingJobs('wc_delegated');
		$pw->cache?->delete('wire-console:test:queue-marker');
		$pw->cache?->delete('wire-console:test:seeder-marker');
		$pw->cache?->delete('wire-console:test:task-marker');

		// Dispatch and process queue job via delegated QueueWorkCommand
		$dispatchRes = $freshRuntime->dispatchQueueJob('WcDelegationVerifyQueue', '{"marker":"delegated-queue-ok"}', 'wc_delegated');
		expect($dispatchRes['ok'])->toBeTrue()
			->and($dispatchRes['jobId'])->toBeGreaterThan(0);

		$workRes = $freshRuntime->workQueueBatch('wc_delegated', 3, 1);
		expect($workRes['ok'])->toBeTrue()
			->and($workRes['processed'])->toBe(1)
			->and($workRes['failed'])->toBe(0)
			->and($pw->cache?->get('wire-console:test:queue-marker'))->toBe('delegated-queue-ok');

		// Purge pending queue jobs via QueueManager API
		$freshRuntime->dispatchQueueJob('WcDelegationVerifyQueue', '{"marker":"to-be-purged"}', 'wc_delegated');
		$purgeRes = $freshRuntime->purgePendingJobs('wc_delegated');
		expect($purgeRes['ok'])->toBeTrue()
			->and($purgeRes['purgedCount'])->toBe(1);

		// Failing job + deleteFailedJob via QueueManager API
		$freshRuntime->dispatchQueueJob('WcDelegationFailQueue', '{}', 'wc_delegated');
		$failWorkRes = $freshRuntime->workQueueBatch('wc_delegated', 2, 1);
		expect($failWorkRes['ok'])->toBeFalse()
			->and($failWorkRes['failed'])->toBeGreaterThanOrEqual(1);

		$failedJobs = $freshRuntime->getQueuesStatus(50)['failedJobs'];
		$targetFailedId = 0;
		foreach ($failedJobs as $fj) {
			if ($fj['queue'] === 'wc_delegated') {
				$targetFailedId = $fj['id'];
				break;
			}
		}
		expect($targetFailedId)->toBeGreaterThan(0);

		$delFailedRes = $freshRuntime->deleteFailedJob($targetFailedId);
		expect($delFailedRes['ok'])->toBeTrue()
			->and($delFailedRes['deleted'])->toBeTrue();

		// Run seeder via delegated DbSeedCommand
		$seedRes = $freshRuntime->runSeeders('WcDelegationVerifySeeder');
		expect($seedRes['ok'])->toBeTrue()
			->and($seedRes['executed'])->toContain('WcDelegationVerifySeeder')
			->and($pw->cache?->get('wire-console:test:seeder-marker'))->toBe('seeded-via-db-seed-command');

		// Run scheduled task via delegated ScheduleRunCommand
		$taskRes = $freshRuntime->runScheduledTasks('due');
		expect($taskRes['ok'])->toBeTrue()
			->and($taskRes['executed'])->toContain('WcDelegationVerifyTask')
			->and($pw->cache?->get('wire-console:test:task-marker'))->toBe('ran-via-schedule-run-command');

		// Clear WireCache via $cache API (no raw SQL)
		$pw->cache?->save('wire-console:test:cache-canary', 'alive', 300);
		expect($pw->cache?->get('wire-console:test:cache-canary'))->toBe('alive');
		$cacheClearRes = $freshRuntime->clearSystemCaches('wire');
		expect($cacheClearRes['ok'])->toBeTrue()
			->and($cacheClearRes['cleared'])->toBe(['WireCache'])
			->and($pw->cache?->get('wire-console:test:cache-canary'))->toBeNull();
	} finally {
		\Totoglu\WireConsole\ConsoleRuntime::make($pw)->purgePendingJobs('wc_delegated');
		foreach ([$queueFile, $failQueueFile, $seederFile, $taskFile] as $tempFile) {
			if (is_file($tempFile)) {
				@unlink($tempFile);
			}
		}
		$pw->cache?->delete('wire-console:test:queue-marker');
		$pw->cache?->delete('wire-console:test:seeder-marker');
		$pw->cache?->delete('wire-console:test:task-marker');
		$pw->cache?->delete('wire-console:test:cache-canary');
	}
});


