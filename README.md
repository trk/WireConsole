# ProcessWireConsole

Full-page Inertia v3 + React 19 + Tailwind CSS v4 administrative workspace (`Setup > Console`, `{admin}/setup/wire-console/`) for operating **`trk/processwire-console`** and **`trk/processwire-boost`** from the ProcessWire backend.

![ProcessWire Console](https://raw.githubusercontent.com/trk/WireConsole/refs/heads/main/preview.jpg)

## Requirements

- PHP `>= 8.4`
- ProcessWire `>= 3.0.271`
- ProcessWire modules: `Inertia`, `ProcessReactWorkspace`
- Composer packages:
  - `trk/processwire-console` (`^0.2` — schema migrations, background queues, database seeders, scheduled cron tasks, SQL backups, Pest PHP runner, and Playwright E2E runner)
  - `trk/processwire-boost` (`^0.3`, optional — AI guidelines, skills, and the 28-tool JSON-RPC MCP server)

## Installation

### 1. Via Composer & CLI

```bash
composer require trk/processwire-console
composer require --dev trk/processwire-boost
php vendor/bin/wire module:install ProcessWireConsole
```

### 2. Via ProcessWire Admin (Modules Directory / ZIP URL)

1. In the ProcessWire admin, go to **Modules > New**.
2. Install from the **Modules Directory** using class name `ProcessWireConsole`, or from **Add Module from URL** using:
   ```text
   https://github.com/trk/WireConsole/releases/latest/download/WireConsole.zip
   ```
3. Or extract the archive to `site/modules/WireConsole/`, run **Modules > Refresh**, and click **Install** next to **Console** (`ProcessWireConsole`).

## Quick Start

Navigate to **Setup > Console** (or open **Admin > Workspace > Console**) to inspect runtime health, run pending migrations, work queue batches, trigger scheduled tasks, manage SQL snapshots, execute Pest suites, or inspect AI guidelines and read-only MCP tools.

Programmatic inspection via `ConsoleRuntime`:

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

/** @var ProcessWireConsole $console */
$console = $this->wire()->modules->get('ProcessWireConsole');
$runtime = $console->runtime();

$telemetry = $runtime->getOverviewTelemetry();
if ($telemetry['migrations']['pending'] > 0) {
	$this->wire()->log->save(
		$runtime::LOG_CHANNEL,
		sprintf('Pending schema migrations: %d', $telemetry['migrations']['pending'])
	);
}
```

## Configuration & Workspace Sections

`ProcessWireConsole` is zero-configuration out of the box and automatically discovers project features from ProcessWire's native directories (`site/migrations/`, `site/queue/`, `site/seeders/`, `site/schedule/`, `site/assets/backups/database/`, `tests/`, `.agents/`) and installed modules via `ProcessReactWorkspace::deferModuleRegistration(...)`.

| Section | Route | Required Permission | Managed Subsystem |
|---|---|---|---|
| **Overview** | `./` | `wire-console` | Runtime stack versions, package readiness, maintenance mode (`wire down` / `wire up`), and system/WireCache flush |
| **Migrations** | `./migrations/` | `wire-console-migrations` | `wire_migrations` table setup, pending runs, batch rollbacks, `reset`/`refresh`/`fresh` with optional SQL safety snapshot, and typed `make:migration` (`--type`) |
| **Queues** | `./queues/` | `wire-console-queues` | `queue_jobs` & `failed_jobs` tables, synchronous worker batches, job dispatch, failed job retry/delete/clear, pending purge, and `make:queue` |
| **Seeding** | `./seeding/` | `wire-console-seeding` | Discovered `*Seeder.php` execution (`db:seed`), FakerPHP readiness, and `make:seeder` |
| **Scheduling** | `./scheduling/` | `wire-console-scheduling` | Discovered `*Task.php` cron expressions, next-run calculation, `schedule:run`, and `make:task` |
| **Backups** | `./backup/` | `wire-console-backup` | `.sql` snapshot creation, preview, authenticated download, retention purge (`backup:purge`), and restore (`db:restore`) |
| **Testing** | `./testing/` | `wire-console-testing` | `*Test.php` and `*.spec.ts` discovery, filtered Pest execution (`wire test`), headless Playwright E2E execution & setup (`wire test:e2e`, `wire test:e2e --init`), and `make:test` (`--unit`, `--e2e`, `--module`) |
| **Boost & AI** | `./boost/` | `wire-console-boost` | `AGENTS.md` / `SKILL.md` inspection, `boost:update`, `.agents/boost.json` configuration, remote skill auditing/installation, in-process read-only MCP tool testing, MCP guard diagnostics (`PW_MCP_ALLOW_EXECUTE`, `PW_MCP_ALLOW_RESTORE`), and typed `make:module` (`--type`) |
| **Logs & Audit** | `./logs/` | `wire-console-logs` | Structured `wire-console-audit` and ProcessWire log channel browsing, substring search, and channel truncation |

## Hooks & Events

Hookable methods on `ProcessWire\ProcessWireConsole`:

- `ProcessWireConsole::execute()`
- `ProcessWireConsole::executeMigrations()`
- `ProcessWireConsole::executeQueues()`
- `ProcessWireConsole::executeSeeding()`
- `ProcessWireConsole::executeScheduling()`
- `ProcessWireConsole::executeBackup()`
- `ProcessWireConsole::executeTesting()`
- `ProcessWireConsole::executeBoost()`
- `ProcessWireConsole::executeLogs()`
- `ProcessWireConsole::executeAction()`
- `ProcessWireConsole::executeNavJSON(array $options = [])`
- `ProcessWireConsole::executeDownloadBackup()`
- `ProcessWireConsole::translations()`
- `ProcessWireConsole::install()`
- `ProcessWireConsole::uninstall()`

```php
<?php

declare(strict_types=1);

namespace ProcessWire;

$wire->addHookAfter('ProcessWireConsole::execute', function (HookEvent $event): void {
	$user = $event->wire()->user;
	if ($user->isSuperuser()) {
		$event->wire()->log->save('wire-console-audit', 'Console opened by ' . $user->name);
	}
});
```

## CLI & MCP Integration

Every operation in the Console workspace maps directly to `vendor/bin/wire` commands (`--json`, `--dry-run`, `--force`) and `McpServer` JSON-RPC 2.0 read-only tools:

```bash
php vendor/bin/wire version --json
php vendor/bin/wire migrate:status --json
php vendor/bin/wire migrate --json
php vendor/bin/wire migrate:rollback --force --json
php vendor/bin/wire queue:work --queue=default --once --json
php vendor/bin/wire db:seed --class=DatabaseSeeder --force --json
php vendor/bin/wire schedule:run --json
php vendor/bin/wire db:backup --json
php vendor/bin/wire backup:purge --days=30 --force --json
php vendor/bin/wire test
php vendor/bin/wire test:e2e --module=WireConsole
php vendor/bin/wire boost:update --discover --json
php vendor/bin/wire log:tail wire-console-audit
```

## Security & Permissions

- **Granular Section RBAC:** Non-superuser roles granted `wire-console` plus specific `wire-console-*` permissions receive **read-only** visibility restricted strictly to their permitted sections; unauthorized payload slices are omitted (`null`).
- **Superuser & Confirmation Gate:** All mutating actions (`migrate`, `queue:work`, `db:seed`, `schedule:run`, `db:backup`, `db:restore`, `test`, `boost:*`, `make:*`, cache flushes, maintenance toggles, log clearing) enforce:
  1. Valid ProcessWire `$session->CSRF` token (`_token` or `X-CSRF-Token` header)
  2. Authenticated ProcessWire **superuser** account (and `$config->demo` disabled)
  3. Action-specific confirmation token (`RUN`, `ROLLBACK`, `RESET`, `BACKUP`, `RESTORE`, `PURGE`, etc.)
  4. Per-action session cooldown rate-limiting
- **File Inspection & Backup Isolation:** Read-only source inspection (`inspectFile`) validates strict identifier patterns, `!is_link()` + `basename()` / `realpath()` directory containment, and the owning section's permission. Inspecting or downloading `.sql` snapshots additionally requires **superuser** privileges, `wire-console-backup`, and `$config->demo` disabled.
- **Bounded Inputs & Redacted Audit Trail:** JSON payloads are bounded to `64 KB` and `16` nesting levels, Pest CLI arguments are token-allowlisted without shell interpolation, and all actions write structured entries (with automatic secret/token/password redaction) to `wire-console-audit`.
