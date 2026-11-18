<?php

declare(strict_types=1);

namespace Totoglu\WireConsole\Concerns;

/**
 * Unified server-side action dispatcher for WireConsole Inertia requests.
 *
 * Enforces RBAC permissions, superuser guards, confirmation tokens, and
 * session rate-limiting before delegating to the underlying ConsoleRuntime
 * domain handlers.
 */
trait HandlesWorkspaceActions
{
	/**
	 * Execute a named workspace action with full security and rate-limit guards.
	 *
	 * @param string $action
	 * @param array<string, mixed> $payload
	 * @return array{ok: bool, message: string, data?: mixed, output?: string, details?: string, tool?: string, isError?: bool, outputFormatted?: string, exitCode?: int, command?: string, tab?: string}
	 */
	public function executeWorkspaceAction(string $action, array $payload = []): array
	{
		return match ($action) {
			'installMigrationsTable' => $this->guardAndRun(
				tab: 'migrations',
				permission: self::PERMISSION_MIGRATIONS,
				guardKey: 'migrations_install',
				expectedConfirm: 'RUN',
				cooldown: 2,
				payload: $payload,
				callback: fn(): array => $this->installMigrationsTable(),
			),
			'runMigrations' => $this->guardAndRun(
				tab: 'migrations',
				permission: self::PERMISSION_MIGRATIONS,
				guardKey: 'migrations_run',
				expectedConfirm: 'RUN',
				cooldown: 3,
				payload: $payload,
				callback: fn(): array => $this->runMigrations($this->payloadInt($payload, 'migration_steps', 0)),
			),
			'rollbackMigrations' => $this->guardAndRun(
				tab: 'migrations',
				permission: self::PERMISSION_MIGRATIONS,
				guardKey: 'migrations_rollback',
				expectedConfirm: 'ROLLBACK',
				cooldown: 3,
				payload: $payload,
				callback: fn(): array => $this->rollbackMigrations(max(1, $this->payloadInt($payload, 'rollback_steps', 1))),
			),
			'resetOrRefreshMigrations' => $this->guardAndRun(
				tab: 'migrations',
				permission: self::PERMISSION_MIGRATIONS,
				guardKey: 'migrations_reset',
				expectedConfirm: 'RESET',
				cooldown: 5,
				payload: $payload,
				callback: function () use ($payload): array {
					$mode = $this->payloadString($payload, 'reset_mode', 'refresh');
					$preBackup = $this->payloadBool($payload, 'reset_pre_backup', true);

					return $this->resetMigrations($mode, $preBackup);
				},
			),
			'makeMigration' => $this->guardAndRun(
				tab: 'migrations',
				permission: self::PERMISSION_MIGRATIONS,
				guardKey: 'migrations_make',
				expectedConfirm: 'CREATE',
				cooldown: 2,
				payload: $payload,
				callback: fn(): array => $this->scaffoldComponentFile(
					'migration',
					$this->payloadString($payload, 'make_migration_name'),
					['type' => $this->payloadString($payload, 'make_migration_type', 'blank')]
				),
			),
			'installQueueTables' => $this->guardAndRun(
				tab: 'queues',
				permission: self::PERMISSION_QUEUES,
				guardKey: 'queues_install',
				expectedConfirm: 'RUN',
				cooldown: 2,
				payload: $payload,
				callback: fn(): array => $this->installQueueTables(),
			),
			'retryFailedJobs' => $this->guardAndRun(
				tab: 'queues',
				permission: self::PERMISSION_QUEUES,
				guardKey: 'queues_retry',
				expectedConfirm: 'RETRY',
				cooldown: 3,
				payload: $payload,
				callback: function () use ($payload): array {
					$target = $this->payloadString($payload, 'retry_target', 'all');
					if ($target === '') {
						$target = 'all';
					}

					return $this->retryFailedJobs($target);
				},
			),
			'clearFailedJobs' => $this->guardAndRun(
				tab: 'queues',
				permission: self::PERMISSION_QUEUES,
				guardKey: 'queues_clear',
				expectedConfirm: 'CLEAR',
				cooldown: 3,
				payload: $payload,
				callback: fn(): array => $this->clearFailedJobs(),
			),
			'purgePendingJobs' => $this->guardAndRun(
				tab: 'queues',
				permission: self::PERMISSION_QUEUES,
				guardKey: 'queues_purge_pending',
				expectedConfirm: 'PURGE',
				cooldown: 3,
				payload: $payload,
				callback: fn(): array => $this->purgePendingJobs(
					$this->payloadString($payload, 'purge_queue_name')
				),
			),
			'deleteFailedJob' => $this->guardAndRun(
				tab: 'queues',
				permission: self::PERMISSION_QUEUES,
				guardKey: 'queues_delete_one',
				expectedConfirm: 'DELETE',
				cooldown: 2,
				payload: $payload,
				callback: fn(): array => $this->deleteFailedJob(
					$this->payloadInt($payload, 'delete_failed_id', 0)
				),
			),
			'workQueueBatch' => $this->guardAndRun(
				tab: 'queues',
				permission: self::PERMISSION_QUEUES,
				guardKey: 'queues_work',
				expectedConfirm: 'WORK',
				cooldown: 3,
				payload: $payload,
				callback: function () use ($payload): array {
					$queueName = $this->payloadString($payload, 'work_queue_name', 'default');
					if ($queueName === '') {
						$queueName = 'default';
					}
					$maxJobs = max(1, min(50, $this->payloadInt($payload, 'work_max_jobs', 5)));
					$maxTries = max(1, min(10, $this->payloadInt($payload, 'work_max_tries', 3)));

					return $this->workQueueBatch($queueName, $maxJobs, $maxTries);
				},
			),
			'dispatchQueueJob' => $this->guardAndRun(
				tab: 'queues',
				permission: self::PERMISSION_QUEUES,
				guardKey: 'queues_dispatch',
				expectedConfirm: 'DISPATCH',
				cooldown: 2,
				payload: $payload,
				callback: function () use ($payload): array {
					$className = $this->payloadString($payload, 'dispatch_job_class');
					$rawPayload = $this->payloadString($payload, 'dispatch_job_payload', '{}');
					$queueName = $this->payloadString($payload, 'dispatch_job_queue', 'default');
					$delay = max(0, $this->payloadInt($payload, 'dispatch_job_delay', 0));

					return $this->dispatchQueueJob($className, $rawPayload, $queueName, $delay);
				},
			),
			'makeQueueClass' => $this->guardAndRun(
				tab: 'queues',
				permission: self::PERMISSION_QUEUES,
				guardKey: 'queues_make',
				expectedConfirm: 'CREATE',
				cooldown: 2,
				payload: $payload,
				callback: fn(): array => $this->scaffoldComponentFile(
					'queue',
					$this->payloadString($payload, 'make_queue_name')
				),
			),
			'runSeeders' => $this->guardAndRun(
				tab: 'seeding',
				permission: self::PERMISSION_SEEDING,
				guardKey: 'seeding_run',
				expectedConfirm: 'SEED',
				cooldown: 5,
				payload: $payload,
				callback: function () use ($payload): array {
					$target = $this->payloadString($payload, 'seeder_class', 'all');

					return $this->runSeeders($target !== '' ? $target : 'all');
				},
			),
			'makeSeederClass' => $this->guardAndRun(
				tab: 'seeding',
				permission: self::PERMISSION_SEEDING,
				guardKey: 'seeding_make',
				expectedConfirm: 'CREATE',
				cooldown: 2,
				payload: $payload,
				callback: fn(): array => $this->scaffoldComponentFile(
					'seeder',
					$this->payloadString($payload, 'make_seeder_name')
				),
			),
			'runScheduledTasks' => $this->guardAndRun(
				tab: 'scheduling',
				permission: self::PERMISSION_SCHEDULING,
				guardKey: 'scheduling_run',
				expectedConfirm: 'RUN',
				cooldown: 5,
				payload: $payload,
				callback: function () use ($payload): array {
					$target = $this->payloadString($payload, 'schedule_task', 'due');

					return $this->runScheduledTasks($target !== '' ? $target : 'due');
				},
			),
			'makeTaskClass' => $this->guardAndRun(
				tab: 'scheduling',
				permission: self::PERMISSION_SCHEDULING,
				guardKey: 'scheduling_make',
				expectedConfirm: 'CREATE',
				cooldown: 2,
				payload: $payload,
				callback: fn(): array => $this->scaffoldComponentFile(
					'task',
					$this->payloadString($payload, 'make_task_name')
				),
			),
			'createBackup' => $this->guardAndRun(
				tab: 'backup',
				permission: self::PERMISSION_BACKUP,
				guardKey: 'backup_create',
				expectedConfirm: 'BACKUP',
				cooldown: 5,
				payload: $payload,
				callback: fn(): array => $this->createBackup(
					$this->payloadString($payload, 'backup_description')
				),
			),
			'purgeBackups' => $this->guardAndRun(
				tab: 'backup',
				permission: self::PERMISSION_BACKUP,
				guardKey: 'backup_purge',
				expectedConfirm: 'PURGE',
				cooldown: 5,
				payload: $payload,
				callback: fn(): array => $this->purgeBackups(
					max(1, $this->payloadInt($payload, 'purge_days', 30))
				),
			),
			'restoreBackup' => $this->guardAndRun(
				tab: 'backup',
				permission: self::PERMISSION_BACKUP,
				guardKey: 'backup_restore',
				expectedConfirm: 'RESTORE',
				cooldown: 10,
				payload: $payload,
				callback: function () use ($payload): array {
					$fileName = $this->payloadString($payload, 'restore_file');
					$preBackup = $this->payloadBool($payload, 'restore_pre_backup', true);

					return $this->restoreBackup($fileName, $preBackup);
				},
			),
			'runTests' => $this->guardAndRun(
				tab: 'testing',
				permission: self::PERMISSION_TESTING,
				guardKey: 'testing_run',
				expectedConfirm: 'TEST',
				cooldown: 8,
				payload: $payload,
				callback: function () use ($payload): array {
					$rawArgs = $this->payloadString($payload, 'test_args');
					if ($rawArgs === '') {
						$tokens = [];
						$path = $this->payloadString($payload, 'test_path');
						$filter = $this->payloadString($payload, 'test_filter');
						$suite = $this->payloadString($payload, 'test_suite');
						$group = $this->payloadString($payload, 'test_group');
						$stopOnFailure = $this->payloadBool($payload, 'test_stop_on_failure', false);

						if ($path !== '') {
							$tokens[] = $path;
						}
						if ($filter !== '') {
							$tokens[] = '--filter=' . $filter;
						}
						if ($suite !== '') {
							$tokens[] = '--testsuite=' . $suite;
						}
						if ($group !== '') {
							$tokens[] = '--group=' . $group;
						}
						if ($stopOnFailure) {
							$tokens[] = '--stop-on-failure';
						}
						$rawArgs = implode(' ', $tokens);
					}

					return $this->runTests($rawArgs, 60);
				},
			),
			'runE2eTests' => $this->guardAndRun(
				tab: 'testing',
				permission: self::PERMISSION_TESTING,
				guardKey: 'testing_e2e_run',
				expectedConfirm: 'E2E',
				cooldown: 10,
				payload: $payload,
				callback: function () use ($payload): array {
					$rawArgs = $this->payloadString($payload, 'e2e_args');
					if ($rawArgs === '') {
						$tokens = [];
						$path = $this->payloadString($payload, 'e2e_path');
						$module = $this->payloadString($payload, 'e2e_module');
						$grep = $this->payloadString($payload, 'e2e_grep');

						if ($module !== '') {
							$tokens[] = '--module=' . $module;
						} elseif ($path !== '') {
							$tokens[] = $path;
						}
						if ($grep !== '') {
							$tokens[] = '--grep=' . $grep;
						}
						$rawArgs = implode(' ', $tokens);
					}

					return $this->runE2eTests($rawArgs, 120);
				},
			),
			'initPlaywrightE2e' => $this->guardAndRun(
				tab: 'testing',
				permission: self::PERMISSION_TESTING,
				guardKey: 'testing_e2e_init',
				expectedConfirm: 'INIT',
				cooldown: 5,
				payload: $payload,
				callback: fn(): array => $this->initPlaywrightE2e(),
			),
			'makeTestFile' => $this->guardAndRun(
				tab: 'testing',
				permission: self::PERMISSION_TESTING,
				guardKey: 'testing_make',
				expectedConfirm: 'CREATE',
				cooldown: 2,
				payload: $payload,
				callback: fn(): array => $this->scaffoldComponentFile(
					'test',
					$this->payloadString($payload, 'make_test_name'),
					[
						'test_kind' => $this->payloadString($payload, 'make_test_kind', 'feature'),
						'module' => $this->payloadString($payload, 'make_test_module'),
					]
				),
			),
			'syncBoost' => $this->guardAndRun(
				tab: 'boost',
				permission: self::PERMISSION_BOOST,
				guardKey: 'boost_sync',
				expectedConfirm: 'SYNC',
				cooldown: 5,
				payload: $payload,
				callback: fn(): array => $this->syncBoost($this->payloadBool($payload, 'boost_discover', true)),
			),
			'configureBoost' => $this->guardAndRun(
				tab: 'boost',
				permission: self::PERMISSION_BOOST,
				guardKey: 'boost_configure',
				expectedConfirm: 'SAVE',
				cooldown: 5,
				payload: $payload,
				callback: function () use ($payload): array {
					$features = $this->parseCsvList($this->payloadString($payload, 'boost_features', 'guidelines,skills,mcp'));
					$modules = $this->parseCsvList($this->payloadString($payload, 'boost_modules'));
					$agents = $this->parseCsvList($this->payloadString($payload, 'boost_agents'));

					return $this->configureBoost($features, $modules, $agents);
				},
			),
			'installRemoteBoostSkills' => $this->guardAndRun(
				tab: 'boost',
				permission: self::PERMISSION_BOOST,
				guardKey: 'boost_add_skill',
				expectedConfirm: 'INSTALL',
				cooldown: 5,
				payload: $payload,
				callback: function () use ($payload): array {
					$repo = $this->payloadString($payload, 'boost_remote_repo');
					$force = $this->payloadBool($payload, 'boost_remote_force', false);

					return $this->installRemoteBoostSkills($repo, $force);
				},
			),
			'runMcpReadOnlyTool' => $this->guardAndRun(
				tab: 'boost',
				permission: self::PERMISSION_BOOST,
				guardKey: 'boost_mcp_test',
				expectedConfirm: 'QUERY',
				cooldown: 2,
				payload: $payload,
				callback: function () use ($payload): array {
					$toolName = $this->payloadString($payload, 'mcp_tool_name', 'pw_schema_read');
					$rawArgs = $this->payloadString($payload, 'mcp_tool_args', '{}');

					return $this->executeReadOnlyMcpTool($toolName, $rawArgs);
				},
				allowInDemo: true,
			),
			'makeModuleClass' => $this->guardAndRun(
				tab: 'boost',
				permission: self::PERMISSION_BOOST,
				guardKey: 'boost_make_module',
				expectedConfirm: 'CREATE',
				cooldown: 2,
				payload: $payload,
				callback: fn(): array => $this->scaffoldComponentFile(
					'module',
					$this->payloadString($payload, 'make_module_name'),
					['module_type' => $this->payloadString($payload, 'make_module_type', 'module')]
				),
			),
			'enableMaintenanceMode' => $this->guardAndRun(
				tab: 'overview',
				permission: self::PERMISSION_VIEW,
				guardKey: 'system_down',
				expectedConfirm: 'DOWN',
				cooldown: 3,
				payload: $payload,
				callback: function () use ($payload): array {
					$secret = $this->payloadString($payload, 'maintenance_secret');
					$retry = max(60, $this->payloadInt($payload, 'maintenance_retry', 3600));

					return $this->enableMaintenanceMode($secret, $retry);
				},
			),
			'disableMaintenanceMode' => $this->guardAndRun(
				tab: 'overview',
				permission: self::PERMISSION_VIEW,
				guardKey: 'system_up',
				expectedConfirm: 'UP',
				cooldown: 3,
				payload: $payload,
				callback: fn(): array => $this->disableMaintenanceMode(),
			),
			'clearSystemCaches' => $this->guardAndRun(
				tab: 'overview',
				permission: self::PERMISSION_VIEW,
				guardKey: 'system_cache_clear',
				expectedConfirm: 'CACHE',
				cooldown: 3,
				payload: $payload,
				callback: function () use ($payload): array {
					$mode = $this->payloadString($payload, 'cache_clear_mode', 'all');
					if (!in_array($mode, ['all', 'core', 'wire', 'modules'], true)) {
						$mode = 'all';
					}

					return $this->clearSystemCaches($mode);
				},
			),
			'clearLogChannel' => $this->guardAndRun(
				tab: 'logs',
				permission: self::PERMISSION_LOGS,
				guardKey: 'logs_clear',
				expectedConfirm: 'CLEAR',
				cooldown: 3,
				payload: $payload,
				callback: fn(): array => $this->clearLogChannel(
					$this->payloadString($payload, 'clear_log_channel')
				),
			),
			'clearAllLogChannels' => $this->guardAndRun(
				tab: 'logs',
				permission: self::PERMISSION_LOGS,
				guardKey: 'logs_clear_all',
				expectedConfirm: 'CLEARALL',
				cooldown: 5,
				payload: $payload,
				callback: fn(): array => $this->clearAllLogChannels(),
			),
			'inspectFile' => $this->runInspectFileAction($payload),
			default => [
				'ok' => false,
				'message' => sprintf($this->_('Unknown workspace action: %s'), $action),
			],
		};
	}

	/**
	 * Guard an action with RBAC, superuser check, confirmation word, and rate limit.
	 *
	 * @param string $tab
	 * @param string $permission
	 * @param string $guardKey
	 * @param string $expectedConfirm
	 * @param int $cooldown
	 * @param array<string, mixed> $payload
	 * @param callable(): array{ok: bool, message: string, data?: mixed, output?: string, details?: string, tool?: string, isError?: bool, outputFormatted?: string, exitCode?: int, command?: string} $callback
	 * @return array{ok: bool, message: string, data?: mixed, output?: string, details?: string, tool?: string, isError?: bool, outputFormatted?: string, exitCode?: int, command?: string, tab: string}
	 */
	protected function guardAndRun(
		string $tab,
		string $permission,
		string $guardKey,
		string $expectedConfirm,
		int $cooldown,
		array $payload,
		callable $callback,
		bool $allowInDemo = false
	): array {
		if (!$this->userCanView($permission)) {
			$msg = $this->_('Access denied: missing required permission for this workspace section.');
			$this->audit($guardKey, 'DENIED: ' . $msg);
			return [
				'ok' => false,
				'message' => $msg,
				'tab' => $tab,
			];
		}

		if (!$this->isSuperuser()) {
			$msg = $this->_('Mutations and destructive operations require a ProcessWire superuser account.');
			$this->audit($guardKey, 'DENIED: ' . $msg);
			return [
				'ok' => false,
				'message' => $msg,
				'tab' => $tab,
			];
		}

		if (!$allowInDemo && $this->isDemoMode()) {
			$msg = $this->_('Mutations are disabled in demo mode.');
			$this->audit($guardKey, 'DENIED: ' . $msg);
			return [
				'ok' => false,
				'message' => $msg,
				'tab' => $tab,
			];
		}

		$confirmKey = 'confirm_' . $guardKey;
		$actualConfirm = strtoupper($this->payloadString($payload, $confirmKey));
		if ($actualConfirm !== strtoupper($expectedConfirm)) {
			$msg = sprintf(
				$this->_('Confirmation failed: please type "%s" to confirm this operation.'),
				$expectedConfirm
			);
			return [
				'ok' => false,
				'message' => $msg,
				'tab' => $tab,
			];
		}

		$rate = $this->checkRateLimit($guardKey, $cooldown);
		if (!$rate['allowed']) {
			$msg = sprintf(
				$this->_('Rate limit active: please wait %d seconds before repeating this action.'),
				$rate['retryAfter']
			);
			return [
				'ok' => false,
				'message' => $msg,
				'tab' => $tab,
			];
		}

		try {
			$result = $callback();
			$result['tab'] = $tab;
			$this->audit($guardKey, ($result['ok'] ? 'SUCCESS: ' : 'FAILED: ') . ($result['message'] ?? ''));

			return $result;
		} catch (\Throwable $e) {
			$this->audit($guardKey, 'EXCEPTION: ' . $e->getMessage());
			return [
				'ok' => false,
				'message' => $e->getMessage(),
				'tab' => $tab,
			];
		}
	}

	/**
	 * Read-only file inspector action for viewing migration, queue, seeder, task, or test source code.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{ok: bool, message: string, data?: mixed, tab: string}
	 */
	protected function runInspectFileAction(array $payload): array
	{
		$category = $this->payloadString($payload, 'inspect_category', 'migration');
		$file = $this->payloadString($payload, 'inspect_file');
		$tab = $this->payloadString($payload, 'tab', 'overview');

		$tabMap = [
			'migration' => 'migrations',
			'queue' => 'queues',
			'seeder' => 'seeding',
			'task' => 'scheduling',
			'test' => 'testing',
			'skill' => 'boost',
			'boost-doc' => 'boost',
			'backup' => 'backup',
		];
		$requiredTab = $tabMap[$category] ?? $tab;

		if (!$this->userCanInspectCategory($category)) {
			return [
				'ok' => false,
				'message' => $this->_('Access denied: missing permission to inspect files in this category.'),
				'tab' => $requiredTab,
			];
		}

		$inspection = $this->inspectProjectFile($category, $file);
		if ($inspection === null) {
			return [
				'ok' => false,
				'message' => $this->_('Requested file could not be found or is outside the allowlisted directories.'),
				'tab' => $requiredTab,
			];
		}

		return [
			'ok' => true,
			'message' => sprintf($this->_('Loaded preview for %s (%s).'), $inspection['relativePath'], $inspection['sizeFormatted']),
			'data' => $inspection,
			'tab' => $requiredTab,
		];
	}

	/**
	 * Safely extract a trimmed string scalar from a request payload array.
	 *
	 * @param array<string, mixed> $payload
	 */
	protected function payloadString(array $payload, string $key, string $default = ''): string
	{
		if (!array_key_exists($key, $payload)) {
			return $default;
		}
		$val = $payload[$key];
		if (is_string($val)) {
			return trim($val);
		}
		if (is_int($val) || is_float($val)) {
			return (string) $val;
		}
		if (is_bool($val)) {
			return $val ? '1' : '0';
		}

		return $default;
	}

	/**
	 * Safely extract an integer scalar from a request payload array.
	 *
	 * @param array<string, mixed> $payload
	 */
	protected function payloadInt(array $payload, string $key, int $default = 0): int
	{
		if (!array_key_exists($key, $payload)) {
			return $default;
		}
		$val = $payload[$key];
		if (is_int($val)) {
			return $val;
		}
		if (is_string($val) && is_numeric(trim($val))) {
			return (int) trim($val);
		}
		if (is_float($val)) {
			return (int) $val;
		}

		return $default;
	}

	/**
	 * Safely extract a boolean flag from a request payload array.
	 *
	 * @param array<string, mixed> $payload
	 */
	protected function payloadBool(array $payload, string $key, bool $default = true): bool
	{
		if (!array_key_exists($key, $payload)) {
			return $default;
		}
		$val = $payload[$key];
		if (is_bool($val)) {
			return $val;
		}
		if (is_int($val)) {
			return $val !== 0;
		}
		if (is_string($val)) {
			$norm = strtolower(trim($val));
			if (in_array($norm, ['1', 'true', 'on', 'yes'], true)) {
				return true;
			}
			if (in_array($norm, ['0', 'false', 'off', 'no'], true)) {
				return false;
			}
		}

		return $default;
	}

	/**
	 * Parse a comma-separated list into a clean array of non-empty tokens.
	 *
	 * @return list<string>
	 */
	protected function parseCsvList(string $raw): array
	{
		$parts = array_map('trim', explode(',', $raw));
		return array_values(array_filter($parts, static fn(string $item): bool => $item !== ''));
	}
}
