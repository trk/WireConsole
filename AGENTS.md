---
description: "Architecture and development reference for ProcessWireConsole (Totoglu\\WireConsole) in ProcessWire 3.0.271+."
---

# ProcessWireConsole (`Totoglu\WireConsole`) Agent Guidelines

`ProcessWireConsole` (`site/modules/WireConsole`) provides the administrative HTMX v4 control plane (`Setup > Console`, `/processwire/setup/wire-console/`) for `trk/processwire-console` and `trk/processwire-boost`.

## Architectural Invariants

1. **Layering & Dependencies (`WireKit`):**
   - `ProcessWireConsole` requires `['PHP>=8.4', 'ProcessWire>=3.0.271', 'WireKit']`.
   - Zero dependency on `WireEntries`, `WireSeo`, `WireForms`, `WireNavigation`, or `WireHeadless`.
   - Registers its workspace into `WireKit`'s `WorkspaceRegistry` under slug `'console'` (`Htmx\Component\ConsoleWorkspace extends Totoglu\WireKit\Workspace\BaseWorkspace`).
2. **Delegation to `trk/processwire-console` (`Totoglu\Console\*`):**
   - `Totoglu\WireConsole\ConsoleRuntime` delegates migration discovery and execution to `Totoglu\Console\Migration\Migrator`, queue management to `Totoglu\Console\Queue\QueueManager`, feature discovery to `Totoglu\Console\Support\FeatureDiscoverer`, scaffolding to `Totoglu\Console\Support\StubGenerator` and `Totoglu\Console\Commands\Make\*`, and Boost/MCP operations to `Totoglu\Console\Boost\BoostManager` and `Totoglu\Console\Boost\Mcp\McpServer`.
   - Never duplicate CLI runtime logic when `Totoglu\Console\*` services provide it directly.
3. **Strict RBAC & File Inspection Boundaries (`ConsoleRuntime`):**
   - Viewing tabs requires `wire-console` plus the tab-specific permission (`wire-console-migrations`, `wire-console-queues`, `wire-console-seeding`, `wire-console-scheduling`, `wire-console-backup`, `wire-console-testing`, `wire-console-boost`, `wire-console-logs`).
   - `ConsoleRuntime::userCanInspectCategory()` enforces the owning tab's permission on `queryInspectFile()` / `inspectProjectFile()`, and requires **superuser** privileges plus `wire-console-backup` for inspecting or downloading `.sql` backups.
   - All mutating actions (`migrate`, `queue:work`, `db:seed`, `schedule:run`, `db:backup`, `db:restore`, `test`, `boost:update`, `make:*`, cache flushes, maintenance toggles) require a **superuser** account, rate-limit check, and audit logging to `wire-console-audit`.
4. **Testing:**
   - Run the module test suite via `php vendor/bin/wire test site/modules/WireConsole/tests`.
