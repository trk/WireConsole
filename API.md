# ProcessWireConsole API Reference

> Developer reference for `ProcessWire\ProcessWireConsole` (`extends ProcessReact` / `ProcessInertia`), `Totoglu\WireConsole\ConsoleRuntime`, and the `WireConsole/Workspace` Inertia + React workspace.

## 1. Process Module: `ProcessWire\ProcessWireConsole`

Mounted at **Setup > Console** (`{admin}/setup/wire-console/`). Extends `Totoglu\ProcessReactWorkspace\Inertia\ProcessReact` (`Totoglu\Inertia\ProcessInertia` + `InteractWithReact`), requiring `Inertia` and `ProcessReactWorkspace`.

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var ProcessWireConsole $console */
$console = $this->wire()->modules->get('ProcessWireConsole');
$runtime = $console->runtime();
```

### Inheritance Hierarchy

- `ProcessWire\ProcessWireConsole`
  - `extends Totoglu\ProcessReactWorkspace\Inertia\ProcessReact` (`uses InteractWithReact`)
    - `extends Totoglu\Inertia\ProcessInertia` (`uses InteractWithInertia`)
      - `extends ProcessWire\Process`

### Class Constants

| Constant | Value | Purpose |
|---|---|---|
| `ProcessWireConsole::PERMISSION_VIEW` | `'wire-console'` | Access `Setup > Console` and Overview section |
| `ProcessWireConsole::PERMISSION_MIGRATIONS` | `'wire-console-migrations'` | View Migrations section & inspect `site/migrations/` |
| `ProcessWireConsole::PERMISSION_QUEUES` | `'wire-console-queues'` | View Queues section & inspect `site/queue/` |
| `ProcessWireConsole::PERMISSION_SEEDING` | `'wire-console-seeding'` | View Seeding section & inspect `site/seeders/` |
| `ProcessWireConsole::PERMISSION_SCHEDULING` | `'wire-console-scheduling'` | View Scheduling section & inspect `site/schedule/` |
| `ProcessWireConsole::PERMISSION_BACKUP` | `'wire-console-backup'` | View Backups section (superuser required to preview/download `.sql`) |
| `ProcessWireConsole::PERMISSION_TESTING` | `'wire-console-testing'` | View Testing section & inspect `tests/`, `site/tests/`, and `tests/e2e/` |
| `ProcessWireConsole::PERMISSION_BOOST` | `'wire-console-boost'` | View Boost & AI section & inspect `.agents/` |
| `ProcessWireConsole::PERMISSION_LOGS` | `'wire-console-logs'` | View Logs & Audit section |
| `ProcessWireConsole::MAX_JSON_BODY_BYTES` | `65536` | Maximum JSON request body size (`64 KB`) accepted by `extractRequestPayload()` |
| `ProcessWireConsole::MAX_JSON_DEPTH` | `16` | Maximum JSON nesting depth accepted by `extractRequestPayload()` |
| `ProcessWireConsole::MAX_JSON_ELEMENTS` | `256` | Maximum total element count accepted in POST/JSON payloads by `extractRequestPayload()` |

### Public & Hookable Methods (`*` = hookable `___method`)

| Method | Signature | Description |
|---|---|---|
| `getModuleInfo` | `public static function getModuleInfo(): array` | Module metadata, permissions, and `requires` declaration. |
| `isAutoload` | `public function isAutoload(): bool` | Returns `true` so deferred workspace (`'console'`) and widget (`'console-overview'`) registrations attach on boot. |
| `init` | `public function init(): void` | Registers admin vector icons via `IconManager::registerAdminIcons(...)` and defers workspace/widget registration via `ProcessReactWorkspace::deferModuleRegistration(...)`. |
| `getModuleScriptAssets` | `public function getModuleScriptAssets(): array` | Returns `list<string>` containing `ProcessReactWorkspace`'s `ReactIcons.js` asset URL and the versioned `dist/ProcessWireConsole.js` bundle URL. |
| `runtime` | `public function runtime(): ConsoleRuntime` | Returns the wired `Totoglu\WireConsole\ConsoleRuntime` instance. |
| `*execute` | `public function ___execute(): string` | Renders `WireConsole/Workspace` for the `overview` section (`Setup > Console`). |
| `*executeMigrations` | `public function ___executeMigrations(): string` | Renders the `migrations` section (`./migrations/`). |
| `*executeQueues` | `public function ___executeQueues(): string` | Renders the `queues` section (`./queues/`). |
| `*executeSeeding` | `public function ___executeSeeding(): string` | Renders the `seeding` section (`./seeding/`). |
| `*executeScheduling` | `public function ___executeScheduling(): string` | Renders the `scheduling` section (`./scheduling/`). |
| `*executeBackup` | `public function ___executeBackup(): string` | Renders the `backup` section (`./backup/`). |
| `*executeTesting` | `public function ___executeTesting(): string` | Renders the `testing` section (`./testing/`). |
| `*executeBoost` | `public function ___executeBoost(): string` | Renders the `boost` section (`./boost/`). |
| `*executeLogs` | `public function ___executeLogs(): string` | Renders the `logs` section (`./logs/`). |
| `*executeAction` | `public function ___executeAction(): string` | Validates CSRF and dispatches a posted workspace action (`./action/`) via `ConsoleRuntime::executeWorkspaceAction(...)`. |
| `*executeNavJSON` | `public function ___executeNavJSON(array $options = []): string\|array` | Returns the `Setup > Console` admin flyout menu JSON filtered by accessible section permissions. |
| `*executeDownloadBackup` | `public function ___executeDownloadBackup(): void` | Streams a verified `.sql` snapshot from `site/assets/backups/database/` (throws `WirePermissionException` for non-superusers or when `$config->demo` is active, and `Wire404Exception` for missing/invalid files). |
| `*translations` | `public function ___translations(): array` | Returns the nested UI translation catalog consumed by `ProcessReactWorkspace::resolveModuleTranslations(...)`. |
| `*install` | `public function ___install(): void` | Creates the `Setup > Console` admin page and installs all `wire-console*` permissions idempotently. |
| `*uninstall` | `public function ___uninstall(): void` | Deletes `wire-console-audit` logs, removes `wire-console*` `WireCache` entries, and removes all `wire-console*` permissions. |

---

## 2. Domain Runtime: `Totoglu\WireConsole\ConsoleRuntime`

Registered under `Totoglu\WireConsole` in `ProcessWireConsole::__construct()` and composed of focused subsystem traits (`HandlesWorkspaceActions`, `InteractsWithMigrations`, `InteractsWithQueues`, `InteractsWithBackups`, `InteractsWithBoost`, `InteractsWithScaffolding`, `InteractsWithSeedingAndScheduling`, `InteractsWithSystemAndLogs`, `InteractsWithTesting`).

### Class Constants

| Constant | Value | Purpose |
|---|---|---|
| `ConsoleRuntime::LOG_CHANNEL` | `'wire-console-audit'` | ProcessWire log channel receiving structured JSON audit entries |
| `ConsoleRuntime::TABLE_MIGRATIONS` | `'wire_migrations'` | Database table tracking applied schema migrations |
| `ConsoleRuntime::TABLE_QUEUE_JOBS` | `'queue_jobs'` | Database table storing pending and reserved queue jobs |
| `ConsoleRuntime::TABLE_QUEUE_FAILED` | `'failed_jobs'` | Database table storing failed queue job records |
| `ConsoleRuntime::MAX_JSON_BODY_BYTES` | `65536` | Maximum JSON argument string byte length (`64 KB`) |
| `ConsoleRuntime::MAX_JSON_DEPTH` | `16` | Maximum JSON argument nesting depth |
| `ConsoleRuntime::MAX_JSON_ELEMENTS` | `512` | Maximum total element count in decoded queue/MCP JSON payloads |
| `ConsoleRuntime::PERMISSION_*` | `'wire-console*'` | 1-to-1 permission constants matching `ProcessWireConsole::PERMISSION_*` |

### Core Runtime & Security Methods

| Method | Signature | Description |
|---|---|---|
| `make` | `public static function make(mixed $wire = null): self` | Instantiates and wires a `ConsoleRuntime` bound to `$wire`. |
| `getTabDefinitions` | `public function getTabDefinitions(): array` | Returns metadata (`label`, `icon`, `permission`, `description`) for all 9 workspace sections. |
| `getAccessibleTabs` | `public function getAccessibleTabs(): array` | Filters `getTabDefinitions()` to sections permitted for the current user. |
| `userCanView` | `public function userCanView(string $permission): bool` | Checks whether the current user is logged in and holds `$permission`. |
| `userCanInspectCategory` | `public function userCanInspectCategory(string $category): bool` | Enforces section-level RBAC (plus superuser and non-demo checks for `'backup'`) before file inspection. |
| `isSuperuser` | `public function isSuperuser(): bool` | Returns `true` only for an authenticated ProcessWire superuser. |
| `isDemoMode` | `public function isDemoMode(): bool` | Returns `true` when ProcessWire demo mode (`$config->demo`) is active. |
| `getProjectRootPath` | `public function getProjectRootPath(): string` | Resolves the normalized root path of the ProcessWire installation. |
| `isConsolePackageAvailable` | `public function isConsolePackageAvailable(): bool` | Returns `true` when `trk/processwire-console` is installed in `vendor/`. |
| `isBoostPackageAvailable` | `public function isBoostPackageAvailable(): bool` | Returns `true` when `trk/processwire-boost` is installed in `vendor/`. |
| `getPackagesStatus` | `public function getPackagesStatus(): array` | Inspects `composer.json`, `vendor/composer/installed.json`, and `vendor/bin/wire` readiness. |
| `getOverviewTelemetry` | `public function getOverviewTelemetry(?array $migrationsStatus = null, ?array $backups = null, ?array $testingEnvironment = null, ?array $boostStatus = null, ?array $seeders = null, ?array $scheduledTasks = null): array` | Aggregates subsystem telemetry counters for the `Overview` section. |
| `buildWorkspacePayload` | `public function buildWorkspacePayload(string $activeTab = 'overview', array $options = []): array` | Builds RBAC-filtered Inertia props for `WireConsole/Workspace` (falling back disallowed tabs to `'overview'`). |
| `executeWorkspaceAction` | `public function executeWorkspaceAction(string $action, array $payload = []): array` | Dispatches all 34 guarded actions (`guardAndRun` with RBAC, superuser, confirmation word, cooldown, and audit logging) plus `inspectFile`. |
| `checkRateLimit` | `public function checkRateLimit(string $actionKey, int $cooldownSeconds): array` | Enforces per-session cooldown rate-limiting for mutating operations. |
| `audit` | `public function audit(string $action, string $status, array $context = []): void` | Appends a structured JSON audit record to `wire-console-audit` with recursive secret/token/password redaction. |

### Subsystem Methods

- **Migrations (`InteractsWithMigrations`):** `migrationsTableExists(): bool`, `installMigrationsTable(): array`, `getMigrationsStatus(): array`, `runMigrations(int $steps = 0): array`, `rollbackMigrations(int $steps = 0): array`, `resetMigrations(string $mode = 'reset', bool $createSafetyBackup = false): array`
- **Queues (`InteractsWithQueues`):** `queueTablesExist(): bool`, `installQueueTables(): array`, `getQueuesSummary(): array`, `getQueuesStatus(int $limit = 50): array`, `purgePendingJobs(string $queueName = 'all'): array`, `retryFailedJobs(string $idOrAll): array`, `clearFailedJobs(): array`, `deleteFailedJob(int $jobId): array`, `workQueueBatch(string $queueName = 'default', int $maxJobs = 5, int $maxTries = 3): array`, `dispatchQueueJob(string $className, string $rawJsonPayload = '{}', string $queueName = 'default', int $delaySeconds = 0): array`
- **Seeding & Scheduling (`InteractsWithSeedingAndScheduling`):** `hasFaker(): bool`, `getDiscoveredSeeders(): array`, `runSeeders(string $seederNameOrAll): array`, `getScheduledTasks(): array`, `runScheduledTasks(string $taskNameOrDue = 'due'): array`
- **Backups (`InteractsWithBackups`):** `getBackupDirectory(): string`, `listBackups(int $limit = 50): array`, `resolveBackupFilePath(string $fileName): ?string`, `createBackup(?string $description = null): array`, `purgeBackups(int $olderThanDays): array`, `restoreBackup(string $fileName, bool $createSafetyBackup = true): array`
- **Testing (`InteractsWithTesting`):** `getDiscoveredTestFiles(): array`, `getTestingEnvironment(): array`, `sanitizeTestArgs(string $rawArgs): array`, `sanitizeE2eArgs(string $rawArgs): array`, `resolvePhpCliBinary(?string $currentBinary = null): string`, `runTests(string $rawArgs = '', int $timeoutSeconds = 60): array`, `runE2eTests(string $rawArgs = '', int $timeoutSeconds = 120): array`, `initPlaywrightE2e(): array`
- **Boost & MCP (`InteractsWithBoost`):** `getReadOnlyMcpToolNames(): array`, `executeReadOnlyMcpTool(string $toolName, string $rawJsonArguments = '{}'): array`, `getMcpServerDiagnostics(): array`, `getBoostStatus(): array`, `syncBoost(bool $discoverNewModules = true): array`, `configureBoost(array $features, array $modules, array $agentNames): array`, `installRemoteBoostSkills(string $repoInput, bool $forceOverwrite = false): array`
- **Scaffolding & Inspector (`InteractsWithScaffolding`):** `isValidIdentifier(string $name): bool`, `scaffoldComponentFile(string $kind, string $rawName, array $options = []): array`, `inspectProjectFile(string $category, string $identifier): ?array`
- **System & Logs (`InteractsWithSystemAndLogs`):** `getVersionMatrix(): array`, `getMaintenanceFilePath(): string`, `getMaintenanceStatus(): array`, `enableMaintenanceMode(?string $secret = null, ?int $retry = null, int $status = 503, ?string $redirect = null): array`, `disableMaintenanceMode(): array`, `clearSystemCaches(string $mode = 'all'): array`, `getLogChannels(): array`, `readLogEntries(string $channelName, int $limit = 50, string $search = ''): array`, `clearLogChannel(string $channelName): array`, `clearAllLogChannels(): array`

---

## 3. Inertia + React Frontend (`resources/ts/` -> `dist/ProcessWireConsole.js`)

- `resources/ts/runtime.ts` — Strictly typed bindings onto `window.ReactWorkspaceRuntime` (`React`, `Inertia`, `AppShell`, `DynamicIcon`, UI primitives, and `useTranslations`).
- `resources/ts/types.ts` — Strict TypeScript interfaces for `WorkspaceProps` and all 9 section payloads.
- `resources/ts/entries/ProcessWireConsole.tsx` — Registers `WireConsole/Workspace` via `registerPages(...)` and compiles to `dist/ProcessWireConsole.js`.
- `resources/ts/pages/Workspace.tsx` — `AppShell` layout, RBAC-safe section navigation, slide-over `Sheet` source inspector, and `ConfirmDialog` acknowledgment workflow.
- `resources/ts/panels/*.tsx` — Section panels (`OverviewPanel`, `MigrationsPanel`, `QueuesPanel`, `SeedingPanel`, `SchedulingPanel`, `BackupPanel`, `TestingPanel`, `BoostPanel`, `LogsPanel`).
