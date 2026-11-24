<?php

declare(strict_types=1);

namespace Totoglu\WireConsole;

use ProcessWire\ProcessWire;
use ProcessWire\Wire;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Totoglu\Console\Boost\BoostManager;
use Totoglu\Console\Migration\Migrator;
use Totoglu\Console\Support\FeatureDiscoverer;
use Totoglu\WireConsole\Concerns\HandlesWorkspaceActions;
use Totoglu\WireConsole\Concerns\InteractsWithBackups;
use Totoglu\WireConsole\Concerns\InteractsWithBoost;
use Totoglu\WireConsole\Concerns\InteractsWithMigrations;
use Totoglu\WireConsole\Concerns\InteractsWithQueues;
use Totoglu\WireConsole\Concerns\InteractsWithScaffolding;
use Totoglu\WireConsole\Concerns\InteractsWithSeedingAndScheduling;
use Totoglu\WireConsole\Concerns\InteractsWithSystemAndLogs;
use Totoglu\WireConsole\Concerns\InteractsWithTesting;

/**
 * ConsoleRuntime
 *
 * Deep domain service encapsulating all ProcessWire Console & Boost runtime operations:
 * - Package & environment inspection (trk/processwire-console, trk/processwire-boost)
 * - Schema migrations (Totoglu\Console\Migration\Migrator)
 * - Background queues (Totoglu\Console\Queue\QueueManager)
 * - Database seeders (Totoglu\Console\Database\Seeder & FeatureDiscoverer)
 * - Scheduled tasks (Totoglu\Console\Scheduling\Task & Event)
 * - SQL backups (ProcessWire\WireDatabaseBackup)
 * - Pest PHP and Playwright E2E test execution (vendor/bin/wire test, vendor/bin/wire test:e2e)
 * - Boost & MCP Server diagnostics (Totoglu\Console\Boost\BoostManager & McpServer)
 * - RBAC permission checks, session rate limiting, and audit logging
 */
class ConsoleRuntime extends Wire
{
	use HandlesWorkspaceActions;
	use InteractsWithMigrations;
	use InteractsWithQueues;
	use InteractsWithBackups;
	use InteractsWithBoost;
	use InteractsWithScaffolding;
	use InteractsWithSeedingAndScheduling;
	use InteractsWithSystemAndLogs;
	use InteractsWithTesting;

	public const PERMISSION_VIEW = 'wire-console';
	public const PERMISSION_MIGRATIONS = 'wire-console-migrations';
	public const PERMISSION_QUEUES = 'wire-console-queues';
	public const PERMISSION_SEEDING = 'wire-console-seeding';
	public const PERMISSION_SCHEDULING = 'wire-console-scheduling';
	public const PERMISSION_BACKUP = 'wire-console-backup';
	public const PERMISSION_TESTING = 'wire-console-testing';
	public const PERMISSION_BOOST = 'wire-console-boost';
	public const PERMISSION_LOGS = 'wire-console-logs';

	public const LOG_CHANNEL = 'wire-console-audit';
	public const TABLE_MIGRATIONS = 'wire_migrations';
	public const TABLE_QUEUE_JOBS = 'queue_jobs';
	public const TABLE_QUEUE_FAILED = 'failed_jobs';
	public const MAX_JSON_BODY_BYTES = 65536;
	public const MAX_JSON_DEPTH = 16;
	public const MAX_JSON_ELEMENTS = 512;

	/** @var array<string, mixed>|null */
	protected ?array $packagesStatusCache = null;

	/** @var array{php: string, processwire: string, database: string, inertia: string, workspace: string, console: string, boost: string}|null */
	protected ?array $versionMatrixCache = null;

	/** @var list<array{id: string, name: string, relativePath: string, path: string, suite: string, runner: string}>|null */
	protected ?array $discoveredTestFilesCache = null;

	protected ?FeatureDiscoverer $discovererInstance = null;

	protected ?Migrator $migratorInstance = null;

	protected ?BoostManager $boostManagerInstance = null;

	/** @var list<array{name: string, path: string}>|null */
	protected ?array $discoveredQueuesCache = null;

	/** @var array{tablesExist: bool, queuedCount: int, failedCount: int, discoveredCount: int}|null */
	protected ?array $queuesSummaryCache = null;

	/**
	 * Instantiate a ConsoleRuntime wired to the given ProcessWire instance.
	 */
	public static function make(mixed $wire = null): self
	{
		$runtime = new self();
		if ($wire !== null) {
			$runtime->setWire($wire);
		}

		return $runtime;
	}

	/**
	 * Map of workspace tabs to their required view permissions and metadata.
	 *
	 * @return array<string, array{label: string, icon: string, permission: string, description: string}>
	 */
	public function getTabDefinitions(): array
	{
		$decode = static fn(string $text): string => html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return [
			'overview' => [
				'label' => $decode($this->_('Overview')),
				'icon' => 'layout-dashboard',
				'permission' => self::PERMISSION_VIEW,
				'description' => $decode($this->_('System readiness, maintenance mode, cache controls, and live system status.')),
			],
			'migrations' => [
				'label' => $decode($this->_('Migrations')),
				'icon' => 'database',
				'permission' => self::PERMISSION_MIGRATIONS,
				'description' => $decode($this->_('Track, scaffold, and execute timestamped database schema migrations.')),
			],
			'queues' => [
				'label' => $decode($this->_('Queues')),
				'icon' => 'stack-2',
				'permission' => self::PERMISSION_QUEUES,
				'description' => $decode($this->_('Monitor background queue jobs, scaffold handlers, and trigger retries.')),
			],
			'seeding' => [
				'label' => $decode($this->_('Seeding')),
				'icon' => 'plant',
				'permission' => self::PERMISSION_SEEDING,
				'description' => $decode($this->_('Discover, scaffold, and execute database seeders in site/seeders/.')),
			],
			'scheduling' => [
				'label' => $decode($this->_('Scheduling')),
				'icon' => 'clock',
				'permission' => self::PERMISSION_SCHEDULING,
				'description' => $decode($this->_('Inspect cron task schedules in site/schedule/, scaffold tasks, and trigger manual runs.')),
			],
			'backup' => [
				'label' => $decode($this->_('Backups')),
				'icon' => 'server',
				'permission' => self::PERMISSION_BACKUP,
				'description' => $decode($this->_('Create, download, purge, and restore SQL database backups.')),
			],
			'testing' => [
				'label' => $decode($this->_('Testing')),
				'icon' => 'flask',
				'permission' => self::PERMISSION_TESTING,
				'description' => $decode($this->_('Scaffold and run automated test suites.')),
			],
			'boost' => [
				'label' => $decode($this->_('Boost & AI')),
				'icon' => 'sparkles',
				'permission' => self::PERMISSION_BOOST,
				'description' => $decode($this->_('Inspect and synchronize AI guidelines, skills, and MCP configuration.')),
			],
			'logs' => [
				'label' => $decode($this->_('Logs & Audit')),
				'icon' => 'list-details',
				'permission' => self::PERMISSION_LOGS,
				'description' => $decode($this->_('Inspect security audit logs and application log channels.')),
			],
		];
	}

	/**
	 * Return only the tabs the current user has permission to view.
	 *
	 * @return array<string, array{label: string, icon: string, permission: string, description: string}>
	 */
	public function getAccessibleTabs(): array
	{
		$tabs = [];
		foreach ($this->getTabDefinitions() as $key => $meta) {
			if ($this->userCanView($meta['permission'])) {
				$tabs[$key] = $meta;
			}
		}

		return $tabs;
	}

	/**
	 * Search accessible Console sections and discovered project artifacts for the Command Palette (`/_react/command-search`).
	 *
	 * @return list<array{id: string, label: string, subtitle: string, badge: string, icon: string, href: string, isInertia: bool}>
	 */
	public function searchCommandPalette(string $query, string $baseUrl = '', int $limit = 5): array
	{
		$needle = trim($query);
		$cleanBase = rtrim(trim($baseUrl), '/');
		if ($cleanBase === '' || mb_strlen($needle, 'UTF-8') < 2 || !$this->userCanView(self::PERMISSION_VIEW)) {
			return [];
		}

		$cap = max(1, min(20, $limit));
		$resolvedBaseUrl = $cleanBase . '/';
		$accessibleTabs = $this->getAccessibleTabs();
		$results = [];

		foreach ($accessibleTabs as $key => $meta) {
			$haystack = $meta['label'] . ' ' . $key . ' ' . $meta['description'];
			if (mb_stripos($haystack, $needle, 0, 'UTF-8') === false) {
				continue;
			}
			$results[] = [
				'id' => 'console-tab-' . $key,
				'label' => $meta['label'],
				'subtitle' => $meta['description'],
				'badge' => '',
				'icon' => $meta['icon'],
				'href' => $key === 'overview' ? $resolvedBaseUrl : $resolvedBaseUrl . rawurlencode($key) . '/',
				'isInertia' => true,
			];
			if (count($results) >= $cap) {
				return $results;
			}
		}

		if (count($results) >= $cap) {
			return $results;
		}
		if (isset($accessibleTabs['migrations'])) {
			foreach ($this->getMigrationsStatus()['items'] as $mig) {
				if (mb_stripos($mig['name'], $needle, 0, 'UTF-8') === false) {
					continue;
				}
				$results[] = [
					'id' => 'console-mig-' . $mig['name'],
					'label' => $mig['name'],
					'subtitle' => 'site/migrations/',
					'badge' => $mig['status'],
					'icon' => 'database',
					'href' => $resolvedBaseUrl . 'migrations/',
					'isInertia' => true,
				];
				if (count($results) >= $cap) {
					return $results;
				}
			}
		}

		if (count($results) >= $cap) {
			return $results;
		}
		if (isset($accessibleTabs['queues'])) {
			foreach ($this->getDiscoveredQueuesList() as $queueItem) {
				if (mb_stripos($queueItem['name'], $needle, 0, 'UTF-8') === false) {
					continue;
				}
				$results[] = [
					'id' => 'console-queue-' . $queueItem['name'],
					'label' => $queueItem['name'],
					'subtitle' => $this->toProjectRelativePath($queueItem['path']),
					'badge' => $accessibleTabs['queues']['label'],
					'icon' => 'stack-2',
					'href' => $resolvedBaseUrl . 'queues/',
					'isInertia' => true,
				];
				if (count($results) >= $cap) {
					return $results;
				}
			}
		}

		if (count($results) >= $cap) {
			return $results;
		}
		if (isset($accessibleTabs['seeding'])) {
			foreach ($this->getDiscoveredSeeders() as $seeder) {
				if (mb_stripos($seeder['name'] . ' ' . $seeder['class'], $needle, 0, 'UTF-8') === false) {
					continue;
				}
				$results[] = [
					'id' => 'console-seeder-' . $seeder['name'],
					'label' => $seeder['name'],
					'subtitle' => $this->toProjectRelativePath($seeder['path']),
					'badge' => $accessibleTabs['seeding']['label'],
					'icon' => 'plant',
					'href' => $resolvedBaseUrl . 'seeding/',
					'isInertia' => true,
				];
				if (count($results) >= $cap) {
					return $results;
				}
			}
		}

		if (count($results) >= $cap) {
			return $results;
		}
		if (isset($accessibleTabs['scheduling'])) {
			foreach ($this->getScheduledTasks() as $task) {
				if (mb_stripos($task['name'] . ' ' . $task['expression'], $needle, 0, 'UTF-8') === false) {
					continue;
				}
				$results[] = [
					'id' => 'console-task-' . $task['name'],
					'label' => $task['name'],
					'subtitle' => $this->toProjectRelativePath($task['path']),
					'badge' => $task['expression'],
					'icon' => 'clock',
					'href' => $resolvedBaseUrl . 'scheduling/',
					'isInertia' => true,
				];
				if (count($results) >= $cap) {
					return $results;
				}
			}
		}

		if (count($results) >= $cap) {
			return $results;
		}
		if (isset($accessibleTabs['boost'])) {
			foreach ($this->listInstalledSkillNames() as $skill) {
				$desc = $this->readSkillFrontmatterDescription($skill);
				if (mb_stripos($skill . ' ' . $desc, $needle, 0, 'UTF-8') === false) {
					continue;
				}
				$results[] = [
					'id' => 'console-skill-' . $skill,
					'label' => $skill,
					'subtitle' => '.agents/skills/' . $skill . '/SKILL.md',
					'badge' => $accessibleTabs['boost']['label'],
					'icon' => 'sparkles',
					'href' => $resolvedBaseUrl . 'boost/',
					'isInertia' => true,
				];
				if (count($results) >= $cap) {
					return $results;
				}
			}
		}

		return $results;
	}

	/**
	 * Check if the current user can view a panel requiring the given permission.
	 */
	public function userCanView(string $permission): bool
	{
		$cleanPermission = trim($permission);
		if ($cleanPermission === '' || ctype_digit($cleanPermission)) {
			return false;
		}

		$user = $this->wire()->user;
		if (!$user || !$user->id) {
			return false;
		}
		if ($user->isSuperuser()) {
			return true;
		}
		if (!$user->hasPermission(self::PERMISSION_VIEW)) {
			return false;
		}

		return (bool) $user->hasPermission($cleanPermission);
	}

	/**
	 * Check whether the current user is a superuser (required for all mutating operations).
	 */
	public function isSuperuser(): bool
	{
		$user = $this->wire()->user;

		return $user !== null && $user->isSuperuser();
	}

	/**
	 * Check whether ProcessWire demo mode (`$config->demo`) is active.
	 */
	public function isDemoMode(): bool
	{
		return (bool) $this->wire()->config->demo;
	}

	/**
	 * Check whether the current user has permission to inspect files in the given category.
	 *
	 * Backups require superuser status, non-demo mode, and the backup permission (matching backup download);
	 * all other categories enforce the permission of the tab that owns them.
	 */
	public function userCanInspectCategory(string $category): bool
	{
		return match (strtolower(trim($category))) {
			'backup' => !$this->isDemoMode() && $this->isSuperuser() && $this->userCanView(self::PERMISSION_BACKUP),
			'migration' => $this->userCanView(self::PERMISSION_MIGRATIONS),
			'queue' => $this->userCanView(self::PERMISSION_QUEUES),
			'seeder' => $this->userCanView(self::PERMISSION_SEEDING),
			'task' => $this->userCanView(self::PERMISSION_SCHEDULING),
			'test' => $this->userCanView(self::PERMISSION_TESTING),
			'skill', 'boost-doc' => $this->userCanView(self::PERMISSION_BOOST),
			default => false,
		};
	}

	/**
	 * Resolve the absolute root path of the ProcessWire installation.
	 */
	public function getProjectRootPath(): string
	{
		$root = (string) $this->wire()->config->paths->root;
		if ($root !== '' && is_dir($root)) {
			return rtrim(str_replace('\\', '/', $root), '/') . '/';
		}

		return rtrim(str_replace('\\', '/', dirname(__DIR__, 4)), '/') . '/';
	}

	/**
	 * Check whether trk/processwire-console is installed in the project vendor directory.
	 */
	public function isConsolePackageAvailable(): bool
	{
		return $this->getPackagesStatus()['console']['installed'];
	}

	/**
	 * Check whether trk/processwire-boost is installed in the project vendor directory.
	 */
	public function isBoostPackageAvailable(): bool
	{
		return $this->getPackagesStatus()['boost']['installed'];
	}

	/**
	 * Instantiate a FeatureDiscoverer connected to the current ProcessWire instance.
	 */
	protected function createDiscoverer(): ?FeatureDiscoverer
	{
		if ($this->discovererInstance !== null) {
			return $this->discovererInstance;
		}
		if (!$this->isConsolePackageAvailable()) {
			return null;
		}
		$pw = $this->wire();
		if (!$pw) {
			return null;
		}

		return $this->discovererInstance = new FeatureDiscoverer($pw);
	}

	/**
	 * Discover concrete subclasses of `$expectedParentClass` inside `$feature`, verifying `!is_link()` and
	 * `realpath()` containment inside the project root strictly BEFORE loading any file.
	 *
	 * @template T of object
	 * @param class-string<T> $expectedParentClass
	 * @return array<string, array{class: class-string<T>, path: string}>
	 */
	protected function discoverVerifiedClasses(string $feature, string $pattern, string $expectedParentClass): array
	{
		$discoverer = $this->createDiscoverer();
		if ($discoverer === null) {
			return [];
		}

		$realRoot = realpath($this->getProjectRootPath());
		if ($realRoot === false) {
			return [];
		}
		$normalizedRoot = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';

		$verified = [];
		foreach ($discoverer->discoverFiles($feature, $pattern, '.php') as $basename => $filePath) {
			$cleanBase = trim((string) $basename);
			if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $cleanBase) !== 1 || is_link(dirname($filePath)) || is_link($filePath)) {
				continue;
			}

			$realFile = realpath($filePath);
			if ($realFile === false || !is_file($realFile)) {
				continue;
			}

			$normalizedFile = str_replace('\\', '/', $realFile);
			if (!str_starts_with($normalizedFile, $normalizedRoot)) {
				continue;
			}

			$fqcn = FeatureDiscoverer::resolveClassFromFile($realFile, $cleanBase, $expectedParentClass);
			if ($fqcn === null || preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $fqcn) !== 1) {
				continue;
			}

			try {
				$ref = new \ReflectionClass($fqcn);
				if ($ref->isAbstract() || $ref->isInterface() || $ref->isTrait() || !$ref->isInstantiable() || !$ref->isSubclassOf($expectedParentClass)) {
					continue;
				}
				$verified[$cleanBase] = [
					'class' => $fqcn,
					'path' => $normalizedFile,
				];
			} catch (\Throwable) {
				continue;
			}
		}

		return $verified;
	}

	/**
	 * Check whether any discovered file or directory inside `$feature` is a symbolic link or resolves outside the project root.
	 */
	protected function hasUnsafeFeatureFiles(string $feature, string $pattern): bool
	{
		$discoverer = $this->createDiscoverer();
		if ($discoverer === null) {
			return false;
		}

		$realRoot = realpath($this->getProjectRootPath());
		if ($realRoot === false) {
			return true;
		}
		$normalizedRoot = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';

		$siteFeatureDir = rtrim($this->getProjectRootPath(), '/') . '/site/' . ltrim($feature, '/');
		if (is_link($siteFeatureDir)) {
			return true;
		}

		foreach ($discoverer->discoverFiles($feature, $pattern, null) as $filePath) {
			if ($filePath === '' || is_link(dirname($filePath)) || is_link($filePath)) {
				return true;
			}
			$realFile = realpath($filePath);
			if ($realFile === false || !is_file($realFile)) {
				return true;
			}
			$normalizedFile = str_replace('\\', '/', $realFile);
			if (!str_starts_with($normalizedFile, $normalizedRoot)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Execute an upstream trk/processwire-console Symfony Console command in-process using ArrayInput and BufferedOutput.
	 *
	 * @param class-string<Command> $commandClass
	 * @param array<string, mixed> $parameters
	 * @return array{ok: bool, exitCode: int, data: array<string, mixed>, error: ?array{code: string, message: string}, rawOutput: string, stdout: string}
	 */
	protected function runConsoleCommandInProcess(string $commandClass, array $parameters = []): array
	{
		if (!$this->isConsolePackageAvailable()) {
			return [
				'ok' => false,
				'exitCode' => 1,
				'data' => [],
				'error' => [
					'code' => 'UNAVAILABLE',
					'message' => $this->_('Console command runtime is unavailable because trk/processwire-console is not installed.'),
				],
				'rawOutput' => '',
				'stdout' => '',
			];
		}

		$pw = $this->wire();
		if ($pw instanceof ProcessWire) {
			ProcessWire::setCurrentInstance($pw);
		}

		$parameters['--json'] = true;

		/** @var Command $command */
		$command = new $commandClass();
		$input = new ArrayInput($parameters);
		$input->setInteractive(false);
		$output = new BufferedOutput();

		ob_start();
		try {
			$exitCode = $command->run($input, $output);
			$stdout = trim((string) ob_get_clean());
		} catch (\Throwable $e) {
			$stdout = trim((string) ob_get_clean());

			return [
				'ok' => false,
				'exitCode' => 1,
				'data' => [],
				'error' => [
					'code' => 'EXCEPTION',
					'message' => $e->getMessage(),
				],
				'rawOutput' => '',
				'stdout' => $stdout,
			];
		}

		$rawOutput = trim($output->fetch());
		$decoded = $rawOutput !== '' ? json_decode($rawOutput, true) : null;

		if (is_array($decoded) && array_key_exists('ok', $decoded)) {
			$ok = (bool) $decoded['ok'] && $exitCode === 0;
			$data = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [];
			$error = null;
			if (isset($decoded['error']) && is_array($decoded['error'])) {
				$error = [
					'code' => (string) ($decoded['error']['code'] ?? 'ERROR'),
					'message' => (string) ($decoded['error']['message'] ?? $this->_('Command execution failed.')),
				];
			}

			return [
				'ok' => $ok,
				'exitCode' => $exitCode,
				'data' => $data,
				'error' => $error,
				'rawOutput' => $rawOutput,
				'stdout' => $stdout,
			];
		}

		return [
			'ok' => $exitCode === 0,
			'exitCode' => $exitCode,
			'data' => [],
			'error' => $exitCode === 0 ? null : [
				'code' => 'COMMAND_FAILED',
				'message' => $rawOutput !== '' ? $rawOutput : $this->_('Command execution failed.'),
			],
			'rawOutput' => $rawOutput,
			'stdout' => $stdout,
		];
	}

	/**
	 * Inspect Composer environment and installed status for trk/processwire-console and trk/processwire-boost.
	 *
	 * @return array{
	 *   rootPath: string,
	 *   hasComposerJson: bool,
	 *   hasVendorDir: bool,
	 *   hasAutoload: bool,
	 *   hasWireBinary: bool,
	 *   wireBinaryPath: string,
	 *   console: array{installed: bool, version: ?string, declared: ?string, package: string, repo: string, command: string},
	 *   boost: array{installed: bool, version: ?string, declared: ?string, package: string, repo: string, command: string},
	 *   allReady: bool
	 * }
	 */
	public function getPackagesStatus(): array
	{
		if ($this->packagesStatusCache !== null) {
			/** @var array{rootPath: string, hasComposerJson: bool, hasVendorDir: bool, hasAutoload: bool, hasWireBinary: bool, wireBinaryPath: string, console: array{installed: bool, version: ?string, declared: ?string, package: string, repo: string, command: string}, boost: array{installed: bool, version: ?string, declared: ?string, package: string, repo: string, command: string}, allReady: bool} */
			return $this->packagesStatusCache;
		}

		$rootPath = $this->getProjectRootPath();
		$composerJsonPath = $rootPath . 'composer.json';
		$vendorDir = $rootPath . 'vendor';
		$autoloadPath = $vendorDir . '/autoload.php';
		$installedJsonPath = $vendorDir . '/composer/installed.json';
		$wireBin = $vendorDir . '/bin/wire';
		$wireBinBat = $vendorDir . '/bin/wire.bat';

		$hasComposerJson = is_file($composerJsonPath);
		$hasVendorDir = is_dir($vendorDir);
		$hasAutoload = is_file($autoloadPath);
		$hasWireBinary = is_file($wireBin) || is_file($wireBinBat);

		$declared = [];
		if ($hasComposerJson) {
			$raw = @file_get_contents($composerJsonPath);
			if (is_string($raw) && $raw !== '') {
				$decoded = json_decode($raw, true);
				if (is_array($decoded)) {
					$req = is_array($decoded['require'] ?? null) ? $decoded['require'] : [];
					$reqDev = is_array($decoded['require-dev'] ?? null) ? $decoded['require-dev'] : [];
					$declared = array_merge($req, $reqDev);
				}
			}
		}

		$installedVersions = [];
		if (is_file($installedJsonPath)) {
			$raw = @file_get_contents($installedJsonPath);
			if (is_string($raw) && $raw !== '') {
				$decoded = json_decode($raw, true);
				if (is_array($decoded)) {
					$packages = $decoded['packages'] ?? $decoded;
					if (is_array($packages)) {
						foreach ($packages as $pkg) {
							if (is_array($pkg) && isset($pkg['name']) && is_string($pkg['name'])) {
								$installedVersions[$pkg['name']] = isset($pkg['version']) && is_string($pkg['version'])
									? $pkg['version']
									: null;
							}
						}
					}
				}
			}
		}

		$consoleInstalled = array_key_exists('trk/processwire-console', $installedVersions)
			|| is_dir($vendorDir . '/trk/processwire-console');
		$boostInstalled = array_key_exists('trk/processwire-boost', $installedVersions)
			|| is_dir($vendorDir . '/trk/processwire-boost');

		return $this->packagesStatusCache = [
			'rootPath' => $rootPath,
			'hasComposerJson' => $hasComposerJson,
			'hasVendorDir' => $hasVendorDir,
			'hasAutoload' => $hasAutoload,
			'hasWireBinary' => $hasWireBinary,
			'wireBinaryPath' => $wireBin,
			'console' => [
				'installed' => $consoleInstalled,
				'version' => $installedVersions['trk/processwire-console'] ?? ($consoleInstalled ? 'installed' : null),
				'declared' => isset($declared['trk/processwire-console']) ? (string) $declared['trk/processwire-console'] : null,
				'package' => 'trk/processwire-console',
				'repo' => 'https://github.com/trk/processwire-console',
				'command' => 'composer require trk/processwire-console',
			],
			'boost' => [
				'installed' => $boostInstalled,
				'version' => $installedVersions['trk/processwire-boost'] ?? ($boostInstalled ? 'installed' : null),
				'declared' => isset($declared['trk/processwire-boost']) ? (string) $declared['trk/processwire-boost'] : null,
				'package' => 'trk/processwire-boost',
				'repo' => 'https://github.com/trk/processwire-boost',
				'command' => 'composer require --dev trk/processwire-boost',
			],
			'allReady' => $consoleInstalled && $hasWireBinary,
		];
	}

	/**
	 * Gather high-level KPI metrics across all subsystems for the Overview dashboard.
	 *
	 * @param ?array<string, mixed> $migrationsStatus
	 * @param ?list<array{name: string, sizeBytes: int, sizeFormatted: string, modified: int, modifiedFormatted: string}> $backups
	 * @param ?array<string, mixed> $testingEnvironment
	 * @param ?array<string, mixed> $boostStatus
	 * @param ?list<array{name: string, class: string, path: string}> $seeders
	 * @param ?list<array{name: string, class: string, path: string, expression: string, description: string, isDue: bool, nextRun: string, lastTriggered: string}> $scheduledTasks
	 * @return array{
	 *   packages: array<string, mixed>,
	 *   versions: array{php: string, processwire: string, database: string, inertia: string, workspace: string, console: string, boost: string},
	 *   migrations: array{tableExists: bool, total: int, ran: int, pending: int, lastBatch: int},
	 *   queues: array{tablesExist: bool, queuedCount: int, failedCount: int, discoveredCount: int},
	 *   seeding: array{discoveredCount: int, hasFaker: bool},
	 *   scheduling: array{discoveredCount: int, dueCount: int},
	 *   backup: array{totalCount: int, latestFile: ?string, latestTime: ?string, writable: bool},
	 *   testing: array{ready: bool, testFileCount: int, e2eReady: bool, e2eFileCount: int},
	 *   boost: array{available: bool, hasConfig: bool, skillCount: int, agentCount: int, moduleCount: int, enabledModuleCount: int},
	 *   maintenance: array{isDown: bool, file: string, status: int, retry: ?int, redirect: ?string, hasSecret: bool, sinceFormatted: ?string}
	 * }
	 */
	public function getOverviewTelemetry(
		?array $migrationsStatus = null,
		?array $backups = null,
		?array $testingEnvironment = null,
		?array $boostStatus = null,
		?array $seeders = null,
		?array $scheduledTasks = null,
	): array {
		$packages = $this->getPackagesStatus();
		$versions = $this->getVersionMatrix();
		$migStatus = $migrationsStatus ?? $this->getMigrationsStatus();
		$migrations = [
			'tableExists' => (bool) $migStatus['tableExists'],
			'total' => (int) $migStatus['total'],
			'ran' => (int) $migStatus['ran'],
			'pending' => (int) $migStatus['pending'],
			'lastBatch' => (int) $migStatus['lastBatch'],
		];
		$queues = $this->getQueuesSummary();
		$resolvedSeeders = $seeders ?? $this->getDiscoveredSeeders();
		$tasks = $scheduledTasks ?? $this->getScheduledTasks();
		$dueCount = 0;
		foreach ($tasks as $task) {
			if ($task['isDue']) {
				$dueCount++;
			}
		}

		$canViewBackups = $this->userCanView(self::PERMISSION_BACKUP);
		$resolvedBackups = $canViewBackups ? ($backups ?? $this->listBackups(1000)) : [];
		$latestBackup = $resolvedBackups[0] ?? null;
		$backupDir = $this->getBackupDirectory();
		$testingEnv = $testingEnvironment ?? $this->getTestingEnvironment();
		$boost = $boostStatus ?? $this->getBoostStatus();
		$maintenance = $this->getMaintenanceStatus();

		return [
			'packages' => $packages,
			'versions' => $versions,
			'migrations' => $migrations,
			'queues' => $queues,
			'seeding' => [
				'discoveredCount' => count($resolvedSeeders),
				'hasFaker' => $this->hasFaker(),
			],
			'scheduling' => [
				'discoveredCount' => count($tasks),
				'dueCount' => $dueCount,
			],
			'backup' => [
				'totalCount' => count($resolvedBackups),
				'latestFile' => $canViewBackups ? ($latestBackup['name'] ?? null) : null,
				'latestTime' => $canViewBackups ? ($latestBackup['modifiedFormatted'] ?? null) : null,
				'writable' => is_dir($backupDir) && is_writable($backupDir),
			],
			'testing' => [
				'ready' => (bool) $testingEnv['ready'],
				'testFileCount' => (int) $testingEnv['testFileCount'],
				'e2eReady' => (bool) ($testingEnv['e2eReady'] ?? false),
				'e2eFileCount' => (int) ($testingEnv['e2eFileCount'] ?? 0),
			],
			'boost' => [
				'available' => (bool) $boost['available'],
				'hasConfig' => (bool) $boost['hasConfig'],
				'skillCount' => count($boost['installedSkills']),
				'agentCount' => count($boost['config']['agents']),
				'moduleCount' => count($boost['discoverableModules']),
				'enabledModuleCount' => (int) $boost['enabledModuleCount'],
			],
			'maintenance' => $maintenance,
		];
	}

	/**
	 * Build the complete structured payload for the WireConsole Inertia React workspace.
	 *
	 * @param string $activeTab
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 */
	public function buildWorkspacePayload(string $activeTab = 'overview', array $options = []): array
	{
		$accessibleTabs = $this->getAccessibleTabs();
		if ($accessibleTabs !== [] && !isset($accessibleTabs[$activeTab])) {
			$activeTab = (string) array_key_first($accessibleTabs);
		}

		$canViewBackups = $this->userCanView(self::PERMISSION_BACKUP);
		$migrationsStatus = $this->getMigrationsStatus();
		$allBackups = $canViewBackups ? $this->listBackups(1000) : [];
		$testingEnvironment = $this->getTestingEnvironment();
		$boostStatus = $this->getBoostStatus();
		$seeders = $this->getDiscoveredSeeders();
		$scheduledTasks = $this->getScheduledTasks();

		$overview = $this->getOverviewTelemetry(
			migrationsStatus: $migrationsStatus,
			backups: $allBackups,
			testingEnvironment: $testingEnvironment,
			boostStatus: $boostStatus,
			seeders: $seeders,
			scheduledTasks: $scheduledTasks,
		);
		$logChannels = $this->userCanView(self::PERMISSION_LOGS) ? $this->getLogChannels() : [];
		$selectedChannel = $this->payloadString($options, 'logChannel');
		if ($selectedChannel === '' && $logChannels !== []) {
			$selectedChannel = (string) ($logChannels[0]['name'] ?? self::LOG_CHANNEL);
		} elseif ($selectedChannel === '') {
			$selectedChannel = self::LOG_CHANNEL;
		}
		$logSearch = $this->payloadString($options, 'logSearch');
		$logLimit = max(10, min(250, $this->payloadInt($options, 'logLimit', 50)));

		$inspectCategory = $this->payloadString($options, 'inspectCategory');
		$inspectId = $this->payloadString($options, 'inspectId');
		$inspectedFile = null;
		if ($inspectCategory !== '' && $inspectId !== '') {
			$inspectedFile = $this->inspectProjectFile($inspectCategory, $inspectId);
		}

		$isSuperuser = $this->isSuperuser();
		$payload = [
			'activeTab' => $activeTab,
			'tabs' => $accessibleTabs,
			'isSuperuser' => $isSuperuser,
			'isDemoMode' => $this->isDemoMode(),
			'actionUrl' => $this->payloadString($options, 'actionUrl'),
			'downloadBackupUrl' => $this->payloadString($options, 'downloadBackupUrl'),
			'feedback' => $options['feedback'] ?? null,
			'inspectedFile' => $inspectedFile,
			'overview' => $overview,
			'migrations' => $this->userCanView(self::PERMISSION_MIGRATIONS)
				? $migrationsStatus
				: null,
			'queues' => $this->userCanView(self::PERMISSION_QUEUES)
				? $this->getQueuesStatus(50)
				: null,
			'seeding' => $this->userCanView(self::PERMISSION_SEEDING)
				? [
					'hasFaker' => $overview['seeding']['hasFaker'],
					'seeders' => $seeders,
				]
				: null,
			'scheduling' => $this->userCanView(self::PERMISSION_SCHEDULING)
				? [
					'tasks' => $scheduledTasks,
				]
				: null,
			'backup' => $canViewBackups
				? [
					'directory' => $this->getBackupDirectory(),
					'writable' => $overview['backup']['writable'],
					'items' => array_slice($allBackups, 0, 50),
				]
				: null,
			'testing' => $this->userCanView(self::PERMISSION_TESTING)
				? [
					'environment' => $testingEnvironment,
					'files' => $this->getDiscoveredTestFiles(),
					'lastResult' => $options['testResult'] ?? null,
				]
				: null,
			'boost' => $this->userCanView(self::PERMISSION_BOOST)
				? [
					'status' => $boostStatus,
					'mcp' => $this->getMcpServerDiagnostics(),
					'readOnlyTools' => $this->getReadOnlyMcpToolNames(),
					'mcpResult' => $options['mcpResult'] ?? null,
				]
				: null,
			'logs' => $this->userCanView(self::PERMISSION_LOGS)
				? [
					'channels' => $logChannels,
					'selectedChannel' => $selectedChannel,
					'search' => $logSearch,
					'limit' => $logLimit,
					'entries' => $this->readLogEntries($selectedChannel, $logLimit, $logSearch),
				]
				: null,
		];

		return $isSuperuser ? $payload : $this->redactWorkspacePathsForNonSuperuser($payload);
	}

	/**
	 * Convert absolute host filesystem paths into project-relative paths before serializing Inertia props for non-superusers.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	protected function redactWorkspacePathsForNonSuperuser(array $payload): array
	{
		if (isset($payload['overview']) && is_array($payload['overview'])) {
			if (isset($payload['overview']['packages']) && is_array($payload['overview']['packages'])) {
				$payload['overview']['packages']['rootPath'] = '/';
				$payload['overview']['packages']['wireBinaryPath'] = 'vendor/bin/wire';
			}
			if (isset($payload['overview']['maintenance']) && is_array($payload['overview']['maintenance'])) {
				$payload['overview']['maintenance']['file'] = 'site/down.json';
			}
		}

		if (isset($payload['backup']) && is_array($payload['backup'])) {
			$payload['backup']['directory'] = 'site/assets/backups/database/';
		}

		if (isset($payload['queues']) && is_array($payload['queues'])) {
			$rootPathWithSlash = $this->getProjectRootPath();
			$rootPathNoSlash = rtrim($rootPathWithSlash, '/');
			$stripHostRoot = static function (string $value) use ($rootPathWithSlash, $rootPathNoSlash): string {
				if ($rootPathWithSlash !== '') {
					$value = str_replace($rootPathWithSlash, '', $value);
				}
				if ($rootPathNoSlash !== '') {
					$value = str_replace($rootPathNoSlash, '', $value);
				}

				return $value;
			};

			if (isset($payload['queues']['discoveredQueues']) && is_array($payload['queues']['discoveredQueues'])) {
				foreach ($payload['queues']['discoveredQueues'] as $idx => $item) {
					if (is_array($item) && isset($item['path']) && is_string($item['path'])) {
						$payload['queues']['discoveredQueues'][$idx]['path'] = $this->toProjectRelativePath($item['path']);
					}
				}
			}

			if (isset($payload['queues']['pendingJobs']) && is_array($payload['queues']['pendingJobs'])) {
				foreach ($payload['queues']['pendingJobs'] as $idx => $item) {
					if (is_array($item)) {
						$payload['queues']['pendingJobs'][$idx]['payloadFormatted'] = '';
					}
				}
			}

			if (isset($payload['queues']['failedJobs']) && is_array($payload['queues']['failedJobs'])) {
				foreach ($payload['queues']['failedJobs'] as $idx => $item) {
					if (is_array($item)) {
						if (isset($item['exceptionPreview']) && is_string($item['exceptionPreview'])) {
							$payload['queues']['failedJobs'][$idx]['exceptionPreview'] = $stripHostRoot($item['exceptionPreview']);
						}
						if (isset($item['exceptionFull']) && is_string($item['exceptionFull'])) {
							$payload['queues']['failedJobs'][$idx]['exceptionFull'] = $stripHostRoot($item['exceptionFull']);
						}
						$payload['queues']['failedJobs'][$idx]['payloadFormatted'] = '';
					}
				}
			}
		}

		if (isset($payload['seeding']['seeders']) && is_array($payload['seeding']['seeders'])) {
			foreach ($payload['seeding']['seeders'] as $idx => $item) {
				if (is_array($item) && isset($item['path']) && is_string($item['path'])) {
					$payload['seeding']['seeders'][$idx]['path'] = $this->toProjectRelativePath($item['path']);
				}
			}
		}

		if (isset($payload['scheduling']['tasks']) && is_array($payload['scheduling']['tasks'])) {
			foreach ($payload['scheduling']['tasks'] as $idx => $item) {
				if (is_array($item) && isset($item['path']) && is_string($item['path'])) {
					$payload['scheduling']['tasks'][$idx]['path'] = $this->toProjectRelativePath($item['path']);
				}
			}
		}

		if (isset($payload['testing']['files']) && is_array($payload['testing']['files'])) {
			foreach ($payload['testing']['files'] as $idx => $item) {
				if (is_array($item) && isset($item['relativePath']) && is_string($item['relativePath'])) {
					$payload['testing']['files'][$idx]['path'] = $item['relativePath'];
				}
			}
		}

		return $payload;
	}

	// =========================================================================
	// SECURITY GUARDS: RATE LIMITING & AUDIT LOGGING
	// =========================================================================

	/**
	 * Enforce a per-session cooldown window for mutating operations.
	 *
	 * @return array{allowed: bool, retryAfter: int}
	 */
	public function checkRateLimit(string $actionKey, int $cooldownSeconds): array
	{
		if ($cooldownSeconds <= 0) {
			return ['allowed' => true, 'retryAfter' => 0];
		}

		$session = $this->wire()->session;
		$storageKey = 'wc_ratelimit_' . preg_replace('/[^a-z0-9_]/i', '_', $actionKey);
		$lastRun = (int) ($session->get($storageKey) ?? 0);
		$now = time();

		if ($lastRun > 0 && ($now - $lastRun) < $cooldownSeconds) {
			return [
				'allowed' => false,
				'retryAfter' => $cooldownSeconds - ($now - $lastRun),
			];
		}

		$session->set($storageKey, $now);

		return ['allowed' => true, 'retryAfter' => 0];
	}

	/**
	 * Record a structured JSON audit entry in the wire-console-audit ProcessWire log channel.
	 *
	 * @param array<string, mixed> $context
	 */
	public function audit(string $action, string $status, array $context = []): void
	{
		try {
			$user = $this->wire()->user;
			$payload = [
				'action' => $action,
				'status' => $status,
				'user' => $user->id ? (string) $user->name : 'guest',
				'userId' => $user->id ? (int) $user->id : 0,
				'ip' => (string) $this->wire()->session->getIP(),
				'context' => $this->redactContext($context),
			];
			$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if (is_string($json)) {
				$this->wire()->log->save(self::LOG_CHANNEL, $json, [
					'url' => $this->resolveSafeLogUrl(),
				]);
			}
		} catch (\Throwable) {
			// Audit logging must never crash the request
		}
	}

	/**
	 * Build a scalar-safe request URL for WireLog so array query parameters never trigger PHP warnings.
	 */
	protected function resolveSafeLogUrl(): string
	{
		$input = $this->wire()->input;
		$sanitizer = $this->wire()->sanitizer;
		$url = (string) $input->httpUrl();
		if ($url === '') {
			return '?';
		}

		if ($input->get->count() > 0) {
			$pairs = [];
			foreach ($input->get as $k => $v) {
				$cleanKey = $sanitizer->name((string) $k);
				if ($cleanKey === '' || !is_scalar($v)) {
					continue;
				}
				$cleanVal = rawurlencode($sanitizer->text((string) $v));
				$pairs[] = $cleanKey . '=' . $cleanVal;
			}
			if ($pairs !== []) {
				$url .= '?' . implode('&', $pairs);
			}
		}

		return strlen($url) > 500 ? substr($url, 0, 500) : $url;
	}

	/**
	 * Redact sensitive keys from audit log and queue inspection payloads.
	 *
	 * @param array<array-key, mixed> $context
	 * @return array<array-key, mixed>
	 */
	protected function redactContext(array $context): array
	{
		$redacted = [];
		foreach ($context as $key => $value) {
			if (preg_match('/(pass|secret|token|key|auth|pwd)/i', (string) $key)) {
				$redacted[$key] = '[REDACTED]';
			} elseif (is_array($value)) {
				$redacted[$key] = $this->redactContext($value);
			} elseif (is_scalar($value) || $value === null) {
				$str = (string) $value;
				$redacted[$key] = mb_strlen($str) > 300 ? mb_substr($str, 0, 297) . '...' : $value;
			}
		}

		return $redacted;
	}

	/**
	 * Format a byte count into a human-readable string (KB, MB, GB).
	 */
	protected function formatBytes(int $bytes): string
	{
		if ($bytes < 1024) {
			return $bytes . ' B';
		}
		$kb = $bytes / 1024;
		if ($kb < 1024) {
			return number_format($kb, 1) . ' KB';
		}
		$mb = $kb / 1024;
		if ($mb < 1024) {
			return number_format($mb, 2) . ' MB';
		}

		return number_format($mb / 1024, 2) . ' GB';
	}
}
