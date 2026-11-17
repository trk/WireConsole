<?php

declare(strict_types=1);

namespace Totoglu\WireConsole\Concerns;

use ProcessWire\ProcessWire;
use Totoglu\Console\Commands\CacheClearCommand;
use Totoglu\Console\Commands\DownCommand;
use Totoglu\Console\Commands\LogClearAllCommand;
use Totoglu\Console\Commands\LogClearCommand;
use Totoglu\Console\Commands\UpCommand;

/**
 * System Maintenance (`down`/`up`), Cache Flush (`cache:clear`/`cache:wire:clear`),
 * Runtime Version Matrix (`version`), and Log/Audit operations for ConsoleRuntime.
 */
trait InteractsWithSystemAndLogs
{
	/**
	 * Build the runtime version matrix (`wire version` equivalent) for PHP, ProcessWire, Database, Inertia, ProcessReactWorkspace, Console, and Boost.
	 *
	 * @return array{
	 *   php: string,
	 *   processwire: string,
	 *   database: string,
	 *   inertia: string,
	 *   workspace: string,
	 *   console: string,
	 *   boost: string
	 * }
	 */
	public function getVersionMatrix(): array
	{
		if ($this->versionMatrixCache !== null) {
			return $this->versionMatrixCache;
		}

		$pwVersion = (string) ($this->wire()->config?->version ?? '');
		if ($pwVersion === '') {
			$pwVersion = ProcessWire::versionMajor . '.' . ProcessWire::versionMinor . '.' . ProcessWire::versionRevision;
			if (ProcessWire::versionSuffix !== '') {
				$pwVersion .= '-' . ProcessWire::versionSuffix;
			}
		}

		$dbVersion = '—';
		try {
			$db = $this->wire()->database;
			if ($db) {
				$stmt = $db->query('SELECT VERSION()');
				if ($stmt !== false) {
					$rawVer = (string) $stmt->fetchColumn();
					if ($rawVer !== '') {
						$dbVersion = $rawVer;
					}
				}
			}
		} catch (\Throwable) {
			$dbVersion = '—';
		}

		$inertiaVersion = $this->resolveReadableModuleVersion('Inertia');
		$workspaceVersion = $this->resolveReadableModuleVersion('ProcessReactWorkspace');

		$packages = $this->getPackagesStatus();
		$consoleVer = is_string($packages['console']['version'] ?? null) ? $packages['console']['version'] : '—';
		$boostVer = is_string($packages['boost']['version'] ?? null) ? $packages['boost']['version'] : '—';

		return $this->versionMatrixCache = [
			'php' => PHP_VERSION,
			'processwire' => $pwVersion !== '' ? $pwVersion : '—',
			'database' => $dbVersion,
			'inertia' => $inertiaVersion,
			'workspace' => $workspaceVersion,
			'console' => $consoleVer,
			'boost' => $boostVer,
		];
	}

	/**
	 * Resolve a human-readable semantic version string (e.g. "2.1.3" instead of "213") for an installed ProcessWire module.
	 */
	protected function resolveReadableModuleVersion(string $moduleName): string
	{
		try {
			$modules = $this->wire()->modules;
			if (!$modules) {
				return '—';
			}

			$info = $modules->getModuleInfoVerbose($moduleName);
			if (!is_array($info) || (($info['version'] ?? 0) === 0 && empty($info['versionStr']))) {
				$staticInfo = $modules->getModuleInfo($moduleName);
				if (is_array($staticInfo)) {
					$info = is_array($info) ? array_merge($info, $staticInfo) : $staticInfo;
				}
			}
			if (is_array($info)) {
				if (isset($info['versionStr']) && is_string($info['versionStr']) && $info['versionStr'] !== '' && $info['versionStr'] !== '0.0.0') {
					return $info['versionStr'];
				}
				if (isset($info['version'])) {
					$raw = $info['version'];
					if (is_int($raw) || (is_string($raw) && ctype_digit($raw))) {
						if ((int) $raw > 0) {
							return $modules->formatVersion((int) $raw);
						}
					}
					if (is_string($raw) && $raw !== '' && $raw !== '0') {
						return $raw;
					}
				}
			}
		} catch (\Throwable) {
			// Fall through
		}

		return '—';
	}

	/**
	 * Resolve path to site/down.json maintenance flag file.
	 */
	public function getMaintenanceFilePath(): string
	{
		$sitePath = (string) ($this->wire()->config?->paths?->site ?? ($this->getProjectRootPath() . 'site/'));

		return rtrim(str_replace('\\', '/', $sitePath), '/') . '/down.json';
	}

	/**
	 * Inspect current application maintenance mode status (`site/down.json`).
	 *
	 * @return array{
	 *   isDown: bool,
	 *   file: string,
	 *   status: int,
	 *   retry: ?int,
	 *   redirect: ?string,
	 *   hasSecret: bool,
	 *   sinceFormatted: ?string
	 * }
	 */
	public function getMaintenanceStatus(): array
	{
		$file = $this->getMaintenanceFilePath();
		if (!is_file($file)) {
			return [
				'isDown' => false,
				'file' => $file,
				'status' => 200,
				'retry' => null,
				'redirect' => null,
				'hasSecret' => false,
				'sinceFormatted' => null,
			];
		}

		$raw = @file_get_contents($file);
		$data = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($data)) {
			$data = [];
		}

		$time = isset($data['time']) ? (int) $data['time'] : (int) (@filemtime($file) ?: 0);

		return [
			'isDown' => true,
			'file' => $file,
			'status' => isset($data['status']) ? (int) $data['status'] : 503,
			'retry' => isset($data['retry']) && $data['retry'] !== null && $data['retry'] !== '' ? (int) $data['retry'] : null,
			'redirect' => isset($data['redirect']) && is_string($data['redirect']) && $data['redirect'] !== '' ? $data['redirect'] : null,
			'hasSecret' => isset($data['secret']) && is_string($data['secret']) && $data['secret'] !== '',
			'sinceFormatted' => $time > 0 ? date('Y-m-d H:i:s', $time) : null,
		];
	}

	/**
	 * Put the application into maintenance mode (`site/down.json`) by delegating to `DownCommand`.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function enableMaintenanceMode(?string $secret = null, ?int $retry = null, int $status = 503, ?string $redirect = null): array
	{
		$file = $this->getMaintenanceFilePath();
		$cleanStatus = in_array($status, [503, 500, 404, 302, 307], true) ? $status : 503;

		if (is_file($file) && $this->isConsolePackageAvailable()) {
			$this->runConsoleCommandInProcess(UpCommand::class);
		}

		$parameters = [
			'--status' => (string) $cleanStatus,
		];
		if ($secret !== null && trim($secret) !== '') {
			$parameters['--secret'] = trim($secret);
		}
		if ($retry !== null && $retry > 0) {
			$parameters['--retry'] = (string) $retry;
		}
		if ($redirect !== null && trim($redirect) !== '') {
			$parameters['--redirect'] = trim($redirect);
		}

		$res = $this->runConsoleCommandInProcess(DownCommand::class, $parameters);
		if (!$res['ok']) {
			return [
				'ok' => false,
				'message' => (string) ($res['error']['message'] ?? $this->_('Failed to write site/down.json maintenance file.')),
			];
		}

		if (is_file($file)) {
			@chmod($file, 0600);
		}

		if ($secret !== null && trim($secret) !== '') {
			$this->wire()->session?->set('maintenance_secret', trim($secret));
		}

		return [
			'ok' => true,
			'message' => $this->_('Application is now in maintenance mode (site/down.json created).'),
		];
	}

	/**
	 * Bring the application out of maintenance mode by delegating to `UpCommand`.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function disableMaintenanceMode(): array
	{
		$file = $this->getMaintenanceFilePath();
		if (!is_file($file)) {
			return [
				'ok' => true,
				'message' => $this->_('Application is already live.'),
			];
		}

		$res = $this->runConsoleCommandInProcess(UpCommand::class);
		clearstatcache(true, $file);
		if ($res['ok'] || !is_file($file)) {
			return [
				'ok' => true,
				'message' => $this->_('Application is now live (site/down.json removed).'),
			];
		}

		return [
			'ok' => false,
			'message' => (string) ($res['error']['message'] ?? $this->_('Failed to remove site/down.json.')),
		];
	}

	/**
	 * Clear ProcessWire module cache, FileCompiler cache, WireCache entries, or refresh module registry (`cache:clear` / `$cache->deleteAll()` / `module:refresh`).
	 *
	 * @param string $mode 'all', 'core', 'wire' (WireCache only), or 'modules' (Modules::refresh)
	 * @return array{ok: bool, cleared: list<string>, message: string}
	 */
	public function clearSystemCaches(string $mode = 'all'): array
	{
		$resolvedMode = strtolower(trim($mode));
		if (!in_array($resolvedMode, ['all', 'core', 'wire', 'modules'], true)) {
			$resolvedMode = 'all';
		}

		$cleared = [];

		try {
			if ($resolvedMode === 'modules') {
				$modules = $this->wire()->modules;
				if ($modules) {
					try {
						$modules->refresh();
					} catch (\Error) {
						$this->wire()->cache?->delete('Modules.*');
					}
					$cleared[] = 'ModulesRegistry';
				}
			}

			if ($resolvedMode === 'all' || $resolvedMode === 'core') {
				$res = $this->runConsoleCommandInProcess(CacheClearCommand::class);
				if (!$res['ok']) {
					return [
						'ok' => false,
						'cleared' => $cleared,
						'message' => (string) ($res['error']['message'] ?? $this->_('Failed to clear core caches.')),
					];
				}
				$cmdCleared = isset($res['data']['cleared']) && is_array($res['data']['cleared'])
					? array_values(array_map('strval', $res['data']['cleared']))
					: ['Modules', 'FileCompiler'];
				foreach ($cmdCleared as $item) {
					$cleared[] = $item;
				}
			}

			if ($resolvedMode === 'all' || $resolvedMode === 'wire') {
				$cache = $this->wire()->cache;
				if ($cache) {
					$cache->deleteAll();
					$cleared[] = 'WireCache';
				}
			}

			return [
				'ok' => true,
				'cleared' => $cleared,
				'message' => sprintf($this->_('Cleared caches: %s.'), implode(', ', $cleared)),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'cleared' => $cleared,
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * List available ProcessWire log files ordered by modification time (with wire-console-audit prioritized).
	 *
	 * @return list<array{name: string, sizeBytes: int, sizeFormatted: string, modified: int, modifiedFormatted: string, isAudit: bool}>
	 */
	public function getLogChannels(): array
	{
		$log = $this->wire()->log;
		if (!$log) {
			return [];
		}

		$files = $log->getFiles();
		if (!is_array($files) || $files === []) {
			return [];
		}

		$items = [];
		foreach ($files as $name => $fileInfo) {
			$filePath = is_object($fileInfo) && method_exists($fileInfo, 'getPathname')
				? (string) $fileInfo->getPathname()
				: (string) $fileInfo;
			$sizeBytes = is_object($fileInfo) && method_exists($fileInfo, 'getSize')
				? (int) $fileInfo->getSize()
				: (int) (@filesize($filePath) ?: 0);
			$mtime = is_object($fileInfo) && method_exists($fileInfo, 'getMTime')
				? (int) $fileInfo->getMTime()
				: (int) (@filemtime($filePath) ?: 0);

			$items[] = [
				'name' => (string) $name,
				'sizeBytes' => $sizeBytes,
				'sizeFormatted' => $this->formatBytes($sizeBytes),
				'modified' => $mtime,
				'modifiedFormatted' => $mtime > 0 ? date('Y-m-d H:i:s', $mtime) : '—',
				'isAudit' => ((string) $name) === self::LOG_CHANNEL,
			];
		}

		usort($items, static function (array $a, array $b): int {
			if ($a['name'] === self::LOG_CHANNEL) {
				return -1;
			}
			if ($b['name'] === self::LOG_CHANNEL) {
				return 1;
			}

			return $b['modified'] <=> $a['modified'];
		});

		return $items;
	}

	/**
	 * Read recent entries from a ProcessWire log channel, optionally filtered by a case-insensitive substring search.
	 * Automatically parses JSON audit payloads into structured `audit` metadata.
	 *
	 * @return list<array{
	 *   date: string,
	 *   user: string,
	 *   url: string,
	 *   text: string,
	 *   audit: ?array{action: string, status: string, ip: string, contextFormatted: string}
	 * }>
	 */
	public function readLogEntries(string $channelName, int $limit = 50, string $search = ''): array
	{
		$log = $this->wire()->log;
		$sanitizer = $this->wire()->sanitizer;
		if (!$log || !$sanitizer) {
			return [];
		}

		$cleanName = $sanitizer->name((string) preg_replace('/\.txt$/i', '', basename(trim($channelName))));
		if ($cleanName === '') {
			return [];
		}

		$clampedLimit = max(1, min(250, $limit));
		$cleanSearch = trim($search);
		$fetchLimit = $cleanSearch !== '' ? max($clampedLimit, 250) : $clampedLimit;

		try {
			$rawEntries = $log->getEntries($cleanName, ['limit' => $fetchLimit]);
			if (!is_array($rawEntries)) {
				return [];
			}

			$entries = [];
			foreach ($rawEntries as $entry) {
				if (!is_array($entry)) {
					continue;
				}
				$date = (string) ($entry['date'] ?? '');
				$user = (string) ($entry['user'] ?? '');
				$url = (string) ($entry['url'] ?? '');
				$text = (string) ($entry['text'] ?? '');

				if ($cleanSearch !== '') {
					$haystack = $text . ' ' . $user . ' ' . $url . ' ' . $date;
					if (mb_stripos($haystack, $cleanSearch) === false) {
						continue;
					}
				}

				$auditMeta = null;
				$trimmedText = trim($text);
				if (str_starts_with($trimmedText, '{') && str_ends_with($trimmedText, '}')) {
					$decoded = json_decode($trimmedText, true);
					if (is_array($decoded) && isset($decoded['action'], $decoded['status']) && is_string($decoded['action']) && is_string($decoded['status'])) {
						$ctx = isset($decoded['context']) && is_array($decoded['context']) ? $decoded['context'] : [];
						$ctxJson = $ctx !== []
							? (string) (json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}')
							: '';
						$auditMeta = [
							'action' => $decoded['action'],
							'status' => $decoded['status'],
							'ip' => isset($decoded['ip']) && is_string($decoded['ip']) ? $decoded['ip'] : '',
							'contextFormatted' => $ctxJson,
						];
					}
				}

				$entries[] = [
					'date' => $date,
					'user' => $user,
					'url' => $url,
					'text' => $text,
					'audit' => $auditMeta,
				];

				if (count($entries) >= $clampedLimit) {
					break;
				}
			}

			return $entries;
		} catch (\Throwable) {
			return [];
		}
	}

	/**
	 * Clear a specific ProcessWire log file by delegating to `LogClearCommand`.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function clearLogChannel(string $channelName): array
	{
		$sanitizer = $this->wire()->sanitizer;
		$logsDir = (string) ($this->wire()->config?->paths?->logs ?? '');
		if (!$sanitizer || $logsDir === '') {
			return [
				'ok' => false,
				'message' => $this->_('Log directory is unavailable.'),
			];
		}

		$cleanName = $sanitizer->name((string) preg_replace('/\.txt$/i', '', basename(trim($channelName))));
		if ($cleanName === '') {
			return [
				'ok' => false,
				'message' => $this->_('Invalid log channel name.'),
			];
		}

		$filePath = rtrim($logsDir, '/') . '/' . $cleanName . '.txt';
		if (is_link($filePath) || !is_file($filePath)) {
			return [
				'ok' => false,
				'message' => sprintf($this->_('Log file "%s" does not exist.'), $cleanName),
			];
		}

		$result = $this->runConsoleCommandInProcess(LogClearCommand::class, [
			'name' => [$cleanName],
			'--force' => true,
		]);
		if (!$result['ok']) {
			return [
				'ok' => false,
				'message' => sprintf($this->_('Failed to clear log channel "%s".'), $cleanName),
			];
		}

		return [
			'ok' => true,
			'message' => sprintf($this->_('Cleared log channel "%s".'), $cleanName),
		];
	}

	/**
	 * Clear all ProcessWire log files by delegating to `LogClearAllCommand`.
	 *
	 * @return array{ok: bool, clearedCount: int, message: string}
	 */
	public function clearAllLogChannels(): array
	{
		$logsDir = (string) ($this->wire()->config?->paths?->logs ?? '');
		if ($logsDir === '' || !is_dir($logsDir)) {
			return [
				'ok' => false,
				'clearedCount' => 0,
				'message' => $this->_('Log directory is unavailable.'),
			];
		}

		$files = glob(rtrim($logsDir, '/') . '/*.txt');
		if (!is_array($files) || $files === []) {
			return [
				'ok' => true,
				'clearedCount' => 0,
				'message' => $this->_('No log files found to clear.'),
			];
		}

		$result = $this->runConsoleCommandInProcess(LogClearAllCommand::class, [
			'--force' => true,
		]);
		if (!$result['ok']) {
			return [
				'ok' => false,
				'clearedCount' => 0,
				'message' => (string) ($result['error']['message'] ?? $this->_('Log directory is unavailable.')),
			];
		}

		$clearedCount = count(array_filter($result['data'], static fn(mixed $val): bool => $val === true));

		return [
			'ok' => true,
			'clearedCount' => $clearedCount,
			'message' => sprintf($this->_('Cleared %d log channel(s).'), $clearedCount),
		];
	}
}
