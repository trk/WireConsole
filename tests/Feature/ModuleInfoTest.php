<?php // __(file-not-translatable)

declare(strict_types=1);

use ProcessWire\ProcessWireConsole;
use Totoglu\WireConsole\ConsoleRuntime;
use function ProcessWire\wire;

require_once __DIR__ . '/../bootstrap.php';

test('ProcessWireConsole registers WireClassLoader namespace strictly once in __construct and uses zero manual require_once or class_exists guards', function (): void {
	$moduleRoot = dirname(__DIR__, 2);
	$moduleFile = $moduleRoot . '/ProcessWireConsole.module.php';
	$moduleSource = (string) file_get_contents($moduleFile);

	expect(substr_count($moduleSource, '->addNamespace('))->toBe(1)
		->and($moduleSource)->toContain("\$this->wire()->classLoader->addNamespace('Totoglu\\\\WireConsole', __DIR__ . '/src/');")
		->and($moduleSource)->toContain('parent::init();')
		->and($moduleSource)->not->toContain('registerNamespace')
		->and($moduleSource)->not->toContain('require_once')
		->and($moduleSource)->not->toContain('class_exists')
		->and($moduleSource)->not->toContain('trait_exists')
		->and($moduleSource)->not->toContain('interface_exists');

	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleRoot . '/src'));
	foreach ($iterator as $file) {
		if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
			continue;
		}
		$srcContent = (string) file_get_contents($file->getPathname());
		$relPath = str_replace($moduleRoot . '/', '', $file->getPathname());
		expect($srcContent)->not->toContain('addNamespace(')
			->and($srcContent)->not->toContain('require_once')
			->and($srcContent)->not->toContain('class_exists')
			->and($srcContent)->not->toContain('trait_exists')
			->and($srcContent)->not->toContain('interface_exists');
	}

	// Literal permission constants on ProcessWireConsole must match ConsoleRuntime constants 1-to-1
	expect(ProcessWireConsole::PERMISSION_VIEW)->toBe(ConsoleRuntime::PERMISSION_VIEW)
		->and(ProcessWireConsole::PERMISSION_MIGRATIONS)->toBe(ConsoleRuntime::PERMISSION_MIGRATIONS)
		->and(ProcessWireConsole::PERMISSION_QUEUES)->toBe(ConsoleRuntime::PERMISSION_QUEUES)
		->and(ProcessWireConsole::PERMISSION_SEEDING)->toBe(ConsoleRuntime::PERMISSION_SEEDING)
		->and(ProcessWireConsole::PERMISSION_SCHEDULING)->toBe(ConsoleRuntime::PERMISSION_SCHEDULING)
		->and(ProcessWireConsole::PERMISSION_BACKUP)->toBe(ConsoleRuntime::PERMISSION_BACKUP)
		->and(ProcessWireConsole::PERMISSION_TESTING)->toBe(ConsoleRuntime::PERMISSION_TESTING)
		->and(ProcessWireConsole::PERMISSION_BOOST)->toBe(ConsoleRuntime::PERMISSION_BOOST)
		->and(ProcessWireConsole::PERMISSION_LOGS)->toBe(ConsoleRuntime::PERMISSION_LOGS);
});

test('ProcessWireConsole defines the Inertia + React Console process module metadata and permissions', function (): void {
	$info = ProcessWireConsole::getModuleInfo();

	expect($info['title'])->toBe('Console')
		->and($info['version'])->toBe(220)
		->and($info['singular'])->toBeTrue()
		->and($info['autoload'])->toBeFalse()
		->and($info['reactCapabilities'])->toBe(['workspace', 'icons', 'commandSearch'])
		->and($info['useNavJSON'])->toBeTrue()
		->and($info['page'])->toBe([
			'name' => 'wire-console',
			'parent' => 'setup',
			'title' => 'Console',
		])
		->and($info['permission'])->toBe('wire-console')
		->and($info['requires'])->toBe([
			'PHP>=8.4',
			'ProcessWire>=3.0.271',
			'Inertia',
			'React',
		])
		->and(array_keys($info['permissions']))->toBe([
			'wire-console',
			'wire-console-migrations',
			'wire-console-queues',
			'wire-console-seeding',
			'wire-console-scheduling',
			'wire-console-backup',
			'wire-console-testing',
			'wire-console-boost',
			'wire-console-logs',
		]);
});

test('ProcessWireConsole ships Inertia + React TSX panels without obsolete server-rendered UI directories', function (): void {
	$moduleRoot = dirname(__DIR__, 2);

	expect(is_dir($moduleRoot . '/components'))->toBeFalse()
		->and(is_dir($moduleRoot . '/ui'))->toBeFalse()
		->and(is_file($moduleRoot . '/ProcessWireConsole.css'))->toBeFalse()
		->and(is_file($moduleRoot . '/ProcessWireConsole.module.php'))->toBeTrue()
		->and(is_file($moduleRoot . '/dist/ProcessWireConsole.js'))->toBeTrue()
		->and(is_file($moduleRoot . '/src/Concerns/HandlesWorkspaceActions.php'))->toBeTrue()
		->and(is_file($moduleRoot . '/resources/ts/entries/ProcessWireConsole.tsx'))->toBeTrue()
		->and(is_file($moduleRoot . '/resources/ts/pages/Workspace.tsx'))->toBeTrue()
		->and(is_file($moduleRoot . '/resources/ts/panels/OverviewPanel.tsx'))->toBeTrue()
		->and(is_file($moduleRoot . '/resources/ts/panels/MigrationsPanel.tsx'))->toBeTrue()
		->and(is_file($moduleRoot . '/resources/ts/panels/QueuesPanel.tsx'))->toBeTrue()
		->and(is_file($moduleRoot . '/resources/ts/panels/SeedingPanel.tsx'))->toBeTrue()
		->and(is_file($moduleRoot . '/resources/ts/panels/SchedulingPanel.tsx'))->toBeTrue()
		->and(is_file($moduleRoot . '/resources/ts/panels/BackupPanel.tsx'))->toBeTrue()
		->and(is_file($moduleRoot . '/resources/ts/panels/TestingPanel.tsx'))->toBeTrue()
		->and(is_file($moduleRoot . '/resources/ts/panels/BoostPanel.tsx'))->toBeTrue()
		->and(is_file($moduleRoot . '/resources/ts/panels/LogsPanel.tsx'))->toBeTrue();
});

test('ProcessWireConsole uninstall removes the wire-console-audit log channel, caches, and permissions', function (): void {
	$pw = \ProcessWire\ProcessWire::getCurrentInstance();
	$module = new class extends ProcessWireConsole {
		public function className($options = null): string
		{
			return 'ProcessWireConsole';
		}

		protected function installPage(
			$name = '',
			$parent = null,
			$title = '',
			$template = 'admin',
			$extras = array()
		) {
			return wire('pages')->newNullPage();
		}

		protected function uninstallPage()
		{
			return 0;
		}
	};
	$module->setWire($pw);

	$composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
	expect($composer['version'] ?? null)->toBe('2.2.0')
		->and($composer['require']['trk/processwire-inertia'] ?? null)->toBe('^1.0')
		->and($composer['require']['trk/processwire-react'] ?? null)->toBe('^1.0 || ^2.0');

	$module->___install();
	expect($pw->permissions->get(ProcessWireConsole::PERMISSION_VIEW)?->id)->toBeGreaterThan(0);

	$module->init();
	$react = \ProcessWire\React::instance($module);
	expect($react->workspaces()->has('console'))->toBeTrue()
		->and($react->widgets()->has('console-overview'))->toBeTrue();

	$module->runtime()->audit('uninstall_check', 'ok');
	$pw->cache->save('wire-console-test-key', '1', 60);
	$channelsBefore = array_column($module->runtime()->getLogChannels(), 'name');
	expect($channelsBefore)->toContain(\Totoglu\WireConsole\ConsoleRuntime::LOG_CHANNEL);

	try {
		$module->___uninstall();

		$channelsAfter = array_column($module->runtime()->getLogChannels(), 'name');
		expect($channelsAfter)->not->toContain(\Totoglu\WireConsole\ConsoleRuntime::LOG_CHANNEL)
			->and($pw->cache->get('wire-console-test-key'))->toBeNull()
			->and($react->workspaces()->has('console'))->toBeFalse()
			->and($react->widgets()->has('console-overview'))->toBeFalse()
			->and((int) ($pw->permissions->get(ProcessWireConsole::PERMISSION_VIEW)?->id ?? 0))->toBe(0);
	} finally {
		$module->___install();
		wcTestModule();
	}
});

test('ProcessWireConsole exposes nested UI translations and matching LanguagePorter CSV packs for en, tr, de, fr, es, ru', function (): void {
	$moduleRoot = dirname(__DIR__, 2);
	$languagesDir = $moduleRoot . '/languages';

	$module = wcTestModule();
	$enTranslations = $module->translations();

	$expectedSections = [
		'workspace',
		'feedback',
		'inspector',
		'overview',
		'migrations',
		'queues',
		'seeding',
		'scheduling',
		'backup',
		'testing',
		'boost',
		'logs',
	];

	expect($enTranslations)->toBeArray()
		->not->toBeEmpty()
		->toHaveKeys($expectedSections);

	foreach ($expectedSections as $section) {
		expect($enTranslations[$section])->toBeArray()->not->toBeEmpty();
	}

	$workspace = \ProcessWire\React::instance($module);
	$resolved = $workspace->resolveModuleTranslations($module);

	expect($resolved)->toBeArray()
		->not->toBeEmpty()
		->and($resolved['workspace.title'] ?? null)->toBeString()->not->toBe('')
		->and($resolved['feedback.completed'] ?? null)->toContain('{action}')
		->and($resolved['inspector.title'] ?? null)->toBeString()->not->toBe('');

	$defaultLang = wire('languages')->getDefault();
	$exportedCsv = $defaultLang->porter->exportCsv([
		'source' => 'site/modules/WireConsole/',
		'scope' => 'all',
		'exportTo' => 'string',
	]);

	$fp = fopen('php://temp', 'r+');
	fwrite($fp, $exportedCsv);
	rewind($fp);
	$exportHeader = fgetcsv($fp, 0, ',', '"', '\\');
	$expectedRows = [];
	while (($row = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
		$expectedRows[] = [
			'original' => $row[0],
			'file' => $row[3],
			'hash' => $row[4],
		];
	}
	fclose($fp);

	expect($exportHeader)->toBeArray()
		->and($expectedRows)->toHaveCount(651);

	$expectedLocales = ['en', 'tr', 'de', 'fr', 'es', 'ru'];
	foreach ($expectedLocales as $locale) {
		$csvPath = $languagesDir . '/' . $locale . '.csv';
		expect(is_file($csvPath))->toBeTrue();

		$handle = fopen($csvPath, 'r');
		$header = fgetcsv($handle, 0, ',', '"', '\\');
		$expectedFirstCol = $locale === 'en' ? 'default' : 'en';
		expect($header)->toBe([$expectedFirstCol, $locale, 'description', 'file', 'hash']);

		$rows = [];
		while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
			expect(trim((string) $row[1]))->not->toBe('');
			$rows[] = [
				'original' => $row[0],
				'file' => $row[3],
				'hash' => $row[4],
			];
		}
		fclose($handle);

		expect($rows)->toBe($expectedRows);
	}
});

test('API.md method and parameter signatures match ProcessWireConsole and ConsoleRuntime via Reflection', function (): void {
	$moduleRoot = dirname(__DIR__, 2);
	$apiMd = (string) file_get_contents($moduleRoot . '/API.md');

	$moduleRef = new ReflectionClass(ProcessWireConsole::class);
	$runtimeRef = new ReflectionClass(ConsoleRuntime::class);

	// 1. Verify every `public [static] function name(...)` in API.md exists with exact parameter names
	preg_match_all('/`public\s+(?:static\s+)?function\s+([A-Za-z0-9_]+)\(([^)]*)\)/', $apiMd, $tableMatches, PREG_SET_ORDER);
	expect(count($tableMatches))->toBeGreaterThanOrEqual(30);

	foreach ($tableMatches as $match) {
		$methodName = $match[1];
		$paramsRaw = trim($match[2]);
		$targetRef = $moduleRef->hasMethod($methodName)
			? $moduleRef
			: ($runtimeRef->hasMethod($methodName) ? $runtimeRef : null);

		expect($targetRef)->not->toBeNull("Documented method {$methodName}() in API.md does not exist on ProcessWireConsole or ConsoleRuntime");
		$methodRef = $targetRef->getMethod($methodName);
		expect($methodRef->isPublic())->toBeTrue();

		preg_match_all('/\$([A-Za-z0-9_]+)/', $paramsRaw, $docParamMatches);
		$docParams = $docParamMatches[1];
		$actualParams = array_map(static fn(ReflectionParameter $p): string => $p->getName(), $methodRef->getParameters());
		expect($actualParams)->toBe($docParams, "Parameter mismatch in API.md for {$methodName}()");
	}

	// 2. Verify inline subsystem signatures `methodName(...): returnType` on ConsoleRuntime
	preg_match_all('/`([a-z][A-Za-z0-9_]+)\(([^)]*)\):\s*\??[A-Za-z0-9_|]+`/', $apiMd, $inlineMatches, PREG_SET_ORDER);
	expect(count($inlineMatches))->toBeGreaterThanOrEqual(30);

	foreach ($inlineMatches as $match) {
		$methodName = $match[1];
		$paramsRaw = trim($match[2]);
		expect($runtimeRef->hasMethod($methodName))->toBeTrue("Documented ConsoleRuntime method {$methodName}() in API.md does not exist");
		$methodRef = $runtimeRef->getMethod($methodName);
		expect($methodRef->isPublic())->toBeTrue();

		preg_match_all('/\$([A-Za-z0-9_]+)/', $paramsRaw, $docParamMatches);
		$docParams = $docParamMatches[1];
		$actualParams = array_map(static fn(ReflectionParameter $p): string => $p->getName(), $methodRef->getParameters());
		expect($actualParams)->toBe($docParams, "Parameter mismatch in API.md for ConsoleRuntime::{$methodName}()");
	}
});

test('ProcessWireConsole is autoload => false and lazily pulled via reactCapabilities on clean boot', function (): void {
	expect((new ProcessWireConsole())->isAutoload())->toBeFalse()
		->and(wire('modules')->isAutoload('ProcessWireConsole'))->toBeFalse();

	$root = dirname(__DIR__, 5);
	$code = <<<'PHP'
require __ROOT__ . '/wire/core/ProcessWire.php';
$config = \ProcessWire\ProcessWire::buildConfig(__ROOT__);
$wire = new \ProcessWire\ProcessWire($config);
$home = $wire->pages->get(1);
if ($home && $home->id) {
	$wire->fuel->set('page', $home);
}
$loadedBefore = !($wire->modules->offsetGet('ProcessWireConsole') instanceof \ProcessWire\ModulePlaceholder)
	&& class_exists(\ProcessWire\ProcessWireConsole::class, false);
$classBefore = class_exists(\ProcessWire\ProcessWireConsole::class, false);
/** @var \ProcessWire\React $react */
$react = $wire->modules->get('React');
$discovered = $react->discoverCapabilityModules();
$loadedAfterDiscover = !($wire->modules->offsetGet('ProcessWireConsole') instanceof \ProcessWire\ModulePlaceholder)
	&& class_exists(\ProcessWire\ProcessWireConsole::class, false);
$guest = $wire->users->getGuestUser();
$wire->users->setCurrentUser($guest);
$ws = $react->workspaces();
$wd = $react->widgets();
$cs = $react->commandSearch();
$tmpAssets = sys_get_temp_dir() . '/wc-cleanboot-' . bin2hex(random_bytes(4)) . '/';
mkdir($tmpAssets, 0777, true);
$origAssets = $wire->config->paths->assets;
$wire->config->paths->assets = $tmpAssets;
$cssPath = $react->icons()->generateAdminCss();
$wire->config->paths->assets = $origAssets;
$css = (string) @file_get_contents($cssPath);
$wire->files->rmdir($tmpAssets, true);
echo json_encode([
	'loaded_before' => $loadedBefore,
	'class_before' => $classBefore,
	'discovered_ws' => in_array('ProcessWireConsole', $discovered['workspace'] ?? [], true),
	'discovered_icons' => in_array('ProcessWireConsole', $discovered['icons'] ?? [], true),
	'discovered_cs' => in_array('ProcessWireConsole', $discovered['commandSearch'] ?? [], true),
	'loaded_after_discover' => $loadedAfterDiscover,
	'loaded_after_pull' => $wire->modules->offsetGet('ProcessWireConsole') instanceof \ProcessWire\ProcessWireConsole,
	'has_workspace' => $ws->has('console'),
	'has_widget' => $wd->has('console-overview'),
	'has_command_search' => $cs->has('wire-console'),
	'icon_dashboard' => \Totoglu\React\Icon\IconManager::isAdminIconRegistered('layout-dashboard'),
	'icon_terminal' => \Totoglu\React\Icon\IconManager::isAdminIconRegistered('terminal'),
	'css_has_dashboard' => str_contains($css, '.fa-rwv--layout-dashboard'),
], JSON_THROW_ON_ERROR);
PHP;
	$code = str_replace('__ROOT__', var_export($root, true), $code);

	$spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
	$proc = proc_open([PHP_BINARY, '-r', $code], $spec, $pipes, $root);
	expect(is_resource($proc))->toBeTrue();
	fclose($pipes[0]);
	$stdout = (string) stream_get_contents($pipes[1]);
	$stderr = (string) stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$exit = proc_close($proc);

	expect($exit)->toBe(0, 'Subprocess failed: ' . $stderr)
		->and($stderr)->toBe('');
	$data = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
	expect($data['loaded_before'] ?? null)->toBeFalse()
		->and($data['class_before'] ?? null)->toBeFalse()
		->and($data['discovered_ws'] ?? null)->toBeTrue()
		->and($data['discovered_icons'] ?? null)->toBeTrue()
		->and($data['discovered_cs'] ?? null)->toBeTrue()
		->and($data['loaded_after_discover'] ?? null)->toBeFalse()
		->and($data['loaded_after_pull'] ?? null)->toBeTrue()
		->and($data['has_workspace'] ?? null)->toBeTrue()
		->and($data['has_widget'] ?? null)->toBeTrue()
		->and($data['has_command_search'] ?? null)->toBeTrue()
		->and($data['icon_dashboard'] ?? null)->toBeTrue()
		->and($data['icon_terminal'] ?? null)->toBeTrue()
		->and($data['css_has_dashboard'] ?? null)->toBeTrue();
});
