<?php // __(file-not-translatable)

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
	expect($versions)->toHaveKeys(['php', 'processwire', 'database', 'inertia', 'workspace', 'console', 'boost'])
		->and($versions['php'])->toContain(PHP_MAJOR_VERSION . '.')
		->and($versions['inertia'])->toContain('.')
		->and($versions['workspace'])->toContain('.');

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
	expect($moduleNames)->toContain('Inertia')
		->and($moduleNames)->toContain('WireConsole');

	$inertiaMeta = null;
	foreach ($boost['discoverableModules'] as $mod) {
		if ($mod['name'] === 'Inertia') {
			$inertiaMeta = $mod;
			break;
		}
	}
	expect($inertiaMeta)->toBeArray()
		->and($inertiaMeta['version'])->toContain('.')
		->and($inertiaMeta['hasGuidelines'])->toBeTrue()
		->and($inertiaMeta['hasSkills'])->toBeTrue();

	$mcpDiag = $runtime->getMcpServerDiagnostics();
	expect($mcpDiag['available'])->toBeTrue()
		->and($mcpDiag['protocolVersion'])->toBe('2024-11-05')
		->and($mcpDiag['allowExecute'])->toBeBool()
		->and($mcpDiag['allowRestore'])->toBeBool()
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
		->and($runtime->inspectProjectFile('unknown', 'pw-inertia'))->toBeNull();

	$skillPreview = $runtime->inspectProjectFile('skill', 'pw-inertia');
	expect($skillPreview)->toBeArray()
		->and($skillPreview['relativePath'])->toContain('.agents/skills/pw-inertia/SKILL.md')
		->and($skillPreview['content'])->toContain('pw-inertia');

	$boostDocPreview = $runtime->inspectProjectFile('boost-doc', 'agents-md');
	expect($boostDocPreview)->toBeArray()
		->and($boostDocPreview['relativePath'])->toBe('AGENTS.md');

	$discoveredTests = $runtime->getDiscoveredTestFiles();
	expect($discoveredTests)->not->toBeEmpty();
	$runners = array_unique(array_column($discoveredTests, 'runner'));
	expect($runners)->toContain('pest')
		->and($runners)->toContain('playwright');

	$testPreview = $runtime->inspectProjectFile('test', 'ActionGuardsTest');
	expect($testPreview)->toBeArray()
		->and($testPreview['relativePath'])->toContain('ActionGuardsTest.php');

	$e2ePreview = $runtime->inspectProjectFile('test', 'console-workspace.spec.ts');
	expect($e2ePreview)->toBeArray()
		->and($e2ePreview['relativePath'])->toBe('site/modules/WireConsole/tests/e2e/console-workspace.spec.ts')
		->and($e2ePreview['content'])->toContain('@playwright/test');

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
	$createdPermissions = [];
	$role = null;
	$user = null;

	try {
		file_put_contents($backupFullPath, "-- SECRET SQL BACKUP CONTENT\nSELECT 1;\n");

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
			'skill' => 'pw-inertia',
			'boost-doc' => 'agents-md',
		];

		foreach ($targets as $category => $identifier) {
			expect($restrictedRuntime->userCanInspectCategory($category))->toBeFalse("Expected userCanInspectCategory('{$category}') to be false")
				->and($restrictedRuntime->inspectProjectFile($category, $identifier))->toBeNull("Expected inspectProjectFile('{$category}', '{$identifier}') to return null");

			// Action-time refusal via executeWorkspaceAction('inspectFile')
			$actionRes = $restrictedRuntime->executeWorkspaceAction('inspectFile', [
				'inspect_category' => $category,
				'inspect_file' => $identifier,
			]);
			expect($actionRes['ok'])->toBeFalse()
				->and($actionRes['message'])->toContain('Access denied');

			// Payload-time refusal when options carry an unauthorized inspected category
			$payload = $restrictedRuntime->buildWorkspacePayload('overview', [
				'inspectCategory' => $category,
				'inspectId' => $identifier,
			]);
			expect($payload['inspectedFile'])->toBeNull()
				->and($payload['backup'])->toBeNull()
				->and($payload['overview']['backup']['totalCount'])->toBe(0)
				->and($payload['overview']['backup']['latestFile'])->toBeNull()
				->and($payload['overview']['backup']['latestTime'])->toBeNull();
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

		$actionRes = $runtime->executeWorkspaceAction('inspectFile', [
			'inspect_category' => 'backup',
			'inspect_file' => $backupFile,
		]);
		expect($actionRes['ok'])->toBeTrue()
			->and($actionRes['data']['content'])->toContain('SUPERUSER SQL SNAPSHOT');
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
	$runtimeSource = (string) file_get_contents($root . 'site/modules/WireConsole/src/ConsoleRuntime.php');
	$queuesSource = (string) file_get_contents($root . 'site/modules/WireConsole/src/Concerns/InteractsWithQueues.php');
	$seedSchedSource = (string) file_get_contents($root . 'site/modules/WireConsole/src/Concerns/InteractsWithSeedingAndScheduling.php');
	$sysLogsSource = (string) file_get_contents($root . 'site/modules/WireConsole/src/Concerns/InteractsWithSystemAndLogs.php');

	expect($runtimeSource)->toContain('discoverVerifiedClasses(')
		->and($runtimeSource)->toContain('ReflectionClass');

	expect($queuesSource)->toContain('QueueWorkCommand')
		->and($queuesSource)->toContain('discoverVerifiedClasses(')
		->and($queuesSource)->toContain('escapeTable(')
		->and($queuesSource)->toContain('->prepare(')
		->and($queuesSource)->toContain(':limit')
		->and($queuesSource)->toContain(':id')
		->and($queuesSource)->toContain(':queue')
		->and($queuesSource)->not->toContain('ReflectionObject')
		->and($queuesSource)->not->toContain('->handle(')
		->and($queuesSource)->not->toContain('->markFailed(')
		->and($queuesSource)->not->toContain('->releaseJob(')
		->and($queuesSource)->not->toContain('SHOW TABLES');

	expect($seedSchedSource)->toContain('DbSeedCommand')
		->and($seedSchedSource)->toContain('ScheduleRunCommand')
		->and($seedSchedSource)->toContain('discoverVerifiedClasses(')
		->and($seedSchedSource)->not->toContain('->run()')
		->and($seedSchedSource)->not->toContain('->handle()');

	expect($sysLogsSource)->toContain('DownCommand')
		->and($sysLogsSource)->toContain('UpCommand')
		->and($sysLogsSource)->toContain('->getLogs(true)')
		->and($sysLogsSource)->not->toContain('->getFiles(')
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

test('non-scalar array payloads, JSON list arguments, and oversized JSON element counts are rejected cleanly without PHP warnings', function (): void {
	wcSetSuperuser();
	$pw = ProcessWire::getCurrentInstance();
	$module = wcTestModule();
	$runtime = $module->runtime();

	$warnings = [];
	set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
		$warnings[] = [$errno, $errstr];
		return true;
	});

	try {
		// 1. Array-shaped confirmation and parameter payloads in executeWorkspaceAction
		$res = $runtime->executeWorkspaceAction('runMigrations', [
			'confirm_migrations_run' => ['RUN'],
			'migration_steps' => ['bad'],
		]);
		expect($res['ok'])->toBeFalse()
			->and($res['message'])->toContain('Confirmation failed');

		// 2. Array-shaped POST parameters through ProcessWireConsole::executeAction
		$pw->input->post->removeAll();
		$pw->input->post->set('tab', ['migrations']);
		$pw->input->post->set('action', ['runMigrations']);
		$pw->input->post->set('_token', ['invalid']);
		$html = $module->executeAction();
		expect($html)->toBeString()->toContain('WireConsole/Workspace');

		// 3. Array-shaped GET query parameter on executeDownloadBackup throws clean 404 without warning
		$pw->input->get->set('file', ['evil.sql']);
		expect(fn () => $module->executeDownloadBackup())->toThrow(\ProcessWire\Wire404Exception::class);

		// 4. JSON list instead of JSON object in executeReadOnlyMcpTool
		$mcpListRes = $runtime->executeReadOnlyMcpTool('pw_query', '[1, 2, 3]');
		expect($mcpListRes['ok'])->toBeFalse()
			->and($mcpListRes['isError'])->toBeTrue();

		// 5. JSON payload exceeding ConsoleRuntime::MAX_JSON_ELEMENTS (512)
		$hugeObject = [];
		for ($i = 0; $i < 600; $i++) {
			$hugeObject['k' . $i] = $i;
		}
		$hugeJson = (string) json_encode($hugeObject);
		$queueHugeRes = $runtime->dispatchQueueJob('AnyQueue', $hugeJson);
		expect($queueHugeRes['ok'])->toBeFalse()
			->and($queueHugeRes['jobId'])->toBe(0);

		$mcpHugeRes = $runtime->executeReadOnlyMcpTool('pw_query', $hugeJson);
		expect($mcpHugeRes['ok'])->toBeFalse()
			->and($mcpHugeRes['isError'])->toBeTrue();

		expect($warnings)->toBe([]);
	} finally {
		restore_error_handler();
		$pw->input->post->removeAll();
		$pw->input->get->removeAll();
	}
});

test('Playwright E2E sanitizer, TestE2eCommand --init, Make*Command scaffolding delegation, and Boost applyConfiguration work end-to-end', function (): void {
	wcSetSuperuser();
	$runtime = wcTestModule()->runtime();
	$root = $runtime->getProjectRootPath();

	// 1. Source verification: InteractsWithScaffolding delegates to Make*Command without local stub duplication,
	//    InteractsWithBackups & InteractsWithSystemAndLogs delegate to BackupPurgeCommand / LogClear*Command,
	//    and InteractsWithBoost delegates to BoostManager::applyConfiguration & SkillWriter::sanitizeSkillName
	$scaffoldSource = (string) file_get_contents($root . 'site/modules/WireConsole/src/Concerns/InteractsWithScaffolding.php');
	$backupsSource = (string) file_get_contents($root . 'site/modules/WireConsole/src/Concerns/InteractsWithBackups.php');
	$sysLogsSource = (string) file_get_contents($root . 'site/modules/WireConsole/src/Concerns/InteractsWithSystemAndLogs.php');
	$boostSource = (string) file_get_contents($root . 'site/modules/WireConsole/src/Concerns/InteractsWithBoost.php');

	expect($scaffoldSource)->toContain('MakeMigrationCommand::class')
		->and($scaffoldSource)->toContain('MakeTestCommand::class')
		->and($scaffoldSource)->toContain('MakeModuleCommand::class')
		->and($scaffoldSource)->toContain('MakeQueueCommand::class')
		->and($scaffoldSource)->toContain('MakeSeederCommand::class')
		->and($scaffoldSource)->toContain('MakeTaskCommand::class')
		->and($scaffoldSource)->not->toContain('renderConsoleStub');

	expect($backupsSource)->toContain('BackupPurgeCommand::class')
		->and($backupsSource)->toContain('is_link(')
		->and($sysLogsSource)->toContain('LogClearCommand::class')
		->and($sysLogsSource)->toContain('LogClearAllCommand::class')
		->and($sysLogsSource)->toContain('is_link(');

	expect($boostSource)->toContain('->applyConfiguration(')
		->and($boostSource)->toContain('SkillWriter::sanitizeSkillName(')
		->and($boostSource)->toContain('risk->weight() >= 3')
		->and($boostSource)->toContain('.staging_')
		->and($boostSource)->toContain("McpServer::isEnvEnabled('PW_MCP_ALLOW_EXECUTE')")
		->and($boostSource)->toContain("McpServer::isEnvEnabled('PW_MCP_ALLOW_RESTORE')");

	// Invalid explicit agent or module names in configureBoost must fail before touching any files
	$invalidAgentRes = $runtime->configureBoost(['guidelines'], ['WireConsole'], ['non_existent_agent_xyz']);
	$mixedInvalidAgentRes = $runtime->configureBoost(['guidelines'], ['WireConsole'], ['claude_code', 'non_existent_agent_xyz']);
	$invalidModuleRes = $runtime->configureBoost(['guidelines'], ['WireConsole', 'NonExistentModuleXyz'], ['claude_code']);
	expect($invalidAgentRes['ok'])->toBeFalse()
		->and($mixedInvalidAgentRes['ok'])->toBeFalse()
		->and($invalidModuleRes['ok'])->toBeFalse();

	// 2. Testing environment & sanitizeE2eArgs validation
	$testEnv = $runtime->getTestingEnvironment();
	expect($testEnv['e2eReady'])->toBeTrue()
		->and($testEnv['hasPlaywrightBinary'])->toBeTrue()
		->and($testEnv['hasPlaywrightConfig'])->toBeTrue()
		->and($testEnv['e2eFileCount'])->toBeGreaterThanOrEqual(1);

	$validE2e = $runtime->sanitizeE2eArgs('--module=WireConsole --grep=accessibility --workers=1 --reporter=line');
	expect($validE2e['ok'])->toBeTrue()
		->and($validE2e['tokens'])->toBe([
			'--module=WireConsole',
			'--grep=accessibility',
			'--workers=1',
			'--reporter=line',
		]);

	$validE2ePath = $runtime->sanitizeE2eArgs('site/modules/WireConsole/tests/e2e/console-workspace.spec.ts');
	expect($validE2ePath['ok'])->toBeTrue();

	foreach ([
		'--ui',
		'--headed',
		'--debug',
		'--config=/etc/passwd',
		'../../etc/passwd',
		'--grep=test;whoami',
		'--grep=' . str_repeat('X', 210),
	] as $rejectedE2eArg) {
		expect($runtime->sanitizeE2eArgs($rejectedE2eArg)['ok'])->toBeFalse("Expected E2E arg to be rejected: {$rejectedE2eArg}");
	}

	// 3. Isolated sandbox verification of initPlaywrightE2e (never touches shared project root)
	$pw = ProcessWire::getCurrentInstance();
	$originalSitePath = (string) $pw->config->paths->site;
	$sandboxRoot = rtrim(sys_get_temp_dir(), '/\\') . '/wc_e2e_init_' . uniqid();
	@mkdir($sandboxRoot . '/site', 0755, true);
	@mkdir($sandboxRoot . '/node_modules/.bin', 0755, true);
	$fakePlaywright = $sandboxRoot . '/node_modules/.bin/playwright';
	file_put_contents($fakePlaywright, "#!/bin/sh\nexit 0\n");
	@chmod($fakePlaywright, 0755);

	try {
		$pw->config->paths->set('site', $sandboxRoot . '/site/');
		$firstInit = $runtime->initPlaywrightE2e();
		expect($firstInit['ok'])->toBeTrue()
			->and($firstInit['created'])->toContain('playwright.config.ts');

		$secondInit = $runtime->initPlaywrightE2e();
		expect($secondInit['ok'])->toBeTrue()
			->and($secondInit['skipped'])->toContain('playwright.config.ts');
	} finally {
		$pw->config->paths->set('site', $originalSitePath);
		\Totoglu\Console\Boost\Install\SkillWriter::deleteTree($sandboxRoot);
	}

	// 4. Scaffold typed migration, unit Pest test, and Playwright E2E spec via Make*Command delegation
	$createdFiles = [];
	$siteTestsExisted = is_dir($root . 'site/tests');
	$migDirExisted = is_dir($root . 'site/migrations');

	try {
		$typedMig = $runtime->scaffoldComponentFile('migration', 'wc_typed_field_mig', ['type' => 'create-field']);
		expect($typedMig['ok'])->toBeTrue()
			->and($typedMig['path'])->toBeString();
		$createdFiles[] = (string) $typedMig['path'];
		expect((string) file_get_contents((string) $typedMig['path']))->toContain('FieldtypeText');

		$badMigType = $runtime->scaffoldComponentFile('migration', 'wc_bad_type', ['type' => 'unknown-stub']);
		expect($badMigType['ok'])->toBeFalse();

		$unitTest = $runtime->scaffoldComponentFile('test', 'WcDelegatedUnitCheck', ['test_kind' => 'unit']);
		expect($unitTest['ok'])->toBeTrue()
			->and($unitTest['file'])->toBe('WcDelegatedUnitCheckTest.php')
			->and($unitTest['path'])->toContain('site/tests/Unit/WcDelegatedUnitCheckTest.php');
		$createdFiles[] = (string) $unitTest['path'];

		$e2eSpec = $runtime->scaffoldComponentFile('test', 'wc-delegated-flow', ['test_kind' => 'e2e']);
		expect($e2eSpec['ok'])->toBeTrue()
			->and($e2eSpec['file'])->toBe('wc-delegated-flow.spec.ts')
			->and($e2eSpec['path'])->toContain('site/tests/e2e/wc-delegated-flow.spec.ts');
		$createdFiles[] = (string) $e2eSpec['path'];
		expect((string) file_get_contents((string) $e2eSpec['path']))->toContain('@playwright/test');

		// Verify newly scaffolded site tests are discovered by getDiscoveredTestFiles()
		$discoveredNames = array_column($runtime->getDiscoveredTestFiles(), 'name');
		expect($discoveredNames)->toContain('WcDelegatedUnitCheckTest')
			->and($discoveredNames)->toContain('wc-delegated-flow.spec.ts');
	} finally {
		foreach ($createdFiles as $f) {
			if ($f !== '' && is_file($f)) {
				@unlink($f);
			}
		}
		if (!$siteTestsExisted && is_dir($root . 'site/tests')) {
			foreach (['site/tests/Unit', 'site/tests/Feature', 'site/tests/e2e', 'site/tests'] as $relDir) {
				$absDir = $root . $relDir;
				if (is_dir($absDir) && (glob($absDir . '/*') ?: []) === []) {
					@rmdir($absDir);
				}
			}
		}
		if (!$migDirExisted && is_dir($root . 'site/migrations') && (glob($root . 'site/migrations/*') ?: []) === []) {
			@rmdir($root . 'site/migrations');
		}
	}
});

test('symlinked files, abstract task/seeder classes, and canonical module Boost skill discovery are enforced', function (): void {
	wcSetSuperuser();
	$pw = ProcessWire::getCurrentInstance();
	$runtime = \Totoglu\WireConsole\ConsoleRuntime::make($pw);
	$root = $runtime->getProjectRootPath();

	// 1. Canonical Boost discovery for WireConsole (.agents/skills/pw-wire-console/SKILL.md + AGENTS.md)
	$boostStatus = $runtime->getBoostStatus();
	expect($boostStatus['available'])->toBeTrue();
	$wireConsoleBoost = null;
	foreach ($boostStatus['discoverableModules'] as $modMeta) {
		if ($modMeta['name'] === 'WireConsole') {
			$wireConsoleBoost = $modMeta;
			break;
		}
	}
	expect($wireConsoleBoost)->toBeArray()
		->and($wireConsoleBoost['hasGuidelines'])->toBeTrue()
		->and($wireConsoleBoost['hasSkills'])->toBeTrue();

	// 2. Abstract Task and symlinked Task/Backup/Migration rejection
	$scheduleDir = $root . 'site/schedule/';
	$migDir = $root . 'site/migrations/';
	$backupDir = $runtime->getBackupDirectory();
	if (!is_dir($scheduleDir)) {
		mkdir($scheduleDir, 0755, true);
	}
	if (!is_dir($migDir)) {
		mkdir($migDir, 0755, true);
	}

	$abstractTaskFile = $scheduleDir . 'WcAbstractSkipTask.php';
	$symlinkMigPath = $migDir . '99999999999999_wc_symlink_mig.php';
	$symlinkBackupPath = $backupDir . 'wc_symlink_escape.sql';
	$outsideSecretFile = rtrim(sys_get_temp_dir(), '/\\') . '/wc_outside_secret_' . uniqid() . '.txt';

	file_put_contents($outsideSecretFile, "TOP_SECRET_OUTSIDE_ROOT\n");
	file_put_contents($abstractTaskFile, <<<'PHP'
<?php
declare(strict_types=1);
namespace Site\Schedule;
use Totoglu\Console\Scheduling\Event;
use Totoglu\Console\Scheduling\Task;
abstract class WcAbstractSkipTask extends Task
{
	public function schedule(Event $event): void
	{
		$event->everyMinute();
	}
}
PHP);

	@symlink($outsideSecretFile, $symlinkMigPath);
	@symlink($outsideSecretFile, $symlinkBackupPath);

	try {
		$fresh = \Totoglu\WireConsole\ConsoleRuntime::make($pw);
		$taskNames = array_column($fresh->getScheduledTasks(), 'name');
		expect($taskNames)->not->toContain('WcAbstractSkipTask');

		expect($fresh->resolveBackupFilePath('wc_symlink_escape.sql'))->toBeNull()
			->and($fresh->inspectProjectFile('backup', 'wc_symlink_escape.sql'))->toBeNull()
			->and($fresh->inspectProjectFile('migration', '99999999999999_wc_symlink_mig'))->toBeNull();

		// Symlinked log file in site/assets/logs/ must be rejected by both readLogEntries() and clearLogChannel()
		$logsDir = rtrim((string) $pw->config->paths->logs, '/') . '/';
		$symlinkLogPath = $logsDir . 'wc-symlink-log.txt';
		@symlink($outsideSecretFile, $symlinkLogPath);
		try {
			expect($fresh->readLogEntries('wc-symlink-log'))->toBe([]);
			$clearSymRes = $fresh->clearLogChannel('wc-symlink-log');
			expect($clearSymRes['ok'])->toBeFalse()
				->and((string) file_get_contents($outsideSecretFile))->toContain('TOP_SECRET_OUTSIDE_ROOT');
		} finally {
			if (is_link($symlinkLogPath) || is_file($symlinkLogPath)) {
				@unlink($symlinkLogPath);
			}
		}

		// 3. Numeric-ID RBAC permission strings ("36", "1", "") must always be rejected by userCanView()
		expect($fresh->userCanView('36'))->toBeFalse()
			->and($fresh->userCanView('1'))->toBeFalse()
			->and($fresh->userCanView(''))->toBeFalse();

		// 4. Non-POST HTTP requests (e.g. GET) must never dispatch workspace actions via executeAction()
		$module = wcTestModule();
		$prevMethod = $_SERVER['REQUEST_METHOD'] ?? null;
		try {
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$pw->input->post->set('action', 'clearSystemCaches');
			$pw->input->post->set('cache_clear_mode', 'wire');
			$pw->input->post->set('confirm_system_cache_clear', 'CACHE');
			$pw->input->post->set('_token', (string) $pw->session->CSRF->getTokenValue());

			$pw->cache->save('wire-console:test:get-method-guard', 'untouched', 300);
			$module->executeAction();
			expect($pw->cache->get('wire-console:test:get-method-guard'))->toBe('untouched');
		} finally {
			if ($prevMethod === null) {
				unset($_SERVER['REQUEST_METHOD']);
			} else {
				$_SERVER['REQUEST_METHOD'] = $prevMethod;
			}
			$pw->input->post->removeAll();
			$pw->cache->delete('wire-console:test:get-method-guard');
		}

		// 5. Symlinked PHP files in site/queue, site/seeders, and site/schedule must NEVER be require_once'd
		$queueDir = $root . 'site/queue/';
		$seederDir = $root . 'site/seeders/';
		if (!is_dir($queueDir)) {
			mkdir($queueDir, 0755, true);
		}
		if (!is_dir($seederDir)) {
			mkdir($seederDir, 0755, true);
		}

		$outsidePhpPayload = rtrim(sys_get_temp_dir(), '/\\') . '/wc_outside_payload_' . uniqid() . '.php';
		file_put_contents($outsidePhpPayload, "<?php\n\$GLOBALS['__wc_symlink_included'] = true;\n");
		$symlinkQueuePath = $queueDir . 'WcSymlinkEscapeQueue.php';
		$symlinkSeederPath = $seederDir . 'WcSymlinkEscapeSeeder.php';
		$symlinkTaskPath = $scheduleDir . 'WcSymlinkEscapeTask.php';
		$abstractQueueFile = $queueDir . 'WcAbstractSkipQueue.php';

		file_put_contents($abstractQueueFile, <<<'PHP'
<?php
declare(strict_types=1);
namespace Site\Queue;
use Totoglu\Console\Queue\Queue;
abstract class WcAbstractSkipQueue extends Queue
{
}
PHP);

		@symlink($outsidePhpPayload, $symlinkQueuePath);
		@symlink($outsidePhpPayload, $symlinkSeederPath);
		@symlink($outsidePhpPayload, $symlinkTaskPath);
		unset($GLOBALS['__wc_symlink_included']);

		try {
			$isolatedRt = \Totoglu\WireConsole\ConsoleRuntime::make($pw);
			$queueNames = array_column($isolatedRt->getQueuesStatus()['discoveredQueues'], 'name');
			$seederNames = array_column($isolatedRt->getDiscoveredSeeders(), 'name');
			$schedNames = array_column($isolatedRt->getScheduledTasks(), 'name');

			expect($queueNames)->not->toContain('WcSymlinkEscapeQueue')
				->and($queueNames)->not->toContain('WcAbstractSkipQueue')
				->and($seederNames)->not->toContain('WcSymlinkEscapeSeeder')
				->and($schedNames)->not->toContain('WcSymlinkEscapeTask');

			$isolatedRt->installQueueTables();
			$dispatchSymRes = $isolatedRt->dispatchQueueJob('WcSymlinkEscapeQueue', '{}');
			$workSymRes = $isolatedRt->workQueueBatch();
			$seedSymRes = $isolatedRt->runSeeders('all');
			$schedSymRes = $isolatedRt->runScheduledTasks('due');
			expect($dispatchSymRes['ok'])->toBeFalse()
				->and($workSymRes['ok'])->toBeFalse()
				->and($seedSymRes['ok'])->toBeFalse()
				->and($schedSymRes['ok'])->toBeFalse()
				->and(isset($GLOBALS['__wc_symlink_included']))->toBeFalse();
		} finally {
			unset($GLOBALS['__wc_symlink_included']);
			foreach ([$symlinkQueuePath, $symlinkSeederPath, $symlinkTaskPath, $abstractQueueFile, $outsidePhpPayload] as $symCleanup) {
				if (is_link($symCleanup) || is_file($symCleanup)) {
					@unlink($symCleanup);
				}
			}
		}

		// 6. ProcessWire demo mode ($config->demo = true) blocks mutations, SQL backup previews, and SQL downloads while permitting read-only MCP inspection
		$demoSqlFixture = $backupDir . 'wc_demo_guard_fixture.sql';
		file_put_contents($demoSqlFixture, "-- DEMO PROTECTED SQL\nSELECT 1;\n");
		$prevDemo = (bool) ($pw->config->demo ?? false);
		$pw->config->demo = true;
		try {
			$demoRt = \Totoglu\WireConsole\ConsoleRuntime::make($pw);
			expect($demoRt->isDemoMode())->toBeTrue()
				->and($demoRt->userCanInspectCategory('backup'))->toBeFalse()
				->and($demoRt->inspectProjectFile('backup', 'wc_demo_guard_fixture.sql'))->toBeNull()
				->and($demoRt->inspectProjectFile('boost-doc', 'agents-md'))->toBeArray();

			$demoPayload = $demoRt->buildWorkspacePayload('overview');
			expect($demoPayload['isDemoMode'])->toBeTrue();

			$blockedMut = $demoRt->executeWorkspaceAction('clearSystemCaches', [
				'cache_clear_mode' => 'wire',
				'confirm_system_cache_clear' => 'CACHE',
			]);
			expect($blockedMut['ok'])->toBeFalse()
				->and($blockedMut['message'])->toContain('demo mode');

			$allowedMcp = $demoRt->executeWorkspaceAction('runMcpReadOnlyTool', [
				'mcp_tool_name' => 'pw_module_list',
				'mcp_tool_args' => '{}',
				'confirm_boost_mcp_test' => 'QUERY',
			]);
			expect($allowedMcp['ok'])->toBeTrue();

			$pw->input->get->set('file', 'wc_demo_guard_fixture.sql');
			expect(static fn() => wcTestModule()->executeDownloadBackup())->toThrow(\ProcessWire\WirePermissionException::class);
		} finally {
			$pw->config->demo = $prevDemo;
			$pw->input->get->removeAll();
			if (is_file($demoSqlFixture)) {
				@unlink($demoSqlFixture);
			}
		}

		// 7. Missing applied migrations, symlinked site/down.json refusal, and throwing Task::schedule() isolation
		$rtExtra = \Totoglu\WireConsole\ConsoleRuntime::make($pw);
		$rtExtra->installMigrationsTable();
		$ghostMigration = '2099_12_31_235959_wc_ghost_missing_migration';
		$downPath = $rtExtra->getMaintenanceFilePath();
		$goodTaskFile = $root . 'site/schedule/WcHealthyVerifyTask.php';
		$throwingTaskFile = $root . 'site/schedule/WcThrowingScheduleTask.php';

		try {
			$migTable = $pw->database->escapeTable(\Totoglu\WireConsole\ConsoleRuntime::TABLE_MIGRATIONS);
			$insStmt = $pw->database->prepare("INSERT INTO `{$migTable}` (`migration`, `batch`) VALUES (:mig, 99)");
			$insStmt->execute([':mig' => $ghostMigration]);

			$migStatus = $rtExtra->getMigrationsStatus();
			$ghostRow = null;
			foreach ($migStatus['items'] as $migItem) {
				if ($migItem['name'] === $ghostMigration) {
					$ghostRow = $migItem;
					break;
				}
			}
			expect($ghostRow)->not->toBeNull()
				->and($ghostRow['status'] ?? null)->toBe('Missing')
				->and($ghostRow['batch'] ?? null)->toBe(99)
				->and($migStatus['lastBatch'])->toBeGreaterThanOrEqual(99)
				->and($migStatus['pending'])->toBe(0)
				->and(array_column($migStatus['items'], 'name'))->not->toContain('99999999999999_wc_symlink_mig');

			expect($rtExtra->runMigrations()['ok'])->toBeFalse()
				->and($rtExtra->rollbackMigrations()['ok'])->toBeFalse()
				->and($rtExtra->resetMigrations('reset', false)['ok'])->toBeFalse();

			if (!is_file($downPath) && !is_link($downPath)) {
				@symlink($outsideSecretFile, $downPath);
				$downRes = $rtExtra->enableMaintenanceMode('sym-secret', 600);
				expect($downRes['ok'])->toBeFalse()
					->and(is_link($downPath))->toBeTrue()
					->and(is_file($outsideSecretFile))->toBeTrue();

				$upRes = $rtExtra->disableMaintenanceMode();
				expect($upRes['ok'])->toBeTrue()
					->and(is_link($downPath))->toBeFalse()
					->and(is_file($outsideSecretFile))->toBeTrue();
			}

			if (!is_dir(dirname($goodTaskFile))) {
				@mkdir(dirname($goodTaskFile), 0755, true);
			}
			file_put_contents($goodTaskFile, <<<'PHP'
<?php
declare(strict_types=1);
namespace Site\Schedule;
use Totoglu\Console\Scheduling\Event;
use Totoglu\Console\Scheduling\Task;
class WcHealthyVerifyTask extends Task
{
	public function schedule(Event $event): void { $event->hourly(); }
	public function handle(): void {}
}
PHP);
			file_put_contents($throwingTaskFile, <<<'PHP'
<?php
declare(strict_types=1);
namespace Site\Schedule;
use Totoglu\Console\Scheduling\Event;
use Totoglu\Console\Scheduling\Task;
class WcThrowingScheduleTask extends Task
{
	public function schedule(Event $event): void { throw new \RuntimeException('Broken schedule definition'); }
	public function handle(): void {}
}
PHP);

			$isolatedTasks = array_column(\Totoglu\WireConsole\ConsoleRuntime::make($pw)->getScheduledTasks(), 'name');
			expect($isolatedTasks)->toContain('WcHealthyVerifyTask')
				->and($isolatedTasks)->not->toContain('WcThrowingScheduleTask');
		} finally {
			$migTable = $pw->database->escapeTable(\Totoglu\WireConsole\ConsoleRuntime::TABLE_MIGRATIONS);
			$delStmt = $pw->database->prepare("DELETE FROM `{$migTable}` WHERE `migration` = :mig");
			$delStmt->execute([':mig' => $ghostMigration]);
			if (is_link($downPath)) {
				@unlink($downPath);
			}
			foreach ([$goodTaskFile, $throwingTaskFile] as $taskClean) {
				if (is_file($taskClean)) {
					@unlink($taskClean);
				}
			}
		}
	} finally {
		foreach ([$abstractTaskFile, $symlinkMigPath, $symlinkBackupPath, $outsideSecretFile] as $cleanupPath) {
			if (is_link($cleanupPath) || is_file($cleanupPath)) {
				@unlink($cleanupPath);
			}
		}
	}
});
