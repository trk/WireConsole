---
description: "Architecture and development reference for ProcessWireConsole (Totoglu\\WireConsole) in ProcessWire 3.0.271+."
---

# ProcessWireConsole (`Totoglu\WireConsole`) Agent Guidelines

`ProcessWireConsole` (`site/modules/WireConsole`) provides the Inertia v3 + React 19 + Tailwind CSS v4 administrative control plane (`Setup > Console`, `/processwire/setup/wire-console/`) for `trk/processwire-console` and `trk/processwire-boost`.

## Architectural Invariants

1. **Layering & Single-Point `WireClassLoader` Boot:**
   - `ProcessWireConsole` extends `Totoglu\ProcessReactWorkspace\Inertia\ProcessReact` (`Totoglu\Inertia\ProcessInertia` + `InteractWithReact`) and requires `['PHP>=8.4', 'ProcessWire>=3.0.271', 'Inertia', 'ProcessReactWorkspace']`.
   - Zero dependency on `WireEntries`, `WireSeo`, `WireForms`, `WireNavigation`, `WireBlocks`, or `WireHeadless` (enforced by `tests/Unit/LayeringTest.php`).
   - Registers `Totoglu\WireConsole` -> `__DIR__ . '/src/'` strictly once inside `ProcessWireConsole::__construct()`. Keep `PERMISSION_*` constants on `ProcessWireConsole` as literal strings (synchronized 1-to-1 with `ConsoleRuntime::PERMISSION_*`) so PHP class compilation and `static getModuleInfo()` never autoload `src/` before `__construct()` executes. Never add `require_once` or `class_exists` shims in `ProcessWireConsole.module.php` or `src/`.
   - Defers `'console'` workspace and `'console-overview'` widget registration via `ProcessReactWorkspace::deferModuleRegistration(...)` so frontend requests pay zero boot overhead.
2. **Shared Runtime (`window.ReactWorkspaceRuntime`) & Vector Icons:**
   - `dist/ProcessWireConsole.js` imports React, Inertia, `AppShell`, `DynamicIcon`, UI primitives, and `useTranslations` with strict TypeScript types from `window.ReactWorkspaceRuntime` (`resources/ts/runtime.ts`).
   - Preloads `ReactIcons.js` (`window.ProcessWireIcons`) via `getModuleScriptAssets()` and registers admin icon names via `IconManager::registerAdminIcons(...)` in `init()`. Every icon identifier in PHP and TSX must exist in `ProcessReactWorkspace`'s `vector-icons.json` outline catalog; `executeNavJSON()` maps section icons to classic ProcessWire admin flyout icon names via `toAdminFlyoutIcon()`.
   - Only use Tailwind CSS v4 utility classes already compiled in `ProcessReactWorkspace/dist/ReactInertia.css` (or inline React `style` objects when a layout property is absent from `ReactInertia.css`).
3. **Direct Delegation to `Totoglu\Console\*`:**
   - Delegate migrations to `Totoglu\Console\Migration\Migrator`, queues to `Totoglu\Console\Queue\QueueManager` and `QueueWorkCommand`, seeders/tasks/maintenance/cache/logs/backup-purge to `DbSeedCommand` / `ScheduleRunCommand` / `DownCommand` / `UpCommand` / `CacheClearCommand` / `LogClearCommand` / `LogClearAllCommand` / `BackupPurgeCommand`, scaffolding to `MakeMigrationCommand` / `MakeQueueCommand` / `MakeSeederCommand` / `MakeTaskCommand` / `MakeTestCommand` / `MakeModuleCommand`, Playwright E2E setup to `TestE2eCommand`, and Boost/MCP operations to `BoostManager::applyConfiguration()`, `SkillWriter::sanitizeSkillName()`, and `McpServer`. Never duplicate worker/seeder/scheduler/scaffolding loops or run raw SQL against queue/cache tables.
4. **Security, RBAC & Localization Parity:**
   - Viewing sections requires `wire-console` plus the section permission (`wire-console-*`); unauthorized sections fall back to `'overview'` and emit `null` props.
   - `inspectFile` enforces the owning section's permission, and inspecting or downloading `.sql` backups requires **superuser** + `wire-console-backup`.
   - All 34 mutating actions enforce CSRF (`_token` or `X-CSRF-Token`), **superuser** status, action-specific confirmation word (`confirm_<guardKey>`), session cooldown rate-limiting, scalar-safe parameter extraction, bounded JSON parsing (`<= 64 KB`, depth `<= 16`, bounded element counts), and redacted `wire-console-audit` logging.
   - Any change to translatable `$this->_('...')` strings must keep all 6 `languages/{en,tr,de,fr,es,ru}.csv` files synchronized with `LanguagePorter::exportCsv`, and every PHP file under `tests/` must begin with `<?php // __(file-not-translatable)`.

## Verification Commands

- **Cold CLI Boot:** `php vendor/bin/wire version --json`
- **Static Analysis (PHPStan Level 8):** `vendor/bin/phpstan analyse -c site/modules/WireConsole/phpstan.neon --no-progress --memory-limit=1G`
- **Frontend Typecheck, Build & Contract Tests:** `npm --prefix site/modules/WireConsole run build && npm --prefix site/modules/WireConsole run test:unit`
- **Backend Pest Suite:** `php vendor/bin/wire test site/modules/WireConsole/tests`
- **Browser E2E & WCAG 2.1 AA Suite:** `php vendor/bin/wire test:e2e --module=WireConsole`
