<?php

declare(strict_types=1);

namespace Totoglu\WireConsole\Concerns;

use Totoglu\Console\Migration\Migrator;

/**
 * Migration domain operations for ConsoleRuntime.
 */
trait InteractsWithMigrations
{
	/**
	 * Check whether the wire_migrations tracking table exists in the database.
	 */
	public function migrationsTableExists(): bool
	{
		try {
			$db = $this->wire()->database;
			if (is_object($db) && method_exists($db, 'tableExists')) {
				return (bool) $db->tableExists(self::TABLE_MIGRATIONS);
			}
			if ($db) {
				$table = $db->escapeTable(self::TABLE_MIGRATIONS);
				$stmt = $db->prepare('SHOW TABLES LIKE :table');
				$stmt->bindValue(':table', $table, \PDO::PARAM_STR);
				$stmt->execute();

				return $stmt->rowCount() > 0;
			}
		} catch (\Throwable) {
			return false;
		}

		return false;
	}

	/**
	 * Instantiate a Migrator connected to the current ProcessWire instance.
	 */
	protected function createMigrator(): ?Migrator
	{
		if ($this->migratorInstance !== null) {
			return $this->migratorInstance;
		}
		if (!$this->isConsolePackageAvailable()) {
			return null;
		}

		return $this->migratorInstance = new Migrator($this->wire()->database, $this->createDiscoverer());
	}

	/**
	 * Partition discovered migration files into verified in-project regular files and unsafe/symlinked entries.
	 *
	 * @return array{verified: array<string, string>, unsafe: list<string>}
	 */
	protected function partitionMigrationFiles(): array
	{
		$discoverer = $this->createDiscoverer();
		if ($discoverer === null) {
			return ['verified' => [], 'unsafe' => []];
		}

		$realRoot = realpath($this->getProjectRootPath());
		if ($realRoot === false) {
			return ['verified' => [], 'unsafe' => []];
		}
		$normalizedRoot = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';

		$siteMigrationsDir = rtrim($this->getProjectRootPath(), '/') . '/site/migrations';
		if (is_link($siteMigrationsDir)) {
			return ['verified' => [], 'unsafe' => ['site/migrations']];
		}

		$verified = [];
		$unsafe = [];
		foreach ($discoverer->discoverFiles('migrations', '*.php', null) as $migKey => $filePath) {
			$name = trim((string) $migKey);
			if ($name === '' || $filePath === '' || is_link(dirname($filePath)) || is_link($filePath)) {
				$unsafe[] = $name !== '' ? $name : basename($filePath);
				continue;
			}
			$realFile = realpath($filePath);
			if ($realFile === false || !is_file($realFile)) {
				$unsafe[] = $name;
				continue;
			}
			$normalizedFile = str_replace('\\', '/', $realFile);
			if (!str_starts_with($normalizedFile, $normalizedRoot)) {
				$unsafe[] = $name;
				continue;
			}
			$verified[$name] = $realFile;
		}

		return ['verified' => $verified, 'unsafe' => $unsafe];
	}

	/**
	 * Create the wire_migrations table if it does not exist.
	 *
	 * @return array{ok: bool, created: bool, message: string}
	 */
	public function installMigrationsTable(): array
	{
		$migrator = $this->createMigrator();
		if ($migrator === null) {
			return [
				'ok' => false,
				'created' => false,
				'message' => $this->_('Migration engine is unavailable because trk/processwire-console is not installed.'),
			];
		}

		try {
			$created = $migrator->install();

			return [
				'ok' => true,
				'created' => $created,
				'message' => $created
					? $this->_('Created the wire_migrations tracking table.')
					: $this->_('The wire_migrations tracking table already exists.'),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'created' => false,
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Return full migration status list and summary counters.
	 *
	 * @return array{
	 *   available: bool,
	 *   tableExists: bool,
	 *   total: int,
	 *   ran: int,
	 *   pending: int,
	 *   lastBatch: int,
	 *   rollbackPreview: list<string>,
	 *   items: list<array{name: string, status: string, batch: ?int, migrated_at: ?string}>
	 * }
	 */
	public function getMigrationsStatus(): array
	{
		$migrator = $this->createMigrator();
		$tableExists = $this->migrationsTableExists();

		if ($migrator === null) {
			return [
				'available' => false,
				'tableExists' => $tableExists,
				'total' => 0,
				'ran' => 0,
				'pending' => 0,
				'lastBatch' => 0,
				'rollbackPreview' => [],
				'items' => [],
			];
		}

		$items = [];
		$ran = 0;
		$pending = 0;
		$lastBatch = 0;
		$rollbackPreview = [];

		try {
			if ($tableExists) {
				try {
					$rollbackPreview = array_values(array_map('strval', $migrator->getPendingRollback(0)));
				} catch (\Throwable) {
					$rollbackPreview = [];
				}

				$appliedAtMap = [];
				$db = $this->wire()->database;
				if ($db) {
					try {
						$table = $db->escapeTable(self::TABLE_MIGRATIONS);
						$stmt = $db->query("SELECT `migration`, `applied_at` FROM `{$table}`");
						if ($stmt !== false) {
							while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
								if (isset($row['migration'])) {
									$appliedAtMap[(string) $row['migration']] = isset($row['applied_at'])
										? (string) $row['applied_at']
										: null;
								}
							}
						}
					} catch (\Throwable) {
						// Ignore timestamp lookup error
					}
				}

				['verified' => $verifiedMap] = $this->partitionMigrationFiles();
				$rawItems = $migrator->getStatus();
				foreach ($rawItems as $item) {
					$rawStatus = (string) ($item['status'] ?? 'pending');
					$migName = (string) ($item['name'] ?? '');
					$isVerifiedOnDisk = isset($verifiedMap[$migName]);
					if ($rawStatus === 'pending' && !$isVerifiedOnDisk) {
						continue;
					}
					$isApplied = $rawStatus === 'applied' && $isVerifiedOnDisk;
					$isMissing = $rawStatus === 'missing' || ($rawStatus === 'applied' && !$isVerifiedOnDisk);
					$batch = isset($item['batch']) ? (int) $item['batch'] : null;
					if ($isApplied || $isMissing) {
						$ran++;
						if ($batch !== null && $batch > $lastBatch) {
							$lastBatch = $batch;
						}
					} else {
						$pending++;
					}
					$items[] = [
						'name' => $migName,
						'status' => $isApplied ? 'Ran' : ($isMissing ? 'Missing' : 'Pending'),
						'batch' => $batch,
						'migrated_at' => $appliedAtMap[$migName] ?? null,
					];
				}
			} else {
				['verified' => $verifiedMap] = $this->partitionMigrationFiles();
				foreach (array_keys($verifiedMap) as $migName) {
					$pending++;
					$items[] = [
						'name' => $migName,
						'status' => 'Pending',
						'batch' => null,
						'migrated_at' => null,
					];
				}
			}
		} catch (\Throwable) {
			// Fall through with collected items
		}

		return [
			'available' => true,
			'tableExists' => $tableExists,
			'total' => $ran + $pending,
			'ran' => $ran,
			'pending' => $pending,
			'lastBatch' => $lastBatch,
			'rollbackPreview' => $rollbackPreview,
			'items' => $items,
		];
	}

	/**
	 * Run pending migrations.
	 *
	 * @return array{ok: bool, migrated: list<string>, errors: list<string>, details: string, message: string}
	 */
	public function runMigrations(int $steps = 0): array
	{
		$migrator = $this->createMigrator();
		if ($migrator === null) {
			return [
				'ok' => false,
				'migrated' => [],
				'errors' => [],
				'details' => '',
				'message' => $this->_('Migration engine is unavailable.'),
			];
		}

		['unsafe' => $unsafeMigrations] = $this->partitionMigrationFiles();
		if ($unsafeMigrations !== []) {
			$msg = $this->_('Refusing to execute migrations while symbolic link or out-of-root migration files exist.');

			return [
				'ok' => false,
				'migrated' => [],
				'errors' => [$msg],
				'details' => '',
				'message' => $msg,
			];
		}

		try {
			$result = $migrator->runPending(max(0, $steps));
			$applied = isset($result['applied']) && is_array($result['applied'])
				? array_values(array_map('strval', $result['applied']))
				: [];
			$errors = isset($result['errors']) && is_array($result['errors'])
				? array_values(array_map('strval', $result['errors']))
				: [];
			$count = count($applied);
			$details = $applied !== [] ? implode("\n", $applied) : '';

			if ($errors !== []) {
				return [
					'ok' => false,
					'migrated' => $applied,
					'errors' => $errors,
					'details' => $details,
					'message' => implode('; ', $errors),
				];
			}

			return [
				'ok' => true,
				'migrated' => $applied,
				'errors' => [],
				'details' => $details,
				'message' => $count > 0
					? sprintf($this->_('Successfully executed %d pending migration(s).'), $count)
					: $this->_('Nothing to migrate. All schema migrations are up to date.'),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'migrated' => [],
				'errors' => [$e->getMessage()],
				'details' => '',
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Roll back the last migration batch or a specific number of steps.
	 *
	 * @return array{ok: bool, rolledBack: list<string>, errors: list<string>, details: string, message: string}
	 */
	public function rollbackMigrations(int $steps = 0): array
	{
		$migrator = $this->createMigrator();
		if ($migrator === null) {
			return [
				'ok' => false,
				'rolledBack' => [],
				'errors' => [],
				'details' => '',
				'message' => $this->_('Migration engine is unavailable.'),
			];
		}

		['unsafe' => $unsafeMigrations] = $this->partitionMigrationFiles();
		if ($unsafeMigrations !== []) {
			$msg = $this->_('Refusing to execute migrations while symbolic link or out-of-root migration files exist.');

			return [
				'ok' => false,
				'rolledBack' => [],
				'errors' => [$msg],
				'details' => '',
				'message' => $msg,
			];
		}

		try {
			$result = $steps > 0
				? $migrator->rollbackSteps($steps)
				: $migrator->rollbackLastBatch();
			$rolledBack = isset($result['rolledBack']) && is_array($result['rolledBack'])
				? array_values(array_map('strval', $result['rolledBack']))
				: [];
			$errors = isset($result['errors']) && is_array($result['errors'])
				? array_values(array_map('strval', $result['errors']))
				: [];
			$count = count($rolledBack);
			$details = $rolledBack !== [] ? implode("\n", $rolledBack) : '';

			if ($errors !== []) {
				return [
					'ok' => false,
					'rolledBack' => $rolledBack,
					'errors' => $errors,
					'details' => $details,
					'message' => implode('; ', $errors),
				];
			}

			return [
				'ok' => true,
				'rolledBack' => $rolledBack,
				'errors' => [],
				'details' => $details,
				'message' => $count > 0
					? sprintf($this->_('Rolled back %d migration(s).'), $count)
					: $this->_('No executed migrations found to roll back.'),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'rolledBack' => [],
				'errors' => [$e->getMessage()],
				'details' => '',
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Roll back all applied migrations (`migrate:reset`), reset and re-run (`migrate:refresh`),
	 * or drop the migration tracking table and re-run from scratch (`migrate:fresh`).
	 *
	 * @param string $mode 'reset', 'refresh', or 'fresh'
	 * @return array{ok: bool, rolledBack: list<string>, migrated: list<string>, errors: list<string>, preBackupFile: ?string, details: string, message: string}
	 */
	public function resetMigrations(string $mode = 'reset', bool $createSafetyBackup = false): array
	{
		$migrator = $this->createMigrator();
		if ($migrator === null) {
			return [
				'ok' => false,
				'rolledBack' => [],
				'migrated' => [],
				'errors' => [],
				'preBackupFile' => null,
				'details' => '',
				'message' => $this->_('Migration engine is unavailable.'),
			];
		}

		['unsafe' => $unsafeMigrations] = $this->partitionMigrationFiles();
		if ($unsafeMigrations !== []) {
			$msg = $this->_('Refusing to execute migrations while symbolic link or out-of-root migration files exist.');

			return [
				'ok' => false,
				'rolledBack' => [],
				'migrated' => [],
				'errors' => [$msg],
				'preBackupFile' => null,
				'details' => '',
				'message' => $msg,
			];
		}

		$resolvedMode = strtolower(trim($mode));
		if (!in_array($resolvedMode, ['reset', 'refresh', 'fresh'], true)) {
			$resolvedMode = 'reset';
		}

		$preBackupFile = null;
		if ($createSafetyBackup) {
			$pre = $this->createBackup(sprintf('Pre-migrate-%s safety snapshot', $resolvedMode));
			if (!$pre['ok']) {
				return [
					'ok' => false,
					'rolledBack' => [],
					'migrated' => [],
					'errors' => [$pre['message']],
					'preBackupFile' => null,
					'details' => '',
					'message' => sprintf($this->_('Migration %s aborted because safety snapshot failed: %s'), $resolvedMode, $pre['message']),
				];
			}
			$preBackupFile = $pre['file'];
		}

		$buildDetails = static function (?string $backupFile, array $rbList, array $migList): string {
			$lines = [];
			if ($backupFile !== null) {
				$lines[] = 'Backup: ' . $backupFile;
			}
			foreach ($rbList as $rb) {
				$lines[] = '- ' . $rb;
			}
			foreach ($migList as $mig) {
				$lines[] = '+ ' . $mig;
			}

			return implode("\n", $lines);
		};

		try {
			if ($resolvedMode === 'fresh') {
				$freshResult = $migrator->fresh();
				$migrated = isset($freshResult['applied']) && is_array($freshResult['applied'])
					? array_values(array_map('strval', $freshResult['applied']))
					: [];
				$errors = isset($freshResult['errors']) && is_array($freshResult['errors'])
					? array_values(array_map('strval', $freshResult['errors']))
					: [];
				$details = $buildDetails($preBackupFile, [], $migrated);

				if ($errors !== []) {
					return [
						'ok' => false,
						'rolledBack' => [],
						'migrated' => $migrated,
						'errors' => $errors,
						'preBackupFile' => $preBackupFile,
						'details' => $details,
						'message' => implode('; ', $errors),
					];
				}

				return [
					'ok' => true,
					'rolledBack' => [],
					'migrated' => $migrated,
					'errors' => [],
					'preBackupFile' => $preBackupFile,
					'details' => $details,
					'message' => sprintf(
						$this->_('Recreated wire_migrations table and applied %d migration(s) from scratch.'),
						count($migrated)
					),
				];
			}

			$resetResult = $migrator->reset();
			$rolledBack = isset($resetResult['rolledBack']) && is_array($resetResult['rolledBack'])
				? array_values(array_map('strval', $resetResult['rolledBack']))
				: [];
			$errors = isset($resetResult['errors']) && is_array($resetResult['errors'])
				? array_values(array_map('strval', $resetResult['errors']))
				: [];

			if ($errors !== []) {
				return [
					'ok' => false,
					'rolledBack' => $rolledBack,
					'migrated' => [],
					'errors' => $errors,
					'preBackupFile' => $preBackupFile,
					'details' => $buildDetails($preBackupFile, $rolledBack, []),
					'message' => implode('; ', $errors),
				];
			}

			$migrated = [];
			if ($resolvedMode === 'refresh') {
				$runResult = $migrator->runPending(0);
				$migrated = isset($runResult['applied']) && is_array($runResult['applied'])
					? array_values(array_map('strval', $runResult['applied']))
					: [];
				$runErrors = isset($runResult['errors']) && is_array($runResult['errors'])
					? array_values(array_map('strval', $runResult['errors']))
					: [];
				$details = $buildDetails($preBackupFile, $rolledBack, $migrated);
				if ($runErrors !== []) {
					return [
						'ok' => false,
						'rolledBack' => $rolledBack,
						'migrated' => $migrated,
						'errors' => $runErrors,
						'preBackupFile' => $preBackupFile,
						'details' => $details,
						'message' => implode('; ', $runErrors),
					];
				}

				return [
					'ok' => true,
					'rolledBack' => $rolledBack,
					'migrated' => $migrated,
					'errors' => [],
					'preBackupFile' => $preBackupFile,
					'details' => $details,
					'message' => sprintf(
						$this->_('Refreshed schema migrations: rolled back %d and applied %d migration(s).'),
						count($rolledBack),
						count($migrated)
					),
				];
			}

			return [
				'ok' => true,
				'rolledBack' => $rolledBack,
				'migrated' => [],
				'errors' => [],
				'preBackupFile' => $preBackupFile,
				'details' => $buildDetails($preBackupFile, $rolledBack, []),
				'message' => count($rolledBack) > 0
					? sprintf($this->_('Reset %d applied migration(s).'), count($rolledBack))
					: $this->_('No applied migrations found to reset.'),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'rolledBack' => [],
				'migrated' => [],
				'errors' => [$e->getMessage()],
				'preBackupFile' => $preBackupFile,
				'details' => $buildDetails($preBackupFile, [], []),
				'message' => $e->getMessage(),
			];
		}
	}
}
