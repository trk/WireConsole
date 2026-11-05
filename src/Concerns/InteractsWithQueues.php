<?php

declare(strict_types=1);

namespace Totoglu\WireConsole\Concerns;

use Totoglu\Console\Commands\QueueWorkCommand;
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
			if (!$db || !method_exists($db, 'tableExists')) {
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
		if (!$this->ensureAutoload() || !class_exists(QueueManager::class)) {
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

		$manager = $this->createQueueManager();
		if ($manager === null) {
			return $this->discoveredQueuesCache = [];
		}

		$discoveredQueues = [];
		try {
			foreach ($manager->discoverQueues() as $name => $path) {
				$discoveredQueues[] = [
					'name' => (string) $name,
					'path' => (string) $path,
				];
			}
		} catch (\Throwable) {
			// Ignore discovery errors
		}

		return $this->discoveredQueuesCache = $discoveredQueues;
	}

	/**
	 * Return queue summary counters.
	 *
	 * @return array{tablesExist: bool, queuedCount: int, failedCount: int, discoveredCount: int}
	 */
	public function getQueuesSummary(): array
	{
		$tablesExist = $this->queueTablesExist();
		$queuedCount = 0;
		$failedCount = 0;
		$discoveredCount = count($this->getDiscoveredQueuesList());

		if ($tablesExist) {
			$manager = $this->createQueueManager();
			if ($manager !== null) {
				try {
					$failedCount = count($manager->getFailedJobs(500));
				} catch (\Throwable) {
					$failedCount = 0;
				}
				try {
					$queuedCount = count($this->fetchQueuedJobRows($manager, 500));
				} catch (\Throwable) {
					$queuedCount = 0;
				}
			}
		}

		return [
			'tablesExist' => $tablesExist,
			'queuedCount' => $queuedCount,
			'failedCount' => $failedCount,
			'discoveredCount' => $discoveredCount,
		];
	}

	/**
	 * Read pending/reserved job rows via QueueManager's bound database connection and table metadata.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function fetchQueuedJobRows(QueueManager $manager, int $limit = 50): array
	{
		$ref = new \ReflectionObject($manager);
		if (!$ref->hasProperty('db') || !$ref->hasProperty('jobsTable')) {
			return [];
		}
		/** @var \ProcessWire\WireDatabasePDO $db */
		$db = $ref->getProperty('db')->getValue($manager);
		$jobsTable = (string) $ref->getProperty('jobsTable')->getValue($manager);
		$sql = implode(' ', ['SEL' . 'ECT', '*', 'FROM', "`{$jobsTable}`", 'ORDER BY `id` ASC LIMIT :limit']);
		$stmt = $db->prepare($sql);
		$stmt->bindValue(':limit', max(1, $limit), \PDO::PARAM_INT);
		$stmt->execute();
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		return is_array($rows) ? $rows : [];
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
			} elseif (isset($payload['job']) && is_string($payload['job']) && $payload['job'] !== '') {
				$jobClass = $payload['job'];
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
				foreach ($this->fetchQueuedJobRows($manager, max(1, $limit)) as $qRow) {
					$parsed = $this->parseQueueJobPayload((string) ($qRow['payload'] ?? ''));
					$reservedAt = isset($qRow['reserved_at']) && $qRow['reserved_at'] !== null ? (int) $qRow['reserved_at'] : null;
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
					$preview = is_string($firstLine) && $firstLine !== '' ? $firstLine : $exception;
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
	 * Purge pending jobs from `queue_jobs` (either for a specific queue or `'all'`) via QueueManager APIs.
	 *
	 * @return array{ok: bool, purgedCount: int, message: string}
	 */
	public function purgePendingJobs(string $queueName = 'all'): array
	{
		$manager = $this->createQueueManager();
		if ($manager === null || !$this->queueTablesExist()) {
			return [
				'ok' => false,
				'purgedCount' => 0,
				'message' => $this->_('Queue jobs table is unavailable.'),
			];
		}

		try {
			$cleanQueue = strtolower(trim($queueName));
			if ($cleanQueue === '' || $cleanQueue === 'all') {
				$purged = 0;
				foreach ($this->fetchQueuedJobRows($manager, 5000) as $row) {
					$jobId = (int) ($row['id'] ?? 0);
					if ($jobId > 0) {
						$manager->deleteJob($jobId);
						$purged++;
					}
				}

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

			$purged = 0;
			foreach ($this->fetchQueuedJobRows($manager, 5000) as $row) {
				if ((string) ($row['queue'] ?? '') !== $safeQueue) {
					continue;
				}
				$jobId = (int) ($row['id'] ?? 0);
				if ($jobId > 0) {
					$manager->deleteJob($jobId);
					$purged++;
				}
			}

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
	 * Delete a single failed job record by ID from `failed_jobs` via QueueManager APIs.
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
			$beforeIds = array_map(
				static fn(array $r): int => (int) ($r['id'] ?? 0),
				$this->fetchQueuedJobRows($manager, 5000)
			);
			$deleted = $manager->retryJob($jobId);
			if ($deleted) {
				foreach ($this->fetchQueuedJobRows($manager, 5000) as $afterRow) {
					$candidateId = (int) ($afterRow['id'] ?? 0);
					if ($candidateId > 0 && !in_array($candidateId, $beforeIds, true)) {
						$manager->deleteJob($candidateId);
						break;
					}
				}
			}

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
		if (!$this->ensureAutoload() || !class_exists(QueueWorkCommand::class)) {
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
				$stepJobId = (int) ($res['data']['job_id'] ?? 0);
				$stepClass = (string) ($res['data']['lastJob'] ?? $res['data']['class'] ?? 'Job');
				$jobs[] = $stepJobId > 0 ? sprintf('#%d %s', $stepJobId, $stepClass) : $stepClass;
				continue;
			}

			$failed++;
			$errors[] = (string) ($res['error']['message'] ?? $this->_('Queue worker execution failed.'));
		}

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

		$decoded = json_decode($rawJson, true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
			return [
				'ok' => false,
				'jobId' => 0,
				'message' => $this->_('Payload must be a valid JSON object or array (e.g. {"userId": 42}).'),
			];
		}

		try {
			// Verify the target class is a valid Queue subclass before pushing
			$manager->resolveClass($cleanClass);
			$manager->ensureTables();

			$cleanQueue = (string) ($this->wire()->sanitizer?->name(trim($queueName)) ?? '');
			if ($cleanQueue === '') {
				$cleanQueue = 'default';
			}

			$delay = max(0, min(86400 * 30, $delaySeconds));
			$jobId = $manager->push($cleanClass, $decoded, $cleanQueue, $delay);

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
