---
description: "Architecture and development reference for ProcessWireConsole (Totoglu\\WireConsole) in ProcessWire 3.0.271+."
---

# ProcessWireConsole (`Totoglu\WireConsole`) Agent Guidelines

`ProcessWireConsole` (`site/modules/WireConsole`) provides the Inertia v3 + React 19 + Tailwind CSS v4 administrative control plane (`Setup > Console`, `{admin}/setup/wire-console/`) for `trk/processwire-console` and `trk/processwire-boost`, and ships its canonical Boost skill at `.agents/skills/pw-wire-console/SKILL.md`.

## Architectural Invariants

1. **Layering & Single-Point `WireClassLoader` Boot:**
   - `ProcessWireConsole` extends `Totoglu\React\Inertia\ProcessReact` (`Totoglu\Inertia\ProcessInertia` + `InteractWithReact`) and requires `['PHP>=8.4', 'ProcessWire>=3.0.271', 'Inertia', 'React']`.
   - Zero dependency on upper-layer domain modules (enforced by `tests/Unit/LayeringTest.php`). See [docs/ARCHITECTURE.md](./docs/ARCHITECTURE.md) for the full layering and security design.
   - Registers `Totoglu\WireConsole` -> `__DIR__ . '/src/'` strictly once inside `ProcessWireConsole::__construct()`. Keep `PERMISSION_*` constants on `ProcessWireConsole` as literal strings (synchronized 1-to-1 with `ConsoleRuntime::PERMISSION_*`) so PHP class compilation and `static getModuleInfo()` never autoload `src/` before `__construct()` executes. Never add `require_once` or `class_exists` shims in `ProcessWireConsole.module.php` or `src/`.
   - Declares `'autoload' => false` and `'reactCapabilities' => ['workspace', 'icons', 'commandSearch']` so `React` lazily pulls `ProcessWireConsole` on demand (`init()` defers `'console'` workspace, `'console-overview'` widget, and `'wire-console'` command palette search registration via `React::deferModuleRegistration(...)` and `React::deferCommandSearchRegistration(...)`) and frontend requests never load the module.
2. **Shared `@pw/react/*` SDK, Delta CSS & Vector Icons:**
   - `dist/ProcessWireConsole.js` imports React, Inertia, `AppShell`, `DynamicIcon`, UI primitives, `SchemaField`, `useTranslations`, and `useKeyboardShortcuts` with strict TypeScript types from the `@pw/react/*` SDK (`@pw/react/core`, `@pw/react/layout`, `@pw/react/ui`, `@pw/react/fields`, `@pw/react/hooks`).
   - Preloads `ReactIcons.js` (`window.ProcessWireIcons`) via `getModuleScriptAssets()` and registers admin icon names via `IconManager::registerAdminIcons(...)` in `init()`. Every icon identifier in PHP and TSX must exist in `React`'s `vector-icons.json` outline catalog; `executeNavJSON()` delegates to `InteractWithReact::buildAdminNavJSON(...)` so classic ProcessWire admin flyout items render `rwv--<icon>` CSS mask icons.
   - Shared Tailwind CSS v4 utilities come from `React/dist/ReactInertia.css`, and module-specific utilities in `resources/ts/**/*.{ts,tsx}` compile from `resources/css/entries/WireConsole.css` into the delta stylesheet `dist/WireConsole.css` (loaded via `getModuleStyleAssets()`).
3. **Direct Delegation to `Totoglu\Console\*`:**
   - Delegate migrations to `Totoglu\Console\Migration\Migrator`, queues to `Totoglu\Console\Queue\QueueManager`, `QueueWorkCommand`, and prepared `WireDatabasePDO` queries (`escapeTable()` + `prepare()`), seeders/tasks/maintenance/cache/logs/backup-purge to `DbSeedCommand` / `ScheduleRunCommand` / `DownCommand` / `UpCommand` / `CacheClearCommand` / `LogClearCommand` / `LogClearAllCommand` / `BackupPurgeCommand`, scaffolding to `MakeMigrationCommand` / `MakeQueueCommand` / `MakeSeederCommand` / `MakeTaskCommand` / `MakeTestCommand` / `MakeModuleCommand`, Playwright E2E setup to `TestE2eCommand`, and Boost/MCP operations to `BoostManager::applyConfiguration()`, `SkillWriter::sanitizeSkillName()`, and `McpServer`. Discover dynamic queue/seeder/task classes exclusively via `ConsoleRuntime::discoverVerifiedClasses()` so `!is_link()` and `realpath()` project-root containment are enforced before any file is loaded.
4. **Security, RBAC & Localization Parity:**
   - Viewing sections requires `wire-console` plus the section permission (`wire-console-*`); unauthorized sections fall back to `'overview'` and emit `null` props, and non-superuser responses redact host filesystem paths.
   - `inspectFile` enforces the owning section's permission, and inspecting or downloading `.sql` backups requires **superuser** + `wire-console-backup` + `$config->demo` disabled.
   - All mutating actions enforce HTTP `POST`, CSRF (`_token` or `X-CSRF-Token`), **superuser** status, `$config->demo` disabled (except allowlisted read-only `runMcpReadOnlyTool`), action-specific confirmation word (`confirm_<guardKey>`), session cooldown rate-limiting, scalar-safe parameter extraction, bounded JSON parsing (`<= 64 KB`, depth `<= 16`, bounded element counts), and redacted `wire-console-audit` logging.
   - Any change to translatable `$this->_('...')` strings must keep all 6 `languages/{en,tr,de,fr,es,ru}.csv` files synchronized with `LanguagePorter::exportCsv`, and every PHP file under `tests/` must begin with `<?php // __(file-not-translatable)`.

## Verification Commands

- **Cold CLI Boot:** `php vendor/bin/wire version --json`
- **Static Analysis (PHPStan Level 8):** `vendor/bin/phpstan analyse -c site/modules/WireConsole/phpstan.neon --no-progress --memory-limit=1G`
- **Frontend Typecheck, Build & Contract Tests:** `npm --prefix site/modules/WireConsole run build && npm --prefix site/modules/WireConsole run test:unit`
- **Backend Pest Suite:** `php vendor/bin/wire test site/modules/WireConsole/tests`
- **Browser E2E & WCAG 2.1 AA Suite:** `php vendor/bin/wire test:e2e --module=WireConsole`
