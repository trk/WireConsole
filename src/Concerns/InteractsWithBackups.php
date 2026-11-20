<?php

declare(strict_types=1);

namespace Totoglu\WireConsole\Concerns;

use ProcessWire\WireDatabaseBackup;
use Totoglu\Console\Commands\BackupPurgeCommand;

/**
 * SQL Database Backup domain operations for ConsoleRuntime.
 */
trait InteractsWithBackups
{
	/**
	 * Resolve the directory where ProcessWire database backups are stored.
	 */
	public function getBackupDirectory(): string
	{
		$assetsPath = (string) $this->wire()->config->paths->assets;
		if ($assetsPath === '') {
			$assetsPath = $this->getProjectRootPath() . 'site/assets/';
		}
		$backupDir = rtrim(str_replace('\\', '/', $assetsPath), '/') . '/backups/database/';

		if (!is_dir($backupDir)) {
			try {
				$this->wire()->files->mkdir($backupDir, true);
			} catch (\Throwable) {
				@mkdir($backupDir, 0755, true);
			}
		}

		return $backupDir;
	}

	/**
	 * Instantiate a WireDatabaseBackup instance pointed at the standard backup directory.
	 */
	protected function createBackupManager(): ?WireDatabaseBackup
	{
		$db = $this->wire()->database;
		if (!$db) {
			return null;
		}

		$backup = new WireDatabaseBackup($this->getBackupDirectory());
		$backup->setWire($this->wire());
		$backup->setDatabase($db);
		$config = $this->wire()->config;
		if ($config) {
			$backup->setDatabaseConfig($config);
		}

		return $backup;
	}

	/**
	 * List SQL backup files ordered newest-first.
	 *
	 * @return list<array{name: string, sizeBytes: int, sizeFormatted: string, modified: int, modifiedFormatted: string}>
	 */
	public function listBackups(int $limit = 50): array
	{
		$dir = $this->getBackupDirectory();
		if (is_link(rtrim($dir, '/')) || !is_dir($dir)) {
			return [];
		}

		$files = glob($dir . '*.sql');
		if (!is_array($files) || $files === []) {
			return [];
		}

		$items = [];
		foreach ($files as $filePath) {
			if (is_link($filePath) || !is_file($filePath)) {
				continue;
			}
			$mtime = (int) (@filemtime($filePath) ?: 0);
			$size = (int) (@filesize($filePath) ?: 0);
			$items[] = [
				'name' => basename($filePath),
				'sizeBytes' => $size,
				'sizeFormatted' => $this->formatBytes($size),
				'modified' => $mtime,
				'modifiedFormatted' => $mtime > 0 ? date('Y-m-d H:i:s', $mtime) : '-',
			];
		}

		usort($items, static fn(array $a, array $b): int => $b['modified'] <=> $a['modified']);

		return array_slice($items, 0, max(1, $limit));
	}

	/**
	 * Safely resolve and validate a `.sql` backup filename inside the backup directory.
	 * Prevents path traversal (`../`), symbolic links, null bytes, and non-SQL file access.
	 */
	public function resolveBackupFilePath(string $fileName): ?string
	{
		$clean = trim($fileName);
		if ($clean === '' || str_contains($clean, "\0") || basename($clean) !== $clean) {
			return null;
		}
		if (!preg_match('/^[A-Za-z0-9._-]+\.sql$/', $clean)) {
			return null;
		}

		$dir = $this->getBackupDirectory();
		if (is_link(rtrim($dir, '/'))) {
			return null;
		}
		$dirReal = realpath($dir);
		$rootReal = realpath($this->getProjectRootPath());
		$candidate = $dir . $clean;
		if (is_link($candidate)) {
			return null;
		}
		$candidateReal = realpath($candidate);

		if ($dirReal === false || $rootReal === false || $candidateReal === false || !is_file($candidateReal)) {
			return null;
		}

		$normalizedDir = rtrim(str_replace('\\', '/', $dirReal), '/');
		$normalizedRoot = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
		$normalizedCandidate = str_replace('\\', '/', $candidateReal);

		if (dirname($normalizedCandidate) !== $normalizedDir || !str_starts_with($normalizedCandidate, $normalizedRoot)) {
			return null;
		}

		return $candidateReal;
	}

	/**
	 * Create a new database backup snapshot.
	 *
	 * @return array{ok: bool, file: ?string, message: string}
	 */
	public function createBackup(?string $description = null): array
	{
		$backup = $this->createBackupManager();
		if ($backup === null) {
			return [
				'ok' => false,
				'file' => null,
				'message' => $this->_('Database backup manager is unavailable.'),
			];
		}

		try {
			$options = [];
			if ($description !== null && trim($description) !== '') {
				$options['description'] = trim($description);
			}
			$file = $backup->backup($options);
			if (!is_string($file) || $file === '' || !is_file($file)) {
				$errors = $backup->errors();
				$reason = $errors !== [] ? implode('; ', $errors) : $this->_('Backup file could not be written.');

				return [
					'ok' => false,
					'file' => null,
					'message' => $reason,
				];
			}

			$base = basename($file);

			return [
				'ok' => true,
				'file' => $base,
				'message' => sprintf($this->_('Created SQL backup snapshot: %s'), $base),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'file' => null,
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Purge SQL backups older than the given number of days (minimum 1 day) by delegating to `BackupPurgeCommand`.
	 *
	 * @return array{ok: bool, deletedCount: int, message: string}
	 */
	public function purgeBackups(int $olderThanDays): array
	{
		$days = max(1, $olderThanDays);
		$dir = $this->getBackupDirectory();
		$files = is_dir($dir) ? glob($dir . '*.sql') : [];

		if (!is_array($files) || $files === []) {
			return [
				'ok' => true,
				'deletedCount' => 0,
				'message' => $this->_('No backup files found to purge.'),
			];
		}

		$result = $this->runConsoleCommandInProcess(BackupPurgeCommand::class, [
			'--days' => (string) $days,
			'--force' => true,
		]);

		if (!$result['ok']) {
			return [
				'ok' => false,
				'deletedCount' => 0,
				'message' => (string) ($result['error']['message'] ?? $this->_('No backup files found to purge.')),
			];
		}

		$deleted = (int) ($result['data']['deleted'] ?? 0);

		return [
			'ok' => true,
			'deletedCount' => $deleted,
			'message' => $deleted > 0
				? sprintf($this->_('Purged %d backup file(s) older than %d day(s).'), $deleted, $days)
				: sprintf($this->_('No backup files were older than %d day(s).'), $days),
		];
	}

	/**
	 * Restore a verified SQL backup file, optionally taking a safety snapshot beforehand.
	 *
	 * @return array{ok: bool, preBackupFile: ?string, message: string, details: string}
	 */
	public function restoreBackup(string $fileName, bool $createSafetyBackup = true): array
	{
		$resolvedPath = $this->resolveBackupFilePath($fileName);
		if ($resolvedPath === null) {
			return [
				'ok' => false,
				'preBackupFile' => null,
				'message' => $this->_('Invalid or missing SQL backup filename.'),
				'details' => '',
			];
		}

		$backup = $this->createBackupManager();
		if ($backup === null) {
			return [
				'ok' => false,
				'preBackupFile' => null,
				'message' => $this->_('Database backup manager is unavailable.'),
				'details' => '',
			];
		}

		$preBackupFile = null;
		$details = '';
		if ($createSafetyBackup) {
			$pre = $this->createBackup('Pre-restore safety snapshot');
			if (!$pre['ok']) {
				return [
					'ok' => false,
					'preBackupFile' => null,
					'message' => sprintf($this->_('Restore aborted because safety snapshot failed: %s'), $pre['message']),
					'details' => '',
				];
			}
			$preBackupFile = $pre['file'];
			if ($preBackupFile !== null) {
				$details = sprintf($this->_('Safety snapshot created prior to restore: %s'), $preBackupFile);
			}
		}

		try {
			$restored = $backup->restore($resolvedPath);
			if (!$restored) {
				$errors = $backup->errors();
				$reason = $errors !== [] ? implode('; ', $errors) : $this->_('Database restore failed.');

				return [
					'ok' => false,
					'preBackupFile' => $preBackupFile,
					'message' => $reason,
					'details' => $details,
				];
			}

			return [
				'ok' => true,
				'preBackupFile' => $preBackupFile,
				'message' => sprintf($this->_('Successfully restored database from %s.'), basename($resolvedPath)),
				'details' => $details,
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'preBackupFile' => $preBackupFile,
				'message' => $e->getMessage(),
				'details' => $details,
			];
		}
	}
}
