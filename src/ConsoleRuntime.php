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
 * - Pest test execution (vendor/bin/wire test)
 * - Boost & MCP Server diagnostics (Totoglu\Console\Boost\BoostManager & McpServer)
 * - RBAC permission checks, session rate limiting, and audit logging
 */
class ConsoleRuntime extends Wire
{
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

	/** @var array<string, mixed>|null */
	protected ?array $packagesStatusCache = null;

	/** @var array{php: string, processwire: string, database: string, htmx: string, wirekit: string, console: string, boost: string}|null */
	protected ?array $versionMatrixCache = null;

	/** @var list<array{id: string, name: string, relativePath: string, path: string, suite: string}>|null */
	protected ?array $discoveredTestFilesCache = null;

	protected ?FeatureDiscoverer $discovererInstance = null;

	protected ?Migrator $migratorInstance = null;

	protected ?BoostManager $boostManagerInstance = null;

	/** @var list<array{name: string, path: string}>|null */
	protected ?array $discoveredQueuesCache = null;

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
		return [
			'overview' => [
				'label' => $this->_('Overview'),
				'icon' => 'dashboard',
				'permission' => self::PERMISSION_VIEW,
				'description' => $this->_('System readiness, maintenance mode, cache controls, and operational telemetry.'),
			],
			'migrations' => [
				'label' => $this->_('Migrations'),
				'icon' => 'database',
				'permission' => self::PERMISSION_MIGRATIONS,
				'description' => $this->_('Track, scaffold, and execute timestamped ProcessWire schema migrations.'),
			],
			'queues' => [
				'label' => $this->_('Queues'),
				'icon' => 'refresh',
				'permission' => self::PERMISSION_QUEUES,
				'description' => $this->_('Monitor background queue jobs, scaffold handlers, and trigger retries.'),
			],
			'seeding' => [
				'label' => $this->_('Seeding'),
				'icon' => 'bolt',
				'permission' => self::PERMISSION_SEEDING,
				'description' => $this->_('Discover, scaffold, and execute database seeders in site/seeders/.'),
			],
			'scheduling' => [
				'label' => $this->_('Scheduling'),
				'icon' => 'clock-o',
				'permission' => self::PERMISSION_SCHEDULING,
				'description' => $this->_('Inspect cron task schedules in site/schedule/, scaffold tasks, and trigger manual runs.'),
			],
			'backup' => [
				'label' => $this->_('Backups'),
				'icon' => 'hdd-o',
				'permission' => self::PERMISSION_BACKUP,
				'description' => $this->_('Create, download, purge, and restore ProcessWire SQL backups.'),
			],
			'testing' => [
				'label' => $this->_('Testing'),
				'icon' => 'check-circle',
				'permission' => self::PERMISSION_TESTING,
				'description' => $this->_('Scaffold and run Pest PHP test suites via vendor/bin/wire test.'),
			],
			'boost' => [
				'label' => $this->_('Boost & AI'),
				'icon' => 'rocket',
				'permission' => self::PERMISSION_BOOST,
				'description' => $this->_('Inspect and synchronize ProcessWire Boost AI guidelines, skills, and MCP configuration.'),
			],
			'logs' => [
				'label' => $this->_('Logs & Audit'),
				'icon' => 'list-alt',
				'permission' => self::PERMISSION_LOGS,
				'description' => $this->_('Inspect Console audit logs (wire-console-audit) and ProcessWire application log channels.'),
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
	 * Check if the current user can view a panel requiring the given permission.
	 */
	public function userCanView(string $permission): bool
	{
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

		return $user->hasPermission($permission);
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
	 * Check whether the current user has permission to inspect files in the given category.
	 *
	 * Backups require both superuser status and the backup permission (matching backup download);
	 * all other categories enforce the permission of the tab that owns them.
	 */
	public function userCanInspectCategory(string $category): bool
	{
		return match (strtolower(trim($category))) {
			'backup' => $this->isSuperuser() && $this->userCanView(self::PERMISSION_BACKUP),
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
		$root = (string) ($this->wire()->config?->paths?->root ?? '');
		if ($root !== '' && is_dir($root)) {
			return rtrim(str_replace('\\', '/', $root), '/') . '/';
		}

		return rtrim(str_replace('\\', '/', dirname(__DIR__, 4)), '/') . '/';
	}

	/**
	 * Ensure the project's Composer autoloader is loaded if available.
	 */
	protected function ensureAutoload(): bool
	{
		if (class_exists(Migrator::class)) {
			return true;
		}

		$autoload = $this->getProjectRootPath() . 'vendor/autoload.php';
		if (is_file($autoload)) {
			require_once $autoload;
		}

		return class_exists(Migrator::class);
	}

	/**
	 * Instantiate a FeatureDiscoverer connected to the current ProcessWire instance.
	 */
	protected function createDiscoverer(): ?FeatureDiscoverer
	{
		if ($this->discovererInstance !== null) {
			return $this->discovererInstance;
		}
		if (!$this->ensureAutoload() || !class_exists(FeatureDiscoverer::class)) {
			return null;
		}
		$pw = $this->wire();
		if (!$pw) {
			return null;
		}

		return $this->discovererInstance = new FeatureDiscoverer($pw);
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
		if (!$this->ensureAutoload() || !class_exists($commandClass) || !class_exists(ArrayInput::class) || !class_exists(BufferedOutput::class)) {
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

		$this->ensureAutoload();
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
			|| class_exists(Migrator::class);
		$boostInstalled = array_key_exists('trk/processwire-boost', $installedVersions)
			|| class_exists(BoostManager::class)
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
	 * @return array{
	 *   packages: array<string, mixed>,
	 *   versions: array{php: string, processwire: string, database: string, htmx: string, wirekit: string, console: string, boost: string},
	 *   migrations: array{tableExists: bool, total: int, ran: int, pending: int, lastBatch: int},
	 *   queues: array{tablesExist: bool, queuedCount: int, failedCount: int, discoveredCount: int},
	 *   seeding: array{discoveredCount: int, hasFaker: bool},
	 *   scheduling: array{discoveredCount: int, dueCount: int},
	 *   backup: array{totalCount: int, latestFile: ?string, latestTime: ?string, writable: bool},
	 *   testing: array{ready: bool, testFileCount: int},
	 *   boost: array{available: bool, hasConfig: bool, skillCount: int, agentCount: int, moduleCount: int, enabledModuleCount: int},
	 *   maintenance: array{isDown: bool, file: string, status: int, retry: ?int, redirect: ?string, hasSecret: bool, sinceFormatted: ?string}
	 * }
	 */
	public function getOverviewTelemetry(): array
	{
		$packages = $this->getPackagesStatus();
		$versions = $this->getVersionMatrix();
		$migStatus = $this->getMigrationsStatus();
		$migrations = [
			'tableExists' => $migStatus['tableExists'],
			'total' => $migStatus['total'],
			'ran' => $migStatus['ran'],
			'pending' => $migStatus['pending'],
			'lastBatch' => $migStatus['lastBatch'],
		];
		$queues = $this->getQueuesSummary();
		$seeders = $this->getDiscoveredSeeders();
		$tasks = $this->getScheduledTasks();
		$dueCount = 0;
		foreach ($tasks as $task) {
			if ($task['isDue']) {
				$dueCount++;
			}
		}

		$backups = $this->listBackups(1000);
		$latestBackup = $backups[0] ?? null;
		$backupDir = $this->getBackupDirectory();
		$testingEnv = $this->getTestingEnvironment();
		$boost = $this->getBoostStatus();
		$maintenance = $this->getMaintenanceStatus();

		return [
			'packages' => $packages,
			'versions' => $versions,
			'migrations' => $migrations,
			'queues' => $queues,
			'seeding' => [
				'discoveredCount' => count($seeders),
				'hasFaker' => $this->hasFaker(),
			],
			'scheduling' => [
				'discoveredCount' => count($tasks),
				'dueCount' => $dueCount,
			],
			'backup' => [
				'totalCount' => count($backups),
				'latestFile' => $latestBackup['name'] ?? null,
				'latestTime' => $latestBackup['modifiedFormatted'] ?? null,
				'writable' => is_dir($backupDir) && is_writable($backupDir),
			],
			'testing' => [
				'ready' => $testingEnv['ready'],
				'testFileCount' => $testingEnv['testFileCount'],
			],
			'boost' => [
				'available' => $boost['available'],
				'hasConfig' => $boost['hasConfig'],
				'skillCount' => count($boost['installedSkills']),
				'agentCount' => count($boost['config']['agents']),
				'moduleCount' => count($boost['discoverableModules']),
				'enabledModuleCount' => $boost['enabledModuleCount'],
			],
			'maintenance' => $maintenance,
		];
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
		$session = $this->wire()->session;
		if (!$session || $cooldownSeconds <= 0) {
			return ['allowed' => true, 'retryAfter' => 0];
		}

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
				'user' => $user && $user->id ? (string) $user->name : 'guest',
				'userId' => $user && $user->id ? (int) $user->id : 0,
				'ip' => (string) ($this->wire()->session?->getIP() ?? ''),
				'context' => $this->redactContext($context),
			];
			$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if (is_string($json)) {
				$this->wire()->log?->save(self::LOG_CHANNEL, $json);
			}
		} catch (\Throwable) {
			// Audit logging must never crash the request
		}
	}

	/**
	 * Redact sensitive keys from audit log payloads.
	 *
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
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
