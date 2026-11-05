# ProcessWireConsole API Reference

> API reference for `ProcessWire\ProcessWireConsole`, `Totoglu\WireConsole\ConsoleRuntime`, and `Htmx\Component\ConsoleWorkspace`.

## 1. Process Module: `ProcessWire\ProcessWireConsole`

Mounted at **Setup > Console** (`/processwire/setup/wire-console/`). Requires `WireKit` and `Htmx`.

### Permission Constants

- `ProcessWireConsole::PERMISSION_VIEW` (`'wire-console'`)
- `ProcessWireConsole::PERMISSION_MIGRATIONS` (`'wire-console-migrations'`)
- `ProcessWireConsole::PERMISSION_QUEUES` (`'wire-console-queues'`)
- `ProcessWireConsole::PERMISSION_SEEDING` (`'wire-console-seeding'`)
- `ProcessWireConsole::PERMISSION_SCHEDULING` (`'wire-console-scheduling'`)
- `ProcessWireConsole::PERMISSION_BACKUP` (`'wire-console-backup'`)
- `ProcessWireConsole::PERMISSION_TESTING` (`'wire-console-testing'`)
- `ProcessWireConsole::PERMISSION_BOOST` (`'wire-console-boost'`)
- `ProcessWireConsole::PERMISSION_LOGS` (`'wire-console-logs'`)

### Public & Hookable Methods

- `runtime(): ConsoleRuntime` — Returns the active `Totoglu\WireConsole\ConsoleRuntime` service instance.
- `bootComponents(): void` — Registers `ConsoleWorkspace` (`'console'`) into `WireKit::registerWorkspace()` and registers `components/` and `ui/` paths with `WireKit` and `Htmx`.
- `___execute(): string` — Renders the `Htmx\Component\ConsoleWorkspace` dashboard.
- `___executeNavJSON(array $options = []): string` — Populates the `Setup > Console` admin flyout menu filtered by `ConsoleRuntime::getAccessibleTabs()`.
- `___executeDownloadBackup(): void` — Streams a validated `.sql` backup file from `site/assets/backups/database/` (requires superuser + `wire-console-backup`).
- `___uninstall(): void` — Deletes the `wire-console` admin page, removes all `wire-console*` permissions, and clears the `wire-console-audit` log channel.

---

## 2. Domain Service: `Totoglu\WireConsole\ConsoleRuntime`

Encapsulates all ProcessWire Console and Boost inspection and execution operations via trait composition (`InteractsWithMigrations`, `InteractsWithQueues`, `InteractsWithBackups`, `InteractsWithBoost`, `InteractsWithScaffolding`, `InteractsWithSeedingAndScheduling`, `InteractsWithSystemAndLogs`, `InteractsWithTesting`).

### Instantiation & RBAC

- `ConsoleRuntime::make(mixed $wire = null): self`
- `getTabDefinitions(): array<string, array{label: string, icon: string, permission: string, description: string}>`
- `getAccessibleTabs(): array<string, array{label: string, icon: string, permission: string, description: string}>`
- `userCanView(string $permission): bool`
- `userCanInspectCategory(string $category): bool` — Enforces per-category tab permissions (`migration`, `queue`, `seeder`, `task`, `test`, `skill`, `boost-doc`) and requires superuser + `wire-console-backup` for `backup`.
- `isSuperuser(): bool`
- `audit(string $action, string $status, array $context = []): void` — Appends a structured JSON entry to the `wire-console-audit` log channel.

### Subsystem Methods

- **Overview & System (`InteractsWithSystemAndLogs`):** `getVersionMatrix()`, `getPackagesStatus()`, `getOverviewTelemetry()`, `getMaintenanceStatus()`, `toggleMaintenanceMode(bool $down, string $secret = '', int $retry = 60)`, `clearCaches(string $scope = 'all')`, `getLogChannels()`, `getLogEntries(string $channel, int $limit = 50, string $search = '')`, `clearLogChannel(string $channel)`, `inspectProjectFile(string $category, string $identifier)`
- **Migrations (`InteractsWithMigrations`):** `getMigrationsStatus()`, `installMigrationsTable()`, `runPendingMigrations()`, `rollbackMigrations(int $step = 1)`, `resetMigrations()`, `refreshMigrations()`, `freshMigrations()`
- **Queues (`InteractsWithQueues`):** `getQueuesStatus()`, `installQueueTables()`, `workQueueBatch(string $queue = 'default', int $maxJobs = 5)`, `pushQueueJob(string $handlerClass, array $payload = [], string $queue = 'default', int $delay = 0)`, `retryFailedQueueJobs(string $id = 'all')`, `forgetFailedQueueJob(int $id)`, `clearFailedQueueJobs()`, `purgePendingQueueJobs(string $queue = 'default')`
- **Seeding & Scheduling (`InteractsWithSeedingAndScheduling`):** `getSeedersStatus()`, `runSeeder(string $class = 'DatabaseSeeder')`, `getSchedulingStatus()`, `runScheduledTasks(string $taskClass = '')`
- **Backups (`InteractsWithBackups`):** `getBackupsStatus()`, `createDatabaseBackup(string $description = '')`, `restoreDatabaseBackup(string $filename)`, `deleteDatabaseBackup(string $filename)`, `purgeDatabaseBackups(int $keep = 5)`
- **Testing (`InteractsWithTesting`):** `getTestingStatus()`, `discoverTestFiles()`, `runPestTests(array $options = [])`
- **Boost & MCP (`InteractsWithBoost`):** `getBoostStatus()`, `runBoostUpdate(bool $discover = true)`, `addRemoteBoostSkill(string $repo, string $skill = '')`, `executeReadOnlyMcpTool(string $toolName, array $arguments = [])`
- **Scaffolding (`InteractsWithScaffolding`):** `makeMigration(string $name)`, `makeQueue(string $name)`, `makeSeeder(string $name)`, `makeTask(string $name)`, `makeTest(string $name, bool $unit = false, string $module = '')`, `makeModule(string $name, string $type = 'general')`

---

## 3. HTMX v4 Components (`Htmx\Component\*`)

- `Htmx\Component\ConsoleWorkspace` (`extends Totoglu\WireKit\Workspace\BaseWorkspace`) — Main stateful workspace component (`workspaceId(): 'console'`) coordinating tab switching (`querySwitchTab`), read-only file inspection (`queryInspectFile`, `queryCloseInspect`), log filtering (`querySelectLogChannel`, `queryFilterLogs`), and superuser-gated mutation actions (`HandlesWorkspaceMutations`).
- Section Panels (`extends Htmx\Component\ConsolePanelComponent`): `ConsoleOverviewPanel`, `ConsoleMigrationsPanel`, `ConsoleQueuesPanel`, `ConsoleSeedingPanel`, `ConsoleSchedulingPanel`, `ConsoleBackupPanel`, `ConsoleTestingPanel`, `ConsoleBoostPanel`, `ConsoleLogsPanel`.
