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
