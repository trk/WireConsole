---
name: pw-wire-console
description: "Use when working with ProcessWire WireConsole (ProcessWireConsole / Totoglu\\WireConsole\\ConsoleRuntime), operating or extending the Setup > Console administrative workspace, querying ConsoleRuntime telemetry, dispatching guarded workspace actions, inspecting wire-console-audit logs, or configuring wire-console* RBAC permissions."
risk: safe
source: processwire-boost
metadata:
  version: 210
  processwire-version: ">=3.0.271"
  php-version: ">=8.4"
  triggers:
    - WireConsole
    - ProcessWireConsole
    - Totoglu\WireConsole
    - ConsoleRuntime
    - WireConsole/Workspace
    - wire-console
    - wire-console-migrations
    - wire-console-queues
    - wire-console-seeding
    - wire-console-scheduling
    - wire-console-backup
    - wire-console-testing
    - wire-console-boost
    - wire-console-logs
    - wire-console-audit
---

# ProcessWire WireConsole (`pw-wire-console`)

Full-page Inertia v3 + React 19 + Tailwind CSS v4 administrative control plane (`Setup > Console`, `{admin}/setup/wire-console/`) for operating `trk/processwire-console` and `trk/processwire-boost` from the ProcessWire backend.

## Context Pointers

1. **Module API Reference:** Read `site/modules/WireConsole/API.md` for all `ProcessWireConsole` hookable endpoints and `ConsoleRuntime` method signatures.
2. **Module Architecture Invariants:** Read `site/modules/WireConsole/AGENTS.md` before modifying PHP traits in `src/Concerns/` or React panels in `resources/ts/`.
3. **Active Runtime Inspection:** Query `pw_module_info` (`ProcessWireConsole`) and `pw_schema_read` (permissions `wire-console*`) via MCP before writing RBAC selectors or hooks.

## Core Architecture

- **`ProcessWire\ProcessWireConsole`** (`extends Totoglu\ProcessReactWorkspace\Inertia\ProcessReact`):
  - Autoload Process module registering `Totoglu\WireConsole` -> `__DIR__ . '/src/'` strictly once in `__construct()`.
  - Defers `'console'` workspace and `'console-overview'` dashboard widget registration via `ProcessReactWorkspace::deferModuleRegistration(...)` so frontend requests incur zero boot overhead.
  - Preloads `ReactIcons.js` (`window.ProcessWireIcons`) and versioned `dist/ProcessWireConsole.js` via `getModuleScriptAssets()`.
- **`Totoglu\WireConsole\ConsoleRuntime`** (`extends ProcessWire\Wire`):
  - Domain service returned by `$console->runtime()`, composed of 9 focused traits in `src/Concerns/`:
    `HandlesWorkspaceActions`, `InteractsWithMigrations`, `InteractsWithQueues`, `InteractsWithBackups`, `InteractsWithBoost`, `InteractsWithScaffolding`, `InteractsWithSeedingAndScheduling`, `InteractsWithSystemAndLogs`, and `InteractsWithTesting`.
  - Delegates operations directly to `Totoglu\Console\*` APIs and in-process Symfony Console commands (`--json`) without duplicating worker, seeder, scheduler, or scaffolding loops.

## Workspace Sections & Granular RBAC

| Section | Route | Required View Permission | Subsystem API Trait |
|---|---|---|---|
| **Overview** | `./` | `wire-console` | `InteractsWithSystemAndLogs` |
| **Migrations** | `./migrations/` | `wire-console-migrations` | `InteractsWithMigrations` |
| **Queues** | `./queues/` | `wire-console-queues` | `InteractsWithQueues` |
| **Seeding** | `./seeding/` | `wire-console-seeding` | `InteractsWithSeedingAndScheduling` |
| **Scheduling** | `./scheduling/` | `wire-console-scheduling` | `InteractsWithSeedingAndScheduling` |
| **Backups** | `./backup/` | `wire-console-backup` | `InteractsWithBackups` |
| **Testing** | `./testing/` | `wire-console-testing` | `InteractsWithTesting` |
| **Boost & AI** | `./boost/` | `wire-console-boost` | `InteractsWithBoost` |
| **Logs & Audit** | `./logs/` | `wire-console-logs` | `InteractsWithSystemAndLogs` |

- **Read-Only Non-Superuser Access:** Roles granted `wire-console` plus specific `wire-console-*` permissions can view only those sections; unauthorized payload keys resolve to `null` and disallowed deep-links fall back to `'overview'`.
- **Superuser & Demo-Mode Mutation Gate:** Every mutating action (`executeWorkspaceAction`) requires:
  1. Valid ProcessWire `$session->CSRF` token (`_token` or `X-CSRF-Token` header) on HTTP `POST ./action/`
  2. Authenticated ProcessWire **superuser** account (`$runtime->isSuperuser()`) and `$config->demo` disabled (`!$runtime->isDemoMode()`, except allowlisted read-only `runMcpReadOnlyTool`)
  3. Exact confirmation word (`confirm_<guardKey>`)
  4. Active per-session cooldown window (`checkRateLimit()`)
- **SQL Backup Containment:** Previewing (`inspectProjectFile('backup', ...)`) or downloading (`./download-backup/?file=...`) `.sql` files requires **superuser** privileges, `wire-console-backup`, and `$config->demo` disabled, plus strict `basename()`, `!is_link()`, and `realpath()` containment inside `site/assets/backups/database/`.

## Programmatic Usage (`ConsoleRuntime`)

### 1. Inspecting Runtime Telemetry & Subsystem Status

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var ProcessWireConsole $console */
$console = $this->wire()->modules->get('ProcessWireConsole');
$runtime = $console->runtime();

$overview = $runtime->getOverviewTelemetry();
$pendingMigrations = $overview['migrations']['pending'];
$failedJobs = $overview['queues']['failedCount'];
$isMaintenance = $overview['maintenance']['isDown'];

if ($pendingMigrations > 0 || $failedJobs > 0) {
	$runtime->audit('health.check', 'attention_required', [
		'pendingMigrations' => $pendingMigrations,
		'failedJobs' => $failedJobs,
		'isMaintenance' => $isMaintenance,
	]);
}
```

### 2. Executing Guarded Workspace Actions Programmatically

When invoking `executeWorkspaceAction()` from trusted backend code (with an authenticated superuser wired to `$wire->user`), pass the required `confirm_<guardKey>` token:

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var ProcessWireConsole $console */
$console = $this->wire()->modules->get('ProcessWireConsole');
$runtime = $console->runtime();

// Flush WireCache entries through the guarded dispatcher
$cacheResult = $runtime->executeWorkspaceAction('clearSystemCaches', [
	'cache_clear_mode' => 'wire',
	'confirm_system_cache_clear' => 'CACHE',
]);

// Run a read-only Boost MCP tool in-process via JSON-RPC 2.0
$mcpResult = $runtime->executeWorkspaceAction('runMcpReadOnlyTool', [
	'mcp_tool_name' => 'pw_schema_read',
	'mcp_tool_args' => '{}',
	'confirm_boost_mcp_test' => 'QUERY',
]);
```

### 3. Hooking Console Endpoints & Reading Audit Logs

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

$wire->addHookAfter('ProcessWireConsole::executeAction', function (HookEvent $event): void {
	$input = $event->wire()->input;
	$action = (string) ($input->post('action') ?? '');
	if ($action === 'restoreBackup') {
		$event->wire()->log->save('security', 'Database restore triggered in Console');
	}
});

/** @var ProcessWireConsole $console */
$console = $wire->modules->get('ProcessWireConsole');
$recentAudits = $console->runtime()->readLogEntries('wire-console-audit', 25, 'DENIED');
```

## Common Anti-Patterns to Avoid

- **Hardcoding `/processwire/` URLs:** Never hardcode `/processwire/setup/wire-console/` in PHP, JS, or Playwright tests. Resolve admin URLs dynamically from `$this->wire()->config->urls->admin` (or `getAdminUrl('setup/wire-console/')` in E2E specs).
- **Using `#pw-internal` Log Methods:** Use `$this->wire()->log->getLogs(true)` rather than `#pw-internal` `$log->getFiles()` when enumerating log channels.
- **Bypassing Symlink & Concrete-Class Checks:** Never read or instantiate discovered files/classes without checking `!is_link()`, `realpath()` project-root containment, and `ReflectionClass` (`!$ref->isAbstract() && $ref->isSubclassOf(...)`).
- **Breaking Zero-Business-Coupling:** `ProcessWireConsole` is a foundational infrastructure module (`Inertia` + `ProcessReactWorkspace` only). Never import domain modules (`WireEntries`, `WireBlocks`, `WireForms`, `WireSeo`, `WireNavigation`, `WireHeadless`) into `WireConsole`.
- **Desynchronizing Localization Packs:** Whenever a `$this->_('...')` string changes in `ProcessWireConsole.module.php` or `src/`, rebuild `dist/ProcessWireConsole.js` and re-synchronize all 6 `languages/{en,tr,de,fr,es,ru}.csv` files; ensure every PHP file under `tests/` starts with `<?php // __(file-not-translatable)`.
