<?php

declare(strict_types=1);

namespace Totoglu\WireConsole\Concerns;

use Totoglu\Console\Commands\QueueWorkCommand;
use Totoglu\Console\Queue\Queue;
use Totoglu\Console\Queue\QueueManager;

/**
 * Background Queue domain operations for ConsoleRuntime.
 */
trait InteractsWithQueues
{
	/**
	 * Check if both queue_jobs and failed_jobs tables exist.
	 */
	public function queueTablesExist(): bool
	{
		try {
			$db = $this->wire()->database;
			if (!is_object($db) || !method_exists($db, 'tableExists')) {
				return false;
			}

			return (bool) $db->tableExists(self::TABLE_QUEUE_JOBS)
				&& (bool) $db->tableExists(self::TABLE_QUEUE_FAILED);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Instantiate a QueueManager connected to the current ProcessWire instance.
	 */
	protected function createQueueManager(): ?QueueManager
	{
		if (!$this->isConsolePackageAvailable()) {
			return null;
		}

		return new QueueManager($this->wire()->database, $this->createDiscoverer());
	}

	/**
	 * Install the queue database tables (queue_jobs & failed_jobs).
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function installQueueTables(): array
	{
		$manager = $this->createQueueManager();
		if ($manager === null) {
			return [
				'ok' => false,
				'message' => $this->_('Queue manager is unavailable because trk/processwire-console is not installed.'),
			];
		}

		try {
			$manager->ensureTables();
			$this->queuesSummaryCache = null;

			return [
				'ok' => true,
				'message' => $this->_('Queue database tables (queue_jobs, failed_jobs) are ready.'),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Discover queue handler classes with per-instance memoization.
	 *
	 * @return list<array{name: string, path: string}>
	 */
	protected function getDiscoveredQueuesList(): array
	{
		if ($this->discoveredQueuesCache !== null) {
			return $this->discoveredQueuesCache;
		}

		if ($this->createQueueManager() === null) {
			return $this->discoveredQueuesCache = [];
		}

		$discoveredQueues = [];
		try {
			foreach ($this->discoverVerifiedClasses('queue', '*Queue.php', Queue::class) as $name => $info) {
				$discoveredQueues[] = [
					'name' => $name,
					'path' => $info['path'],
				];
			}
		} catch (\Throwable) {
			// Ignore discovery errors
		}

		return $this->discoveredQueuesCache = $discoveredQueues;
	}

	/**
	 * Return queue summary counters using exact SQL COUNT(*) queries with per-instance memoization.
	 *
	 * @return array{tablesExist: bool, queuedCount: int, failedCount: int, discoveredCount: int}
	 */
	public function getQueuesSummary(): array
	{
		if ($this->queuesSummaryCache !== null) {
			return $this->queuesSummaryCache;
		}

		$tablesExist = $this->queueTablesExist();
		$queuedCount = 0;
		$failedCount = 0;
		$discoveredCount = count($this->getDiscoveredQueuesList());

		if ($tablesExist && $this->isConsolePackageAvailable()) {
			$queuedCount = $this->countQueueTableRows(self::TABLE_QUEUE_JOBS);
			$failedCount = $this->countQueueTableRows(self::TABLE_QUEUE_FAILED);
		}

		return $this->queuesSummaryCache = [
			'tablesExist' => $tablesExist,
			'queuedCount' => $queuedCount,
			'failedCount' => $failedCount,
			'discoveredCount' => $discoveredCount,
		];
	}

	/**
	 * Count rows in a verified queue table without hydrating LONGTEXT payloads.
	 */
	protected function countQueueTableRows(string $tableName): int
	{
		try {
			$db = $this->wire()->database;
			if (!$db) {
				return 0;
			}
			$safeTable = $db->escapeTable($tableName);
			$stmt = $db->query("SELECT COUNT(*) FROM `{$safeTable}`");

			return $stmt !== false ? max(0, (int) $stmt->fetchColumn()) : 0;
		} catch (\Throwable) {
			return 0;
		}
	}

	/**
	 * Read pending and reserved job rows from `queue_jobs` using a prepared statement.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function fetchQueuedJobRows(int $limit = 50): array
	{
		$db = $this->wire()->database;
		if (!$db) {
			return [];
		}

		$jobsTable = $db->escapeTable(self::TABLE_QUEUE_JOBS);
		$stmt = $db->prepare("SELECT * FROM `{$jobsTable}` ORDER BY `id` ASC LIMIT :limit");
		$stmt->bindValue(':limit', max(1, $limit), \PDO::PARAM_INT);
		$stmt->execute();
		/** @var list<array<string, mixed>>|false $rows */
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		return is_array($rows) ? array_values($rows) : [];
	}

	/**
	 * Parse a serialized queue job JSON payload into its handler class name and pretty-printed JSON.
	 *
	 * @return array{jobClass: string, payloadFormatted: string}
	 */
	protected function parseQueueJobPayload(string $payloadRaw): array
	{
		$payload = json_decode($payloadRaw, true);
		$jobClass = $this->_('Unknown Job');
		$payloadFormatted = $payloadRaw;

		if (is_array($payload)) {
			if (isset($payload['class']) && is_string($payload['class']) && $payload['class'] !== '') {
				$jobClass = $payload['class'];
			}
			$pretty = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			if (is_string($pretty)) {
				$payloadFormatted = $pretty;
			}
		}

		return [
			'jobClass' => $jobClass,
			'payloadFormatted' => $payloadFormatted,
		];
	}

	/**
	 * Return full queue telemetry including discovered queue classes, pending jobs, and failed job rows.
	 *
	 * @return array{
	 *   available: bool,
	 *   tablesExist: bool,
	 *   queuedCount: int,
	 *   failedCount: int,
	 *   discoveredQueues: list<array{name: string, path: string}>,
	 *   pendingJobs: list<array{id: int, queue: string, jobClass: string, attempts: int, status: string, availableAt: string, payloadFormatted: string}>,
	 *   failedJobs: list<array{id: int, queue: string, jobClass: string, failedAt: string, exceptionPreview: string, payloadFormatted: string, exceptionFull: string}>
	 * }
	 */
	public function getQueuesStatus(int $limit = 50): array
	{
		$manager = $this->createQueueManager();
		$summary = $this->getQueuesSummary();

		if ($manager === null) {
			return [
				'available' => false,
				'tablesExist' => $summary['tablesExist'],
				'queuedCount' => $summary['queuedCount'],
				'failedCount' => $summary['failedCount'],
				'discoveredQueues' => [],
				'pendingJobs' => [],
				'failedJobs' => [],
			];
		}

		$discoveredQueues = $this->getDiscoveredQueuesList();

		$pendingJobs = [];
		$failedJobs = [];
		if ($summary['tablesExist']) {
			try {
				$now = time();
				foreach ($this->fetchQueuedJobRows(max(1, $limit)) as $qRow) {
					$parsed = $this->parseQueueJobPayload((string) ($qRow['payload'] ?? ''));
					$reservedAt = isset($qRow['reserved_at']) ? (int) $qRow['reserved_at'] : null;
					$availableAtTs = (int) ($qRow['available_at'] ?? $now);
					$status = $reservedAt !== null ? 'reserved' : ($availableAtTs > $now ? 'delayed' : 'ready');

					$pendingJobs[] = [
						'id' => (int) ($qRow['id'] ?? 0),
						'queue' => (string) ($qRow['queue'] ?? 'default'),
						'jobClass' => $parsed['jobClass'],
						'attempts' => (int) ($qRow['attempts'] ?? 0),
						'status' => $status,
						'availableAt' => date('Y-m-d H:i:s', $availableAtTs),
						'payloadFormatted' => $parsed['payloadFormatted'],
					];
				}
			} catch (\Throwable) {
				// Ignore pending jobs query error
			}

			try {
				$rows = $manager->getFailedJobs(max(1, $limit));
				foreach ($rows as $row) {
					$parsed = $this->parseQueueJobPayload((string) ($row['payload'] ?? ''));

					$exception = trim((string) ($row['exception'] ?? ''));
					$firstLine = strtok($exception, "\r\n");
					$preview = is_string($firstLine) ? $firstLine : $exception;
					if (mb_strlen($preview) > 160) {
						$preview = mb_substr($preview, 0, 157) . '...';
					}
					$exceptionFull = mb_strlen($exception) > 4000
						? mb_substr($exception, 0, 4000) . "\n..."
						: $exception;

					$failedJobs[] = [
						'id' => (int) ($row['id'] ?? 0),
						'queue' => (string) ($row['queue'] ?? 'default'),
						'jobClass' => $parsed['jobClass'],
						'failedAt' => (string) ($row['failed_at'] ?? ''),
						'exceptionPreview' => $preview,
						'payloadFormatted' => $parsed['payloadFormatted'],
						'exceptionFull' => $exceptionFull,
					];
				}
			} catch (\Throwable) {
				// Ignore failed jobs query error
			}
		}

		return [
			'available' => true,
			'tablesExist' => $summary['tablesExist'],
			'queuedCount' => $summary['queuedCount'],
			'failedCount' => $summary['failedCount'],
			'discoveredQueues' => $discoveredQueues,
			'pendingJobs' => $pendingJobs,
			'failedJobs' => $failedJobs,
		];
	}

	/**
	 * Purge pending jobs from `queue_jobs` (either for a specific queue or `'all'`) atomically via prepared SQL.
	 *
	 * @return array{ok: bool, purgedCount: int, message: string}
	 */
	public function purgePendingJobs(string $queueName = 'all'): array
	{
		$manager = $this->createQueueManager();
		$db = $this->wire()->database;
		if ($manager === null || !$db || !$this->queueTablesExist()) {
			return [
				'ok' => false,
				'purgedCount' => 0,
				'message' => $this->_('Queue jobs table is unavailable.'),
			];
		}

		try {
			$jobsTable = $db->escapeTable(self::TABLE_QUEUE_JOBS);
			$cleanQueue = strtolower(trim($queueName));
			if ($cleanQueue === '' || $cleanQueue === 'all') {
				$stmt = $db->prepare("DELETE FROM `{$jobsTable}`");
				$stmt->execute();
				$purged = $stmt->rowCount();
				$this->queuesSummaryCache = null;

				return [
					'ok' => true,
					'purgedCount' => $purged,
					'message' => $purged > 0
						? sprintf($this->_('Purged %d pending job(s) from all queues.'), $purged)
						: $this->_('No pending jobs found in queue_jobs.'),
				];
			}

			$safeQueue = (string) ($this->wire()->sanitizer?->name(trim($queueName)) ?? '');
			if ($safeQueue === '') {
				return [
					'ok' => false,
					'purgedCount' => 0,
					'message' => $this->_('Invalid queue name.'),
				];
			}

			$stmt = $db->prepare("DELETE FROM `{$jobsTable}` WHERE `queue` = :queue");
			$stmt->bindValue(':queue', $safeQueue, \PDO::PARAM_STR);
			$stmt->execute();
			$purged = $stmt->rowCount();
			$this->queuesSummaryCache = null;

			return [
				'ok' => true,
				'purgedCount' => $purged,
				'message' => $purged > 0
					? sprintf($this->_('Purged %d pending job(s) from queue "%s".'), $purged, $safeQueue)
					: sprintf($this->_('No pending jobs found on queue "%s".'), $safeQueue),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'purgedCount' => 0,
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Retry a single failed job by ID or all failed jobs ('all').
	 *
	 * @return array{ok: bool, retriedCount: int, message: string}
	 */
	public function retryFailedJobs(string $idOrAll): array
	{
		$manager = $this->createQueueManager();
		if ($manager === null) {
			return [
				'ok' => false,
				'retriedCount' => 0,
				'message' => $this->_('Queue manager is unavailable.'),
			];
		}

		try {
			if (strtolower(trim($idOrAll)) === 'all') {
				$count = $manager->retryAllFailed();
				$this->queuesSummaryCache = null;

				return [
					'ok' => true,
					'retriedCount' => $count,
					'message' => $count > 0
						? sprintf($this->_('Re-queued %d failed job(s) for processing.'), $count)
						: $this->_('No failed jobs found to retry.'),
				];
			}

			$jobId = (int) $idOrAll;
			if ($jobId <= 0) {
				return [
					'ok' => false,
					'retriedCount' => 0,
					'message' => $this->_('Provide a valid failed job ID or select "all".'),
				];
			}

			$ok = $manager->retryJob($jobId);
			$this->queuesSummaryCache = null;

			return [
				'ok' => $ok,
				'retriedCount' => $ok ? 1 : 0,
				'message' => $ok
					? sprintf($this->_('Failed job #%d has been pushed back onto the queue.'), $jobId)
					: sprintf($this->_('Failed job #%d was not found.'), $jobId),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'retriedCount' => 0,
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Clear all failed jobs from failed_jobs.
	 *
	 * @return array{ok: bool, clearedCount: int, message: string}
	 */
	public function clearFailedJobs(): array
	{
		$manager = $this->createQueueManager();
		if ($manager === null) {
			return [
				'ok' => false,
				'clearedCount' => 0,
				'message' => $this->_('Queue manager is unavailable.'),
			];
		}

		try {
			$count = $manager->clearFailedJobs();
			$this->queuesSummaryCache = null;

			return [
				'ok' => true,
				'clearedCount' => $count,
				'message' => $count > 0
					? sprintf($this->_('Purged %d failed job record(s).'), $count)
					: $this->_('No failed job records to clear.'),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'clearedCount' => 0,
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Delete a single failed job record by ID from `failed_jobs` atomically.
	 *
	 * @return array{ok: bool, deleted: bool, message: string}
	 */
	public function deleteFailedJob(int $jobId): array
	{
		if ($jobId <= 0) {
			return [
				'ok' => false,
				'deleted' => false,
				'message' => $this->_('Provide a valid failed job ID to delete.'),
			];
		}

		$manager = $this->createQueueManager();
		$db = $this->wire()->database;
		if ($manager === null || !$db || !$this->queueTablesExist()) {
			return [
				'ok' => false,
				'deleted' => false,
				'message' => $this->_('Failed jobs table is unavailable.'),
			];
		}

		try {
			$failedTable = $db->escapeTable(self::TABLE_QUEUE_FAILED);
			$stmt = $db->prepare("DELETE FROM `{$failedTable}` WHERE `id` = :id");
			$stmt->bindValue(':id', $jobId, \PDO::PARAM_INT);
			$stmt->execute();
			$deleted = $stmt->rowCount() > 0;
			$this->queuesSummaryCache = null;

			return [
				'ok' => $deleted,
				'deleted' => $deleted,
				'message' => $deleted
					? sprintf($this->_('Deleted failed job #%d.'), $jobId)
					: sprintf($this->_('Failed job #%d was not found.'), $jobId),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'deleted' => false,
				'message' => $e->getMessage(),
			];
		}
	}

	/**
	 * Process a bounded batch of pending jobs from `queue_jobs` synchronously by delegating to `QueueWorkCommand`.
	 *
	 * @return array{ok: bool, processed: int, failed: int, jobs: list<string>, errors: list<string>, details: string, message: string}
	 */
	public function workQueueBatch(string $queueName = 'default', int $maxJobs = 5, int $maxTries = 3): array
	{
		if (!$this->isConsolePackageAvailable()) {
			return [
				'ok' => false,
				'processed' => 0,
				'failed' => 0,
				'jobs' => [],
				'errors' => [],
				'details' => '',
				'message' => $this->_('Queue manager is unavailable.'),
			];
		}

		if (!$this->queueTablesExist()) {
			return [
				'ok' => false,
				'processed' => 0,
				'failed' => 0,
				'jobs' => [],
				'errors' => [],
				'details' => '',
				'message' => $this->_('Queue tables are not installed yet.'),
			];
		}

		if ($this->hasUnsafeFeatureFiles('queue', '*Queue.php')) {
			$msg = $this->_('Refusing to process queue jobs while symbolic link or out-of-root queue files exist.');

			return [
				'ok' => false,
				'processed' => 0,
				'failed' => 0,
				'jobs' => [],
				'errors' => [$msg],
				'details' => '',
				'message' => $msg,
			];
		}

		$cleanQueue = (string) ($this->wire()->sanitizer?->name(trim($queueName)) ?? '');
		if ($cleanQueue === '') {
			$cleanQueue = 'default';
		}

		$limit = max(1, min(50, $maxJobs));
		$tries = max(1, min(10, $maxTries));
		$processed = 0;
		$failed = 0;
		$jobs = [];
		$errors = [];

		for ($i = 0; $i < $limit; $i++) {
			$res = $this->runConsoleCommandInProcess(QueueWorkCommand::class, [
				'--queue' => $cleanQueue,
				'--tries' => (string) $tries,
				'--once' => true,
			]);

			if ($res['ok']) {
				$stepProcessed = (int) ($res['data']['processed'] ?? 0);
				if ($stepProcessed === 0) {
					break;
				}
				$processed += $stepProcessed;
				$jobs[] = (string) ($res['data']['lastJob'] ?? 'Job');
				continue;
			}

			$failed++;
			$errors[] = (string) ($res['error']['message'] ?? $this->_('Queue worker execution failed.'));
		}
		$this->queuesSummaryCache = null;

		if ($processed === 0 && $failed === 0 && $errors === []) {
			return [
				'ok' => true,
				'processed' => 0,
				'failed' => 0,
				'jobs' => [],
				'errors' => [],
				'details' => '',
				'message' => sprintf($this->_('No available jobs waiting on queue "%s".'), $cleanQueue),
			];
		}

		$lines = [];
		foreach ($jobs as $jobSummary) {
			$lines[] = '+ ' . $jobSummary;
		}
		foreach ($errors as $errSummary) {
			$lines[] = '! ' . $errSummary;
		}

		return [
			'ok' => $failed === 0 && $errors === [],
			'processed' => $processed,
			'failed' => $failed,
			'jobs' => $jobs,
			'errors' => $errors,
			'details' => implode("\n", $lines),
			'message' => sprintf(
				$this->_('Processed %d job(s) and recorded %d failure(s) on queue "%s".'),
				$processed,
				$failed,
				$cleanQueue
			),
		];
	}

	/**
	 * Dispatch a discovered Queue handler class onto `queue_jobs` with a validated JSON payload.
	 *
	 * @return array{ok: bool, jobId: int, message: string}
	 */
	public function dispatchQueueJob(string $className, string $rawJsonPayload = '{}', string $queueName = 'default', int $delaySeconds = 0): array
	{
		$manager = $this->createQueueManager();
		if ($manager === null) {
			return [
				'ok' => false,
				'jobId' => 0,
				'message' => $this->_('Queue manager is unavailable.'),
			];
		}

		$cleanClass = trim($className);
		if ($cleanClass === '') {
			return [
				'ok' => false,
				'jobId' => 0,
				'message' => $this->_('Select or provide a valid Queue handler class to dispatch.'),
			];
		}

		$rawJson = trim($rawJsonPayload);
		if ($rawJson === '') {
			$rawJson = '{}';
		}

		$decoded = strlen($rawJson) <= self::MAX_JSON_BODY_BYTES ? json_decode($rawJson, true, self::MAX_JSON_DEPTH) : null;
		if (
			$decoded === null
			|| json_last_error() !== JSON_ERROR_NONE
			|| !is_array($decoded)
			|| count($decoded, COUNT_RECURSIVE) > self::MAX_JSON_ELEMENTS
		) {
			return [
				'ok' => false,
				'jobId' => 0,
				'message' => $this->_('Job data must be a valid JSON object or array (e.g. {"userId": 42}).'),
			];
		}

		try {
			$basename = basename(str_replace('\\', '/', ltrim($cleanClass, '\\')));
			$rawFiles = $manager->discoverQueues();
			if (isset($rawFiles[$basename])) {
				$candidateFile = $rawFiles[$basename];
				$realFile = (is_link(dirname($candidateFile)) || is_link($candidateFile)) ? false : realpath($candidateFile);
				$realRoot = realpath($this->getProjectRootPath());
				if (
					$realFile === false
					|| $realRoot === false
					|| !is_file($realFile)
					|| !str_starts_with(str_replace('\\', '/', $realFile), rtrim(str_replace('\\', '/', $realRoot), '/') . '/')
				) {
					throw new \RuntimeException(sprintf('Queue handler file for [%s] is outside the project root or a symbolic link.', $cleanClass));
				}
			}

			// Verify the target class is a valid concrete Queue subclass before pushing
			$manager->resolveClass($cleanClass);
			$manager->ensureTables();

			$cleanQueue = (string) ($this->wire()->sanitizer?->name(trim($queueName)) ?? '');
			if ($cleanQueue === '') {
				$cleanQueue = 'default';
			}

			$delay = max(0, min(86400 * 30, $delaySeconds));
			$jobId = $manager->push($cleanClass, $decoded, $cleanQueue, $delay);
			$this->queuesSummaryCache = null;

			return [
				'ok' => $jobId > 0,
				'jobId' => $jobId,
				'message' => sprintf(
					$this->_('Dispatched %s onto queue "%s" (Job #%d).'),
					$cleanClass,
					$cleanQueue,
					$jobId
				),
			];
		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'jobId' => 0,
				'message' => $e->getMessage(),
			];
		}
	}
}
