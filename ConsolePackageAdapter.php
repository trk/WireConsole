<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * Single integration point between this admin module and the Console package.
 *
 * Keep all direct references to Totoglu\Console\* classes in this adapter so that:
 * - compatibility checks are centralized
 * - ProcessWireConsole module stays stable when the Console package evolves
 */
final class ConsolePackageAdapter
{
	/**
	 * Minimal PHP API surface expected from the Console package.
	 *
	 * @var array<string, string[]>
	 */
	private const REQUIRED_CLASSES = [
		'migrations' => [
			'\\Totoglu\\Console\\Migration\\Migrator',
			'\\Totoglu\\Console\\Migration\\MigrationRepository',
		],
		'queues' => [
			'\\Totoglu\\Console\\Queue\\QueueManager',
		],
		'seeding' => [
			'\\Totoglu\\Console\\Database\\SeederManager',
			'\\Totoglu\\Console\\Database\\Seeder',
		],
		'scheduling' => [
			'\\Totoglu\\Console\\Scheduling\\ScheduleManager',
			'\\Totoglu\\Console\\Scheduling\\Task',
			'\\Totoglu\\Console\\Scheduling\\Event',
		],
	];

	private bool $autoloadEnsured = false;

	public function __construct(private Wire $wire)
	{
	}

	public function ensureAutoload(): bool
	{
		// If any Console package class is already available, autoload is effectively ready.
		if (class_exists(\Totoglu\Console\Migration\Migrator::class, false)) {
			$this->autoloadEnsured = true;
			return true;
		}

		if ($this->autoloadEnsured) {
			return class_exists(\Totoglu\Console\Migration\Migrator::class, false);
		}

		$this->autoloadEnsured = true;

		$root = WireConsoleDependencyChecker::getProjectRootPath($this->wire->config);
		if ($root === '') {
			return false;
		}

		$autoload = $root . '/vendor/autoload.php';
		if (!is_file($autoload)) {
			return false;
		}

		require_once $autoload;

		return class_exists(\Totoglu\Console\Migration\Migrator::class);
	}

	/**
	 * Return the FQCN if it exists (autoload ensured), otherwise null.
	 */
	public function getClass(string $fqcn): ?string
	{
		if (!$this->ensureAutoload()) {
			return null;
		}
		return class_exists($fqcn) ? $fqcn : null;
	}

	/**
	 * Create a scheduler Event instance if available.
	 */
	public function newScheduleEvent(): ?object
	{
		$eventClass = $this->getClass('\\Totoglu\\Console\\Scheduling\\Event');
		if (!$eventClass) {
			return null;
		}
		try {
			return new $eventClass();
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @return array{migrator: \Totoglu\Console\Migration\Migrator, repository: \Totoglu\Console\Migration\MigrationRepository}|null
	 */
	public function getMigrationEngine(): ?array
	{
		if (!$this->ensureAutoload()) {
			return null;
		}

		if (!class_exists(\Totoglu\Console\Migration\Migrator::class) || !class_exists(\Totoglu\Console\Migration\MigrationRepository::class)) {
			return null;
		}

		try {
			$repository = new \Totoglu\Console\Migration\MigrationRepository();
			$migrator = new \Totoglu\Console\Migration\Migrator($repository);
			return [
				'repository' => $repository,
				'migrator' => $migrator,
			];
		} catch (\Throwable) {
			return null;
		}
	}

	public function getQueueManager(): ?\Totoglu\Console\Queue\QueueManager
	{
		if (!$this->ensureAutoload()) {
			return null;
		}

		if (!class_exists(\Totoglu\Console\Queue\QueueManager::class)) {
			return null;
		}

		try {
			return new \Totoglu\Console\Queue\QueueManager();
		} catch (\Throwable) {
			return null;
		}
	}

	public function getSeederManager(): ?\Totoglu\Console\Database\SeederManager
	{
		if (!$this->ensureAutoload()) {
			return null;
		}

		if (!class_exists(\Totoglu\Console\Database\SeederManager::class)) {
			return null;
		}

		try {
			return new \Totoglu\Console\Database\SeederManager($this->wire);
		} catch (\Throwable) {
			return null;
		}
	}

	public function getScheduleManager(): ?\Totoglu\Console\Scheduling\ScheduleManager
	{
		if (!$this->ensureAutoload()) {
			return null;
		}

		if (!class_exists(\Totoglu\Console\Scheduling\ScheduleManager::class)) {
			return null;
		}

		try {
			return new \Totoglu\Console\Scheduling\ScheduleManager($this->wire);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Return a compatibility/support report for the Console package PHP API.
	 *
	 * @return array{
	 *   autoload: bool,
	 *   features: array<string, array{supported: bool, missing: string[]}>
	 * }
	 */
	public function getSupportMatrix(): array
	{
		$autoload = $this->ensureAutoload();

		$features = [];
		foreach (self::REQUIRED_CLASSES as $feature => $classes) {
			$missing = [];
			if ($autoload) {
				foreach ($classes as $fqcn) {
					if (!class_exists($fqcn)) {
						$missing[] = $fqcn;
					}
				}
			} else {
				$missing = $classes;
			}
			$features[$feature] = [
				'supported' => $autoload && empty($missing),
				'missing' => $missing,
			];
		}

		return [
			'autoload' => $autoload,
			'features' => $features,
		];
	}
}
