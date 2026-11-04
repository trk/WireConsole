<?php

declare(strict_types=1);

namespace Htmx\Component\Concerns;

use Totoglu\WireConsole\ConsoleRuntime;

/**
 * Superuser-gated HTMX POST mutation handlers for ConsoleWorkspace.
 *
 * Coordinates all 32 state-changing actions through `beginMutation()`, `completeMutation()`,
 * and `executeScaffoldAction()` so RBAC, Superuser verification, typed confirmation tokens,
 * session cooldowns, `wire-console-audit` JSON logging, and flash notices share a flat,
 * zero-boilerplate contract.
 */
trait HandlesWorkspaceMutations
{
	/**
	 * Install the wire_migrations database tracking table.
	 */
	public function installMigrationsTable(): void
	{
		if (!$this->beginMutation('migrations', ConsoleRuntime::PERMISSION_MIGRATIONS, 'migrations_install', 'RUN', 2)) {
			return;
		}

		$result = $this->runtime()->installMigrationsTable();
		$this->completeMutation(
			'migrations.install',
			$result['ok'],
			$result['message'],
			$this->_('Migrations Table Ready'),
			$this->_('Migration Table Error'),
			['created' => $result['created'], 'message' => $result['message']]
		);
	}

	/**
	 * Execute pending schema migrations.
	 */
	public function runMigrations(): void
	{
		if (!$this->beginMutation('migrations', ConsoleRuntime::PERMISSION_MIGRATIONS, 'migrations_run', 'RUN', 3)) {
			return;
		}

		$steps = max(0, (int) ($this->wire()->input->post('migration_steps') ?? 0));
		$result = $this->runtime()->runMigrations($steps);
		$this->completeMutation(
			'migrations.run',
			$result['ok'],
			$result['message'],
			$this->_('Migrations Executed'),
			$this->_('Migration Failed'),
			['steps' => $steps, 'migrated' => $result['migrated'], 'message' => $result['message']],
			$result['details']
		);
	}

	/**
	 * Roll back the last migration batch or a specified number of steps.
	 */
	public function rollbackMigrations(): void
	{
		if (!$this->beginMutation('migrations', ConsoleRuntime::PERMISSION_MIGRATIONS, 'migrations_rollback', 'ROLLBACK', 3)) {
			return;
		}

		$steps = max(0, (int) ($this->wire()->input->post('rollback_steps') ?? 0));
		$result = $this->runtime()->rollbackMigrations($steps);
		$this->completeMutation(
			'migrations.rollback',
			$result['ok'],
			$result['message'],
			$this->_('Rollback Completed'),
			$this->_('Rollback Failed'),
			['steps' => $steps, 'rolledBack' => $result['rolledBack'], 'message' => $result['message']],
			$result['details'],
			'warning'
		);
	}

	/**
	 * Reset all applied migrations (`migrate:reset`), reset and re-apply (`migrate:refresh`),
	 * or recreate the tracking table and re-apply from scratch (`migrate:fresh`).
	 */
	public function resetOrRefreshMigrations(): void
	{
		if (!$this->beginMutation('migrations', ConsoleRuntime::PERMISSION_MIGRATIONS, 'migrations_reset', 'RESET', 5)) {
			return;
		}

		$mode = strtolower(trim((string) ($this->wire()->input->post('migration_reset_mode') ?? 'reset')));
		if (!in_array($mode, ['reset', 'refresh', 'fresh'], true)) {
			$mode = 'reset';
		}
		$preBackup = ((int) ($this->wire()->input->post('migration_pre_backup') ?? 0)) === 1;
		$result = $this->runtime()->resetMigrations($mode, $preBackup);

		$auditAction = match ($mode) {
			'fresh' => 'migrations.fresh',
			'refresh' => 'migrations.refresh',
			default => 'migrations.reset',
		};
		$successTitle = match ($mode) {
			'fresh' => $this->_('Migrations Rebuilt (Fresh)'),
			'refresh' => $this->_('Migrations Refreshed'),
			default => $this->_('Migrations Reset'),
		};

		$this->completeMutation(
			$auditAction,
			$result['ok'],
			$result['message'],
			$successTitle,
			$this->_('Migration Reset Failed'),
			[
				'mode' => $mode,
				'preBackupFile' => $result['preBackupFile'],
				'rolledBack' => $result['rolledBack'],
				'migrated' => $result['migrated'],
				'message' => $result['message'],
			],
			$result['details'],
			$mode === 'reset' ? 'warning' : 'success'
		);
	}

	/**
	 * Install queue database tables (queue_jobs & failed_jobs).
	 */
	public function installQueueTables(): void
	{
		if (!$this->beginMutation('queues', ConsoleRuntime::PERMISSION_QUEUES, 'queues_install', 'RUN', 2)) {
			return;
		}

		$result = $this->runtime()->installQueueTables();
		$this->completeMutation(
			'queues.install',
			$result['ok'],
			$result['message'],
			$this->_('Queue Tables Ready'),
			$this->_('Queue Setup Failed')
		);
	}

	/**
	 * Retry a single failed job ID or all failed jobs.
	 */
	public function retryFailedJobs(): void
	{
		if (!$this->beginMutation('queues', ConsoleRuntime::PERMISSION_QUEUES, 'queues_retry', 'RETRY', 3)) {
			return;
		}

		$target = trim((string) ($this->wire()->input->post('retry_target') ?? 'all'));
		$result = $this->runtime()->retryFailedJobs($target);
		$this->completeMutation(
			'queues.retry',
			$result['ok'],
			$result['message'],
			$this->_('Queue Retry Completed'),
			$this->_('Queue Retry Failed'),
			['target' => $target, 'retriedCount' => $result['retriedCount'], 'message' => $result['message']]
		);
	}

	/**
	 * Purge all failed job records.
	 */
	public function clearFailedJobs(): void
	{
		if (!$this->beginMutation('queues', ConsoleRuntime::PERMISSION_QUEUES, 'queues_clear', 'CLEAR', 3)) {
			return;
		}

		$result = $this->runtime()->clearFailedJobs();
		$this->completeMutation(
			'queues.clear',
			$result['ok'],
			$result['message'],
			$this->_('Failed Jobs Cleared'),
			$this->_('Clear Failed Jobs Error'),
			['clearedCount' => $result['clearedCount'], 'message' => $result['message']],
			'',
			'warning'
		);
	}

	/**
	 * Purge pending job records from `queue_jobs` (either all queues or a specific queue).
	 */
	public function purgePendingJobs(): void
	{
		if (!$this->beginMutation('queues', ConsoleRuntime::PERMISSION_QUEUES, 'queues_purge_pending', 'PURGE', 3)) {
			return;
		}

		$queueName = trim((string) ($this->wire()->input->post('purge_queue_name') ?? 'all'));
		$result = $this->runtime()->purgePendingJobs($queueName !== '' ? $queueName : 'all');
		$this->completeMutation(
			'queues.purge_pending',
			$result['ok'],
			$result['message'],
			$this->_('Pending Queue Purged'),
			$this->_('Purge Pending Queue Error'),
			['queue' => $queueName, 'purgedCount' => $result['purgedCount'], 'message' => $result['message']],
			'',
			'warning'
		);
	}

	/**
	 * Delete a single failed job record by ID from `failed_jobs`.
	 */
	public function deleteFailedJob(): void
	{
		if (!$this->beginMutation('queues', ConsoleRuntime::PERMISSION_QUEUES, 'queues_delete_one', 'DELETE', 2)) {
			return;
		}

		$jobId = max(0, (int) ($this->wire()->input->post('delete_failed_id') ?? 0));
		$result = $this->runtime()->deleteFailedJob($jobId);
		$this->completeMutation(
			'queues.delete_one',
			$result['ok'],
			$result['message'],
			$this->_('Failed Job Deleted'),
			$this->_('Delete Failed Job Error'),
			['jobId' => $jobId, 'deleted' => $result['deleted'], 'message' => $result['message']],
			'',
			'warning'
		);
	}

	/**
	 * Process a bounded batch of pending queue jobs (`queue:work` batch).
	 */
	public function workQueueBatch(): void
	{
		if (!$this->beginMutation('queues', ConsoleRuntime::PERMISSION_QUEUES, 'queues_work', 'WORK', 3)) {
			return;
		}

		$queueName = trim((string) ($this->wire()->input->post('work_queue_name') ?? 'default'));
		$maxJobs = max(1, min(50, (int) ($this->wire()->input->post('work_max_jobs') ?? 5)));
		$maxTries = max(1, min(10, (int) ($this->wire()->input->post('work_max_tries') ?? 3)));
		$result = $this->runtime()->workQueueBatch($queueName, $maxJobs, $maxTries);

		$this->completeMutation(
			'queues.work',
			$result['ok'],
			$result['message'],
			$this->_('Queue Batch Processed'),
			$this->_('Queue Batch Errors'),
			[
				'queue' => $queueName,
				'maxJobs' => $maxJobs,
				'processed' => $result['processed'],
				'failed' => $result['failed'],
				'message' => $result['message'],
			],
			$result['details']
		);
	}

	/**
	 * Dispatch a discovered Queue handler class onto `queue_jobs` with a JSON payload.
	 */
	public function dispatchQueueJob(): void
	{
		if (!$this->beginMutation('queues', ConsoleRuntime::PERMISSION_QUEUES, 'queues_dispatch', 'DISPATCH', 2)) {
			return;
		}

		$className = trim((string) ($this->wire()->input->post('dispatch_job_class') ?? ''));
		$payload = trim((string) ($this->wire()->input->post('dispatch_job_payload') ?? '{}'));
		$queueName = trim((string) ($this->wire()->input->post('dispatch_queue_name') ?? 'default'));
		$delay = max(0, (int) ($this->wire()->input->post('dispatch_job_delay') ?? 0));
		$result = $this->runtime()->dispatchQueueJob($className, $payload, $queueName, $delay);

		$this->completeMutation(
			'queues.dispatch',
			$result['ok'],
			$result['message'],
			$this->_('Queue Job Dispatched'),
			$this->_('Queue Dispatch Failed'),
			[
				'class' => $className,
				'queue' => $queueName,
				'delay' => $delay,
				'jobId' => $result['jobId'],
				'message' => $result['message'],
			]
		);
	}

	/**
	 * Execute a selected database seeder or all discovered seeders.
	 */
	public function runSeeders(): void
	{
		if (!$this->beginMutation('seeding', ConsoleRuntime::PERMISSION_SEEDING, 'seeding_run', 'SEED', 5)) {
			return;
		}

		$target = trim((string) ($this->wire()->input->post('seeder_target') ?? 'all'));
		$result = $this->runtime()->runSeeders($target);
		$this->completeMutation(
			'seeding.run',
			$result['ok'],
			$result['message'],
			$this->_('Database Seeding Complete'),
			$this->_('Seeding Failed'),
			['target' => $target, 'executed' => $result['executed'], 'message' => $result['message']],
			$result['details']
		);
	}

	/**
	 * Run due scheduled tasks or force-run a specific task class.
	 */
	public function runScheduledTasks(): void
	{
		if (!$this->beginMutation('scheduling', ConsoleRuntime::PERMISSION_SCHEDULING, 'scheduling_run', 'RUN', 5)) {
			return;
		}

		$target = trim((string) ($this->wire()->input->post('schedule_target') ?? 'due'));
		$result = $this->runtime()->runScheduledTasks($target);

		$this->completeMutation(
			'scheduling.run',
			$result['ok'],
			$result['message'],
			$this->_('Scheduler Run Complete'),
			$this->_('Scheduler Run Error'),
			[
				'target' => $target,
				'executed' => $result['executed'],
				'errors' => $result['errors'],
				'message' => $result['message'],
			],
			$result['details']
		);
	}

	/**
	 * Create a new SQL database backup snapshot.
	 */
	public function createBackup(): void
	{
		if (!$this->beginMutation('backup', ConsoleRuntime::PERMISSION_BACKUP, 'backup_create', 'BACKUP', 5)) {
			return;
		}

		$description = trim((string) ($this->wire()->input->post('backup_description') ?? ''));
		$result = $this->runtime()->createBackup($description !== '' ? $description : null);
		$this->completeMutation(
			'backup.create',
			$result['ok'],
			$result['message'],
			$this->_('Backup Created'),
			$this->_('Backup Failed'),
			['file' => $result['file'], 'message' => $result['message']]
		);
	}

	/**
	 * Purge SQL backups older than N days.
	 */
	public function purgeBackups(): void
	{
		if (!$this->beginMutation('backup', ConsoleRuntime::PERMISSION_BACKUP, 'backup_purge', 'PURGE', 5)) {
			return;
		}

		$days = max(1, (int) ($this->wire()->input->post('purge_days') ?? 30));
		$result = $this->runtime()->purgeBackups($days);
		$this->completeMutation(
			'backup.purge',
			$result['ok'],
			$result['message'],
			$this->_('Backup Purge Complete'),
			$this->_('Backup Purge Failed'),
			['days' => $days, 'deletedCount' => $result['deletedCount'], 'message' => $result['message']],
			'',
			'warning'
		);
	}

	/**
	 * Restore a verified SQL backup file.
	 */
	public function restoreBackup(): void
	{
		if (!$this->beginMutation('backup', ConsoleRuntime::PERMISSION_BACKUP, 'backup_restore', 'RESTORE', 10)) {
			return;
		}

		$fileName = trim((string) ($this->wire()->input->post('restore_file') ?? ''));
		$preBackup = ((int) ($this->wire()->input->post('restore_pre_backup') ?? 1)) === 1;
		$result = $this->runtime()->restoreBackup($fileName, $preBackup);

		$this->completeMutation(
			'backup.restore',
			$result['ok'],
			$result['message'],
			$this->_('Database Restored'),
			$this->_('Database Restore Failed'),
			['file' => $fileName, 'preBackupFile' => $result['preBackupFile'], 'message' => $result['message']],
			$result['details']
		);
	}

	/**
	 * Execute the Pest test suite via vendor/bin/wire test.
	 */
	public function runTests(): void
	{
		if (!$this->beginMutation('testing', ConsoleRuntime::PERMISSION_TESTING, 'testing_run', 'TEST', 8)) {
			return;
		}

		$targetPath = trim((string) ($this->wire()->input->post('test_target_path') ?? ''));
		$args = trim((string) ($this->wire()->input->post('test_args') ?? ''));
		if ($targetPath !== '' && !str_contains($args, $targetPath)) {
			$args = trim($targetPath . ' ' . $args);
		}
		$result = $this->runtime()->runTests($args, 60);

		$this->completeMutation(
			'testing.run',
			$result['ok'],
			$result['message'],
			$this->_('Test Suite Passed'),
			$this->_('Test Suite Failed'),
			['args' => $args, 'exitCode' => $result['exitCode'], 'command' => $result['command']],
			$result['output']
		);
	}

	/**
	 * Synchronize ProcessWire Boost guidelines, skills, and schema map (`boost:update`).
	 */
	public function syncBoost(): void
	{
		if (!$this->beginMutation('boost', ConsoleRuntime::PERMISSION_BOOST, 'boost_sync', 'SYNC', 5)) {
			return;
		}

		$discover = ((int) ($this->wire()->input->post('boost_discover') ?? 1)) === 1;
		$result = $this->runtime()->syncBoost($discover);
		$this->completeMutation(
			'boost.sync',
			$result['ok'],
			$result['message'],
			$this->_('Boost Synchronized'),
			$this->_('Boost Sync Failed'),
			[
				'discover' => $discover,
				'agents' => $result['agents'],
				'modules' => $result['modules'],
				'message' => $result['message'],
			]
		);
	}

	/**
	 * Configure ProcessWire Boost features, enabled modules, and AI agents (`boost:install`).
	 */
	public function configureBoost(): void
	{
		if (!$this->beginMutation('boost', ConsoleRuntime::PERMISSION_BOOST, 'boost_configure', 'SAVE', 5)) {
			return;
		}

		$rawFeatures = $this->wire()->input->post('boost_features');
		$rawModules = $this->wire()->input->post('boost_modules');
		$rawAgents = $this->wire()->input->post('boost_agents');

		$features = is_array($rawFeatures) ? array_values(array_map('strval', $rawFeatures)) : ['guidelines', 'skills'];
		$modules = is_array($rawModules) ? array_values(array_map('strval', $rawModules)) : [];
		$agents = is_array($rawAgents) ? array_values(array_map('strval', $rawAgents)) : [];
		$result = $this->runtime()->configureBoost($features, $modules, $agents);

		$this->completeMutation(
			'boost.configure',
			$result['ok'],
			$result['message'],
			$this->_('Boost Configuration Saved'),
			$this->_('Boost Configuration Failed'),
			[
				'features' => $result['features'],
				'modules' => $result['modules'],
				'agents' => $result['agents'],
				'message' => $result['message'],
			]
		);
	}

	/**
	 * Fetch, security-audit, and install remote AI skills from a GitHub repository (`boost:add-skill`).
	 */
	public function installRemoteBoostSkills(): void
	{
		if (!$this->beginMutation('boost', ConsoleRuntime::PERMISSION_BOOST, 'boost_add_skill', 'INSTALL', 5)) {
			return;
		}

		$repo = trim((string) ($this->wire()->input->post('boost_remote_repo') ?? ''));
		$force = ((int) ($this->wire()->input->post('boost_remote_force') ?? 0)) === 1;
		$result = $this->runtime()->installRemoteBoostSkills($repo, $force);

		$this->completeMutation(
			'boost.add_skill',
			$result['ok'],
			$result['message'],
			$this->_('Remote Skills Processed'),
			$this->_('Remote Skill Installation Failed'),
			[
				'repo' => $result['repo'],
				'installed' => $result['installed'],
				'blocked' => $result['blocked'],
				'message' => $result['message'],
			],
			$result['details']
		);
	}

	/**
	 * Execute a read-only ProcessWire Boost MCP tool (`boost:mcp`) and display its JSON-RPC payload.
	 */
	public function runMcpReadOnlyTool(): void
	{
		if (!$this->beginMutation('boost', ConsoleRuntime::PERMISSION_BOOST, 'boost_mcp_test', 'QUERY', 2)) {
			return;
		}

		$toolName = trim((string) ($this->wire()->input->post('mcp_tool_name') ?? 'pw_schema_read'));
		$rawArgs = trim((string) ($this->wire()->input->post('mcp_tool_args') ?? '{}'));
		$result = $this->runtime()->executeReadOnlyMcpTool($toolName, $rawArgs);

		$this->completeMutation(
			'boost.mcp_test',
			$result['ok'],
			$result['message'],
			$this->_('MCP Tool Response'),
			$this->_('MCP Tool Error'),
			['tool' => $result['tool'], 'isError' => $result['isError'], 'message' => $result['message']],
			$result['outputFormatted']
		);
	}

	/**
	 * Scaffold a new ProcessWire module in site/modules/ (`make:module`).
	 */
	public function makeModuleClass(): void
	{
		$this->executeScaffoldAction(
			'boost',
			ConsoleRuntime::PERMISSION_BOOST,
			'boost_make_module',
			'make_module_name',
			'module',
			'boost.make_module',
			$this->_('Module Scaffolded')
		);
	}

	/**
	 * Enable application maintenance mode (`site/down.json`).
	 */
	public function enableMaintenanceMode(): void
	{
		if (!$this->beginMutation('overview', ConsoleRuntime::PERMISSION_VIEW, 'system_down', 'DOWN', 3)) {
			return;
		}

		$secret = trim((string) ($this->wire()->input->post('down_secret') ?? ''));
		$retryRaw = (int) ($this->wire()->input->post('down_retry') ?? 60);
		$retry = $retryRaw > 0 ? $retryRaw : null;
		$result = $this->runtime()->enableMaintenanceMode($secret !== '' ? $secret : null, $retry);

		$this->completeMutation(
			'system.down',
			$result['ok'],
			$result['message'],
			$this->_('Maintenance Mode Active'),
			$this->_('Maintenance Mode Error'),
			['retry' => $retry, 'hasSecret' => $secret !== '', 'message' => $result['message']],
			'',
			'warning'
		);
	}

	/**
	 * Disable application maintenance mode (`site/down.json`).
	 */
	public function disableMaintenanceMode(): void
	{
		if (!$this->beginMutation('overview', ConsoleRuntime::PERMISSION_VIEW, 'system_up', 'UP', 3)) {
			return;
		}

		$result = $this->runtime()->disableMaintenanceMode();
		$this->completeMutation(
			'system.up',
			$result['ok'],
			$result['message'],
			$this->_('Application Live'),
			$this->_('Maintenance Mode Error')
		);
	}

	/**
	 * Clear ProcessWire module cache, FileCompiler cache, WireCache entries, or refresh module registry (`cache:clear` / `cache:wire:clear` / `module:refresh`).
	 */
	public function clearSystemCaches(): void
	{
		if (!$this->beginMutation('overview', ConsoleRuntime::PERMISSION_VIEW, 'system_cache_clear', 'CACHE', 3)) {
			return;
		}

		$mode = strtolower(trim((string) ($this->wire()->input->post('cache_clear_mode') ?? 'all')));
		if (!in_array($mode, ['all', 'wire', 'core', 'modules'], true)) {
			$mode = 'all';
		}
		$result = $this->runtime()->clearSystemCaches($mode);

		$this->completeMutation(
			'system.cache_clear',
			$result['ok'],
			$result['message'],
			$this->_('Caches Cleared'),
			$this->_('Cache Clear Error'),
			['mode' => $mode, 'cleared' => $result['cleared'], 'message' => $result['message']]
		);
	}

	/**
	 * Scaffold a new timestamped migration file (`make:migration`).
	 */
	public function makeMigration(): void
	{
		$this->executeScaffoldAction(
			'migrations',
			ConsoleRuntime::PERMISSION_MIGRATIONS,
			'migrations_make',
			'make_migration_name',
			'migration',
			'migrations.make',
			$this->_('Migration Created')
		);
	}

	/**
	 * Scaffold a new Queue class (`make:queue`).
	 */
	public function makeQueueClass(): void
	{
		$this->executeScaffoldAction(
			'queues',
			ConsoleRuntime::PERMISSION_QUEUES,
			'queues_make',
			'make_queue_name',
			'queue',
			'queues.make',
			$this->_('Queue Handler Created')
		);
	}

	/**
	 * Scaffold a new Seeder class (`make:seeder`).
	 */
	public function makeSeederClass(): void
	{
		$this->executeScaffoldAction(
			'seeding',
			ConsoleRuntime::PERMISSION_SEEDING,
			'seeding_make',
			'make_seeder_name',
			'seeder',
			'seeding.make',
			$this->_('Seeder Class Created')
		);
	}

	/**
	 * Scaffold a new Scheduled Task class (`make:task`).
	 */
	public function makeTaskClass(): void
	{
		$this->executeScaffoldAction(
			'scheduling',
			ConsoleRuntime::PERMISSION_SCHEDULING,
			'scheduling_make',
			'make_task_name',
			'task',
			'scheduling.make',
			$this->_('Scheduled Task Created')
		);
	}

	/**
	 * Scaffold a new Pest feature test (`make:test`).
	 */
	public function makeTestFile(): void
	{
		$this->executeScaffoldAction(
			'testing',
			ConsoleRuntime::PERMISSION_TESTING,
			'testing_make',
			'make_test_name',
			'test',
			'testing.make',
			$this->_('Pest Test Created')
		);
	}

	/**
	 * Clear a selected ProcessWire log channel (`log:clear`).
	 */
	public function clearLogChannel(): void
	{
		if (!$this->beginMutation('logs', ConsoleRuntime::PERMISSION_LOGS, 'logs_clear', 'CLEAR', 3)) {
			return;
		}

		$channel = trim((string) ($this->wire()->input->post('log_channel_target') ?? $this->activeLogChannel));
		$result = $this->runtime()->clearLogChannel($channel);
		$this->completeMutation(
			'logs.clear',
			$result['ok'],
			$result['message'],
			$this->_('Log Channel Cleared'),
			$this->_('Log Clear Error'),
			['channel' => $channel, 'message' => $result['message']],
			'',
			'warning'
		);
	}

	/**
	 * Clear all ProcessWire log channels at once (`log:clear-all`).
	 */
	public function clearAllLogChannels(): void
	{
		if (!$this->beginMutation('logs', ConsoleRuntime::PERMISSION_LOGS, 'logs_clear_all', 'CLEARALL', 5)) {
			return;
		}

		$result = $this->runtime()->clearAllLogChannels();
		$this->completeMutation(
			'logs.clear_all',
			$result['ok'],
			$result['message'],
			$this->_('All Log Channels Cleared'),
			$this->_('Log Clear Error'),
			['clearedCount' => $result['clearedCount'], 'message' => $result['message']],
			'',
			'warning'
		);
	}

	/**
	 * Shared guard, scaffold, audit, and flash-notice workflow for `make:*` component generators.
	 */
	protected function executeScaffoldAction(
		string $tab,
		string $permission,
		string $guardKey,
		string $postField,
		string $kind,
		string $auditAction,
		string $successTitle
	): void {
		if (!$this->beginMutation($tab, $permission, $guardKey, 'CREATE', 2)) {
			return;
		}

		$name = trim((string) ($this->wire()->input->post($postField) ?? ''));
		$result = $this->runtime()->scaffoldComponentFile($kind, $name);
		$this->completeMutation(
			$auditAction,
			$result['ok'],
			$result['message'],
			$successTitle,
			$this->_('Scaffold Failed'),
			['name' => $name, 'file' => $result['file'], 'message' => $result['message']]
		);
	}

	/**
	 * Set the active workspace tab and verify Superuser RBAC, confirmation token, and cooldown rate limit.
	 */
	protected function beginMutation(
		string $tab,
		string $permission,
		string $guardKey,
		string $confirmationWord,
		int $cooldownSeconds
	): bool {
		$this->activeTab = $this->resolvePermittedTab($tab);

		return $this->guardMutation($permission, $guardKey, $confirmationWord, $cooldownSeconds);
	}

	/**
	 * Record a structured `wire-console-audit` log entry and set the workspace flash notice.
	 *
	 * @param array<string, mixed> $auditContext
	 */
	protected function completeMutation(
		string $auditAction,
		bool $ok,
		string $message,
		string $successTitle,
		string $errorTitle,
		array $auditContext = [],
		string $details = '',
		string $successVariant = 'success'
	): void {
		$context = $auditContext !== [] ? $auditContext : ['message' => $message];
		$this->runtime()->audit($auditAction, $ok ? 'ok' : 'error', $context);

		$this->setNotice(
			$ok ? $successVariant : 'danger',
			$ok ? $successTitle : $errorTitle,
			$message,
			$details
		);
	}
}
